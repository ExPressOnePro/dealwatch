<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FavoriteController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = $request->string('tab')->toString() ?: 'active';

        $base = Deal::query()
            ->with(['listing', 'marketPriceRef'])
            ->where('is_favorite', true);

        $active = (clone $base)
            ->whereNotIn('user_status', ['completed', 'cancelled'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Deal $deal) => $this->serialize($deal));

        $completed = (clone $base)
            ->where('user_status', 'completed')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Deal $deal) => $this->serialize($deal));

        $cancelled = (clone $base)
            ->where('user_status', 'cancelled')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Deal $deal) => $this->serialize($deal));

        $completedDeals = Deal::query()
            ->where('is_favorite', true)
            ->where('user_status', 'completed')
            ->whereNotNull('purchase_price')
            ->whereNotNull('sale_price')
            ->get();

        $stats = [
            'active_count' => $active->count(),
            'completed_count' => $completed->count(),
            'cancelled_count' => $cancelled->count(),
            'turnover' => (int) $completedDeals->sum('sale_price'),
            'total_purchase' => (int) $completedDeals->sum('purchase_price'),
            'net_profit' => (int) $completedDeals->sum(fn (Deal $d) => $d->netProfit() ?? 0),
            'avg_profit' => $completedDeals->isNotEmpty()
                ? (int) round($completedDeals->avg(fn (Deal $d) => $d->netProfit() ?? 0))
                : 0,
        ];

        $items = match ($tab) {
            'completed' => $completed,
            'cancelled' => $cancelled,
            default => $active,
        };

        return Inertia::render('favorites/index', [
            'items' => $items,
            'stats' => $stats,
            'tab' => $tab,
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }

    public function store(Deal $deal): RedirectResponse
    {
        $deal->update(['is_favorite' => true]);

        return back()->with('success', 'Добавлено в избранное');
    }

    public function destroy(Deal $deal): RedirectResponse
    {
        $deal->update([
            'is_favorite' => false,
            'user_status' => in_array($deal->user_status, ['completed', 'cancelled'], true)
                ? $deal->user_status
                : 'new',
        ]);

        return back()->with('success', 'Убрано из избранного');
    }

    public function complete(Request $request, Deal $deal): RedirectResponse
    {
        if (! $deal->is_favorite) {
            return back()->with('error', 'Сначала добавьте в избранное');
        }

        $data = $request->validate([
            'purchase_price' => 'required|integer|min:1',
            'sale_price' => 'required|integer|min:1',
        ]);

        $deal->update([
            ...$data,
            'user_status' => 'completed',
            'completed_at' => now(),
        ]);

        $profit = $deal->fresh()->netProfit();

        return redirect()
            ->route('favorites.index', ['tab' => 'completed'])
            ->with('success', sprintf(
                'Сделка закрыта: прибыль %s MDL',
                number_format((int) $profit, 0, '.', ' ')
            ));
    }

    public function cancel(Request $request, Deal $deal): RedirectResponse
    {
        if (! $deal->is_favorite) {
            return back()->with('error', 'Сначала добавьте в избранное');
        }

        $data = $request->validate([
            'cancel_note' => 'nullable|string|max:500',
        ]);

        $deal->update([
            'user_status' => 'cancelled',
            'cancel_note' => $data['cancel_note'] ?? null,
            'completed_at' => null,
        ]);

        return redirect()
            ->route('favorites.index', ['tab' => 'cancelled'])
            ->with('success', 'Покупка отменена — устройство не берём');
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Deal $deal): array
    {
        $listing = $deal->listing;

        return [
            'id' => $deal->id,
            'deal_score' => $deal->deal_score,
            'verdict' => $deal->verdict,
            'potential_profit' => $deal->potential_profit,
            'market_price' => $deal->market_price,
            'user_status' => $deal->user_status,
            'purchase_price' => $deal->purchase_price,
            'sale_price' => $deal->sale_price,
            'net_profit' => $deal->netProfit(),
            'cancel_note' => $deal->cancel_note,
            'completed_at' => optional($deal->completed_at)->toIso8601String(),
            'is_favorite' => $deal->is_favorite,
            'listing' => [
                'id' => $listing->id,
                'external_id' => $listing->external_id,
                'title' => $listing->title,
                'description' => $listing->description,
                'display_name' => $listing->displayName(),
                'price_mdl' => $listing->price_mdl ?? $listing->price,
                'price_original' => $listing->price_original,
                'currency' => $listing->currency,
                'url' => $listing->url,
                'location' => $listing->location,
                'seller_phone' => $listing->seller_phone,
                'seller_type' => $listing->seller_type,
                'listing_kind' => $listing->listing_kind,
                'is_reseller' => (bool) $listing->is_reseller,
            ],
        ];
    }
}
