<?php

namespace Tests\Feature\Ai;

use App\Models\Deal;
use App\Models\Listing;
use App\Models\MarketPrice;
use App\Services\Ai\ListingBatchAnalyst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ListingBatchAnalystTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openai.key', 'test-key');
        config()->set('dealwatch.ai.models.screen', ['name' => 'screen-model', 'input_price' => 1.0, 'output_price' => 1.0]);
        config()->set('dealwatch.ai.models.deep', ['name' => 'deep-model', 'input_price' => 10.0, 'output_price' => 10.0]);
        MarketPrice::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function response(array $payload, int $inputTokens = 1000, int $outputTokens = 1000): array
    {
        return [
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]]],
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ];
    }

    /**
     * @return Collection<int, Deal>
     */
    private function deals(int $count = 3): Collection
    {
        return collect(range(1, $count))->map(fn (int $i) => Deal::factory()->create([
            'listing_id' => Listing::factory()->create([
                'title' => "iPhone 13 128GB #{$i}",
                'price_mdl' => 8000 + $i * 100,
                'seller_phone' => '+37369000'.$i.'11',
                'seller_name' => 'seller_'.$i,
            ])->id,
            'potential_profit' => 2000 - $i * 100,
        ])->load('listing'));
    }

    public function test_screening_ranks_the_batch_and_deep_dive_covers_finalists(): void
    {
        $deals = $this->deals(3);
        [$first, $second, $third] = [$deals[0]->id, $deals[1]->id, $deals[2]->id];

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->response([
                'summary' => 'Две интересные позиции из трёх.',
                'items' => [
                    ['listing_id' => $first, 'verdict' => 'take', 'rank' => 90, 'risk' => 'low', 'reason' => 'Дёшево и свежо'],
                    ['listing_id' => $second, 'verdict' => 'check', 'rank' => 60, 'risk' => 'medium', 'reason' => 'Мало данных'],
                    ['listing_id' => $third, 'verdict' => 'skip', 'rank' => 10, 'risk' => 'high', 'reason' => 'Дорого'],
                ],
            ]))
            ->push($this->response([
                'recommendation' => 'Звони по первому, второй — после уточнений.',
                'items' => [
                    [
                        'listing_id' => $first,
                        'call_priority' => 1,
                        'target_price_mdl' => 7600,
                        'reasoning' => 'Запас маржи выше порога.',
                        'questions' => ['Аккумулятор сколько %?'],
                        'red_flags' => ['Проверить iCloud'],
                    ],
                ],
            ])),
        ]);

        $result = app(ListingBatchAnalyst::class)->analyze($deals, 'iPhone 13 до 9000');

        $this->assertSame('Две интересные позиции из трёх.', $result['summary']);
        $this->assertSame('Звони по первому, второй — после уточнений.', $result['recommendation']);
        $this->assertSame(3, $result['listing_count']);
        $this->assertSame('screen-model', $result['model_screen']);
        $this->assertSame('deep-model', $result['model_deep']);
        // 0.002 за скрининг + 0.02 за глубокий разбор
        $this->assertSame(0.022, $result['cost_usd']);

        $items = collect($result['items']);
        $this->assertSame([$first, $second, $third], $items->pluck('deal_id')->all());
        $this->assertSame(1, $items->firstWhere('deal_id', $first)['call_priority']);
        $this->assertSame(7600, $items->firstWhere('deal_id', $first)['target_price_mdl']);
        $this->assertNull($items->firstWhere('deal_id', $third)['call_priority']);
        Http::assertSentCount(2);
    }

    public function test_seller_contacts_never_leave_the_app(): void
    {
        $deals = $this->deals(1);

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->response(['summary' => 'ok', 'items' => [
                ['listing_id' => $deals[0]->id, 'verdict' => 'skip', 'rank' => 5, 'risk' => 'low', 'reason' => 'мимо'],
            ]])),
        ]);

        app(ListingBatchAnalyst::class)->analyze($deals);

        Http::assertSent(function (Request $request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return ! str_contains($body, '+37369000')
                && ! str_contains($body, 'seller_1');
        });
    }

    public function test_batch_is_capped_by_config(): void
    {
        config()->set('dealwatch.ai.batch_size', 2);
        $deals = $this->deals(4);

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->response(['summary' => 'ok', 'items' => []])),
        ]);

        $result = app(ListingBatchAnalyst::class)->analyze($deals);

        $this->assertSame(2, $result['listing_count']);
        Http::assertSentCount(1);
    }

    public function test_empty_selection_costs_nothing(): void
    {
        Http::fake();

        $result = app(ListingBatchAnalyst::class)->analyze(collect());

        $this->assertSame(0, $result['listing_count']);
        $this->assertSame(0.0, $result['cost_usd']);
        Http::assertNothingSent();
    }

    public function test_no_finalists_means_no_expensive_call(): void
    {
        $deals = $this->deals(2);

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->response(['summary' => 'Всё мимо.', 'items' => [
                ['listing_id' => $deals[0]->id, 'verdict' => 'skip', 'rank' => 20, 'risk' => 'high', 'reason' => 'дорого'],
                ['listing_id' => $deals[1]->id, 'verdict' => 'skip', 'rank' => 10, 'risk' => 'high', 'reason' => 'кликбейт'],
            ]])),
        ]);

        $result = app(ListingBatchAnalyst::class)->analyze($deals);

        $this->assertNull($result['model_deep']);
        $this->assertNull($result['recommendation']);
        Http::assertSentCount(1);
    }
}
