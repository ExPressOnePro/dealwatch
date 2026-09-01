<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\ListingAnalyst;
use App\Services\ListingKindClassifier;
use App\Services\ListingScorer;
use App\Services\MarketPriceEngine;
use App\Services\SellerAnalyticsService;
use Illuminate\Console\Command;

class AnalyzeListings extends Command
{
    protected $signature = 'listings:analyze {--chunk=100}';

    protected $description = 'Analyze listing text/price (bait 1/111, negotiable, clickbait) and refresh deal scores';

    public function handle(
        ListingAnalyst $analyst,
        MarketPriceEngine $marketEngine,
        ListingScorer $scoreEngine,
        SellerAnalyticsService $sellers,
    ): int {
        $this->info('Refreshing seller profiles (перекупы >3 тел.)…');
        $sellerStats = $sellers->refreshAll();
        $this->line(sprintf(
            'Аккаунтов-перекупов: %d · объявлений: %d (%s%% базы)',
            $sellerStats['reseller_accounts'],
            $sellerStats['reseller_listings'],
            $sellerStats['reseller_share_percent']
        ));

        $this->info('Reclassifying listing kinds (куплю vs sell)…');
        $kinds = app(ListingKindClassifier::class);
        $wantBuy = 0;
        Listing::query()->where('status', 'active')->chunkById((int) $this->option('chunk'), function ($listings) use ($kinds, &$wantBuy) {
            foreach ($listings as $listing) {
                if ($kinds->apply($listing) === ListingKindClassifier::KIND_WANT_BUY) {
                    $wantBuy++;
                }
            }
        });
        $this->line("Объявлений «куплю»: {$wantBuy}");

        $bait = 0;
        $total = 0;
        $flagCounts = [];

        Listing::query()->where('status', 'active')->chunkById((int) $this->option('chunk'), function ($listings) use (
            $analyst,
            $marketEngine,
            $scoreEngine,
            &$bait,
            &$total,
            &$flagCounts,
        ) {
            foreach ($listings as $listing) {
                // evaluate() re-runs analysis + scoring when market match exists;
                // always persist analysis even without a market row.
                $market = $marketEngine->findForListing($listing);
                $analysis = $analyst->analyze($listing, $market);
                $listing->forceFill([
                    'analyst_comment' => $analysis['comment'],
                    'analyst_flags' => $analysis['flags'],
                    'is_bait' => $analysis['is_bait'],
                    'analyst_risk' => $analysis['risk_level'],
                    'analyst_report' => $analysis['report'],
                    'battery_health' => $listing->battery_health
                        ?: data_get($analysis, 'report.battery_from_text'),
                ])->save();

                if ($market) {
                    $scoreEngine->score($listing);
                }

                $total++;
                if ($analysis['is_bait']) {
                    $bait++;
                }
                foreach ($analysis['flags'] as $flag) {
                    $flagCounts[$flag] = ($flagCounts[$flag] ?? 0) + 1;
                }

                if ($total % 500 === 0) {
                    $this->line("… analyzed {$total}");
                }
            }
        });

        $this->table(
            ['Total', 'Bait / кликбейт', 'High risk'],
            [[
                $total,
                $bait,
                Listing::query()->where('status', 'active')->where('analyst_risk', 'high')->count(),
            ]]
        );

        if ($flagCounts !== []) {
            ksort($flagCounts);
            $rows = [];
            foreach ($flagCounts as $flag => $count) {
                $rows[] = [$flag, $count];
            }
            $this->table(['Flag', 'Count'], $rows);
        }

        $this->info('Sample bait comments:');
        Listing::query()
            ->where('is_bait', true)
            ->orderByDesc('id')
            ->limit(8)
            ->get(['title', 'price_mdl', 'currency', 'price_original', 'analyst_comment'])
            ->each(function (Listing $l) {
                $this->line(sprintf(
                    '• %s | %s | %s',
                    mb_substr($l->title, 0, 50),
                    $l->formattedOriginalPrice(),
                    mb_substr((string) $l->analyst_comment, 0, 140)
                ));
            });

        return self::SUCCESS;
    }
}
