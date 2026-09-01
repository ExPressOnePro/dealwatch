<?php

namespace Tests\Feature\Niche;

use App\Jobs\RunFullNicheAnalysis;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\SearchProfile;
use App\Models\User;
use App\Services\Niche\FullNicheRun;
use App\Services\PipelineRunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FullNicheRunTest extends TestCase
{
    use RefreshDatabase;

    private SearchProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profile = SearchProfile::factory()->generic()->create(['name' => 'JBL Xtreme', 'query' => 'jbl xtreme']);
        Cache::put('currency_rates_mdl', ['EUR' => 20.0, 'USD' => 18.0, 'MDL' => 1.0], now()->addHour());
        config()->set('dealwatch.collector.enrich', false);
    }

    /**
     * @param  list<array<string, mixed>>  $ads
     */
    private function fakeCatalog(array $ads): void
    {
        Http::fake(['999.md/graphql' => Http::response(['data' => ['searchAds' => ['count' => count($ads), 'ads' => $ads]]])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ad(string $id, int $price): array
    {
        return [
            'id' => $id,
            'title' => 'JBL Xtreme 3 колонка',
            'reseted' => now()->format('Y-m-d H:i:s'),
            'price' => ['value' => ['value' => $price, 'unit' => 'UNIT_MDL']],
            'city' => ['value' => ['translated' => 'Кишинёв']],
            'author' => ['value' => ['value' => 18895]],
            'offerType' => ['value' => ['value' => 776]],
            'owner' => ['id' => 'uuid-'.$id, 'login' => 'seller'.$id, 'business' => null],
        ];
    }

    public function test_one_run_collects_scans_scores_and_reports(): void
    {
        $this->fakeCatalog([
            $this->ad('1', 3800),
            $this->ad('2', 4000),
            $this->ad('3', 4200),
            $this->ad('4', 4500),
            $this->ad('5', 2000),
        ]);

        $result = app(FullNicheRun::class)->run($this->profile);

        $this->assertSame(5, $result['collect']['fetched']);
        $this->assertSame(5, $result['rescored']);
        // Пять разных владельцев — значит ключи продавцов не склеились.
        $this->assertSame(5, $result['sellers']['accounts']);
        $this->assertSame(5, $result['niche']['volume']['active']);
        $this->assertSame(4000, $result['niche']['prices']['median']);
        $this->assertNotNull($this->profile->fresh()->last_scan_at);

        // Самое дешёвое объявление получило оценку — рынок ниши уже посчитан.
        $cheap = Deal::query()->whereHas('listing', fn ($q) => $q->where('external_id', '5'))->sole();
        $this->assertNotNull($cheap->potential_profit);
        $this->assertContains($cheap->verdict, ['buy', 'check']);
    }

    public function test_button_queues_the_run(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post("/niches/{$this->profile->id}/full")
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(RunFullNicheAnalysis::class, fn ($job) => $job->profileId === $this->profile->id);
    }

    public function test_job_reports_progress_and_result(): void
    {
        $this->fakeCatalog([$this->ad('1', 3800), $this->ad('2', 4200)]);

        app(RunFullNicheAnalysis::class, ['profileId' => $this->profile->id])
            ->handle(app(FullNicheRun::class), $status = app(PipelineRunStatus::class));

        $run = $status->get(PipelineRunStatus::COLLECT);
        $this->assertSame('done', $run['state']);
        $this->assertStringContainsString('JBL Xtreme', $run['message']);
    }

    public function test_niche_page_lists_only_this_source(): void
    {
        $other = SearchProfile::factory()->create(['name' => 'Другой источник']);

        $mine = Deal::factory()->create([
            'listing_id' => Listing::factory()->create([
                'search_profile_id' => $this->profile->id,
                'title' => 'JBL Xtreme 3',
            ])->id,
            'potential_profit' => 1500,
            'verdict' => 'buy',
        ]);
        Deal::factory()->create([
            'listing_id' => Listing::factory()->create(['search_profile_id' => $other->id])->id,
            'verdict' => 'buy',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/niches?profile='.$this->profile->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('listings', 1)
                ->where('listings.0.id', $mine->id)
                ->where('listings.0.potential_profit', 1500)
                ->where('verdict_counts.all', 1)
                ->where('verdict_counts.buy', 1)
            );
    }

    public function test_niche_listings_sort_by_real_listing_price(): void
    {
        foreach ([5000, 1000, 3000] as $index => $price) {
            Deal::factory()->create([
                'listing_id' => Listing::factory()->create([
                    'search_profile_id' => $this->profile->id,
                    'external_id' => 'sorted-'.$index,
                    'price_mdl' => $price,
                ])->id,
                'deal_score' => 50,
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->get('/niches?profile='.$this->profile->id.'&sort=price')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('listings.0.price_mdl', 1000)
                ->where('listings.1.price_mdl', 3000)
                ->where('listings.2.price_mdl', 5000)
            );
    }

    public function test_niche_listings_can_be_filtered_by_verdict(): void
    {
        foreach (['buy', 'check', 'ignore'] as $verdict) {
            Deal::factory()->create([
                'listing_id' => Listing::factory()->create(['search_profile_id' => $this->profile->id])->id,
                'verdict' => $verdict,
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->get('/niches?profile='.$this->profile->id.'&verdict=check')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('listings', 1)
                ->where('listings.0.verdict', 'check')
                ->where('verdict_counts.all', 3)
            );
    }
}
