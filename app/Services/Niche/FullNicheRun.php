<?php

namespace App\Services\Niche;

use App\Models\Listing;
use App\Models\SearchProfile;
use App\Services\ListingPipeline;
use App\Services\ListingScorer;
use App\Services\ProfileMarketStats;
use App\Services\SellerAnalyticsService;

/**
 * Полный проход по источнику: собрать, переписать каталог, пересчитать
 * продавцов и оценки, снять аналитику. Одна операция вместо четырёх кнопок.
 */
class FullNicheRun
{
    public function __construct(
        private readonly ListingPipeline $pipeline,
        private readonly NicheScanner $scanner,
        private readonly SellerAnalyticsService $sellers,
        private readonly ListingScorer $scorer,
        private readonly ProfileMarketStats $marketStats,
        private readonly NicheAnalytics $analytics,
    ) {}

    /**
     * @param  callable(string):void|null  $onStep
     * @return array<string, mixed>
     */
    public function run(SearchProfile $profile, ?int $depth = null, ?callable $onStep = null): array
    {
        $step = $onStep ?? fn () => null;

        $step('Свежие объявления');
        $collect = $this->pipeline->collectProfile($profile, notify: false);

        $step('Перепись каталога');
        $scan = $this->scanner->scan($profile, $depth);

        $step('Профили продавцов');
        $sellers = $this->refreshSellers($profile);

        $step('Пересчёт оценок');
        $this->marketStats->forget($profile);
        $rescored = $this->scorer->rescoreProfile($profile);

        $step('Аналитика ниши');
        $niche = $this->analytics->forProfile($profile->fresh(), 30);

        return [
            'collect' => $collect,
            'scan' => $scan,
            'sellers' => $sellers,
            'rescored' => $rescored,
            'niche' => $niche,
        ];
    }

    /**
     * @return array{accounts: int, resellers: int}
     */
    private function refreshSellers(SearchProfile $profile): array
    {
        $keys = [];

        Listing::query()
            ->where('search_profile_id', $profile->id)
            ->where('status', 'active')
            ->chunkById(200, function ($listings) use (&$keys) {
                foreach ($listings as $listing) {
                    $this->sellers->refreshListing($listing);
                    $key = $listing->fresh()->seller_key;

                    if ($key) {
                        $keys[$key] = true;
                    }
                }
            });

        return [
            'accounts' => count($keys),
            'resellers' => Listing::query()
                ->where('search_profile_id', $profile->id)
                ->where('is_reseller', true)
                ->count(),
        ];
    }
}
