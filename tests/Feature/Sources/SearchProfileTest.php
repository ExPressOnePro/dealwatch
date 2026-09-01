<?php

namespace Tests\Feature\Sources;

use App\Jobs\CollectListings;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\MarketPrice;
use App\Models\SearchProfile;
use App\Models\User;
use App\Services\Collectors\NineNinetyNineCollector;
use App\Services\ListingPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SearchProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Cache::put('currency_rates_mdl', ['EUR' => 20.0, 'USD' => 18.0, 'MDL' => 1.0], now()->addHour());
        config()->set('dealwatch.collector.enrich', false);
    }

    /**
     * @param  list<array<string, mixed>>  $ads
     */
    private function fakeSearch(array $ads): void
    {
        Http::fake([
            '999.md/graphql' => Http::response(['data' => ['searchAds' => ['count' => count($ads), 'ads' => $ads]]]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ad(array $overrides = []): array
    {
        return array_merge([
            'id' => (string) fake()->unique()->numberBetween(10_000_000, 99_999_999),
            'title' => 'Bicicletă Bergamont Gravel 29',
            'reseted' => now()->format('Y-m-d H:i:s'),
            'price' => ['value' => ['value' => 6000, 'unit' => 'UNIT_MDL']],
            'city' => ['value' => ['translated' => 'Кишинёв']],
            'author' => ['value' => ['value' => 18895, 'translated' => 'Частное лицо']],
            'offerType' => ['value' => ['value' => 776]],
            'body' => ['value' => 'Состояние отличное, катались редко.'],
            'owner' => ['id' => fake()->unique()->numberBetween(1000, 9999), 'login' => 'seller'.fake()->numberBetween(1, 9999), 'business' => null],
        ], $overrides);
    }

    public function test_default_phone_source_exists_after_migration(): void
    {
        $profile = SearchProfile::query()->sole();

        $this->assertSame('Телефоны 999.md', $profile->name);
        $this->assertSame(40, $profile->subcategory_id);
        $this->assertTrue($profile->isPhones());
    }

    public function test_collector_asks_the_platform_for_the_configured_category_and_keywords(): void
    {
        $profile = SearchProfile::factory()->generic()->create([
            'category_id' => 658,
            'subcategory_id' => null,
            'query' => 'велосипед',
            'per_run' => 10,
        ]);
        $this->fakeSearch([$this->ad()]);

        app(NineNinetyNineCollector::class)->collectForProfile($profile);

        Http::assertSent(function (Request $request) {
            $input = $request['variables']['input'];

            return ($input['query'] ?? null) === 'велосипед'
                && ($input['categoryId'] ?? null) === 658
                && ! isset($input['subCategoryId'])
                && $input['pagination']['limit'] === 10;
        });
    }

    public function test_stop_words_and_price_limits_filter_the_results(): void
    {
        $profile = SearchProfile::factory()->generic()->create([
            'exclude_keywords' => ['запчасти'],
            'price_min' => 3000,
            'price_max' => 8000,
        ]);

        $this->fakeSearch([
            $this->ad(['id' => '1', 'title' => 'Bicicletă Bergamont Gravel']),
            $this->ad(['id' => '2', 'title' => 'Велосипед на запчасти']),
            $this->ad(['id' => '3', 'title' => 'Дорогой велосипед', 'price' => ['value' => ['value' => 25000, 'unit' => 'UNIT_MDL']]]),
            $this->ad(['id' => '4', 'title' => 'Дешёвый велосипед', 'price' => ['value' => ['value' => 500, 'unit' => 'UNIT_MDL']]]),
        ]);

        $items = app(NineNinetyNineCollector::class)->collectForProfile($profile);

        $this->assertCount(1, $items);
        $this->assertSame('1', $items[0]['external_id']);
        $this->assertSame($profile->id, $items[0]['search_profile_id']);
    }

    public function test_generic_source_scores_against_its_own_price_median(): void
    {
        $profile = SearchProfile::factory()->generic()->create();

        // Похожие объявления формируют рынок источника: медиана 6000.
        foreach ([5200, 5600, 6000, 6400, 6800] as $index => $price) {
            Listing::factory()->create([
                'search_profile_id' => $profile->id,
                'price_mdl' => $price,
                'price_original' => $price,
                'external_id' => 'market-'.$index,
                'brand' => null,
                'model' => null,
                'storage_gb' => null,
            ]);
        }

        $this->fakeSearch([$this->ad(['id' => '777', 'price' => ['value' => ['value' => 3200, 'unit' => 'UNIT_MDL']]])]);

        $items = app(NineNinetyNineCollector::class)->collectForProfile($profile);
        $deal = app(ListingPipeline::class)->ingest($items[0], notify: false);

        $this->assertNotNull($deal);
        $this->assertSame('generic', $deal->score_breakdown['mode']);
        $this->assertSame(6000, $deal->score_breakdown['market']['median']);
        // ожидаемая продажа 5580 (медиана −7% торга), минус цена 3200, подготовка и резерв
        $this->assertSame(5580 - 3200 - 600, $deal->potential_profit);
        $this->assertSame('buy', $deal->verdict);
    }

    public function test_generic_source_waits_for_enough_data(): void
    {
        $profile = SearchProfile::factory()->generic()->create();
        $this->fakeSearch([$this->ad()]);

        $items = app(NineNinetyNineCollector::class)->collectForProfile($profile);
        $deal = app(ListingPipeline::class)->ingest($items[0], notify: false);

        $this->assertSame('ignore', $deal->verdict);
        $this->assertSame(0, $deal->deal_score);
        $this->assertStringContainsString('Мало данных', $deal->score_breakdown['note']);
    }

    public function test_phone_source_still_uses_the_model_catalogue(): void
    {
        MarketPrice::factory()->create();
        $profile = SearchProfile::query()->sole();   // «Телефоны 999.md»

        $this->fakeSearch([$this->ad([
            'title' => 'iPhone 13 128GB',
            'price' => ['value' => ['value' => 8000, 'unit' => 'UNIT_MDL']],
        ])]);

        $items = app(NineNinetyNineCollector::class)->collectForProfile($profile);
        $deal = app(ListingPipeline::class)->ingest($items[0], notify: false);

        $this->assertArrayNotHasKey('mode', $deal->score_breakdown);
        $this->assertSame('buy', $deal->verdict);
        $this->assertNotNull($deal->listing->model);
    }

    public function test_first_collection_rescoring_gives_every_listing_a_verdict(): void
    {
        $profile = SearchProfile::factory()->generic()->create(['per_run' => 10]);

        // Пять объявлений приходят одной пачкой: пока идёт первое, рынка ещё нет.
        $this->fakeSearch([
            $this->ad(['id' => '1', 'price' => ['value' => ['value' => 9000, 'unit' => 'UNIT_MDL']]]),
            $this->ad(['id' => '2', 'price' => ['value' => ['value' => 9500, 'unit' => 'UNIT_MDL']]]),
            $this->ad(['id' => '3', 'price' => ['value' => ['value' => 10000, 'unit' => 'UNIT_MDL']]]),
            $this->ad(['id' => '4', 'price' => ['value' => ['value' => 10500, 'unit' => 'UNIT_MDL']]]),
            $this->ad(['id' => '5', 'price' => ['value' => ['value' => 5500, 'unit' => 'UNIT_MDL']]]),
        ]);

        app(ListingPipeline::class)->collectProfile($profile, notify: false);

        $cheap = Deal::query()->whereHas('listing', fn ($q) => $q->where('external_id', '5'))->sole();

        // После сбора источник пересчитан целиком — дешёвое объявление получило оценку.
        $this->assertSame('buy', $cheap->verdict);
        $this->assertGreaterThan(0, $cheap->deal_score);
        $this->assertNotNull($cheap->potential_profit);
    }

    public function test_price_far_below_the_source_market_is_flagged_for_checking(): void
    {
        $profile = SearchProfile::factory()->generic()->create();

        foreach ([9000, 9500, 10000, 10500, 11000] as $index => $price) {
            Listing::factory()->create([
                'search_profile_id' => $profile->id,
                'external_id' => 'market-'.$index,
                'price_mdl' => $price,
                'price_original' => $price,
            ]);
        }

        $this->fakeSearch([$this->ad(['id' => '999', 'price' => ['value' => ['value' => 2500, 'unit' => 'UNIT_MDL']]])]);

        $items = app(NineNinetyNineCollector::class)->collectForProfile($profile);
        $deal = app(ListingPipeline::class)->ingest($items[0], notify: false);

        // Вдвое дешевле нижнего квартиля — не «бери сразу», а «сходи проверь».
        $this->assertSame('check', $deal->verdict);
        $this->assertSame(25, $deal->score_breakdown['risk']);
    }

    public function test_sources_page_lists_profiles(): void
    {
        SearchProfile::factory()->generic()->create();
        Http::fake(['999.md/graphql' => Http::response(['data' => ['categoryTree' => ['categories' => [
            ['id' => 658, 'title' => ['translated' => 'Transport'], 'categories' => [['id' => 5000, 'title' => ['translated' => 'Mijloace de transport']]]],
        ]]]])]);

        $this->actingAs($this->user)
            ->get('/sources')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sources/index')
                ->has('profiles', 2)
                ->has('categories', 1)
                ->where('categories.0.label', 'Transport')
            );
    }

    public function test_source_can_be_created_and_removed(): void
    {
        $this->actingAs($this->user)
            ->post('/sources', [
                'name' => 'MacBook до 20 000',
                'category_id' => 2,
                'category_label' => 'Calculatoare',
                'query' => 'macbook',
                'exclude_keywords' => ['запчасти', ''],
                'price_max' => 20000,
                'per_run' => 25,
                'scoring' => 'generic',
                'notify' => false,
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $profile = SearchProfile::query()->where('name', 'MacBook до 20 000')->sole();
        $this->assertSame(['запчасти'], $profile->exclude_keywords);
        $this->assertSame(20000, $profile->price_max);
        $this->assertFalse($profile->notify);

        $this->actingAs($this->user)->delete("/sources/{$profile->id}")->assertRedirect();
        $this->assertNull(SearchProfile::find($profile->id));
    }

    public function test_price_range_is_validated(): void
    {
        $this->actingAs($this->user)
            ->post('/sources', [
                'name' => 'Сломанный диапазон',
                'per_run' => 25,
                'scoring' => 'generic',
                'price_min' => 5000,
                'price_max' => 1000,
            ])
            ->assertSessionHasErrors('price_max');
    }

    public function test_single_source_can_be_collected_on_demand(): void
    {
        Queue::fake();
        $profile = SearchProfile::factory()->generic()->create();

        $this->actingAs($this->user)
            ->post("/sources/{$profile->id}/collect")
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(CollectListings::class, fn ($job) => $job->profileId === $profile->id);
    }

    public function test_feed_can_be_filtered_by_source(): void
    {
        $phones = SearchProfile::query()->sole();
        $bikes = SearchProfile::factory()->generic()->create();

        $phoneDeal = Deal::factory()->create([
            'listing_id' => Listing::factory()->create(['search_profile_id' => $phones->id])->id,
        ]);
        Deal::factory()->create([
            'listing_id' => Listing::factory()->create(['search_profile_id' => $bikes->id])->id,
        ]);

        $this->actingAs($this->user)
            ->get('/deals?profile='.$phones->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('deals', 1)
                ->where('deals.0.id', $phoneDeal->id)
                ->has('sources', 2)
            );
    }

    public function test_collect_command_walks_every_active_source(): void
    {
        SearchProfile::factory()->generic()->create();
        SearchProfile::factory()->create(['name' => 'Выключенный', 'is_active' => false]);
        $this->fakeSearch([$this->ad()]);

        $this->artisan('deals:collect-999', ['--no-notify' => true])->assertSuccessful();

        // Два активных источника (телефоны по умолчанию + велосипеды), выключенный пропущен.
        $this->assertNotNull(SearchProfile::query()->where('name', 'Телефоны 999.md')->sole()->last_run_at);
        $this->assertNotNull(SearchProfile::query()->where('name', 'Велосипеды')->sole()->last_run_at);
        $this->assertNull(SearchProfile::query()->where('name', 'Выключенный')->sole()->last_run_at);
    }
}
