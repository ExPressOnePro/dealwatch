<?php

namespace App\Console\Commands;

use App\Services\ListingPipeline;
use Illuminate\Console\Command;

class Collect999Listings extends Command
{
    protected $signature = 'deals:collect-999 {--no-notify : Do not send Telegram alerts}';

    protected $description = 'Collect new phone listings from 999.md and score deals';

    public function handle(ListingPipeline $pipeline): int
    {
        $this->info('Collecting from 999.md...');
        $stats = $pipeline->collectFrom999(notify: ! $this->option('no-notify'));

        $this->table(
            ['Fetched', 'Ingested', 'Deals', 'Alerts'],
            [[$stats['fetched'], $stats['ingested'], $stats['deals'], $stats['alerts']]]
        );

        if ($stats['fetched'] === 0) {
            $this->warn('No listings fetched. 999 may block HTML scraping or require JS. Demo data: php artisan db:seed --class=DemoListingSeeder');
        }

        return self::SUCCESS;
    }
}
