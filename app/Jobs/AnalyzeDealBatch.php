<?php

namespace App\Jobs;

use App\Models\AiBatchAnalysis;
use App\Services\Ai\ListingBatchAnalyst;
use App\Services\Ai\QueryInterpreter;
use App\Services\DealFeedQuery;
use App\Services\PipelineRunStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ИИ-разбор выборки: обращения к модели идут секундами и стоят денег,
 * поэтому только фоном и с записью результата в ai_batch_analyses.
 */
class AnalyzeDealBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $analysisId,
    ) {}

    public function handle(
        DealFeedQuery $feed,
        QueryInterpreter $interpreter,
        ListingBatchAnalyst $analyst,
        PipelineRunStatus $status,
    ): void {
        $analysis = AiBatchAnalysis::find($this->analysisId);

        if (! $analysis) {
            return;
        }

        $status->started(PipelineRunStatus::AI_BATCH);

        try {
            $filters = $analysis->filters ?? [];
            $query = $feed->build($filters);

            if (filled($analysis->query)) {
                $intent = $interpreter->interpret((string) $analysis->query);
                $query = $feed->applyIntent($query, $intent);
                $filters['intent'] = $intent;
            }

            $deals = $query
                ->orderByDesc('deals.potential_profit')
                ->orderByDesc('deals.deal_score')
                ->limit((int) config('dealwatch.ai.batch_size'))
                ->get();

            $result = $analyst->analyze($deals, $analysis->query);

            $analysis->update([
                'status' => AiBatchAnalysis::STATUS_DONE,
                'filters' => $filters,
                'listing_count' => $result['listing_count'],
                'summary' => $result['summary'],
                'recommendation' => $result['recommendation'],
                'items' => $result['items'],
                'model_screen' => $result['model_screen'],
                'model_deep' => $result['model_deep'],
                'cost_usd' => $result['cost_usd'],
            ]);

            $status->finished(
                PipelineRunStatus::AI_BATCH,
                sprintf(
                    'Разобрано объявлений: %d · стоимость $%.4f',
                    $result['listing_count'],
                    $result['cost_usd']
                ),
                ['analysis_id' => $analysis->id]
            );
        } catch (\Throwable $e) {
            Log::warning('AI batch analysis failed: '.$e->getMessage());

            $analysis->update([
                'status' => AiBatchAnalysis::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            $status->failed(PipelineRunStatus::AI_BATCH, 'ИИ-разбор не удался: '.mb_substr($e->getMessage(), 0, 200));
        }
    }

    public function failed(?\Throwable $e): void
    {
        AiBatchAnalysis::where('id', $this->analysisId)->update([
            'status' => AiBatchAnalysis::STATUS_FAILED,
            'error' => mb_substr($e?->getMessage() ?? 'unknown', 0, 500),
        ]);
    }
}
