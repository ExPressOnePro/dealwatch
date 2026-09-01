<?php

namespace App\Services\Ai;

use App\Models\AiRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Тонкая обёртка над OpenAI Responses API со строгой JSON-схемой ответа.
 *
 * Всё, что делает эта обёртка сверх HTTP-запроса, нужно ради предсказуемости:
 * строгая схема (модель не может вернуть произвольный текст), кеш одинаковых
 * входов, дневные лимиты и журнал расходов в ai_requests.
 */
class OpenAiClient
{
    public function __construct(
        private readonly AiBudget $budget,
    ) {}

    public function configured(): bool
    {
        return (bool) config('dealwatch.ai.enabled') && filled(config('services.openai.key'));
    }

    /**
     * Список моделей, доступных этому ключу. Нужен админке, чтобы модель
     * выбирали из выпадающего списка, а не вбивали строкой наугад.
     *
     * @return list<string>
     */
    public function availableModels(bool $fresh = false): array
    {
        if (! $this->configured()) {
            return [];
        }

        $key = 'dealwatch:ai:models:'.substr(hash('sha256', (string) config('services.openai.key')), 0, 16);

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addHours(6), function () {
            try {
                $response = Http::withToken((string) config('services.openai.key'))
                    ->timeout(20)
                    ->get(config('dealwatch.ai.base_url').'/models');
            } catch (\Throwable $e) {
                Log::warning('OpenAI models list failed: '.$e->getMessage());

                return [];
            }

            if (! $response->successful()) {
                Log::warning('OpenAI models list failed', ['status' => $response->status()]);

                return [];
            }

            return collect($response->json('data') ?? [])
                ->pluck('id')
                ->filter(fn ($id) => is_string($id) && $id !== '')
                ->sort()
                ->values()
                ->all();
        });
    }

    /**
     * Проверка ключа для админки: дешёвый запрос без генерации токенов.
     *
     * @return array{ok: bool, message: string}
     */
    public function ping(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Ключ не задан или ИИ выключен в настройках.'];
        }

        try {
            $response = Http::withToken((string) config('services.openai.key'))
                ->timeout(20)
                ->get(config('dealwatch.ai.base_url').'/models');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Не удалось соединиться с OpenAI: '.$e->getMessage()];
        }

        if ($response->status() === 401) {
            return ['ok' => false, 'message' => 'OpenAI отклонил ключ (401). Проверь значение.'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'OpenAI ответил HTTP '.$response->status().'.'];
        }

        $count = count($response->json('data') ?? []);

        return ['ok' => true, 'message' => "Ключ работает, моделей доступно: {$count}."];
    }

    /**
     * @param  string  $purpose  Метка назначения для журнала: batch_screen, parse_query, …
     * @param  string  $tier  screen | deep
     * @param  list<array{role: string, content: string|list<array<string, mixed>>}>  $messages
     *                                                                                           content-массив — это части сообщения (input_text / input_image) для vision-разбора
     * @param  array<string, mixed>  $schema  JSON Schema ожидаемого ответа (strict)
     * @param  array<string, mixed>  $meta  Что положить в журнал рядом с вызовом
     *
     * @throws AiException
     */
    public function structured(
        string $purpose,
        string $tier,
        array $messages,
        array $schema,
        string $schemaName = 'result',
        array $meta = [],
    ): AiResult {
        if (! $this->configured()) {
            throw new AiUnavailableException('ИИ выключен или не задан OPENAI_API_KEY.');
        }

        $model = $this->model($tier);
        $payload = [
            'model' => $model['name'],
            'input' => $messages,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaName,
                    'schema' => $schema,
                    'strict' => true,
                ],
            ],
        ];

        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if ($cached = $this->fromCache($hash)) {
            $this->log($purpose, $tier, $model['name'], AiRequest::STATUS_CACHED, $hash, 0, 0, 0.0, 0, null, $meta);

            return new AiResult(
                data: $cached,
                model: $model['name'],
                tier: $tier,
                cached: true,
            );
        }

        try {
            $this->budget->assertWithinLimits();
        } catch (AiBudgetExceededException $e) {
            $this->log($purpose, $tier, $model['name'], AiRequest::STATUS_BLOCKED, $hash, 0, 0, 0.0, 0, $e->getMessage(), $meta);

            throw $e;
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withToken((string) config('services.openai.key'))
                ->timeout((int) config('dealwatch.ai.timeout'))
                ->retry(
                    max(1, (int) config('dealwatch.ai.retries') + 1),
                    500,
                    fn ($exception, $request) => $this->shouldRetry($exception),
                    throw: false
                )
                ->post(config('dealwatch.ai.base_url').'/responses', $payload);
        } catch (\Throwable $e) {
            $this->fail($purpose, $tier, $model['name'], $hash, $startedAt, $e->getMessage(), $meta);
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $response->successful()) {
            $this->fail(
                $purpose,
                $tier,
                $model['name'],
                $hash,
                $startedAt,
                'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500),
                $meta
            );
        }

        $body = $response->json() ?? [];
        $text = $this->extractText($body);

        if ($text === null) {
            $this->fail($purpose, $tier, $model['name'], $hash, $startedAt, 'Ответ без текстового содержимого', $meta);
        }

        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->fail($purpose, $tier, $model['name'], $hash, $startedAt, 'Невалидный JSON: '.$e->getMessage(), $meta);
        }

        if (! is_array($data)) {
            $this->fail($purpose, $tier, $model['name'], $hash, $startedAt, 'Ожидался JSON-объект', $meta);
        }

        $inputTokens = (int) data_get($body, 'usage.input_tokens', 0);
        $outputTokens = (int) data_get($body, 'usage.output_tokens', 0);
        $cost = $this->cost($model, $inputTokens, $outputTokens);

        $this->remember($hash, $data);
        $this->log($purpose, $tier, $model['name'], AiRequest::STATUS_OK, $hash, $inputTokens, $outputTokens, $cost, $durationMs, null, $meta);

        return new AiResult(
            data: $data,
            model: $model['name'],
            tier: $tier,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            costUsd: $cost,
        );
    }

    /**
     * @return array{name: string, input_price: float, output_price: float}
     */
    private function model(string $tier): array
    {
        $model = config('dealwatch.ai.models.'.$tier);

        if (! is_array($model) || blank($model['name'] ?? null)) {
            throw new AiUnavailableException("Не настроена модель уровня «{$tier}» (config dealwatch.ai.models).");
        }

        return [
            'name' => (string) $model['name'],
            'input_price' => (float) ($model['input_price'] ?? 0),
            'output_price' => (float) ($model['output_price'] ?? 0),
        ];
    }

    private function shouldRetry(?\Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        $status = $exception instanceof RequestException
            ? $exception->response->status()
            : null;

        // 429 и 5xx — временные, остальное повторять бессмысленно.
        return $status === 429 || ($status !== null && $status >= 500);
    }

    /**
     * Responses API складывает текст в output[].content[] с типом output_text.
     */
    private function extractText(array $body): ?string
    {
        if (is_string($body['output_text'] ?? null) && $body['output_text'] !== '') {
            return $body['output_text'];
        }

        foreach ($body['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $chunk) {
                if (($chunk['type'] ?? null) === 'output_text' && is_string($chunk['text'] ?? null)) {
                    return $chunk['text'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array{name: string, input_price: float, output_price: float}  $model
     */
    private function cost(array $model, int $inputTokens, int $outputTokens): float
    {
        return round(
            $inputTokens / 1_000_000 * $model['input_price']
            + $outputTokens / 1_000_000 * $model['output_price'],
            6
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fromCache(string $hash): ?array
    {
        $hours = (int) config('dealwatch.ai.cache_hours');
        if ($hours <= 0) {
            return null;
        }

        $cached = Cache::get($this->cacheKey($hash));

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function remember(string $hash, array $data): void
    {
        $hours = (int) config('dealwatch.ai.cache_hours');
        if ($hours <= 0) {
            return;
        }

        Cache::put($this->cacheKey($hash), $data, now()->addHours($hours));
    }

    private function cacheKey(string $hash): string
    {
        return 'dealwatch:ai:'.$hash;
    }

    /**
     * @param  array<string, mixed>  $meta
     *
     * @throws AiException
     */
    private function fail(string $purpose, string $tier, string $model, string $hash, float $startedAt, string $error, array $meta): never
    {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        Log::warning('OpenAI request failed', ['purpose' => $purpose, 'model' => $model, 'error' => $error]);
        $this->log($purpose, $tier, $model, AiRequest::STATUS_FAILED, $hash, 0, 0, 0.0, $durationMs, $error, $meta);

        throw new AiException($error);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function log(
        string $purpose,
        string $tier,
        string $model,
        string $status,
        string $hash,
        int $inputTokens,
        int $outputTokens,
        float $cost,
        int $durationMs,
        ?string $error,
        array $meta,
    ): void {
        AiRequest::create([
            'purpose' => $purpose,
            'tier' => $tier,
            'model' => $model,
            'status' => $status,
            'input_hash' => $hash,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => $cost,
            'duration_ms' => $durationMs,
            'error' => $error,
            'meta' => $meta ?: null,
        ]);
    }
}
