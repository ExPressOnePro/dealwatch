<?php

namespace App\Services;

use App\Models\Deal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Построение выборки ленты сделок. Живёт отдельно от контроллера, потому что
 * теми же фильтрами пользуется ИИ-разбор: «разобрать то, что сейчас на экране».
 */
class DealFeedQuery
{
    public const SEGMENTS = ['targets', 'shops', 'want_buy', 'resellers', 'private_all', 'all'];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Deal>
     */
    public function build(array $filters): Builder
    {
        $segment = (string) ($filters['segment'] ?? 'targets');
        $status = (string) ($filters['status'] ?? 'active');
        $verdict = (string) ($filters['verdict'] ?? 'all');
        $minScore = (int) ($filters['min_score'] ?? 0);
        $maxScore = $filters['max_score'] ?? null;
        $profitRange = (string) ($filters['profit_range'] ?? 'all');
        $modelKey = (string) ($filters['model'] ?? 'all');
        $profileId = $filters['profile'] ?? null;

        return Deal::query()
            ->select('deals.*')
            ->with(['listing', 'marketPriceRef'])
            ->join('listings', 'listings.id', '=', 'deals.listing_id')
            ->tap(fn ($q) => $this->applySegment($q, $segment))
            ->when($minScore > 0, fn ($q) => $q->where('deals.deal_score', '>=', $minScore))
            ->when($maxScore !== null, fn ($q) => $q->where('deals.deal_score', '<=', (int) $maxScore))
            ->when($profitRange !== 'all', function ($q) use ($profitRange) {
                match ($profitRange) {
                    'lt800' => $q->where(function ($q2) {
                        $q2->whereNull('deals.potential_profit')->orWhere('deals.potential_profit', '<', 800);
                    }),
                    '800-1499' => $q->whereBetween('deals.potential_profit', [800, 1499]),
                    '1500-2999' => $q->whereBetween('deals.potential_profit', [1500, 2999]),
                    '3000+' => $q->where('deals.potential_profit', '>=', 3000),
                    default => null,
                };
            })
            ->when(in_array($verdict, ['buy', 'check', 'ignore'], true), fn ($q) => $q->where('deals.verdict', $verdict))
            ->when($status === 'active', function ($q) {
                $q->whereIn('deals.user_status', Deal::ACTIVE_STATUSES)
                    ->whereNotExists(fn ($sub) => $this->ignoredListings($sub));
            })
            ->when($status === Deal::STATUS_DISMISSED, fn ($q) => $q->where(function ($q2) {
                $q2->where('deals.user_status', Deal::STATUS_DISMISSED)
                    ->orWhereExists(fn ($sub) => $this->ignoredListings($sub));
            }))
            ->when(! in_array($status, ['all', 'active', Deal::STATUS_DISMISSED], true), fn ($q) => $q->where('deals.user_status', $status))
            ->when(filled($profileId), fn ($q) => $q->where('listings.search_profile_id', (int) $profileId))
            ->when($modelKey !== 'all' && str_contains($modelKey, '|'), function ($q) use ($modelKey) {
                [$brand, $model] = explode('|', $modelKey, 2);
                $q->where('listings.brand', $brand)->where('listings.model', $model);
            });
    }

    /**
     * Дополнительные условия из свободного запроса пользователя.
     *
     * @param  Builder<Deal>  $query
     * @param  array<string, mixed>  $intent
     * @return Builder<Deal>
     */
    public function applyIntent(Builder $query, array $intent): Builder
    {
        return $query
            ->when(filled($intent['brand'] ?? null), fn ($q) => $q->where('listings.brand', $intent['brand']))
            ->when(filled($intent['model'] ?? null), fn ($q) => $q->where('listings.model', 'like', '%'.$intent['model'].'%'))
            ->when(filled($intent['storage_gb'] ?? null), fn ($q) => $q->where('listings.storage_gb', (int) $intent['storage_gb']))
            ->when(filled($intent['max_price_mdl'] ?? null), fn ($q) => $q->where('listings.price_mdl', '<=', (int) $intent['max_price_mdl']));
    }

    /**
     * Сегмент ленты: кого показываем (цели перекупа, магазины, «куплю», …).
     * Работает по колонкам listings.* — запрос уже сджойнен.
     */
    public function applySegment(mixed $query, string $segment): void
    {
        $query->where('listings.status', 'active');

        $sellOnly = fn ($q) => $q->where(function ($q2) {
            $q2->where('listings.listing_kind', 'sell')->orWhereNull('listings.listing_kind');
        });

        match ($segment) {
            'targets' => $sellOnly($query)
                ->where('listings.seller_type', 'private')
                ->where('listings.is_reseller', false),
            'shops' => $sellOnly($query)->where('listings.seller_type', 'shop'),
            'want_buy' => $query->where('listings.listing_kind', 'want_buy'),
            'resellers' => $sellOnly($query)->where('listings.is_reseller', true),
            'private_all' => $sellOnly($query)->where('listings.seller_type', 'private'),
            default => null,
        };
    }

    /** Объявления, скрытые вручную: сопоставляем и площадку, и внешний id. */
    private function ignoredListings(mixed $sub): void
    {
        $sub->select(DB::raw(1))
            ->from('ignored_listings')
            ->whereColumn('ignored_listings.external_id', 'listings.external_id')
            ->whereColumn('ignored_listings.platform', 'listings.platform');
    }
}
