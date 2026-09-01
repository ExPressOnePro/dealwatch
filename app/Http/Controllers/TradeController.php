<?php

namespace App\Http\Controllers;

use App\Jobs\ArchiveListing;
use App\Models\Deal;
use App\Models\Trade;
use App\Services\TradeStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TradeController extends Controller
{
    public function __construct(
        private readonly TradeStats $stats,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (int) $request->user()->id;

        $filters = [
            'status' => $request->string('status')->toString() ?: 'all',
            'from' => $request->date('from')?->toDateString(),
            'to' => $request->date('to')?->toDateString(),
            'model' => $request->string('model')->toString() ?: null,
        ];

        $query = $this->stats->query($userId, $filters === [] ? [] : array_filter([
            'status' => $filters['status'] !== 'all' ? $filters['status'] : null,
            'from' => $filters['from'],
            'to' => $filters['to'],
            'model' => $filters['model'],
        ]));

        $trades = $query
            ->with(['expenses', 'listing', 'snapshot'])
            ->orderByRaw('case when sale_date is null then 0 else 1 end')
            ->orderByDesc('updated_at')
            ->limit(300)
            ->get()
            ->map(fn (Trade $trade) => $this->serialize($trade));

        return Inertia::render('trades/index', [
            'trades' => $trades,
            'summary' => $this->stats->summary($userId, array_filter([
                'from' => $filters['from'],
                'to' => $filters['to'],
            ])),
            'filters' => $filters,
            'statuses' => Trade::STATUSES,
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }

    /**
     * Завести сделку — из карточки ленты или вручную.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'deal_id' => 'nullable|integer|exists:deals,id',
            'title' => 'nullable|string|max:255',
            'brand' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'storage_gb' => 'nullable|integer|min:1|max:2048',
            'purchase_price' => 'nullable|integer|min:0',
            'purchase_date' => 'nullable|date',
        ]);

        $deal = isset($data['deal_id']) ? Deal::with('listing')->find($data['deal_id']) : null;
        $listing = $deal?->listing;

        if (! $deal && blank($data['title'] ?? null)) {
            return back()->with('error', 'Укажи название товара или заведи сделку из карточки объявления.');
        }

        $trade = Trade::create([
            'user_id' => $request->user()->id,
            'listing_id' => $listing?->id,
            'deal_id' => $deal?->id,
            'title' => $data['title'] ?? $listing?->displayName() ?? 'Сделка',
            'brand' => $data['brand'] ?? $listing?->brand,
            'model' => $data['model'] ?? $listing?->model,
            'storage_gb' => $data['storage_gb'] ?? $listing?->storage_gb,
            'status' => Trade::STATUS_BOUGHT,
            'purchase_price' => $data['purchase_price'] ?? $listing?->priceForScoring(),
            'purchase_date' => $data['purchase_date'] ?? now()->toDateString(),
        ]);

        // Объявление снимут — сохраняем фото и копию страницы, пока они есть.
        if ($listing) {
            ArchiveListing::dispatch($listing->id);
        }

        return redirect()
            ->route('trades.index')
            ->with('success', "Сделка «{$trade->title}» добавлена в журнал.");
    }

    public function update(Request $request, Trade $trade): RedirectResponse
    {
        $this->authorizeTrade($request, $trade);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'brand' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'storage_gb' => 'nullable|integer|min:1|max:2048',
            'status' => ['required', Rule::in(Trade::STATUSES)],
            'purchase_price' => 'nullable|integer|min:0',
            'purchase_date' => 'nullable|date',
            'sale_price' => 'nullable|integer|min:0',
            'sale_date' => 'nullable|date',
            'sale_channel' => 'nullable|string|max:100',
            'buyer_note' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'expenses' => 'array|max:20',
            'expenses.*.label' => 'required|string|max:100',
            'expenses.*.amount' => 'required|integer',
        ]);

        $trade->update(collect($data)->except('expenses')->all());

        $trade->expenses()->delete();
        foreach ($data['expenses'] ?? [] as $expense) {
            $trade->expenses()->create($expense);
        }

        return back()->with('success', 'Сделка обновлена.');
    }

    public function destroy(Request $request, Trade $trade): RedirectResponse
    {
        $this->authorizeTrade($request, $trade);

        $trade->delete();

        return redirect()->route('trades.index')->with('success', 'Сделка удалена из журнала.');
    }

    private function authorizeTrade(Request $request, Trade $trade): void
    {
        abort_unless($trade->user_id === $request->user()?->id, 403, 'Это чужая сделка.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Trade $trade): array
    {
        $listing = $trade->listing;

        return [
            'id' => $trade->id,
            'title' => $trade->title,
            'brand' => $trade->brand,
            'model' => $trade->model,
            'storage_gb' => $trade->storage_gb,
            'status' => $trade->status,
            'purchase_price' => $trade->purchase_price,
            'purchase_date' => optional($trade->purchase_date)->toDateString(),
            'sale_price' => $trade->sale_price,
            'sale_date' => optional($trade->sale_date)->toDateString(),
            'sale_channel' => $trade->sale_channel,
            'buyer_note' => $trade->buyer_note,
            'notes' => $trade->notes,
            'expenses' => $trade->expenses->map(fn ($e) => [
                'label' => $e->label,
                'amount' => (int) $e->amount,
            ])->values(),
            'expenses_total' => $trade->expensesTotal(),
            'total_cost' => $trade->totalCost(),
            'net_profit' => $trade->netProfit(),
            'roi_percent' => $trade->roiPercent(),
            'hold_days' => $trade->holdDays(),
            'listing' => $listing ? [
                'id' => $listing->id,
                'url' => $listing->url,
                'gone_at' => optional($listing->gone_at)->toDateString(),
                'archived' => (bool) $listing->archived,
            ] : null,
        ];
    }
}
