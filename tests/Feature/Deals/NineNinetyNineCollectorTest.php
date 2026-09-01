<?php

namespace Tests\Feature\Deals;

use App\Services\Collectors\NineNinetyNineCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NineNinetyNineCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::put('currency_rates_mdl', ['EUR' => 20.0, 'USD' => 18.0, 'MDL' => 1.0], now()->addHour());
    }

    /**
     * @param  list<array<string, mixed>>  $ads
     */
    private function fakeSearch(array $ads): void
    {
        Http::fake([
            '999.md/graphql' => Http::response([
                'data' => ['searchAds' => ['count' => count($ads), 'ads' => $ads]],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ad(array $overrides = []): array
    {
        return array_merge([
            'id' => '11111111',
            'title' => 'iPhone 13 128GB',
            'reseted' => '2026-09-01 10:00:00',
            'price' => ['value' => ['value' => 400, 'unit' => 'EUR']],
            'city' => ['value' => ['translated' => 'Кишинёв']],
            'author' => ['value' => ['value' => 18895, 'translated' => 'Частное лицо']],
            'condition' => ['value' => ['translated' => 'Б/у']],
            'offerType' => ['value' => ['value' => 776]],
            'body' => ['value' => '<p>Состояние отличное, Face ID работает</p>'],
            'phoneNumbers' => ['value' => '+373 69 000 001'],
            'images' => ['value' => [['url' => 'https://i.999.md/photo1.jpg']]],
            'storage' => ['value' => ['translated' => '128 ГБ']],
            'battery' => ['value' => '92'],
            'siteModel' => ['value' => ['translated' => 'iPhone 13']],
            'owner' => ['id' => 555, 'login' => 'user555', 'business' => null],
        ], $overrides);
    }

    public function test_maps_a_private_sell_ad(): void
    {
        $this->fakeSearch([$this->ad()]);

        $items = app(NineNinetyNineCollector::class)->collect(10);

        $this->assertCount(1, $items);
        $item = $items[0];

        $this->assertSame('999', $item['platform']);
        $this->assertSame('11111111', $item['external_id']);
        $this->assertSame('https://999.md/ru/11111111', $item['url']);
        $this->assertSame('private', $item['seller_type']);
        $this->assertSame('sell', $item['listing_kind']);
        $this->assertSame('Кишинёв', $item['location']);
        $this->assertSame('iPhone 13', $item['site_model']);
        $this->assertSame(128, $item['storage_gb']);
        $this->assertSame(92, $item['battery_health']);
        $this->assertSame('+37369000001', $item['seller_phone']);
        $this->assertSame(['https://i.999.md/photo1.jpg'], $item['images']);
        $this->assertStringNotContainsString('<p>', $item['description']);
    }

    public function test_converts_currency_prices_to_mdl(): void
    {
        $this->fakeSearch([$this->ad()]);

        $item = app(NineNinetyNineCollector::class)->collect(10)[0];

        $this->assertSame('EUR', $item['currency']);
        $this->assertSame(400, $item['price_original']);
        $this->assertSame(8000, $item['price_mdl']);
        $this->assertArrayNotHasKey('price', $item);
    }

    public function test_shop_author_option_marks_a_shop(): void
    {
        $this->fakeSearch([$this->ad([
            'author' => ['value' => ['value' => 37797, 'translated' => 'Магазин']],
            'owner' => ['id' => 777, 'login' => 'shop777', 'business' => ['id' => 9, 'plan' => 'pro']],
        ])]);

        $this->assertSame('shop', app(NineNinetyNineCollector::class)->collect(10)[0]['seller_type']);
    }

    public function test_business_account_without_author_field_is_a_shop(): void
    {
        $this->fakeSearch([$this->ad([
            'author' => null,
            'owner' => ['id' => 777, 'login' => 'shop777', 'business' => ['id' => 9, 'plan' => 'pro']],
        ])]);

        $this->assertSame('shop', app(NineNinetyNineCollector::class)->collect(10)[0]['seller_type']);
    }

    public function test_non_sell_offers_are_dropped(): void
    {
        $this->fakeSearch([
            $this->ad(['id' => '22222222', 'offerType' => ['value' => ['value' => 777]], 'title' => 'Куплю iPhone']),
        ]);

        $this->assertSame([], app(NineNinetyNineCollector::class)->collect(10));
    }

    public function test_publish_date_comes_from_the_ad(): void
    {
        $this->fakeSearch([$this->ad()]);

        $item = app(NineNinetyNineCollector::class)->collect(10)[0];

        $this->assertSame(
            '2026-09-01 10:00:00',
            $item['published_at']->timezone('Europe/Chisinau')->format('Y-m-d H:i:s')
        );
    }

    public function test_network_failure_is_an_empty_run_not_a_crash(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->assertSame([], app(NineNinetyNineCollector::class)->collect(10));
    }

    public function test_unreachable_ad_page_returns_null_detail(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->assertNull(app(NineNinetyNineCollector::class)->fetchDetail('11111111'));
    }

    public function test_broken_graphql_response_yields_nothing(): void
    {
        Http::fake(['999.md/graphql' => Http::response(['errors' => [['message' => 'Cannot query field']]])]);

        $this->assertSame([], app(NineNinetyNineCollector::class)->collect(10));
    }
}
