<?php

namespace App\Console\Commands;

use App\Services\Collectors\NineNinetyNineCollector;
use App\Services\ListingPipeline;
use App\Services\MarketPriceRebuilder;
use App\Services\SellerAnalyticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class Backfill999Listings extends Command
{
    protected $signature = 'deals:backfill-999
        {--months=0 : Load ads not older than N months (0 = entire active catalog)}
        {--page-size=50 : GraphQL page size}
        {--rebuild-market=1 : Rebuild market prices from loaded private ads}
        {--min-samples=5 : Min private samples to rebuild a model}
        {--enrich=0 : Fetch each ad page for full description/region (very slow)}';

    protected $description = 'Load all active 999.md phone listings into MVP DB and rebuild market foundations';

    public function handle(
        NineNinetyNineCollector $collector,
        ListingPipeline $pipeline,
        MarketPriceRebuilder $rebuilder,
        SellerAnalyticsService $sellers,
    ): int {
        $months = max(0, (int) $this->option('months'));
        $pageSize = (int) $this->option('page-size');
        $enrich = (bool) (int) $this->option('enrich');
        // Far past = no date cutoff → walk full catalog until skip >= total.
        $since = $months === 0
            ? Carbon::create(2000, 1, 1, 0, 0, 0, 'Europe/Chisinau')
            : Carbon::now('Europe/Chisinau')->subMonths($months)->startOfDay();

        $this->info(
            $months === 0
                ? 'Backfill: весь активный каталог телефонов 999.md'
                : "Backfill 999 phones since {$since->toDateString()} (last {$months} months)"
        );
        if (! $enrich) {
            $this->warn('Без enrich страниц (быстро). Описание/регион можно догнать: php artisan listings:refresh-details');
        }

        $ingested = 0;
        $deals = 0;

        $summary = $collector->collectSince(
            $since,
            $pageSize,
            function (int $fetched, int $total, ?string $oldest) {
                $this->line("… fetched {$fetched} / ~{$total}".($oldest ? " · oldest {$oldest}" : ''));
            },
            function (array $batch) use ($pipeline, $enrich, &$ingested, &$deals) {
                foreach ($batch as $item) {
                    $deal = $pipeline->ingest($item, notify: false, enrich: $enrich);
                    $ingested++;
                    if ($deal) {
                        $deals++;
                    }
                }
                if ($ingested % 250 === 0) {
                    $this->line("… ingested {$ingested}");
                }
            }
        );

        $this->table(
            ['Fetched', 'Ingested', 'Deals scored', 'Catalog total', 'Oldest seen'],
            [[$summary['fetched'], $ingested, $deals, $summary['total'], $summary['oldest'] ?? '—']]
        );

        $this->info('Refreshing seller profiles…');
        $sellers->refreshAll();

        if ((int) $this->option('rebuild-market') === 1) {
            $this->info('Rebuilding market prices from private listings…');
            $marketMonths = $months > 0 ? $months : 0;
            $result = $rebuilder->rebuild($marketMonths, max(3, (int) $this->option('min-samples')));
            $this->table(
                ['Updated models', 'Skipped (few samples)'],
                [[$result['updated'], $result['skipped']]]
            );
            foreach ($result['details'] as $row) {
                if ($row['status'] === 'updated') {
                    $this->line("✓ {$row['model']}: n={$row['samples']} mid={$row['mid']} sell={$row['sell']}");
                }
            }
            $this->call('deals:recalculate');
        }

        return self::SUCCESS;
    }
}
