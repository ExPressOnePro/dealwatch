<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Listing;
use App\Models\SearchProfile;

/**
 * Единая точка входа в оценку объявления: телефоны считаются по справочнику
 * моделей, остальные источники — по медиане цен внутри источника.
 */
class ListingScorer
{
    public function __construct(
        private readonly DealScoreEngine $phones,
        private readonly GenericDealScorer $generic,
    ) {}

    public function score(Listing $listing): ?Deal
    {
        $profile = $listing->searchProfile;

        return $profile && ! $profile->isPhones()
            ? $this->generic->score($listing)
            : $this->phones->evaluate($listing);
    }

    /**
     * Пересчитать весь источник. Нужен после сбора: пока объявлений мало,
     * рынок источника ещё не собран, и первые карточки остаются без оценки.
     *
     * @return int Сколько объявлений пересчитано.
     */
    public function rescoreProfile(SearchProfile $profile): int
    {
        $count = 0;

        Listing::query()
            ->where('search_profile_id', $profile->id)
            ->where('status', 'active')
            ->chunkById(200, function ($listings) use (&$count) {
                foreach ($listings as $listing) {
                    $this->score($listing);
                    $count++;
                }
            });

        return $count;
    }
}
