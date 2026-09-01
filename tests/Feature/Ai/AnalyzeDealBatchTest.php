<?php

namespace Tests\Feature\Ai;

use App\Jobs\AnalyzeDealBatch;
use App\Models\AiBatchAnalysis;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\MarketPrice;
use App\Models\SearchProfile;
use App\Models\User;
use App\Services\Ai\ListingBatchAnalyst;
use App\Services\Ai\QueryInterpreter;
use App\Services\DealFeedQuery;
use App\Services\PipelineRunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AnalyzeDealBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config()->set('services.openai.key', 'test-key');
        MarketPrice::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function response(array $payload): array
    {
        return [
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]]],
            'usage' => ['input_tokens' => 500, 'output_tokens' => 200],
        ];
    }

    public function test_request_queues_an_analysis(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->post('/deals/analyze', ['query' => 'iPhone 13 до 8500', 'segment' => 'targets'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $analysis = AiBatchAnalysis::sole();
        $this->assertSame(AiBatchAnalysis::SOURCE_QUERY, $analysis->source);
        $this->assertSame('iPhone 13 до 8500', $analysis->query);
        $this->assertSame($this->user->id, $analysis->user_id);
        $this->assertSame('targets', $analysis->filters['segment']);

        Queue::assertPushed(AnalyzeDealBatch::class);
    }

    public function test_analysis_without_query_is_marked_as_filter_source(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->post('/deals/analyze', ['segment' => 'shops']);

        $this->assertSame(AiBatchAnalysis::SOURCE_FILTER, AiBatchAnalysis::sole()->source);
    }

    public function test_guests_cannot_start_an_analysis(): void
    {
        Queue::fake();

        $this->post('/deals/analyze')->assertRedirect('/login');

        Queue::assertNothingPushed();
        $this->assertSame(0, AiBatchAnalysis::query()->count());
    }

    public function test_job_stores_the_result_and_reports_progress(): void
    {
        $deal = Deal::factory()->create(['listing_id' => Listing::factory()->create()->id]);

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->response(['summary' => 'Одна интересная позиция.', 'items' => [
                ['listing_id' => $deal->id, 'verdict' => 'take', 'rank' => 88, 'risk' => 'low', 'reason' => 'Хороший запас'],
            ]]))
            ->push($this->response(['recommendation' => 'Звонить сегодня.', 'items' => [
                [
                    'listing_id' => $deal->id,
                    'call_priority' => 1,
                    'target_price_mdl' => 7800,
                    'reasoning' => 'Маржа выше порога',
                    'questions' => ['Аккумулятор?'],
                    'red_flags' => [],
                ],
            ]])),
        ]);

        $analysis = AiBatchAnalysis::create([
            'user_id' => $this->user->id,
            'source' => AiBatchAnalysis::SOURCE_FILTER,
            'filters' => ['segment' => 'targets', 'status' => 'active'],
            'status' => AiBatchAnalysis::STATUS_RUNNING,
        ]);

        app(AnalyzeDealBatch::class, ['analysisId' => $analysis->id])->handle(
            app(DealFeedQuery::class),
            app(QueryInterpreter::class),
            app(ListingBatchAnalyst::class),
            $status = app(PipelineRunStatus::class),
        );

        $analysis->refresh();
        $this->assertSame(AiBatchAnalysis::STATUS_DONE, $analysis->status);
        $this->assertSame('Одна интересная позиция.', $analysis->summary);
        $this->assertSame('Звонить сегодня.', $analysis->recommendation);
        $this->assertSame(1, $analysis->listing_count);
        $this->assertSame(7800, $analysis->items[0]['target_price_mdl']);
        $this->assertGreaterThan(0, $analysis->cost_usd);

        $this->assertSame('done', $status->get(PipelineRunStatus::AI_BATCH)['state']);
    }

    public function test_free_text_query_narrows_the_selection(): void
    {
        $wanted = Deal::factory()->create([
            'listing_id' => Listing::factory()->create(['brand' => 'Apple', 'model' => 'iPhone 13', 'price_mdl' => 8000])->id,
        ]);
        Deal::factory()->create([
            'listing_id' => Listing::factory()->create(['brand' => 'Samsung', 'model' => 'S23 Ultra', 'price_mdl' => 8000])->id,
        ]);
        Deal::factory()->create([
            'listing_id' => Listing::factory()->create(['brand' => 'Apple', 'model' => 'iPhone 13', 'price_mdl' => 11000])->id,
        ]);

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->response(['summary' => 'ok', 'items' => []])),
        ]);

        $analysis = AiBatchAnalysis::create([
            'user_id' => $this->user->id,
            'source' => AiBatchAnalysis::SOURCE_QUERY,
            'query' => 'iPhone 13 до 9000',
            'filters' => ['segment' => 'targets', 'status' => 'active'],
            'status' => AiBatchAnalysis::STATUS_RUNNING,
        ]);

        app(AnalyzeDealBatch::class, ['analysisId' => $analysis->id])->handle(
            app(DealFeedQuery::class),
            app(QueryInterpreter::class),
            app(ListingBatchAnalyst::class),
            app(PipelineRunStatus::class),
        );

        $this->assertSame(1, $analysis->fresh()->listing_count);

        Http::assertSent(function (Request $request) use ($wanted) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            // в пачку ушло только подходящее объявление: нужная модель есть, чужая — нет
            return str_contains($body, (string) $wanted->id)
                && str_contains($body, 'iPhone 13')
                && ! str_contains($body, 'S23 Ultra');
        });
    }

    public function test_failed_ai_call_marks_the_analysis_failed(): void
    {
        Deal::factory()->create(['listing_id' => Listing::factory()->create()->id]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'boom'], 500)]);

        $analysis = AiBatchAnalysis::create([
            'user_id' => $this->user->id,
            'source' => AiBatchAnalysis::SOURCE_FILTER,
            'filters' => ['segment' => 'targets', 'status' => 'active'],
            'status' => AiBatchAnalysis::STATUS_RUNNING,
        ]);

        app(AnalyzeDealBatch::class, ['analysisId' => $analysis->id])->handle(
            app(DealFeedQuery::class),
            app(QueryInterpreter::class),
            app(ListingBatchAnalyst::class),
            $status = app(PipelineRunStatus::class),
        );

        $analysis->refresh();
        $this->assertSame(AiBatchAnalysis::STATUS_FAILED, $analysis->status);
        $this->assertStringContainsString('HTTP 500', $analysis->error);
        $this->assertSame('failed', $status->get(PipelineRunStatus::AI_BATCH)['state']);
    }

    public function test_analysis_of_another_source_is_not_shown(): void
    {
        $phones = SearchProfile::factory()->create(['name' => 'Телефоны']);
        $bikes = SearchProfile::factory()->generic()->create(['name' => 'Велосипеды']);

        AiBatchAnalysis::create([
            'user_id' => $this->user->id,
            'source' => AiBatchAnalysis::SOURCE_FILTER,
            'filters' => ['profile' => $phones->id, 'segment' => 'targets'],
            'status' => AiBatchAnalysis::STATUS_DONE,
            'summary' => 'Разбор телефонов',
            'items' => [],
        ]);

        // На странице другого источника чужой разбор показывать нельзя.
        $this->actingAs($this->user)
            ->get('/deals?profile='.$bikes->id)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('analysis', null));

        $this->actingAs($this->user)
            ->get('/deals?profile='.$phones->id)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('analysis.summary', 'Разбор телефонов'));
    }

    public function test_analysis_remembers_the_source(): void
    {
        Queue::fake();
        $profile = SearchProfile::factory()->create();

        $this->actingAs($this->user)->post('/deals/analyze', [
            'profile' => $profile->id,
            'segment' => 'targets',
        ]);

        $this->assertSame($profile->id, AiBatchAnalysis::sole()->filters['profile']);
    }

    public function test_run_notice_can_be_dismissed(): void
    {
        $status = app(PipelineRunStatus::class);
        $status->failed(PipelineRunStatus::AI_BATCH, 'ИИ выключен или не задан OPENAI_API_KEY.');

        $this->assertNotNull($status->get(PipelineRunStatus::AI_BATCH));

        $this->actingAs($this->user)
            ->delete('/deals/runs', ['key' => PipelineRunStatus::AI_BATCH])
            ->assertRedirect();

        $this->assertNull($status->get(PipelineRunStatus::AI_BATCH));
    }

    public function test_unknown_run_key_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->delete('/deals/runs', ['key' => 'whatever'])
            ->assertSessionHas('error');
    }

    public function test_feed_reports_whether_ai_is_configured(): void
    {
        $this->actingAs($this->user)
            ->get('/deals')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('ai.configured', true));

        config()->set('services.openai.key', null);

        $this->actingAs($this->user)
            ->get('/deals')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('ai.configured', false));
    }

    public function test_last_analysis_is_shown_on_the_feed(): void
    {
        AiBatchAnalysis::create([
            'user_id' => $this->user->id,
            'source' => AiBatchAnalysis::SOURCE_QUERY,
            'query' => 'iPhone 13',
            'status' => AiBatchAnalysis::STATUS_DONE,
            'summary' => 'Две позиции стоят звонка.',
            'listing_count' => 5,
            'items' => [['deal_id' => 1, 'ai_verdict' => 'take', 'rank' => 90]],
            'cost_usd' => 0.01,
        ]);

        $this->actingAs($this->user)
            ->get('/deals')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('analysis.summary', 'Две позиции стоят звонка.')
                ->where('analysis.status', AiBatchAnalysis::STATUS_DONE)
                ->has('analysis.items', 1)
            );
    }
}
