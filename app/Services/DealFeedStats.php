<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\IgnoredListing;
use App\Models\Listing;

/**
 * Счётчики над лентой сделок. Считаются одним агрегатом и кешируются:
 * на корпусе в 50k объявлений полный проход стоит ~200 мс, а страница
 * дёргает их при каждой загрузке.
 */
class DealFeedStats
{
    /**
     * @param  int|null  $profileId  Источник: если выбран, все цифры считаются
     *                               только по нему, иначе лента врёт вперемешку.
     * @return array<string, int>
     */
    public function headline(?int $profileId = null): array
    {
        return StatsCache::remember('dealwatch:stats:feed'.($profileId ? ':'.$profileId : ''), function () use ($profileId) {
            $sell = "(listings.listing_kind = 'sell' or listings.listing_kind is null)";
            $target = $sell." and listings.seller_type = 'private' and listings.is_reseller = 0";

            $row = Deal::query()
                ->join('listings', 'listings.id', '=', 'deals.listing_id')
                ->whereIn('deals.user_status', Deal::ACTIVE_STATUSES)
                ->where('listings.status', 'active')
                ->when($profileId, fn ($q) => $q->where('listings.search_profile_id', $profileId))
                ->selectRaw(
                    "sum(case when {$target} and deals.verdict = 'buy' then 1 else 0 end) as buy_count,"
                    ."sum(case when {$target} and deals.verdict = 'check' then 1 else 0 end) as check_count,"
                    ."sum(case when {$target} and deals.freshness = 'fresh' then 1 else 0 end) as fresh_count,"
                    ."sum(case when {$target} then 1 else 0 end) as total_count,"
                    ."sum(case when {$target} and deals.verdict = 'buy' then coalesce(deals.potential_profit, 0) else 0 end) as profit_sum,"
                    ."sum(case when {$sell} and listings.is_reseller = 1 then 1 else 0 end) as reseller_count,"
                    ."sum(case when {$sell} and listings.seller_type = 'shop' then 1 else 0 end) as shop_count,"
                    ."sum(case when listings.listing_kind = 'want_buy' then 1 else 0 end) as want_buy_count"
                )
                ->first();

            return [
                'buy' => (int) ($row->buy_count ?? 0),
                'check' => (int) ($row->check_count ?? 0),
                'fresh' => (int) ($row->fresh_count ?? 0),
                'total' => (int) ($row->total_count ?? 0),
                'profit_sum' => (int) ($row->profit_sum ?? 0),
                'reseller_deals' => (int) ($row->reseller_count ?? 0),
                'shop_deals' => (int) ($row->shop_count ?? 0),
                'want_buy_deals' => (int) ($row->want_buy_count ?? 0),
                'hidden' => IgnoredListing::query()
                    ->when($profileId, fn ($q) => $q->whereIn(
                        'external_id',
                        Listing::query()->where('search_profile_id', $profileId)->select('external_id')
                    ))
                    ->count(),
            ];
        });
    }
}
