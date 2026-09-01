<?php

namespace App\Services\Niche;

use App\Models\Listing;
use App\Models\ListingSnapshot;
use App\Models\SearchProfile;
use App\Services\Archive\ListingArchivist;
use App\Services\Collectors\NineNinetyNineCollector;
use App\Services\ListingPipeline;
use Illuminate\Support\Facades\Log;

/**
 * Перепись ниши: проходим каталог источника и отмечаем, что ещё висит.
 *
 * Именно это даёт главную метрику ниши — сколько живёт объявление до снятия.
 * Обычный сбор берёт только свежие объявления и о старых ничего не знает.
 */
class NicheScanner
{
    public function __construct(
        private readonly NineNinetyNineCollector $collector,
        private readonly ListingPipeline $pipeline,
        private readonly ListingArchivist $archivist,
    ) {}

    /**
     * @return array{seen:int, total:int, fresh:int, updated:int, price_changes:int, gone:int}
     */
    public function scan(SearchProfile $profile, ?int $depth = null): array
    {
        $depth = max(20, $depth ?? (int) $profile->scan_depth);
        $startedAt = now();

        $stats = ['seen' => 0, 'total' => 0, 'fresh' => 0, 'updated' => 0, 'price_changes' => 0, 'gone' => 0];

        $result = $this->collector->scanProfile($profile, $depth, function (array $batch) use (&$stats) {
            foreach ($batch as $item) {
                $listing = Listing::query()
                    ->where('platform', $item['platform'] ?? '999')
                    ->where('external_id', (string) $item['external_id'])
                    ->first();

                if (! $listing) {
                    // Новое объявление оформляем как обычно, но без похода на его
                    // страницу — иначе одна перепись стоила бы сотни запросов.
                    try {
                        $this->pipeline->ingest($item, notify: false, enrich: false);
                        $stats['fresh']++;
                    } catch (\Throwable $e) {
                        Log::warning('Niche scan ingest failed: '.$e->getMessage());
                    }

                    continue;
                }

                $newPrice = $item['price_mdl'] ?? null;
                $priceChanged = $newPrice !== null && (int) $newPrice !== (int) $listing->price_mdl;

                $listing->forceFill([
                    'last_seen_at' => now(),
                    'missed_scans' => 0,
                    'gone_at' => null,
                    'status' => 'active',
                    'price_mdl' => $newPrice ?? $listing->price_mdl,
                    'price_original' => $item['price_original'] ?? $listing->price_original,
                ])->save();

                $stats['updated']++;

                if ($priceChanged) {
                    // Движение цены — сигнал, что продавец торгуется, а ниша живая.
                    $this->archivist->snapshot($listing, ListingSnapshot::REASON_PRICE_CHANGE);
                    $stats['price_changes']++;
                }
            }
        });

        $stats['seen'] = $result['seen'];
        $stats['total'] = $result['total'];
        $stats['gone'] = $this->markMissing($profile, $startedAt, $result['seen']);

        $profile->forceFill([
            'last_scan_at' => now(),
            'last_scanned' => $result['seen'],
        ])->save();

        return $stats;
    }

    /**
     * Объявления, которые перепись не встретила, помечаем пропавшими — но только
     * со второго раза: одна неудачная страница не должна «продать» товар.
     */
    private function markMissing(SearchProfile $profile, \DateTimeInterface $startedAt, int $seen): int
    {
        // Если перепись ничего не нашла, доверять её результату нельзя.
        if ($seen === 0) {
            return 0;
        }

        $gone = 0;

        Listing::query()
            ->where('search_profile_id', $profile->id)
            ->where('status', 'active')
            ->whereNull('gone_at')
            ->where(function ($q) use ($startedAt) {
                $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $startedAt);
            })
            ->chunkById(200, function ($listings) use (&$gone) {
                foreach ($listings as $listing) {
                    $missed = (int) $listing->missed_scans + 1;
                    $listing->forceFill(['missed_scans' => $missed])->save();

                    if ($missed >= 2) {
                        $this->archivist->markGone($listing);
                        $gone++;
                    }
                }
            });

        return $gone;
    }
}
