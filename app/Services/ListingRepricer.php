<?php

namespace App\Services;

use App\Models\Listing;

/**
 * Цена в MDL фиксируется в момент сбора. Курс живёт своей жизнью, поэтому
 * после его обновления пересчитываем price_mdl активных валютных объявлений —
 * иначе рынок строится по смеси курсов разных дней.
 */
class ListingRepricer
{
    public function __construct(
        private readonly CurrencyRateService $rates,
    ) {}

    /**
     * @param  callable(Listing):void|null  $onUpdated
     * @return int Сколько объявлений получили новую цену в MDL.
     */
    public function repriceActive(?callable $onUpdated = null): int
    {
        $updated = 0;

        Listing::query()
            ->where('status', 'active')
            ->whereNotNull('price_original')
            ->where('price_original', '>', 0)
            ->whereNotNull('currency')
            ->where('currency', '!=', 'MDL')
            ->chunkById(200, function ($listings) use (&$updated, $onUpdated) {
                foreach ($listings as $listing) {
                    $fresh = $this->rates->toMdl((int) $listing->price_original, (string) $listing->currency);

                    if ($fresh <= 0 || $fresh === (int) $listing->price_mdl) {
                        continue;
                    }

                    $listing->forceFill(['price_mdl' => $fresh])->save();
                    $updated++;

                    if ($onUpdated) {
                        $onUpdated($listing);
                    }
                }
            });

        return $updated;
    }
}
