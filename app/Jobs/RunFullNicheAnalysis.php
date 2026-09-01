<?php

namespace App\Jobs;

use App\Models\SearchProfile;
use App\Services\Niche\FullNicheRun;
use App\Services\PipelineRunStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Полный проход по источнику: минуты работы и десятки запросов — только фоном. */
class RunFullNicheAnalysis implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $profileId,
    ) {}

    public function uniqueId(): string
    {
        return 'niche-full-'.$this->profileId;
    }

    public function handle(FullNicheRun $run, PipelineRunStatus $status): void
    {
        $profile = SearchProfile::find($this->profileId);

        if (! $profile) {
            return;
        }

        $status->started(PipelineRunStatus::COLLECT);

        $result = $run->run($profile, null, function (string $step) use ($status, $profile) {
            $status->started(PipelineRunStatus::COLLECT);
            $status->finished(PipelineRunStatus::COLLECT, "«{$profile->name}»: {$step}…");
        });

        $niche = $result['niche'];

        $status->finished(
            PipelineRunStatus::COLLECT,
            sprintf(
                'Источник «%s» разобран: активных %d, новых %d, ушло %d. %s',
                $profile->name,
                $niche['volume']['active'],
                $result['scan']['fresh'],
                $result['scan']['gone'],
                $niche['verdict']['label']
            ),
            [
                'profile_id' => $profile->id,
                'fetched' => $result['collect']['fetched'],
                'scanned' => $result['scan']['seen'],
                'rescored' => $result['rescored'],
            ]
        );
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('Full niche analysis failed: '.($e?->getMessage() ?? 'unknown'));

        app(PipelineRunStatus::class)->failed(
            PipelineRunStatus::COLLECT,
            'Полный анализ источника не удался: '.mb_substr($e?->getMessage() ?? 'неизвестная ошибка', 0, 200)
        );
    }
}
