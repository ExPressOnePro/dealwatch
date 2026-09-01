<?php

namespace Tests\Feature\Deals;

use App\Models\Listing;
use App\Models\MarketPrice;
use App\Services\ListingDetailEnricher;
use App\Services\ListingPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class ListingPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MarketPrice::factory()->create();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
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
            'description' => 'Частник, батарея 92%, Face ID работает, iCloud чистый.',
            'price_original' => 8000,
            'price_mdl' => 8000,
            'currency' => 'MDL',
            'seller_type' => 'private',
            'seller_name' => 'seller_one',
            'location' => 'Кишинёв',
            'published_at' => now()->subMinutes(2),
        ], $overrides);
    }

    private function fakeEnricher(int $times): void
    {
        $this->mock(ListingDetailEnricher::class, function (MockInterface $mock) use ($times) {
            $mock->shouldReceive('enrich')->times($times)->andReturn(false);
        });
    }

    public function test_new_listing_is_enriched_once(): void
    {
        $this->fakeEnricher(1);

        app(ListingPipeline::class)->ingest($this->payload(), notify: false);

        $this->assertDatabaseCount('listings', 1);
    }

    public function test_unchanged_listing_is_not_enriched_again(): void
    {
        $this->fakeEnricher(1);
        $pipeline = app(ListingPipeline::class);

        $pipeline->ingest($this->payload(), notify: false);
        $pipeline->ingest($this->payload(), notify: false);

        $this->assertDatabaseCount('listings', 1);
    }

    public function test_price_change_triggers_a_new_enrich(): void
    {
        $this->fakeEnricher(2);
        $pipeline = app(ListingPipeline::class);

        $pipeline->ingest($this->payload(), notify: false);
        $pipeline->ingest($this->payload(['price_mdl' => 7500, 'price_original' => 7500]), notify: false);

        $this->assertSame(7500, Listing::query()->sole()->price_mdl);
    }

    public function test_enrich_every_run_when_new_only_is_disabled(): void
    {
        config()->set('dealwatch.collector.enrich_new_only', false);
        $this->fakeEnricher(2);
        $pipeline = app(ListingPipeline::class);

        $pipeline->ingest($this->payload(), notify: false);
        $pipeline->ingest($this->payload(), notify: false);
    }

    public function test_enrich_can_be_switched_off_entirely(): void
    {
        config()->set('dealwatch.collector.enrich', false);
        $this->fakeEnricher(0);

        app(ListingPipeline::class)->ingest($this->payload(), notify: false);
    }

    public function test_caller_can_force_enrich_off(): void
    {
        $this->fakeEnricher(0);

        app(ListingPipeline::class)->ingest($this->payload(), notify: false, enrich: false);
    }

    public function test_fresh_private_deal_triggers_a_telegram_alert(): void
    {
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.chat_id', '42');
        $this->fakeEnricher(1);

        $deal = app(ListingPipeline::class)->ingest($this->payload(), notify: true);

        $this->assertSame('buy', $deal->verdict);
        $this->assertTrue($deal->fresh()->notified);
        Http::assertSentCount(1);
    }

    public function test_shop_listing_never_alerts(): void
    {
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.chat_id', '42');
        $this->fakeEnricher(1);

        app(ListingPipeline::class)->ingest($this->payload(['seller_type' => 'shop']), notify: true);

        Http::assertNothingSent();
    }

    public function test_second_run_of_the_same_ad_does_not_alert_twice(): void
    {
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.chat_id', '42');
        $this->fakeEnricher(1);
        $pipeline = app(ListingPipeline::class);

        $pipeline->ingest($this->payload(), notify: true);
        $pipeline->ingest($this->payload(), notify: true);

        Http::assertSentCount(1);
    }
}
