<?php

namespace Tests\Feature\Archive;

use App\Models\Deal;
use App\Models\Listing;
use App\Models\ListingSnapshot;
use App\Models\MarketPrice;
use App\Services\Archive\ListingArchivist;
use App\Services\ListingPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ListingArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MarketPrice::factory()->create();
        config()->set('dealwatch.collector.enrich', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'platform' => '999',
            'external_id' => '12345678',
            'url' => 'https://999.md/ru/12345678',
            'title' => 'iPhone 13 128GB',
            'description' => 'Состояние отличное, батарея 92%.',
            'price_original' => 8000,
            'price_mdl' => 8000,
            'currency' => 'MDL',
            'seller_type' => 'private',
            'location' => 'Кишинёв',
            'images' => ['https://i.999.md/photo1.jpg', 'https://i.999.md/photo2.jpg'],
            'published_at' => now()->subMinutes(5),
        ], $overrides);
    }

    public function test_first_sighting_is_snapshotted(): void
    {
        Http::fake();

        app(ListingPipeline::class)->ingest($this->payload(), notify: false);

        $snapshot = ListingSnapshot::sole();
        $this->assertSame(ListingSnapshot::REASON_FIRST_SEEN, $snapshot->reason);
        $this->assertSame('iPhone 13 128GB', $snapshot->payload['title']);
        $this->assertSame(8000, $snapshot->price_mdl);
        // тяжёлые медиа для «чужого» объявления не качаем
        $this->assertNull($snapshot->image_paths);
        Http::assertNothingSent();
    }

    public function test_price_change_creates_a_new_snapshot(): void
    {
        Http::fake();
        $pipeline = app(ListingPipeline::class);

        $pipeline->ingest($this->payload(), notify: false);
        $pipeline->ingest($this->payload(), notify: false);
        $this->assertSame(1, ListingSnapshot::query()->count());

        $pipeline->ingest($this->payload(['price_mdl' => 7500, 'price_original' => 7500]), notify: false);

        $snapshots = ListingSnapshot::query()->orderBy('id')->get();
        $this->assertCount(2, $snapshots);
        $this->assertSame(ListingSnapshot::REASON_PRICE_CHANGE, $snapshots[1]->reason);
        $this->assertSame(7500, $snapshots[1]->price_mdl);
    }

    public function test_disappeared_listing_is_marked_and_kept(): void
    {
        $listing = Listing::factory()->create();

        app(ListingArchivist::class)->markGone($listing);

        $listing->refresh();
        $this->assertNotNull($listing->gone_at);
        $this->assertSame('gone', $listing->status);
        $this->assertTrue($listing->isGone());
        $this->assertSame(ListingSnapshot::REASON_GONE, ListingSnapshot::sole()->reason);
    }

    public function test_relisted_ad_comes_back_to_life(): void
    {
        Http::fake();
        $pipeline = app(ListingPipeline::class);
        $pipeline->ingest($this->payload(), notify: false);

        $listing = Listing::query()->sole();
        app(ListingArchivist::class)->markGone($listing);
        $this->assertNotNull($listing->fresh()->gone_at);

        $pipeline->ingest($this->payload(), notify: false);

        $this->assertNull($listing->fresh()->gone_at);
    }

    public function test_manual_archive_downloads_photos_and_page(): void
    {
        Storage::fake('local');
        Http::fake([
            'i.999.md/*' => Http::response('binary-image-content'),
            '999.md/ru/*' => Http::response('<html><body>Объявление</body></html>'),
        ]);

        $listing = Listing::factory()->create([
            'images' => ['https://i.999.md/photo1.jpg', 'https://i.999.md/photo2.jpg'],
            'url' => 'https://999.md/ru/12345678',
        ]);

        $snapshot = app(ListingArchivist::class)->archive($listing);

        $this->assertTrue($listing->fresh()->archived);
        $this->assertCount(2, $snapshot->image_paths);
        $this->assertNotNull($snapshot->html_path);
        $this->assertGreaterThan(0, $snapshot->size_bytes);

        Storage::disk('local')->assertExists($snapshot->image_paths[0]);
        Storage::disk('local')->assertExists($snapshot->html_path);
        // страница хранится сжатой
        $this->assertStringContainsString('Объявление', gzdecode(Storage::disk('local')->get($snapshot->html_path)));
    }

    public function test_media_is_kept_for_listings_we_saved(): void
    {
        $archivist = app(ListingArchivist::class);

        $plain = Listing::factory()->create();
        $this->assertFalse($archivist->shouldKeepMedia($plain));

        $favorite = Listing::factory()->create();
        Deal::factory()->create(['listing_id' => $favorite->id, 'is_favorite' => true]);
        $this->assertTrue($archivist->shouldKeepMedia($favorite->fresh()));

        $bought = Listing::factory()->create();
        Deal::factory()->create(['listing_id' => $bought->id, 'user_status' => Deal::STATUS_BOUGHT]);
        $this->assertTrue($archivist->shouldKeepMedia($bought->fresh()));
    }

    public function test_oversized_images_are_skipped(): void
    {
        Storage::fake('local');
        config()->set('dealwatch.archive.max_image_bytes', 10);
        config()->set('dealwatch.archive.keep_html', false);
        Http::fake(['i.999.md/*' => Http::response(str_repeat('x', 100))]);

        $listing = Listing::factory()->create(['images' => ['https://i.999.md/big.jpg']]);
        $snapshot = app(ListingArchivist::class)->archive($listing);

        $this->assertNull($snapshot->image_paths);
        $this->assertSame(0, $snapshot->size_bytes);
    }

    public function test_archiving_can_be_switched_off(): void
    {
        config()->set('dealwatch.archive.enabled', false);
        Http::fake();

        app(ListingPipeline::class)->ingest($this->payload(), notify: false);

        $this->assertSame(0, ListingSnapshot::query()->count());
    }
}
