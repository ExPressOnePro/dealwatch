<?php

namespace App\Console\Commands;

use App\Services\CurrencyRateService;
use App\Services\ListingRepricer;
use App\Services\ListingScorer;
use Illuminate\Console\Command;

class RefreshCurrencyRates extends Command
{
    protected $signature = 'currency:refresh {--no-reprice : Do not re-convert EUR/USD listings to MDL}';

    protected $description = 'Refresh EUR/USD to MDL rates and re-convert active currency listings';

    public function handle(CurrencyRateService $rates, ListingRepricer $repricer, ListingScorer $engine): int
    {
        $data = $rates->refresh();
        $this->table(['Currency', 'MDL'], [
            ['EUR', $data['EUR']],
            ['USD', $data['USD']],
            ['MDL', 1],
        ]);

        if ($this->option('no-reprice')) {
            return self::SUCCESS;
        }

        // Цена в MDL — база для скоринга, поэтому пересчитываем и сами сделки.
        $updated = $repricer->repriceActive(function ($listing) use ($engine) {
            $engine->score($listing);
        });

        $this->info("Пересчитано по новому курсу: {$updated} объявлений.");

        return self::SUCCESS;
    }
}
