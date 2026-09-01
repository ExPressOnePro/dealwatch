<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\IgnoredListing;
use App\Models\Listing;
use App\Models\ListingSnapshot;
use App\Models\SearchProfile;
use App\Services\Archive\ListingArchivist;
use App\Services\Collectors\NineNinetyNineCollector;
use Illuminate\Support\Facades\Log;

class ListingPipeline
{
    public function __construct(
        private readonly PhoneNormalizer $normalizer,
        private readonly TelegramNotifier $telegramNotifier,
        private readonly ListingKindClassifier $kinds,
        private readonly ListingSubjectClassifier $subjects,
        private readonly SellerAnalyticsService $sellers,
        private readonly ListingDetailEnricher $enricher,
        private readonly NineNinetyNineCollector $collector,
        private readonly CollectorHealthMonitor $health,
        private readonly ListingArchivist $archivist,
        private readonly ListingScorer $scorer,
        private readonly ProfileMarketStats $profileStats,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  bool|null  $enrich  null — решаем по конфигу и по тому, что изменилось;
     *                             true/false — форсируем (импорт по URL уже несёт полную карточку).
     */
    public function ingest(array $payload, bool $notify = true, ?bool $enrich = null): ?Deal
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
                'search_profile_id' => $payload['search_profile_id'] ?? null,
                'url' => $payload['url'],
                'title' => $payload['title'],
                'site_model' => $payload['site_model'] ?? null,
                'description' => $payload['description'] ?? null,
                'price_original' => $payload['price_original'] ?? ($payload['price_mdl'] ?? null),
                'price_mdl' => $payload['price_mdl'] ?? ($payload['price_original'] ?? null),
                'currency' => $payload['currency'] ?? 'MDL',
                'seller_name' => $payload['seller_name'] ?? null,
                'seller_phone' => $payload['seller_phone'] ?? null,
                'seller_type' => $payload['seller_type'] ?? null,
                'listing_kind' => $payload['listing_kind']
                    ?? $this->kinds->classify(
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

        $wasRecentlyCreated = $listing->wasRecentlyCreated;
        $priceChanged = $listing->wasChanged('price_mdl');

        // Объявление могут снять в любой момент — сохраняем, что видели сейчас.
        if ($wasRecentlyCreated) {
            $this->archivist->snapshot($listing, ListingSnapshot::REASON_FIRST_SEEN);
        } elseif ($priceChanged) {
            $this->archivist->snapshot($listing, ListingSnapshot::REASON_PRICE_CHANGE);
        }

        // Снятое объявление снова в выдаче — значит живо.
        if ($listing->gone_at !== null) {
            $listing->forceFill(['gone_at' => null])->save();
        }

        if (! $listing->first_seen_at) {
            $listing->forceFill(['first_seen_at' => now()])->save();
        }

        // Authoritative seller text + region from ad page (search API body is often wrong/truncated).
        if ($this->shouldEnrich($listing, $enrich)) {
            try {
                $this->enricher->enrich($listing);
                $listing->refresh();
            } catch (\Throwable $e) {
                Log::warning('Detail enrich failed for '.$listing->external_id.': '.$e->getMessage());
            }
        }

        // Re-classify from live title/description (overrides stale kind).
        $this->kinds->apply($listing);

        // Запчасти, аксессуары и реплики нельзя мерить ценой самого товара.
        $this->subjects->apply($listing);

        $this->sellers->refreshListing($listing);

        $listing->refresh();

        $deal = $this->scorer->score($listing);

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

    /**
     * Обойти все включённые источники: у каждого своя категория, ключевые слова
     * и границы цены.
     *
     * @return array{fetched:int, ingested:int, deals:int, alerts:int, empty_streak:int, profiles:list<array<string, mixed>>}
     */
    public function collectAll(bool $notify = true): array
    {
        $profiles = SearchProfile::query()->active()->where('platform', '999')->get();
        $stats = ['fetched' => 0, 'ingested' => 0, 'deals' => 0, 'alerts' => 0, 'profiles' => []];

        foreach ($profiles as $profile) {
            $result = $this->collectProfile($profile, $notify);

            $stats['fetched'] += $result['fetched'];
            $stats['ingested'] += $result['ingested'];
            $stats['deals'] += $result['deals'];
            $stats['alerts'] += $result['alerts'];
            $stats['profiles'][] = ['name' => $profile->name] + $result;
        }

        $stats['empty_streak'] = $this->health->recordRun($stats['fetched']);

        return $stats;
    }

    /**
     * @return array{fetched:int, ingested:int, deals:int, alerts:int}
     */
    public function collectProfile(SearchProfile $profile, bool $notify = true): array
    {
        $items = $this->collector->collectForProfile($profile);
        $stats = ['fetched' => count($items), 'ingested' => 0, 'deals' => 0, 'alerts' => 0];

        foreach ($items as $item) {
            $deal = $this->ingest($item, $notify && $profile->notify);
            $stats['ingested']++;

            if ($deal) {
                $stats['deals']++;

                if ($deal->notified) {
                    $stats['alerts']++;
                }
            }
        }

        $profile->forceFill([
            'last_run_at' => now(),
            'last_found' => $stats['fetched'],
        ])->save();

        // Новые цены меняют медиану источника. Пока объявлений было мало, первые
        // карточки остались без оценки — пересчитываем источник целиком.
        $this->profileStats->forget($profile);

        if (! $profile->isPhones() && $stats['ingested'] > 0) {
            $this->scorer->rescoreProfile($profile);
        }

        return $stats;
    }

    /**
     * Открывать карточку объявления дорого (HTML + GraphQL на каждое), а шедулер
     * ходит каждые 3 минуты по одному и тому же списку. Поэтому обогащаем только
     * то, что появилось или изменилось, либо когда данных объективно не хватает.
     */
    private function shouldEnrich(Listing $listing, ?bool $force): bool
    {
        if ($force === false) {
            return false;
        }

        if (! config('dealwatch.collector.enrich')) {
            return false;
        }

        if ($force === true) {
            return true;
        }

        if (! config('dealwatch.collector.enrich_new_only')) {
            return true;
        }

        return $listing->wasRecentlyCreated
            || $listing->wasChanged(['title', 'price_mdl', 'price_original', 'currency'])
            || blank($listing->description)
            || blank($listing->model);
    }
}
