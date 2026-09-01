<?php

namespace Tests\Feature\Deals;

use App\Models\Deal;
use App\Models\Listing;
use App\Models\MarketPrice;
use App\Services\DealScoreEngine;
use App\Services\ListingStaleness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingStalenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MarketPrice::factory()->create();
    }

    private function evaluate(int $daysAgo): Deal
    {
        $listing = Listing::factory()->create([
            'price_mdl' => 8000,
            'price_original' => 8000,
            'published_at' => now()->subDays($daysAgo),
            'first_seen_at' => now()->subDays($daysAgo),
        ]);

        return app(DealScoreEngine::class)->evaluate($listing);
    }

    public function test_fresh_listing_keeps_its_score(): void
    {
        $deal = $this->evaluate(1);

        $this->assertSame('buy', $deal->verdict);
        $this->assertGreaterThan(75, $deal->deal_score);
        $this->assertSame(ListingStaleness::LEVEL_FRESH, $deal->score_breakdown['staleness']);
        $this->assertArrayNotHasKey('stale_note', $deal->score_breakdown);
    }

    public function test_listing_untouched_for_weeks_is_capped(): void
    {
        $deal = $this->evaluate(30);

        $this->assertSame(ListingStaleness::LEVEL_SUSPECT, $deal->score_breakdown['staleness']);
        $this->assertSame(30, $deal->score_breakdown['listing_age_days']);
        $this->assertLessThanOrEqual(75, $deal->deal_score);
        $this->assertStringContainsString('возможно, уже продано', $deal->score_breakdown['stale_note']);
    }

    public function test_very_old_listing_is_not_a_buy_anymore(): void
    {
        $deal = $this->evaluate(90);

        $this->assertSame(ListingStaleness::LEVEL_DEAD, $deal->score_breakdown['staleness']);
        // Цена всё ещё выгодная, но звонить как по свежему нельзя.
        $this->assertSame('check', $deal->verdict);
        $this->assertLessThanOrEqual(55, $deal->deal_score);
    }

    public function test_zero_thresholds_do_not_mark_everything_as_sold(): void
    {
        // Пустой или незагруженный конфиг раньше давал порог 0 — и свежее
        // объявление получало пометку «скорее всего, уже продано».
        config()->set('dealwatch.staleness.suspect_days', 0);
        config()->set('dealwatch.staleness.dead_days', 0);

        $deal = $this->evaluate(2);

        $this->assertSame(ListingStaleness::LEVEL_FRESH, $deal->score_breakdown['staleness']);
        $this->assertSame('buy', $deal->verdict);
    }

    public function test_thresholds_come_from_config(): void
    {
        config()->set('dealwatch.staleness.suspect_days', 3);

        $deal = $this->evaluate(5);

        $this->assertSame(ListingStaleness::LEVEL_SUSPECT, $deal->score_breakdown['staleness']);
    }
}
