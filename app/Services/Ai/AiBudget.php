<?php

namespace App\Services\Ai;

use App\Models\AiRequest;

/**
 * Предохранитель расходов: считает, сколько уже потрачено за сутки,
 * и не даёт уйти за лимиты из config('dealwatch.ai.limits').
 */
class AiBudget
{
    /**
     * @return array{calls: int, cost_usd: float, calls_limit: int, cost_limit_usd: float, calls_left: int, cost_left_usd: float}
     */
    public function today(): array
    {
        $row = AiRequest::query()
            ->billable()
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('count(*) as calls, coalesce(sum(cost_usd), 0) as cost')
            ->first();

        $calls = (int) ($row->calls ?? 0);
        $cost = round((float) ($row->cost ?? 0), 6);
        $callsLimit = (int) config('dealwatch.ai.limits.daily_calls');
        $costLimit = (float) config('dealwatch.ai.limits.daily_cost_usd');

        return [
            'calls' => $calls,
            'cost_usd' => $cost,
            'calls_limit' => $callsLimit,
            'cost_limit_usd' => $costLimit,
            'calls_left' => max(0, $callsLimit - $calls),
            'cost_left_usd' => round(max(0, $costLimit - $cost), 6),
        ];
    }

    /**
     * @throws AiBudgetExceededException
     */
    public function assertWithinLimits(): void
    {
        $usage = $this->today();

        if ($usage['calls_limit'] > 0 && $usage['calls'] >= $usage['calls_limit']) {
            throw new AiBudgetExceededException(sprintf(
                'Дневной лимит обращений к ИИ исчерпан (%d из %d). Лимит: DEALWATCH_AI_DAILY_CALLS.',
                $usage['calls'],
                $usage['calls_limit']
            ));
        }

        if ($usage['cost_limit_usd'] > 0 && $usage['cost_usd'] >= $usage['cost_limit_usd']) {
            throw new AiBudgetExceededException(sprintf(
                'Дневной бюджет ИИ исчерпан ($%.2f из $%.2f). Лимит: DEALWATCH_AI_DAILY_COST_USD.',
                $usage['cost_usd'],
                $usage['cost_limit_usd']
            ));
        }
    }
}
