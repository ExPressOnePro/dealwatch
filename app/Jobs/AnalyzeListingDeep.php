<?php

namespace App\Jobs;

use App\Models\ListingAiReport;
use App\Services\Ai\ListingDeepAnalyst;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Разбор одного объявления (текст, при необходимости — фото) в фоне. */
class AnalyzeListingDeep implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $reportId,
    ) {}

    public function handle(ListingDeepAnalyst $analyst): void
    {
        $report = ListingAiReport::with('listing')->find($this->reportId);

        if (! $report) {
            return;
        }

        $analyst->run($report);
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('Listing AI report job failed: '.($e?->getMessage() ?? 'unknown'));

        ListingAiReport::where('id', $this->reportId)->update([
            'status' => ListingAiReport::STATUS_FAILED,
            'error' => mb_substr($e?->getMessage() ?? 'неизвестная ошибка', 0, 500),
        ]);
    }
}
