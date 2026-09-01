<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Сбор и пересчёт уехали в очередь, поэтому результат больше не возвращается
 * в ответ на запрос. Последнее состояние каждой фоновой задачи лежит в кеше
 * и отдаётся на дашборд.
 */
class PipelineRunStatus
{
    public const COLLECT = 'collect';

    public const ANALYTICS = 'analytics';

    public const AI_BATCH = 'ai_batch';

    public function queued(string $key): void
    {
        $this->put($key, [
            'state' => 'queued',
            'message' => 'Задача поставлена в очередь',
            'queued_at' => now()->toIso8601String(),
        ]);
    }

    public function started(string $key): void
    {
        $this->put($key, [
            'state' => 'running',
            'message' => 'Выполняется…',
            'started_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    public function finished(string $key, string $message, array $stats = []): void
    {
        $this->put($key, [
            'state' => 'done',
            'message' => $message,
            'stats' => $stats,
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    public function failed(string $key, string $message): void
    {
        $this->put($key, [
            'state' => 'failed',
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    /** Убрать отчёт о прогоне — например, разобранную ошибку. */
    public function forget(string $key): void
    {
        Cache::forget($this->cacheKey($key));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        return Cache::get($this->cacheKey($key));
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    public function all(): array
    {
        return [
            self::COLLECT => $this->get(self::COLLECT),
            self::ANALYTICS => $this->get(self::ANALYTICS),
            self::AI_BATCH => $this->get(self::AI_BATCH),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function put(string $key, array $payload): void
    {
        Cache::put($this->cacheKey($key), $payload + ['key' => $key], now()->addDay());
    }

    private function cacheKey(string $key): string
    {
        return 'dealwatch:run:'.$key;
    }
}
