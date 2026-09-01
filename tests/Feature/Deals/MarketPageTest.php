<?php

namespace Tests\Feature\Deals;

use App\Models\Listing;
use App\Models\MarketPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MarketPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_page_shows_evidence_for_each_price(): void
    {
        $market = MarketPrice::factory()->create();
        Listing::factory()->count(3)->create();

        $this->actingAs(User::factory()->create())
            ->get('/market')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('market/index')
                ->has('prices', 1)
                ->has('prices.0.evidence.steps')
                ->where('prices.0.id', $market->id)
            );
    }

    public function test_evidence_is_cached_between_requests(): void
    {
        $market = MarketPrice::factory()->create();
        Listing::factory()->count(3)->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/market')->assertOk();

        $key = 'market:evidence:'.$market->id.':'.$market->updated_at->timestamp;
        $this->assertTrue(Cache::has($key));

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->actingAs($user)->get('/market')->assertOk();
        $cachedRunQueries = $queries;

        Cache::flush();
        $queries = 0;
        $this->actingAs($user)->get('/market')->assertOk();

        $this->assertLessThan($queries, $cachedRunQueries);
    }

    public function test_market_recalculation_invalidates_the_cache(): void
    {
        $market = MarketPrice::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/market')->assertOk();
        $staleKey = 'market:evidence:'.$market->id.':'.$market->updated_at->timestamp;

        $this->travel(1)->minutes();
        $market->touch();

        $this->actingAs($user)->get('/market')->assertOk();

        $freshKey = 'market:evidence:'.$market->id.':'.$market->fresh()->updated_at->timestamp;
        $this->assertNotSame($staleKey, $freshKey);
        $this->assertTrue(Cache::has($freshKey));
    }
}
