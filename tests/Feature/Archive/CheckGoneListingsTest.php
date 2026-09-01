<?php

namespace Tests\Feature\Archive;

use App\Models\Listing;
use App\Models\ListingSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckGoneListingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_removed_listing_is_marked_gone(): void
    {
        Http::fake(['999.md/*' => Http::response('not found', 404)]);
        $listing = Listing::factory()->create(['last_seen_at' => now()->subDays(2)]);

        $this->artisan('listings:check-gone', ['--limit' => 5])->assertSuccessful();

        $listing->refresh();
        $this->assertNotNull($listing->gone_at);
        $this->assertSame('gone', $listing->status);
        $this->assertSame(ListingSnapshot::REASON_GONE, ListingSnapshot::sole()->reason);
    }

    public function test_live_listing_gets_its_last_seen_refreshed(): void
    {
        Http::fake(['999.md/*' => Http::response('<html>iPhone 13, продаю</html>')]);
        $listing = Listing::factory()->create(['last_seen_at' => now()->subDays(2)]);

        $this->artisan('listings:check-gone', ['--limit' => 5])->assertSuccessful();

        $listing->refresh();
        $this->assertNull($listing->gone_at);
        $this->assertTrue($listing->last_seen_at->isToday());
    }

    public function test_expired_marker_counts_as_gone(): void
    {
        Http::fake(['999.md/*' => Http::response('<html>Anunțul a expirat</html>')]);
        $listing = Listing::factory()->create(['last_seen_at' => now()->subDays(2)]);

        $this->artisan('listings:check-gone')->assertSuccessful();

        $this->assertNotNull($listing->fresh()->gone_at);
    }

    public function test_fresh_listings_are_not_touched(): void
    {
        Http::fake();
        Listing::factory()->create(['last_seen_at' => now()->subMinutes(10)]);

        $this->artisan('listings:check-gone')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_network_error_leaves_the_listing_alone(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $listing = Listing::factory()->create(['last_seen_at' => now()->subDays(2)]);

        $this->artisan('listings:check-gone')->assertSuccessful();

        $listing->refresh();
        $this->assertNull($listing->gone_at);
        $this->assertSame('active', $listing->status);
    }
}
