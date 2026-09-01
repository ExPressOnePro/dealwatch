<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\DealScoreEngine;
use Illuminate\Console\Command;

class RecalculateDeals extends Command
{
    protected $signature = 'deals:recalculate';

    protected $description = 'Recalculate deal scores for all active listings';

    public function handle(DealScoreEngine $engine): int
    {
        $count = 0;
        Listing::query()->where('status', 'active')->chunkById(100, function ($listings) use ($engine, &$count) {
            foreach ($listings as $listing) {
                $engine->evaluate($listing);
                $count++;
            }
        });

        $this->info("Recalculated {$count} listings.");

        return self::SUCCESS;
    }
}
