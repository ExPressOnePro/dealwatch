<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\QueryInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QueryInterpreterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openai.key', 'test-key');
        Cache::put('currency_rates_mdl', ['EUR' => 20.0, 'USD' => 18.0, 'MDL' => 1.0], now()->addHour());
    }

    public function test_known_model_is_parsed_without_calling_the_model(): void
    {
        Http::fake();

        $intent = app(QueryInterpreter::class)->interpret('iPhone 13 128 гб до 8000');

        $this->assertSame('Apple', $intent['brand']);
        $this->assertSame('iPhone 13', $intent['model']);
        $this->assertSame(128, $intent['storage_gb']);
        $this->assertSame(8000, $intent['max_price_mdl']);
        $this->assertSame('local', $intent['source']);
        Http::assertNothingSent();
    }

    public function test_budget_in_euro_is_converted_to_mdl(): void
    {
        Http::fake();

        $intent = app(QueryInterpreter::class)->interpret('Samsung S23 Ultra не дороже 400 евро');

        $this->assertSame(8000, $intent['max_price_mdl']);
    }

    public function test_unrecognised_query_falls_back_to_the_model(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode([
                'brand' => 'Google',
                'model' => 'Pixel 8 Pro',
                'storage_gb' => 256,
                'max_price_mdl' => 9000,
                'must_have' => ['гарантия'],
                'avoid' => ['замена экрана'],
            ])]]]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ])]);

        $intent = app(QueryInterpreter::class)->interpret('что-нибудь пиксельное потоньше, бюджет девять тысяч');

        $this->assertSame('Google', $intent['brand']);
        $this->assertSame('Pixel 8 Pro', $intent['model']);
        $this->assertSame(9000, $intent['max_price_mdl']);
        $this->assertSame(['замена экрана'], $intent['avoid']);
        $this->assertSame('ai', $intent['source']);
    }

    public function test_ai_failure_degrades_to_local_result(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'boom'], 500)]);

        $intent = app(QueryInterpreter::class)->interpret('что-то непонятное до 5000');

        $this->assertNull($intent['model']);
        $this->assertSame(5000, $intent['max_price_mdl']);
        $this->assertSame('local', $intent['source']);
    }

    public function test_without_api_key_only_local_parsing_is_used(): void
    {
        config()->set('services.openai.key', null);
        Http::fake();

        $intent = app(QueryInterpreter::class)->interpret('непонятный запрос');

        $this->assertSame('local', $intent['source']);
        Http::assertNothingSent();
    }
}
