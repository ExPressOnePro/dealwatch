<?php

namespace Tests\Feature\Ai;

use App\Jobs\AnalyzeListingDeep;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\ListingAiReport;
use App\Models\ListingSnapshot;
use App\Models\User;
use App\Services\Ai\ListingDeepAnalyst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ListingDeepAnalystTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config()->set('services.openai.key', 'test-key');
        config()->set('dealwatch.ai.models.deep', ['name' => 'deep-model', 'input_price' => 1.0, 'output_price' => 1.0]);
        config()->set('dealwatch.ai.models.vision', ['name' => 'vision-model', 'input_price' => 2.0, 'output_price' => 2.0]);
        config()->set('dealwatch.ai.vision.enabled', true);
        config()->set('dealwatch.ai.vision.max_images', 2);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function aiResponse(array $overrides = []): array
    {
        $payload = array_merge([
            'summary' => 'Телефон рабочий, но есть скол на углу.',
            'verdict' => 'check',
            'condition_score' => 72,
            'target_price_mdl' => 7600,
            'confidence' => 'medium',
            'defects' => [[
                'source' => 'photo',
                'label' => 'Скол на левом нижнем углу',
                'severity' => 'medium',
                'evidence' => 'Второе фото, угол корпуса',
                'price_impact_mdl' => 400,
            ]],
            'mismatches' => ['В тексте «идеальное состояние», на фото скол'],
            'questions' => ['Экран менялся?'],
            'checks_on_meeting' => ['Проверить Face ID'],
            'photo_notes' => [],
        ], $overrides);

        return [
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]]],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ];
    }

    private function dealWithListing(array $listingAttributes = []): Deal
    {
        $listing = Listing::factory()->create($listingAttributes);

        return Deal::factory()->create(['listing_id' => $listing->id]);
    }

    public function test_text_report_sends_the_full_description_and_lands_in_the_card(): void
    {
        $longText = 'Продаю iPhone 13. '.str_repeat('Подробное описание состояния. ', 60).'Экран менялся в сервисе.';
        $deal = $this->dealWithListing(['description' => $longText]);

        Http::fake(['api.openai.com/*' => Http::response($this->aiResponse(['defects' => []]))]);

        $this->actingAs($this->user)
            ->post("/deals/{$deal->id}/ai-report")
            ->assertRedirect()
            ->assertSessionHas('success');

        $report = ListingAiReport::sole();
        app(AnalyzeListingDeep::class, ['reportId' => $report->id])->handle(app(ListingDeepAnalyst::class));

        $report->refresh();
        $this->assertSame(ListingAiReport::STATUS_DONE, $report->status);
        $this->assertSame('deep-model', $report->model);
        $this->assertSame('check', $report->verdict);
        $this->assertSame(72, $report->condition_score);
        $this->assertSame(7600, $report->target_price_mdl);
        $this->assertSame(['Экран менялся?'], $report->payload['questions']);

        // в модель ушёл весь текст, а не первые 400 символов
        Http::assertSent(function (Request $request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($body, 'Экран менялся в сервисе')
                && ! str_contains($body, 'input_image');
        });

        $this->actingAs($this->user)
            ->get('/deals')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deals.0.ai_reports.text.summary', 'Телефон рабочий, но есть скол на углу.')
                ->where('deals.0.ai_reports.text.status', ListingAiReport::STATUS_DONE)
                ->where('deals.0.ai_reports.vision', null)
            );
    }

    public function test_photo_report_sends_images_and_uses_the_vision_model(): void
    {
        $deal = $this->dealWithListing([
            'images' => [
                'https://i.999.md/1.jpg',
                'https://i.999.md/2.jpg',
                'https://i.999.md/3.jpg',
            ],
        ]);

        Http::fake(['api.openai.com/*' => Http::response($this->aiResponse())]);

        $report = ListingAiReport::create([
            'listing_id' => $deal->listing_id,
            'deal_id' => $deal->id,
            'kind' => ListingAiReport::KIND_VISION,
            'status' => ListingAiReport::STATUS_RUNNING,
        ]);

        app(ListingDeepAnalyst::class)->run($report->load('listing'));

        $report->refresh();
        $this->assertSame(ListingAiReport::STATUS_DONE, $report->status);
        $this->assertSame('vision-model', $report->model);
        $this->assertSame(2, $report->images_analyzed);
        $this->assertSame('photo', $report->payload['defects'][0]['source']);
        $this->assertSame(['В тексте «идеальное состояние», на фото скол'], $report->payload['mismatches']);

        Http::assertSent(function (Request $request) {
            $content = $request['input'][1]['content'] ?? [];
            $images = array_filter($content, fn ($part) => ($part['type'] ?? null) === 'input_image');

            // лимит из настроек соблюдён: 2 фото из трёх
            return $request['model'] === 'vision-model' && count($images) === 2;
        });
    }

    public function test_archived_photos_are_used_instead_of_live_urls(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('archive/999/1/1/image-1.jpg', 'binary-content');

        $deal = $this->dealWithListing(['images' => ['https://i.999.md/live.jpg']]);
        ListingSnapshot::create([
            'listing_id' => $deal->listing_id,
            'reason' => ListingSnapshot::REASON_ARCHIVED,
            'payload' => [],
            'image_paths' => ['archive/999/1/1/image-1.jpg'],
        ]);

        Http::fake(['api.openai.com/*' => Http::response($this->aiResponse())]);

        $report = ListingAiReport::create([
            'listing_id' => $deal->listing_id,
            'kind' => ListingAiReport::KIND_VISION,
            'status' => ListingAiReport::STATUS_RUNNING,
        ]);

        app(ListingDeepAnalyst::class)->run($report->load('listing'));

        Http::assertSent(function (Request $request) {
            $content = $request['input'][1]['content'] ?? [];

            foreach ($content as $part) {
                if (($part['type'] ?? null) === 'input_image') {
                    return str_starts_with($part['image_url'], 'data:image/jpeg;base64,');
                }
            }

            return false;
        });
    }

    public function test_vision_is_refused_when_switched_off(): void
    {
        config()->set('dealwatch.ai.vision.enabled', false);
        Queue::fake();
        $deal = $this->dealWithListing();

        $this->actingAs($this->user)
            ->post("/deals/{$deal->id}/ai-report", ['with_photos' => true])
            ->assertSessionHas('error');

        $this->assertSame(0, ListingAiReport::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_listing_without_photos_reports_a_clear_reason(): void
    {
        Http::fake();
        $deal = $this->dealWithListing(['images' => null]);

        $report = ListingAiReport::create([
            'listing_id' => $deal->listing_id,
            'kind' => ListingAiReport::KIND_VISION,
            'status' => ListingAiReport::STATUS_RUNNING,
        ]);

        app(ListingDeepAnalyst::class)->run($report->load('listing'));

        $report->refresh();
        $this->assertSame(ListingAiReport::STATUS_FAILED, $report->status);
        $this->assertStringContainsString('нет фотографий', $report->error);
        Http::assertNothingSent();
    }

    public function test_ai_failure_is_recorded_in_the_report(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'boom'], 500)]);
        $deal = $this->dealWithListing();

        $report = ListingAiReport::create([
            'listing_id' => $deal->listing_id,
            'kind' => ListingAiReport::KIND_TEXT,
            'status' => ListingAiReport::STATUS_RUNNING,
        ]);

        app(ListingDeepAnalyst::class)->run($report->load('listing'));

        $report->refresh();
        $this->assertSame(ListingAiReport::STATUS_FAILED, $report->status);
        $this->assertStringContainsString('HTTP 500', $report->error);
    }

    public function test_request_is_refused_without_api_key(): void
    {
        config()->set('services.openai.key', null);
        Queue::fake();
        $deal = $this->dealWithListing();

        $this->actingAs($this->user)
            ->post("/deals/{$deal->id}/ai-report")
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_feed_reports_vision_availability(): void
    {
        $this->actingAs($this->user)
            ->get('/deals')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('ai.vision', true));

        config()->set('dealwatch.ai.vision.enabled', false);

        $this->actingAs($this->user)
            ->get('/deals')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('ai.vision', false));
    }

    public function test_guests_cannot_start_a_report(): void
    {
        $deal = $this->dealWithListing();

        $this->post("/deals/{$deal->id}/ai-report")->assertRedirect('/login');
    }
}
