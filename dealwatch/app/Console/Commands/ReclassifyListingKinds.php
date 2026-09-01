<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\DealScoreEngine;
use App\Services\ListingKindClassifier;
use Illuminate\Console\Command;

class ReclassifyListingKinds extends Command
{
    protected $signature = 'listings:reclassify-kinds {--recalculate : Re-score deals after classify}';

    protected $description = 'Mark «куплю/cumpăr» ads as want_buy (potential buyers, out of market analytics)';

    public function handle(ListingKindClassifier $classifier, DealScoreEngine $engine): int
    {
        $wantBuy = 0;
        $sell = 0;

        Listing::query()->where('status', 'active')->chunkById(200, function ($listings) use ($classifier, &$wantBuy, &$sell) {
            foreach ($listings as $listing) {
                $kind = $classifier->apply($listing);
                if ($kind === ListingKindClassifier::KIND_WANT_BUY) {
                    $wantBuy++;
                } else {
                    $sell++;
                }
            }
        });

        $this->table(
            ['Sell (рынок)', 'Want buy (куплю)', 'Total'],
            [[$sell, $wantBuy, $sell + $wantBuy]]
        );

        if ($this->option('recalculate')) {
            $count = 0;
            Listing::query()->where('status', 'active')->chunkById(100, function ($listings) use ($engine, &$count) {
                foreach ($listings as $listing) {
                    $engine->evaluate($listing);
                    $count++;
                }
            });
            $this->info("Recalculated {$count} listings.");
        }

        return self::SUCCESS;
    }
}
