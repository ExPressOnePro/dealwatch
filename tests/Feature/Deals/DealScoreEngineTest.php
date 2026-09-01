<?php

namespace Tests\Feature\Deals;

use App\Models\Deal;
use App\Models\IgnoredListing;
use App\Models\Listing;
use App\Models\MarketPrice;
use App\Services\DealScoreEngine;
use App\Services\SellerAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealScoreEngineTest extends TestCase
{
    use RefreshDatabase;

    private DealScoreEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(DealScoreEngine::class);
        MarketPrice::factory()->create();
    }

    public function test_cheap_private_listing_becomes_a_buy(): void
    {
        $listing = Listing::factory()->create(['price_mdl' => 8000, 'price_original' => 8000]);

        $deal = $this->engine->evaluate($listing);

        $this->assertNotNull($deal);
        $this->assertSame('buy', $deal->verdict);
        $this->assertGreaterThanOrEqual(80, $deal->deal_score);
        // ожидаемая продажа − цена − подготовка − резерв
        $this->assertGreaterThanOrEqual(1500, $deal->potential_profit);
        $this->assertSame($listing->id, $deal->listing_id);
    }

    public function test_listing_priced_above_market_is_ignored(): void
    {
        $listing = Listing::factory()->create(['price_mdl' => 12500, 'price_original' => 12500]);

        $deal = $this->engine->evaluate($listing);

        $this->assertSame('ignore', $deal->verdict);
        $this->assertLessThan(60, $deal->deal_score);
        $this->assertLessThan(800, $deal->potential_profit);
    }

    public function test_verdict_thresholds_come_from_config(): void
    {
        config()->set('dealwatch.score.verdict.buy.score', 99);

        $deal = $this->engine->evaluate(Listing::factory()->create(['price_mdl' => 8000]));

        $this->assertSame('check', $deal->verdict);
    }

    public function test_costs_come_from_config(): void
    {
        $listing = Listing::factory()->create(['price_mdl' => 8000]);
        $baseline = $this->engine->evaluate($listing)->potential_profit;

        config()->set('dealwatch.economics.prep_cost', 1300);
        $withHigherPrep = $this->engine->evaluate($listing->fresh())->potential_profit;

        $this->assertSame($baseline - 1000, $withHigherPrep);
    }

    public function test_want_buy_ad_is_a_buyer_lead_not_a_deal(): void
    {
        $listing = Listing::factory()->wantBuy()->create(['price_mdl' => 8000]);

        $deal = $this->engine->evaluate($listing);

        $this->assertSame('ignore', $deal->verdict);
        $this->assertSame(0, $deal->deal_score);
        $this->assertTrue($deal->score_breakdown['buyer_lead']);
        $this->assertNull($deal->potential_profit);
    }

    public function test_reseller_never_gets_a_buy(): void
    {
        $listing = Listing::factory()->reseller()->create(['price_mdl' => 8000]);

        $deal = $this->engine->evaluate($listing);

        $this->assertSame('ignore', $deal->verdict);
        $this->assertTrue($deal->score_breakdown['reseller']);
        $this->assertLessThanOrEqual(35, $deal->deal_score);
    }

    public function test_shop_listing_is_a_separate_rubric(): void
    {
        $listing = Listing::factory()->shop()->create(['price_mdl' => 8000]);

        $deal = $this->engine->evaluate($listing);

        $this->assertSame('ignore', $deal->verdict);
        $this->assertTrue($deal->score_breakdown['shop']);
    }

    public function test_listing_without_market_reference_is_not_scored(): void
    {
        $listing = Listing::factory()->create([
            'brand' => 'Nokia',
            'model' => '3310',
            'storage_gb' => null,
            'title' => 'Nokia 3310',
        ]);

        $this->assertNull($this->engine->evaluate($listing));
    }

    public function test_manually_hidden_listing_stays_dismissed_after_rescore(): void
    {
        $listing = Listing::factory()->create(['price_mdl' => 8000]);
        IgnoredListing::remember('999', (string) $listing->external_id);

        $deal = $this->engine->evaluate($listing);

        $this->assertSame(Deal::STATUS_DISMISSED, $deal->user_status);
    }

    public function test_seller_accounts_are_told_apart_by_uuid(): void
    {
        // 999.md отдаёт id владельца строкой-UUID: раньше он приводился к int,
        // и все продавцы сливались в один аккаунт, выглядевший перекупом.
        $first = Listing::factory()->create([
            'external_id' => 'uuid-1',
            'seller_key' => null,
            'raw_data' => ['owner' => ['id' => '0f299c6d-3123-4635-8d27-a24435a06a5f', 'login' => 'Cikpak']],
        ]);
        $second = Listing::factory()->create([
            'external_id' => 'uuid-2',
            'seller_key' => null,
            'raw_data' => ['owner' => ['id' => 'd25b93c8-64b3-43d6-b360-a69c794c7d68', 'login' => 'veterok']],
        ]);

        $sellers = app(SellerAnalyticsService::class);

        $this->assertSame('999:owner:0f299c6d-3123-4635-8d27-a24435a06a5f', $sellers->resolveKey($first));
        $this->assertNotSame($sellers->resolveKey($first), $sellers->resolveKey($second));

        $sellers->refreshListing($first);
        $sellers->refreshListing($second);

        $this->assertFalse($first->fresh()->is_reseller);
        $this->assertFalse($second->fresh()->is_reseller);
    }

    public function test_rescoring_updates_the_same_deal(): void
    {
        $listing = Listing::factory()->create(['price_mdl' => 8000]);
        $first = $this->engine->evaluate($listing);

        $listing->forceFill(['price_mdl' => 12500])->save();
        $second = $this->engine->evaluate($listing->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Deal::query()->count());
        $this->assertSame('ignore', $second->verdict);
    }
}
