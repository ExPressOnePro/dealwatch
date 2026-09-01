<?php

namespace App\Services;

use App\Models\Listing;
use Carbon\Carbon;

class ListingCorpusStats
{
    /**
     * Summary of the loaded 999.md listing corpus for MVP foundations.
     * $months = 0 → all active listings (no publish-date cutoff).
     *
     * @return array<string, mixed>
     */
    public function summary(int $months = 0, ?int $profileId = null): array
    {
        return StatsCache::remember(
            'dealwatch:stats:corpus:'.$months.($profileId ? ':'.$profileId : ''),
            fn () => $this->build($months, $profileId)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(int $months, ?int $profileId = null): array
    {
        $since = $months > 0
            ? now('Europe/Chisinau')->subMonths($months)->startOfDay()
            : null;

        // Одним проходом по listings вместо десяти отдельных COUNT/MIN/MAX:
        // на корпусе в 50k это разница между ~550 мс и ~80 мс.
        $sell = "(listing_kind = 'sell' or listing_kind is null)";

        $row = Listing::query()
            ->where('platform', '999')
            ->where('status', 'active')
            ->when($profileId, fn ($q) => $q->where('search_profile_id', $profileId))
            ->when($since, fn ($q) => $q->where('published_at', '>=', $since))
            ->selectRaw(
                'count(*) as total,'
                ."sum(case when listing_kind = 'want_buy' then 1 else 0 end) as want_buy,"
                ."sum(case when {$sell} then 1 else 0 end) as sell_total,"
                ."sum(case when {$sell} and seller_type = 'private' then 1 else 0 end) as private_total,"
                ."sum(case when {$sell} and seller_type = 'shop' then 1 else 0 end) as shop_total,"
                ."sum(case when {$sell} and price_mdl is not null and price_mdl > 0 then 1 else 0 end) as with_price,"
                ."sum(case when {$sell} and is_reseller = 1 then 1 else 0 end) as resellers,"
                ."sum(case when {$sell} and seller_type = 'private' and is_reseller = 0 then 1 else 0 end) as private_clean,"
                .'min(published_at) as published_from,'
                .'max(published_at) as published_to'
            )
            ->first();

        $total = (int) ($row->total ?? 0);
        $sellTotal = (int) ($row->sell_total ?? 0);
        $wantBuy = (int) ($row->want_buy ?? 0);
        $private = (int) ($row->private_total ?? 0);
        $shop = (int) ($row->shop_total ?? 0);
        $withPrice = (int) ($row->with_price ?? 0);
        $resellers = (int) ($row->resellers ?? 0);
        $privateClean = (int) ($row->private_clean ?? 0);

        $resellerShare = $sellTotal > 0 ? round(100 * $resellers / $sellTotal, 1) : 0.0;
        $shopShare = $sellTotal > 0 ? round(100 * $shop / $sellTotal, 1) : 0.0;
        $privateShare = $sellTotal > 0 ? round(100 * $private / $sellTotal, 1) : 0.0;
        $wantBuyShare = $total > 0 ? round(100 * $wantBuy / $total, 1) : 0.0;

        $from = $row->published_from ?? null;
        $to = $row->published_to ?? null;
        $fromFmt = $from ? Carbon::parse($from)->timezone('Europe/Chisinau')->format('d.m.Y') : null;
        $toFmt = $to ? Carbon::parse($to)->timezone('Europe/Chisinau')->format('d.m.Y') : null;

        $label = $total > 0
            ? sprintf(
                'База MVP: %s объявлений 999.md%s · sell %s (частники %s / магазины %s) · куплю %s',
                number_format($total, 0, '.', ' '),
                $fromFmt && $toFmt ? " с {$fromFmt} по {$toFmt}" : '',
                number_format($sellTotal, 0, '.', ' '),
                number_format($privateClean, 0, '.', ' '),
                number_format($shop, 0, '.', ' '),
                number_format($wantBuy, 0, '.', ' ')
            )
            : 'База объявлений ещё не загружена — запустите deals:backfill-999';

        return [
            'total' => $total,
            'sell_total' => $sellTotal,
            'private' => $private,
            'private_clean' => $privateClean,
            'private_share_percent' => $privateShare,
            'shop' => $shop,
            'shop_share_percent' => $shopShare,
            'resellers' => $resellers,
            'reseller_share_percent' => $resellerShare,
            'want_buy' => $wantBuy,
            'want_buy_share_percent' => $wantBuyShare,
            'with_price' => $withPrice,
            'from' => $fromFmt,
            'to' => $toFmt,
            'from_iso' => $from ? Carbon::parse($from)->toIso8601String() : null,
            'to_iso' => $to ? Carbon::parse($to)->toIso8601String() : null,
            'label' => $label,
        ];
    }
}
