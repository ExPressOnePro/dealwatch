<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\ListingScorer;
use App\Services\SellerAnalyticsService;
use Illuminate\Console\Command;

class RefreshSellerProfiles extends Command
{
    protected $signature = 'sellers:refresh {--recalculate : Re-score deals after refresh}';

    protected $description = 'Detect 999 seller accounts over the reseller threshold (config dealwatch.sellers.reseller_threshold)';

    public function handle(SellerAnalyticsService $sellers, ListingScorer $engine): int
    {
        $this->line('Порог перекупа: >'.SellerAnalyticsService::resellerThreshold().' активных объявлений на аккаунт.');

        $result = $sellers->refreshAll();

        $this->table(
            ['Unique sellers', 'Reseller accounts', 'Reseller listings', 'Share of corpus'],
            [[
                $result['unique_sellers'],
                $result['reseller_accounts'],
                $result['reseller_listings'],
                $result['reseller_share_percent'].'%',
            ]]
        );

        $this->line('Частники без перекупов: '.$result['private_non_reseller']);

        if ($this->option('recalculate')) {
            $count = 0;
            Listing::query()->where('status', 'active')->chunkById(100, function ($listings) use ($engine, &$count) {
                foreach ($listings as $listing) {
                    $engine->score($listing);
                    $count++;
                }
            });
            $this->info("Recalculated {$count} listings.");
        }

        return self::SUCCESS;
    }
}
