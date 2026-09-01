<?php

namespace App\Services;

use App\Models\Trade;
use Illuminate\Database\Eloquent\Builder;

/**
 * Отчётность по своим сделкам: сколько заработано, на чём и как быстро.
 * Расходы учитываются везде, где считается прибыль, — иначе цифры врут.
 */
class TradeStats
{
    /**
     * @param  array{from?: ?string, to?: ?string, status?: ?string, model?: ?string}  $filters
     * @return Builder<Trade>
     */
    public function query(int $userId, array $filters = []): Builder
    {
        return Trade::query()
            ->forUser($userId)
            ->withSum('expenses as expenses_total', 'amount')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['model'] ?? null), function ($q) use ($filters) {
                [$brand, $model] = array_pad(explode('|', (string) $filters['model'], 2), 2, null);
                $q->where('brand', $brand)->when($model, fn ($q2) => $q2->where('model', $model));
            })
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->where(function ($q2) use ($filters) {
                $q2->whereDate('sale_date', '>=', $filters['from'])
                    ->orWhereDate('purchase_date', '>=', $filters['from']);
            }))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->where(function ($q2) use ($filters) {
                $q2->whereDate('sale_date', '<=', $filters['to'])
                    ->orWhereDate('purchase_date', '<=', $filters['to']);
            }));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(int $userId, array $filters = []): array
    {
        $trades = $this->query($userId, $filters)->with('expenses')->get();
        $sold = $trades->where('status', Trade::STATUS_SOLD);

        $turnover = (int) $sold->sum('sale_price');
        $cost = (int) $sold->sum(fn (Trade $t) => $t->totalCost() ?? 0);
        $profit = (int) $sold->sum(fn (Trade $t) => $t->netProfit() ?? 0);
        $holdDays = $sold->map(fn (Trade $t) => $t->holdDays())->filter(fn ($d) => $d !== null);
        $rois = $sold->map(fn (Trade $t) => $t->roiPercent())->filter(fn ($r) => $r !== null);

        $open = $trades->whereIn('status', [Trade::STATUS_PLANNED, Trade::STATUS_BOUGHT, Trade::STATUS_LISTED]);

        return [
            'trades' => $trades->count(),
            'open' => $open->count(),
            'sold' => $sold->count(),
            'cancelled' => $trades->where('status', Trade::STATUS_CANCELLED)->count(),
            'turnover' => $turnover,
            'cost' => $cost,
            'profit' => $profit,
            'expenses' => (int) $trades->sum(fn (Trade $t) => $t->expensesTotal()),
            'avg_profit' => $sold->count() > 0 ? (int) round($profit / $sold->count()) : 0,
            'avg_roi' => $rois->isNotEmpty() ? round($rois->avg(), 1) : null,
            'avg_hold_days' => $holdDays->isNotEmpty() ? (int) round($holdDays->avg()) : null,
            // Деньги, замороженные в непроданных телефонах.
            'locked_money' => (int) $open->sum(fn (Trade $t) => $t->totalCost() ?? 0),
        ];
    }

    /**
     * Что выгоднее перепродавать.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function byModel(int $userId, array $filters = []): array
    {
        return $this->query($userId, $filters)
            ->with('expenses')
            ->get()
            ->groupBy(fn (Trade $t) => trim(($t->brand ?? '—').' '.($t->model ?? '')))
            ->map(function ($group, string $label) {
                $sold = $group->where('status', Trade::STATUS_SOLD);
                $profit = (int) $sold->sum(fn (Trade $t) => $t->netProfit() ?? 0);
                $rois = $sold->map(fn (Trade $t) => $t->roiPercent())->filter(fn ($r) => $r !== null);
                $hold = $sold->map(fn (Trade $t) => $t->holdDays())->filter(fn ($d) => $d !== null);

                return [
                    'label' => $label,
                    'trades' => $group->count(),
                    'sold' => $sold->count(),
                    'profit' => $profit,
                    'avg_profit' => $sold->count() > 0 ? (int) round($profit / $sold->count()) : 0,
                    'avg_roi' => $rois->isNotEmpty() ? round($rois->avg(), 1) : null,
                    'avg_hold_days' => $hold->isNotEmpty() ? (int) round($hold->avg()) : null,
                    'avg_purchase' => $group->whereNotNull('purchase_price')->avg('purchase_price') !== null
                        ? (int) round($group->whereNotNull('purchase_price')->avg('purchase_price'))
                        : null,
                    'avg_sale' => $sold->whereNotNull('sale_price')->avg('sale_price') !== null
                        ? (int) round($sold->whereNotNull('sale_price')->avg('sale_price'))
                        : null,
                ];
            })
            ->sortByDesc('profit')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function byMonth(int $userId, array $filters = []): array
    {
        return $this->query($userId, $filters)
            ->with('expenses')
            ->sold()
            ->whereNotNull('sale_date')
            ->get()
            ->groupBy(fn (Trade $t) => $t->sale_date->format('Y-m'))
            ->map(fn ($group, string $month) => [
                'month' => $month,
                'sold' => $group->count(),
                'turnover' => (int) $group->sum('sale_price'),
                'profit' => (int) $group->sum(fn (Trade $t) => $t->netProfit() ?? 0),
            ])
            ->sortBy('month')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function byChannel(int $userId, array $filters = []): array
    {
        return $this->query($userId, $filters)
            ->with('expenses')
            ->sold()
            ->get()
            ->groupBy(fn (Trade $t) => $t->sale_channel ?: 'не указан')
            ->map(fn ($group, string $channel) => [
                'channel' => $channel,
                'sold' => $group->count(),
                'profit' => (int) $group->sum(fn (Trade $t) => $t->netProfit() ?? 0),
                'avg_profit' => (int) round($group->avg(fn (Trade $t) => $t->netProfit() ?? 0)),
            ])
            ->sortByDesc('profit')
            ->values()
            ->all();
    }
}
