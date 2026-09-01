<?php

namespace Tests\Feature\Ai;

use App\Models\AiRequest;
use App\Services\Ai\AiBudgetExceededException;
use App\Services\Ai\AiException;
use App\Services\Ai\AiResult;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openai.key', 'test-key');
        config()->set('dealwatch.ai.enabled', true);
        config()->set('dealwatch.ai.retries', 2);
        config()->set('dealwatch.ai.cache_hours', 24);
        config()->set('dealwatch.ai.models.screen', [
            'name' => 'test-screen-model',
            'input_price' => 1.0,   // $1 за 1M входных
            'output_price' => 10.0, // $10 за 1M выходных
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fakeResponse(array $payload = ['verdict' => 'buy'], int $inputTokens = 1000, int $outputTokens = 200): array
    {
        return [
            'output' => [[
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]],
            ]],
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['verdict' => ['type' => 'string']],
            'required' => ['verdict'],
            'additionalProperties' => false,
        ];
    }

    private function ask(OpenAiClient $client, string $prompt = 'разбери объявление'): AiResult
    {
        return $client->structured(
            purpose: 'test',
            tier: 'screen',
            messages: [['role' => 'user', 'content' => $prompt]],
            schema: $this->schema(),
            schemaName: 'verdict',
            meta: ['listings' => 3],
        );
    }

    public function test_structured_call_returns_parsed_json_and_logs_cost(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->fakeResponse())]);

        $result = $this->ask(app(OpenAiClient::class));

        $this->assertSame(['verdict' => 'buy'], $result->data);
        $this->assertSame('test-screen-model', $result->model);
        $this->assertFalse($result->cached);
        // 1000/1M * $1 + 200/1M * $10 = 0.001 + 0.002
        $this->assertSame(0.003, $result->costUsd);

        $logged = AiRequest::sole();
        $this->assertSame(AiRequest::STATUS_OK, $logged->status);
        $this->assertSame('test', $logged->purpose);
        $this->assertSame(1000, $logged->input_tokens);
        $this->assertSame(['listings' => 3], $logged->meta);
    }

    public function test_request_uses_strict_json_schema(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->fakeResponse())]);

        $this->ask(app(OpenAiClient::class));

        Http::assertSent(function (Request $request) {
            return str_ends_with($request->url(), '/responses')
                && $request['model'] === 'test-screen-model'
                && $request['text']['format']['type'] === 'json_schema'
                && $request['text']['format']['strict'] === true
                && $request->hasHeader('Authorization', 'Bearer test-key');
        });
    }

    public function test_identical_input_is_served_from_cache(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->fakeResponse())]);
        $client = app(OpenAiClient::class);

        $this->ask($client);
        $second = $this->ask($client);

        Http::assertSentCount(1);
        $this->assertTrue($second->cached);
        $this->assertSame(['verdict' => 'buy'], $second->data);
        $this->assertSame(0.0, $second->costUsd);
        $this->assertSame(AiRequest::STATUS_CACHED, AiRequest::query()->latest('id')->first()->status);
    }

    public function test_daily_call_limit_blocks_before_spending(): void
    {
        config()->set('dealwatch.ai.limits.daily_calls', 1);
        Http::fake(['api.openai.com/*' => Http::response($this->fakeResponse())]);
        $client = app(OpenAiClient::class);

        $this->ask($client, 'первое объявление');

        $this->expectException(AiBudgetExceededException::class);

        try {
            $this->ask($client, 'второе объявление');
        } finally {
            Http::assertSentCount(1);
            $this->assertSame(AiRequest::STATUS_BLOCKED, AiRequest::query()->latest('id')->first()->status);
        }
    }

    public function test_daily_cost_limit_blocks_before_spending(): void
    {
        config()->set('dealwatch.ai.limits.daily_cost_usd', 0.001);
        Http::fake(['api.openai.com/*' => Http::response($this->fakeResponse())]);
        $client = app(OpenAiClient::class);

        $this->ask($client, 'первое объявление');

        $this->expectException(AiBudgetExceededException::class);
        $this->ask($client, 'второе объявление');
    }

    public function test_temporary_failure_is_retried(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push(['error' => 'rate limit'], 429)
                ->push($this->fakeResponse()),
        ]);

        $result = $this->ask(app(OpenAiClient::class));

        $this->assertSame(['verdict' => 'buy'], $result->data);
        Http::assertSentCount(2);
    }

    public function test_persistent_failure_is_logged_and_reported(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'boom'], 500)]);

        try {
            $this->ask(app(OpenAiClient::class));
            $this->fail('Ожидалось AiException');
        } catch (AiException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }

        $logged = AiRequest::sole();
        $this->assertSame(AiRequest::STATUS_FAILED, $logged->status);
        $this->assertStringContainsString('HTTP 500', $logged->error);
    }

    public function test_malformed_model_output_is_rejected(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'output' => [['content' => [['type' => 'output_text', 'text' => 'не json']]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        $this->expectException(AiException::class);

        try {
            $this->ask(app(OpenAiClient::class));
        } finally {
            $this->assertSame(AiRequest::STATUS_FAILED, AiRequest::sole()->status);
        }
    }

    public function test_without_key_nothing_is_sent(): void
    {
        config()->set('services.openai.key', null);
        Http::fake();

        $client = app(OpenAiClient::class);
        $this->assertFalse($client->configured());

        $this->expectException(AiUnavailableException::class);

        try {
            $this->ask($client);
        } finally {
            Http::assertNothingSent();
            $this->assertSame(0, AiRequest::query()->count());
        }
    }
}
