<?php

namespace Tests\Feature\Deals;

use App\Models\Deal;
use App\Models\Listing;
use App\Models\User;
use App\Services\DealFeedStats;
use App\Services\ListingCorpusStats;
use App\Services\StatsCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatsCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dealwatch.stats.cache_seconds', 60);
        StatsCache::flush();
    }

    private function countQueries(callable $fn): int
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $fn();

        return $queries;
    }

    public function test_feed_stats_are_computed_once_and_reused(): void
    {
        Deal::factory()->create(['listing_id' => Listing::factory()->create()->id]);
        $stats = app(DealFeedStats::class);

        $cold = $this->countQueries(fn () => $stats->headline());
        $warm = $this->countQueries(fn () => $stats->headline());

        $this->assertGreaterThan(0, $cold);
        $this->assertSame(0, $warm);
    }

    public function test_corpus_summary_is_a_single_query(): void
    {
        Listing::factory()->count(3)->create();

        // один агрегат вместо десяти COUNT/MIN/MAX
        $this->assertSame(1, $this->countQueries(fn () => app(ListingCorpusStats::class)->summary(0)));
    }

    public function test_user_action_refreshes_the_counters(): void
    {
        $deal = Deal::factory()->create([
            'listing_id' => Listing::factory()->create()->id,
            'verdict' => 'buy',
        ]);
        $stats = app(DealFeedStats::class);

        $this->assertSame(1, $stats->headline()['buy']);

        $this->actingAs(User::factory()->create())
            ->patch("/deals/{$deal->id}", ['user_status' => Deal::STATUS_DISMISSED]);

        $this->assertSame(0, $stats->headline()['buy']);
    }
}
