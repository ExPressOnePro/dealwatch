<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\DealScoreEngine;
use App\Services\ListingKindClassifier;
use App\Services\MarketPriceRebuilder;
use App\Services\PhoneNormalizer;
use App\Services\SellerAnalyticsService;
use Illuminate\Console\Command;

class ReparseListingModels extends Command
{
    protected $signature = 'listings:reparse-models
        {--chunk=200}
        {--rebuild-market=1 : Rebuild market foundations after reparse}
        {--recalculate=1 : Re-score deals after reparse}';

    protected $description = 'Re-parse brand/model: site field «Модель» first, else title';

    public function handle(
        PhoneNormalizer $normalizer,
        ListingKindClassifier $kinds,
        SellerAnalyticsService $sellers,
        MarketPriceRebuilder $rebuilder,
        DealScoreEngine $engine,
    ): int {
        $changed = 0;
        $cleared = 0;
        $multi = 0;
        $total = 0;

        Listing::query()->where('status', 'active')->chunkById((int) $this->option('chunk'), function ($listings) use (
            $normalizer,
            $kinds,
            &$changed,
            &$cleared,
            &$multi,
            &$total,
        ) {
            foreach ($listings as $listing) {
                $siteModel = $listing->site_model
                    ?: $this->siteModelFromRaw($listing->raw_data);
                $parsed = $normalizer->parse((string) $listing->title, $listing->description, $siteModel);
                $kinds->apply($listing);

                $before = $listing->brand.'|'.$listing->model.'|'.$listing->storage_gb;
                $listing->forceFill([
                    'site_model' => $siteModel ?: $listing->site_model,
                    'brand' => $parsed['brand'],
                    'model' => $parsed['model'],
                    'model_source' => $parsed['model_source'] ?? null,
                    'storage_gb' => $parsed['storage_gb'] ?? $listing->storage_gb,
                    'battery_health' => $listing->battery_health ?: $parsed['battery_health'],
                    'parse_confidence' => $parsed['confidence'],
                ])->save();

                $after = $listing->brand.'|'.$listing->model.'|'.$listing->storage_gb;
                if ($before !== $after) {
                    $changed++;
                }
                if ($parsed['multi_model'] ?? false) {
                    $multi++;
                }
                if (! $parsed['model']) {
                    $cleared++;
                }
                $total++;
            }
        });

        $this->table(
            ['Total', 'Model changed', 'Cleared (no model)', 'Multi-model titles'],
            [[$total, $changed, $cleared, $multi]]
        );

        $this->info('Refreshing seller profiles…');
        $sellers->refreshAll();

        if ((int) $this->option('rebuild-market') === 1) {
            $this->info('Rebuilding market from private sell listings…');
            $result = $rebuilder->rebuild(0, 5);
            $this->line("Market updated: {$result['updated']}, skipped: {$result['skipped']}");
        }

        if ((int) $this->option('recalculate') === 1) {
            $this->info('Recalculating deals…');
            $n = 0;
            Listing::query()->where('status', 'active')->chunkById(100, function ($listings) use ($engine, &$n) {
                foreach ($listings as $listing) {
                    $engine->evaluate($listing);
                    $n++;
                }
            });
            $this->info("Recalculated {$n} listings.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    private function siteModelFromRaw(?array $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        $fromFeature = data_get($raw, 'siteModel.value.translated')
            ?? data_get($raw, 'siteModel.value');

        if (is_array($fromFeature)) {
            return $fromFeature['translated'] ?? null;
        }

        return is_string($fromFeature) && $fromFeature !== '' ? $fromFeature : null;
    }
}
