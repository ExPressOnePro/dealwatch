<?php

namespace App\Jobs;

use App\Models\SearchProfile;
use App\Services\ListingPipeline;
use App\Services\PipelineRunStatus;
use App\Services\StatsCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Сбор ходит в сеть за сотней карточек, поэтому живёт в очереди,
 * а не в HTTP-запросе. Результат кладём в PipelineRunStatus — дашборд
 * показывает его при следующей загрузке.
 */
class CollectListings implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /** Секунды, после которых блокировка уникальности снимается сама. */
    public int $uniqueFor = 1800;

    public function __construct(
        public bool $notify = false,
        public ?int $profileId = null,
    ) {}

    public function handle(ListingPipeline $pipeline, PipelineRunStatus $status): void
    {
        $status->started(PipelineRunStatus::COLLECT);

        $profile = $this->profileId ? SearchProfile::find($this->profileId) : null;

        $stats = $profile
            ? $pipeline->collectProfile($profile, $this->notify) + ['empty_streak' => 0]
            : $pipeline->collectAll($this->notify);
        StatsCache::flush();

        $status->finished(
            PipelineRunStatus::COLLECT,
            sprintf(
                '%sСобрано %d объявлений, сделок %d, алертов %d',
                $profile ? 'Источник «'.$profile->name.'»: ' : '',
                $stats['fetched'],
                $stats['deals'],
                $stats['alerts']
            ),
            $stats
        );
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('Collect job failed: '.($e?->getMessage() ?? 'unknown'));

        app(PipelineRunStatus::class)->failed(
            PipelineRunStatus::COLLECT,
            'Сбор упал: '.($e?->getMessage() ?? 'неизвестная ошибка')
        );
    }
}
