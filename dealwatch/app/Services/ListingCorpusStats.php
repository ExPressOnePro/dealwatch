<?php

namespace App\Services;

use App\Models\Listing;

class ListingCorpusStats
{
    /**
     * Summary of the loaded 999.md listing corpus for MVP foundations.
     * $months = 0 → all active listings (no publish-date cutoff).
     *
     * @return array<string, mixed>
     */
    public function summary(int $months = 0): array
    {
        $since = $months > 0
            ? now('Europe/Chisinau')->subMonths($months)->startOfDay()
            : null;

        $base = Listing::query()
            ->where('platform', '999')
            ->where('status', 'active')
            ->when($since, fn ($q) => $q->where('published_at', '>=', $since));

        $total = (clone $base)->count();
        $wantBuy = (clone $base)->wantBuy()->count();
        $sellBase = (clone $base)->marketSell();

        $sellTotal = (clone $sellBase)->count();
        $private = (clone $sellBase)->privateSeller()->count();
        $shop = (clone $sellBase)->shopSeller()->count();
        $withPrice = (clone $sellBase)->whereNotNull('price_mdl')->where('price_mdl', '>', 0)->count();
        $resellers = (clone $sellBase)->where('is_reseller', true)->count();
        $resellerShare = $sellTotal > 0 ? round(100 * $resellers / $sellTotal, 1) : 0.0;
        $privateClean = (clone $sellBase)->privateSeller()->where('is_reseller', false)->count();
        $shopShare = $sellTotal > 0 ? round(100 * $shop / $sellTotal, 1) : 0.0;
        $privateShare = $sellTotal > 0 ? round(100 * $private / $sellTotal, 1) : 0.0;
        $wantBuyShare = $total > 0 ? round(100 * $wantBuy / $total, 1) : 0.0;

        $from = (clone $base)->min('published_at');
        $to = (clone $base)->max('published_at');

        $fromFmt = $from ? \Carbon\Carbon::parse($from)->timezone('Europe/Chisinau')->format('d.m.Y') : null;
        $toFmt = $to ? \Carbon\Carbon::parse($to)->timezone('Europe/Chisinau')->format('d.m.Y') : null;

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
            'from_iso' => $from ? \Carbon\Carbon::parse($from)->toIso8601String() : null,
            'to_iso' => $to ? \Carbon\Carbon::parse($to)->toIso8601String() : null,
            'label' => $label,
        ];
    }
}
