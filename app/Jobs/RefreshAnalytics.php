<?php

namespace App\Jobs;

use App\Services\PipelineRunStatus;
use App\Services\StatsCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Переразбор моделей + пересчёт рынка и сделок по всей базе.
 * Минуты работы, поэтому только фоном.
 */
class RefreshAnalytics implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(PipelineRunStatus $status): void
    {
        $status->started(PipelineRunStatus::ANALYTICS);

        Artisan::call('listings:reparse-models', [
            '--rebuild-market' => 1,
            '--recalculate' => 1,
        ]);

        $output = trim(Artisan::output());
        StatsCache::flush();

        $status->finished(
            PipelineRunStatus::ANALYTICS,
            'Модели переразобраны (title-first), рынок и сделки пересчитаны.',
            ['output' => mb_substr($output, 0, 2000)]
        );
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('Analytics refresh job failed: '.($e?->getMessage() ?? 'unknown'));

        app(PipelineRunStatus::class)->failed(
            PipelineRunStatus::ANALYTICS,
            'Пересчёт упал: '.($e?->getMessage() ?? 'неизвестная ошибка')
        );
    }
}
