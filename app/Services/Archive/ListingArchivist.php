<?php

namespace App\Services\Archive;

use App\Models\Listing;
use App\Models\ListingSnapshot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Хранилище того, что мы видели: объявление исчезает с 999.md, а снимок остаётся.
 *
 * Лёгкий снимок (поля объявления) снимается автоматически, тяжёлый (фото и копия
 * страницы) — только для объявлений, которые пользователь сохранил себе.
 */
class ListingArchivist
{
    /**
     * @param  bool|null  $withMedia  null — решаем по настройкам и по тому, «своё» ли объявление
     */
    public function snapshot(Listing $listing, string $reason, ?bool $withMedia = null): ?ListingSnapshot
    {
        if (! config('dealwatch.archive.enabled')) {
            return null;
        }

        $withMedia ??= $this->shouldKeepMedia($listing);

        $snapshot = ListingSnapshot::create([
            'listing_id' => $listing->id,
            'reason' => $reason,
            'payload' => $this->payload($listing),
            'price_mdl' => $listing->price_mdl,
        ]);

        if ($withMedia) {
            $this->attachMedia($listing, $snapshot);
        }

        return $snapshot;
    }

    /** Полный архив по требованию пользователя: фото и копия страницы. */
    public function archive(Listing $listing): ListingSnapshot
    {
        $listing->forceFill(['archived' => true])->save();

        $snapshot = $this->snapshot($listing, ListingSnapshot::REASON_ARCHIVED, withMedia: true);

        return $snapshot ?? ListingSnapshot::create([
            'listing_id' => $listing->id,
            'reason' => ListingSnapshot::REASON_ARCHIVED,
            'payload' => $this->payload($listing),
            'price_mdl' => $listing->price_mdl,
        ]);
    }

    /** Отметить, что объявление пропало с площадки, сохранив последний снимок. */
    public function markGone(Listing $listing): void
    {
        if ($listing->gone_at === null) {
            $this->snapshot($listing, ListingSnapshot::REASON_GONE);
        }

        $listing->forceFill([
            'gone_at' => $listing->gone_at ?? now(),
            'status' => 'gone',
        ])->save();
    }

    /** Медиа храним только для «своих» объявлений: избранное, сделка, архив вручную. */
    public function shouldKeepMedia(Listing $listing): bool
    {
        if (! config('dealwatch.archive.enabled')) {
            return false;
        }

        if (! config('dealwatch.archive.media_for_saved_only')) {
            return true;
        }

        if ($listing->archived) {
            return true;
        }

        return $listing->deal()
            ->where(function ($q) {
                $q->where('is_favorite', true)
                    ->orWhereIn('user_status', ['bought', 'sold', 'completed']);
            })
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Listing $listing): array
    {
        return [
            'platform' => $listing->platform,
            'external_id' => $listing->external_id,
            'url' => $listing->url,
            'title' => $listing->title,
            'description' => $listing->description,
            'price_original' => $listing->price_original,
            'price_mdl' => $listing->price_mdl,
            'currency' => $listing->currency,
            'brand' => $listing->brand,
            'model' => $listing->model,
            'storage_gb' => $listing->storage_gb,
            'battery_health' => $listing->battery_health,
            'condition' => $listing->condition,
            'seller_type' => $listing->seller_type,
            'seller_name' => $listing->seller_name,
            'seller_phone' => $listing->seller_phone,
            'seller_listings_count' => $listing->seller_listings_count,
            'is_reseller' => $listing->is_reseller,
            'location' => $listing->location,
            'listing_kind' => $listing->listing_kind,
            'images' => $listing->images,
            'analyst_comment' => $listing->analyst_comment,
            'analyst_flags' => $listing->analyst_flags,
            'analyst_risk' => $listing->analyst_risk,
            'published_at' => optional($listing->published_at)->toIso8601String(),
            'first_seen_at' => optional($listing->first_seen_at)->toIso8601String(),
            'captured_at' => now()->toIso8601String(),
        ];
    }

    private function attachMedia(Listing $listing, ListingSnapshot $snapshot): void
    {
        $disk = Storage::disk((string) config('dealwatch.archive.disk'));
        $dir = "archive/{$listing->platform}/{$listing->external_id}/{$snapshot->id}";
        $paths = [];
        $bytes = 0;

        foreach (array_slice((array) ($listing->images ?? []), 0, (int) config('dealwatch.archive.max_images')) as $index => $url) {
            if (! is_string($url) || ! str_starts_with($url, 'http')) {
                continue;
            }

            try {
                $response = Http::timeout(20)->get($url);
            } catch (\Throwable $e) {
                Log::info('Archive image failed: '.$e->getMessage());

                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $body = $response->body();
            if (strlen($body) > (int) config('dealwatch.archive.max_image_bytes')) {
                continue;
            }

            $extension = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $path = $dir.'/image-'.($index + 1).'.'.$extension;
            $disk->put($path, $body);
            $paths[] = $path;
            $bytes += strlen($body);
        }

        $htmlPath = null;
        if (config('dealwatch.archive.keep_html') && filled($listing->url)) {
            try {
                $page = Http::timeout(25)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($listing->url);

                if ($page->successful()) {
                    // gzip: страницы 999.md весят по несколько мегабайт.
                    $compressed = gzencode($page->body(), 6);

                    if ($compressed !== false) {
                        $htmlPath = $dir.'/page.html.gz';
                        $disk->put($htmlPath, $compressed);
                        $bytes += strlen($compressed);
                    }
                }
            } catch (\Throwable $e) {
                Log::info('Archive page failed: '.$e->getMessage());
            }
        }

        $snapshot->forceFill([
            'image_paths' => $paths ?: null,
            'html_path' => $htmlPath,
            'size_bytes' => $bytes,
        ])->save();
    }
}
