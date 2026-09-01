<?php

namespace App\Console\Commands;

use App\Services\MarketPriceRebuilder;
use Illuminate\Console\Command;

class RebuildMarketFromListings extends Command
{
    protected $signature = 'market:rebuild-from-listings {--months=0 : 0 = all active listings} {--min-samples=5}';

    protected $description = 'Rebuild market sell/buy ranges from private listings in DB';

    public function handle(MarketPriceRebuilder $rebuilder): int
    {
        $result = $rebuilder->rebuild(
            max(0, (int) $this->option('months')),
            max(3, (int) $this->option('min-samples'))
        );

        $this->table(['Updated', 'Skipped'], [[$result['updated'], $result['skipped']]]);
        foreach ($result['details'] as $row) {
            $this->line(($row['status'] === 'updated' ? '✓ ' : '· ').json_encode($row, JSON_UNESCAPED_UNICODE));
        }

        $this->call('deals:recalculate');

        return self::SUCCESS;
    }
}
