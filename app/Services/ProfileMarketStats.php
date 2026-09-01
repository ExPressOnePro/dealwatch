<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\SearchProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Рынок для товаров без справочника моделей: медиана и квартили цен по самим
 * объявлениям источника. Работает тем точнее, чем уже настроен источник
 * («Bergamont Gravel 29», а не «велосипеды вообще»).
 */
class ProfileMarketStats
{
    /**
     * @param  int|null  $excludePrice  Цена оцениваемого объявления: своё же предложение
     *                                  не должно тянуть рынок за собой.
     * @return array{samples: int, total_samples: int, median: ?int, p25: ?int, p75: ?int, min: ?int, max: ?int, enough: bool}
     */
    public function for(SearchProfile $profile, ?int $excludePrice = null): array
    {
        $minutes = max(1, (int) config('dealwatch.generic.stats_cache_minutes'));

        $prices = Cache::remember(
            'dealwatch:profile-prices:'.$profile->id,
            now()->addMinutes($minutes),
            fn () => $this->prices($profile)
        );

        return $this->summarise(collect($prices), $excludePrice);
    }

    public function forget(SearchProfile $profile): void
    {
        Cache::forget('dealwatch:profile-prices:'.$profile->id);
    }

    /**
     * @return list<int>
     */
    private function prices(SearchProfile $profile): array
    {
        return Listing::query()
            ->where('search_profile_id', $profile->id)
            ->where('status', 'active')
            ->marketSell()
            // Запчасти, аксессуары и реплики стоят в разы дешевле — они
            // перекашивают медиану и делают ориентир бессмысленным.
            ->where('subject', ListingSubjectClassifier::SUBJECT_ITEM)
            ->where('is_replica', false)
            ->whereNotNull('price_mdl')
            ->where('price_mdl', '>', 0)
            ->pluck('price_mdl')
            ->map(fn ($value) => (int) $value)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $prices
     * @return array{samples: int, total_samples: int, median: ?int, p25: ?int, p75: ?int, min: ?int, max: ?int, enough: bool}
     */
    private function summarise($prices, ?int $excludePrice): array
    {
        // Достаточность выборки считаем по всему источнику: исключение своей же
        // цены не должно ронять оценку ниже порога на маленьких источниках.
        $totalSamples = $prices->count();

        if ($excludePrice !== null) {
            $position = $prices->search($excludePrice, true);

            if ($position !== false) {
                $prices = $prices->forget($position)->values();
            }
        }

        $empty = [
            'samples' => 0, 'total_samples' => $totalSamples, 'median' => null, 'p25' => null,
            'p75' => null, 'min' => null, 'max' => null, 'enough' => false,
        ];

        if ($prices->isEmpty()) {
            return $empty;
        }

        $median = $this->percentile($prices, 50);

        // Отсекаем случайные «1 лей» и «продам склад» — они перекашивают медиану.
        $filtered = $prices
            ->filter(fn (int $price) => $price >= $median * (float) config('dealwatch.generic.outlier_low_ratio')
                && $price <= $median * (float) config('dealwatch.generic.outlier_high_ratio'))
            ->values();

        if ($filtered->isEmpty()) {
            $filtered = $prices;
        }

        return [
            'samples' => $filtered->count(),
            'median' => $this->percentile($filtered, 50),
            'p25' => $this->percentile($filtered, 25),
            'p75' => $this->percentile($filtered, 75),
            'min' => (int) $filtered->first(),
            'max' => (int) $filtered->last(),
            'total_samples' => $totalSamples,
            'enough' => $totalSamples >= (int) config('dealwatch.generic.min_samples'),
        ];
    }

    /**
     * @param  Collection<int, int>  $sorted
     */
    private function percentile($sorted, float $percent): int
    {
        $values = $sorted->values();
        $index = (int) floor(($values->count() - 1) * ($percent / 100));

        return (int) $values->get($index);
    }
}
