<?php

namespace Tests\Feature\Deals;

use App\Models\Deal;
use App\Models\Listing;
use App\Models\MarketPrice;
use App\Models\SearchProfile;
use App\Services\ListingPipeline;
use App\Services\ListingScorer;
use App\Services\ListingSubjectClassifier;
use App\Services\ProfileMarketStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectAwareScoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_parts_listing_never_gets_a_potential(): void
    {
        MarketPrice::factory()->create();

        $parts = Listing::factory()->create([
            'title' => 'iPhone 13 на запчасти, разбит экран',
            'price_mdl' => 2000,
            'subject' => ListingSubjectClassifier::SUBJECT_PARTS,
        ]);

        $deal = app(ListingScorer::class)->score($parts);

        $this->assertSame('ignore', $deal->verdict);
        $this->assertNull($deal->potential_profit);
        $this->assertSame(0, $deal->deal_score);
        $this->assertStringContainsString('не сам телефон', $deal->score_breakdown['note']);
    }

    public function test_replica_is_not_treated_as_the_real_thing(): void
    {
        $profile = SearchProfile::factory()->generic()->create();

        foreach ([3800, 4000, 4200, 4400, 4600] as $index => $price) {
            Listing::factory()->create([
                'search_profile_id' => $profile->id,
                'external_id' => 'real-'.$index,
                'price_mdl' => $price,
            ]);
        }

        $replica = Listing::factory()->create([
            'search_profile_id' => $profile->id,
            'external_id' => 'replica',
            'title' => 'Boxe marca JBL. Replică',
            'price_mdl' => 399,
            'is_replica' => true,
        ]);

        $deal = app(ListingScorer::class)->score($replica);

        $this->assertSame('ignore', $deal->verdict);
        $this->assertNull($deal->potential_profit);
        $this->assertTrue($deal->score_breakdown['is_replica']);
    }

    public function test_market_median_ignores_parts_and_replicas(): void
    {
        $profile = SearchProfile::factory()->generic()->create();

        foreach ([3800, 4000, 4200, 4400, 4600] as $index => $price) {
            Listing::factory()->create([
                'search_profile_id' => $profile->id,
                'external_id' => 'item-'.$index,
                'price_mdl' => $price,
            ]);
        }

        // Дешёвый мусор, который раньше ронял медиану втрое.
        foreach ([['parts', 350], ['parts', 444], ['accessory', 200]] as $index => [$subject, $price]) {
            Listing::factory()->create([
                'search_profile_id' => $profile->id,
                'external_id' => 'junk-'.$index,
                'price_mdl' => $price,
                'subject' => $subject,
            ]);
        }
        Listing::factory()->create([
            'search_profile_id' => $profile->id,
            'external_id' => 'copy',
            'price_mdl' => 399,
            'is_replica' => true,
        ]);

        $market = app(ProfileMarketStats::class)->for($profile);

        $this->assertSame(5, $market['total_samples']);
        $this->assertSame(4200, $market['median']);
    }

    public function test_real_item_in_the_same_source_still_scores(): void
    {
        $profile = SearchProfile::factory()->generic()->create();

        foreach ([3800, 4000, 4200, 4400, 4600] as $index => $price) {
            Listing::factory()->create([
                'search_profile_id' => $profile->id,
                'external_id' => 'item-'.$index,
                'price_mdl' => $price,
            ]);
        }
        Listing::factory()->create([
            'search_profile_id' => $profile->id,
            'external_id' => 'parts',
            'price_mdl' => 350,
            'subject' => ListingSubjectClassifier::SUBJECT_PARTS,
        ]);

        $bargain = Listing::factory()->create([
            'search_profile_id' => $profile->id,
            'external_id' => 'bargain',
            'price_mdl' => 2100,
        ]);

        $deal = app(ListingScorer::class)->score($bargain);

        // Медиана 4200 → ожидаемая продажа 3906, минус цена и издержки.
        $this->assertSame(3906 - 2100 - 600, $deal->potential_profit);
        $this->assertSame('check', $deal->verdict);
    }

    public function test_pipeline_marks_the_subject_on_ingest(): void
    {
        config()->set('dealwatch.collector.enrich', false);
        $profile = SearchProfile::factory()->generic()->create();

        app(ListingPipeline::class)->ingest([
            'platform' => '999',
            'external_id' => '999001',
            'search_profile_id' => $profile->id,
            'url' => 'https://999.md/ru/999001',
            'title' => 'Piese JBL Charge 5-4-6 Flip 5-6 Boombox 1',
            'description' => 'Только запчасти, колонки нет',
            'price_mdl' => 350,
            'price_original' => 350,
            'currency' => 'MDL',
            'seller_type' => 'private',
            'published_at' => now(),
        ], notify: false, enrich: false);

        $listing = Listing::query()->where('external_id', '999001')->sole();
        $this->assertSame(ListingSubjectClassifier::SUBJECT_PARTS, $listing->subject);
        $this->assertFalse($listing->isRealItem());
        $this->assertNull(Deal::query()->where('listing_id', $listing->id)->sole()->potential_profit);
    }
}
