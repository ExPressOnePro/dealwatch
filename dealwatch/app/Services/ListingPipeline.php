<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\IgnoredListing;
use App\Models\Listing;
use App\Services\Collectors\NineNinetyNineCollector;
use Illuminate\Support\Facades\Log;

class ListingPipeline
{
    public function __construct(
        private readonly PhoneNormalizer $normalizer,
        private readonly DealScoreEngine $dealScoreEngine,
        private readonly TelegramNotifier $telegramNotifier,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  bool  $enrich  Fetch full ad page (description/region). Slow — disable for bulk backfill.
     */
    public function ingest(array $payload, bool $notify = true, bool $enrich = true): ?Deal
    {
        $parsed = $this->normalizer->parse(
            (string) ($payload['title'] ?? ''),
            $payload['description'] ?? null,
            $payload['site_model'] ?? null
        );

        $listing = Listing::updateOrCreate(
            [
                'platform' => $payload['platform'] ?? '999',
                'external_id' => (string) $payload['external_id'],
            ],
            [
                'url' => $payload['url'],
                'title' => $payload['title'],
                'site_model' => $payload['site_model'] ?? null,
                'description' => $payload['description'] ?? null,
                'price' => $payload['price'] ?? null,
                'price_original' => $payload['price_original'] ?? ($payload['price'] ?? null),
                'price_mdl' => $payload['price_mdl'] ?? ($payload['price'] ?? null),
                'currency' => $payload['currency'] ?? 'MDL',
                'seller_name' => $payload['seller_name'] ?? null,
                'seller_phone' => $payload['seller_phone'] ?? null,
                'seller_type' => $payload['seller_type'] ?? null,
                'listing_kind' => $payload['listing_kind']
                    ?? app(ListingKindClassifier::class)->classify(
                        (string) ($payload['title'] ?? ''),
                        $payload['description'] ?? null
                    ),
                'location' => $payload['location'] ?? null,
                'images' => $payload['images'] ?? null,
                'published_at' => $payload['published_at'] ?? now(),
                'last_seen_at' => now(),
                'status' => 'active',
                'brand' => $parsed['brand'],
                'model' => $parsed['model'],
                'model_source' => $parsed['model_source'] ?? null,
                'storage_gb' => $parsed['storage_gb'] ?? ($payload['storage_gb'] ?? null),
                'battery_health' => $parsed['battery_health'] ?? ($payload['battery_health'] ?? null),
                'condition' => $payload['condition'] ?? null,
                'parse_confidence' => $parsed['confidence'],
                'raw_data' => $payload['raw_data'] ?? $payload,
            ]
        );

        if (! $listing->first_seen_at) {
            $listing->forceFill(['first_seen_at' => now()])->save();
        }

        // Authoritative seller text + region from ad page (search API body is often wrong/truncated).
        if ($enrich) {
            try {
                app(ListingDetailEnricher::class)->enrich($listing);
                $listing->refresh();
            } catch (\Throwable $e) {
                Log::warning('Detail enrich failed for '.$listing->external_id.': '.$e->getMessage());
            }
        }

        // Re-classify from live title/description (overrides stale kind).
        app(ListingKindClassifier::class)->apply($listing);

        app(SellerAnalyticsService::class)->refreshListing($listing);

        $listing->refresh();
        $wasRecentlyCreated = $listing->wasRecentlyCreated;
        $deal = $this->dealScoreEngine->evaluate($listing);

        if ($deal && $notify && $wasRecentlyCreated
            && ! $listing->is_reseller
            && ! $listing->isWantBuy()
            && $listing->seller_type !== 'shop'
            && ! IgnoredListing::isIgnored((string) $listing->platform, (string) $listing->external_id)
            && in_array($deal->verdict, ['buy', 'check'], true) && ! $deal->notified) {
            try {
                $this->telegramNotifier->notifyDeal($deal);
            } catch (\Throwable $e) {
                Log::warning('Notify failed: '.$e->getMessage());
            }
        }

        return $deal;
    }

    public function collectFrom999(bool $notify = true): array
    {
        $collector = app(NineNinetyNineCollector::class);
        $items = $collector->collect();
        $stats = ['fetched' => count($items), 'ingested' => 0, 'deals' => 0, 'alerts' => 0];

        foreach ($items as $item) {
            $deal = $this->ingest($item, $notify);
            $stats['ingested']++;
            if ($deal) {
                $stats['deals']++;
                if ($deal->notified) {
                    $stats['alerts']++;
                }
            }
        }

        return $stats;
    }
}
