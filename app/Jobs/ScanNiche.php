<?php

namespace App\Jobs;

use App\Models\SearchProfile;
use App\Services\Niche\NicheScanner;
use App\Services\PipelineRunStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Перепись каталога — десятки запросов к площадке, поэтому только фоном. */
class ScanNiche implements ShouldBeUnique, ShouldQueue
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
        return 'niche-scan-'.$this->profileId;
    }

    public function handle(NicheScanner $scanner, PipelineRunStatus $status): void
    {
        $profile = SearchProfile::find($this->profileId);

        if (! $profile) {
            return;
        }

        $status->started(PipelineRunStatus::COLLECT);
        $stats = $scanner->scan($profile);

        $status->finished(
            PipelineRunStatus::COLLECT,
            sprintf(
                'Перепись «%s»: просмотрено %d из %d, новых %d, ушло %d, смена цены у %d',
                $profile->name,
                $stats['seen'],
                $stats['total'],
                $stats['fresh'],
                $stats['gone'],
                $stats['price_changes']
            ),
            $stats
        );
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('Niche scan failed: '.($e?->getMessage() ?? 'unknown'));
    }
}
