<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\ListingDetailEnricher;
use Illuminate\Console\Command;

class RefreshListingDetails extends Command
{
    protected $signature = 'listings:refresh-details {--chunk=40} {--limit=0 : Max listings to refresh (0 = all)}';

    protected $description = 'Re-fetch description and region from 999.md ad pages (JSON-LD)';

    public function handle(ListingDetailEnricher $enricher): int
    {
        $updated = 0;
        $failed = 0;
        $total = 0;
        $limit = (int) $this->option('limit');

        $query = Listing::query()
            ->where('platform', '999')
            ->where('status', 'active')
            ->orderBy('id');

        $query->chunkById((int) $this->option('chunk'), function ($listings) use ($enricher, &$updated, &$failed, &$total, $limit) {
            foreach ($listings as $listing) {
                if ($limit > 0 && $total >= $limit) {
                    return false;
                }

                $total++;
                try {
                    if ($enricher->enrich($listing)) {
                        $updated++;
                    }
                } catch (\Throwable) {
                    $failed++;
                }

                usleep(150_000);

                if ($total % 100 === 0) {
                    $this->line("… processed {$total}, updated {$updated}");
                }
            }
        });

        $this->table(
            ['Processed', 'Updated', 'Failed'],
            [[$total, $updated, $failed]]
        );

        return self::SUCCESS;
    }
}
