<?php

namespace Tests\Feature\Niche;

use App\Models\Listing;
use App\Models\ListingSnapshot;
use App\Models\SearchProfile;
use App\Services\Niche\NicheScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NicheScannerTest extends TestCase
{
    use RefreshDatabase;

    private SearchProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profile = SearchProfile::factory()->generic()->create(['scan_depth' => 50]);
        Cache::put('currency_rates_mdl', ['EUR' => 20.0, 'USD' => 18.0, 'MDL' => 1.0], now()->addHour());
        config()->set('dealwatch.collector.enrich', false);
    }

    /**
     * @param  list<array<string, mixed>>  $ads
     */
    private function fakeCatalog(array $ads): void
    {
        Http::fake([
            '999.md/graphql' => Http::response(['data' => ['searchAds' => ['count' => count($ads), 'ads' => $ads]]]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ad(string $id, int $price, string $title = 'Bergamont Gravel'): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'reseted' => now()->format('Y-m-d H:i:s'),
            'price' => ['value' => ['value' => $price, 'unit' => 'UNIT_MDL']],
            'city' => ['value' => ['translated' => 'Кишинёв']],
            'author' => ['value' => ['value' => 18895]],
            'offerType' => ['value' => ['value' => 776]],
            'owner' => ['id' => (int) $id, 'login' => 'seller'.$id, 'business' => null],
        ];
    }

    public function test_scan_confirms_listings_and_records_price_moves(): void
    {
        $listing = Listing::factory()->create([
            'search_profile_id' => $this->profile->id,
            'external_id' => '111',
            'price_mdl' => 9000,
            'last_seen_at' => now()->subDays(3),
        ]);

        $this->fakeCatalog([$this->ad('111', 8000)]);

        $stats = app(NicheScanner::class)->scan($this->profile);

        $listing->refresh();
        $this->assertSame(8000, $listing->price_mdl);
        $this->assertTrue($listing->last_seen_at->isToday());
        $this->assertSame(1, $stats['price_changes']);
        $this->assertSame(ListingSnapshot::REASON_PRICE_CHANGE, ListingSnapshot::sole()->reason);
        $this->assertNotNull($this->profile->fresh()->last_scan_at);
    }

    public function test_missing_listing_is_marked_gone_only_after_two_scans(): void
    {
        $missing = Listing::factory()->create([
            'search_profile_id' => $this->profile->id,
            'external_id' => '222',
            'last_seen_at' => now()->subDays(5),
        ]);

        $this->fakeCatalog([$this->ad('111', 9000)]);
        $scanner = app(NicheScanner::class);

        $scanner->scan($this->profile);
        $missing->refresh();
        $this->assertNull($missing->gone_at);
        $this->assertSame(1, $missing->missed_scans);

        $scanner->scan($this->profile);
        $missing->refresh();
        $this->assertNotNull($missing->gone_at);
        $this->assertSame('gone', $missing->status);
    }

    public function test_empty_catalog_response_does_not_bury_listings(): void
    {
        $listing = Listing::factory()->create([
            'search_profile_id' => $this->profile->id,
            'external_id' => '333',
            'last_seen_at' => now()->subDays(5),
        ]);

        $this->fakeCatalog([]);

        $stats = app(NicheScanner::class)->scan($this->profile);

        $this->assertSame(0, $stats['gone']);
        $this->assertNull($listing->fresh()->gone_at);
        $this->assertSame(0, $listing->fresh()->missed_scans);
    }

    public function test_returning_listing_comes_back_to_life(): void
    {
        $listing = Listing::factory()->create([
            'search_profile_id' => $this->profile->id,
            'external_id' => '444',
            'gone_at' => now()->subDay(),
            'status' => 'gone',
            'missed_scans' => 2,
        ]);

        $this->fakeCatalog([$this->ad('444', 7000)]);

        app(NicheScanner::class)->scan($this->profile);

        $listing->refresh();
        $this->assertNull($listing->gone_at);
        $this->assertSame('active', $listing->status);
        $this->assertSame(0, $listing->missed_scans);
    }

    public function test_new_listings_found_during_scan_are_ingested(): void
    {
        $this->fakeCatalog([$this->ad('555', 6500, 'Bergamont Rockaddict')]);

        $stats = app(NicheScanner::class)->scan($this->profile);

        $this->assertSame(1, $stats['fresh']);
        $this->assertDatabaseHas('listings', ['external_id' => '555', 'search_profile_id' => $this->profile->id]);
    }
}
