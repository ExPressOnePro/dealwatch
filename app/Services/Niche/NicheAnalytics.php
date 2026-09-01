<?php

namespace App\Services\Niche;

use App\Models\Listing;
use App\Models\ListingSnapshot;
use App\Models\SearchProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Аналитика нишы: есть ли в ней оборот и можно ли на ней зарабатывать.
 *
 * Главный сигнал — не количество объявлений, а скорость их исчезновения:
 * объявление ушло с площадки, значит товар, скорее всего, продан. Отсюда
 * считаются оборачиваемость, запас маржи и «живая/мёртвая» ниша.
 */
class NicheAnalytics
{
    /**
     * @return array<string, mixed>
     */
    public function forProfile(SearchProfile $profile, int $days = 30): array
    {
        $since = now()->subDays($days);
        $previousSince = now()->subDays($days * 2);

        $listings = Listing::query()
            ->where('search_profile_id', $profile->id)
            ->get([
                'id', 'title', 'price_mdl', 'seller_key', 'seller_type', 'is_reseller',
                'listing_kind', 'subject', 'is_replica', 'status', 'first_seen_at', 'gone_at',
                'last_seen_at', 'published_at',
            ]);

        $sell = $listings->filter(fn (Listing $l) => $l->listing_kind !== 'want_buy');
        // Запчасти, аксессуары и реплики не входят ни в рынок, ни в оборот ниши.
        $nonItems = $sell->filter(fn (Listing $l) => ! $l->isRealItem());
        $sell = $sell->filter(fn (Listing $l) => $l->isRealItem());
        $active = $sell->filter(fn (Listing $l) => $l->gone_at === null && $l->status === 'active');
        $gone = $sell->filter(fn (Listing $l) => $l->gone_at !== null);

        $inflow = $sell->filter(fn (Listing $l) => $l->first_seen_at && $l->first_seen_at >= $since);
        $outflow = $gone->filter(fn (Listing $l) => $l->gone_at >= $since);

        $lifespans = $outflow
            ->map(fn (Listing $l) => $l->first_seen_at ? (int) $l->first_seen_at->diffInDays($l->gone_at) : null)
            ->filter(fn (?int $days) => $days !== null)
            ->values();

        $prices = $active->pluck('price_mdl')->filter()->map(fn ($v) => (int) $v)->sort()->values();
        $previousPrices = $sell
            ->filter(fn (Listing $l) => $l->first_seen_at && $l->first_seen_at >= $previousSince && $l->first_seen_at < $since)
            ->pluck('price_mdl')->filter()->map(fn ($v) => (int) $v)->sort()->values();

        $median = $this->percentile($prices, 50);
        $p25 = $this->percentile($prices, 25);
        $p75 = $this->percentile($prices, 75);

        $sellThrough = ($outflow->count() + $active->count()) > 0
            ? round($outflow->count() / ($outflow->count() + $active->count()) * 100, 1)
            : null;

        $overhead = (int) config('dealwatch.economics.prep_cost') + (int) config('dealwatch.economics.risk_reserve');
        $marginPotential = ($median !== null && $p25 !== null) ? max(0, $median - $p25 - $overhead) : null;

        // Считаем по дате последнего обновления на площадке, а не по тому,
        // когда мы сами впервые увидели объявление.
        $staleAfter = (int) config('dealwatch.staleness.suspect_days');
        $stale = $active->filter(function (Listing $l) use ($staleAfter) {
            $updatedOn = $l->published_at ?? $l->first_seen_at;

            return $updatedOn && $updatedOn->diffInDays(now()) >= $staleAfter;
        });
        $medianLifespan = $lifespans->isNotEmpty() ? $this->percentile($lifespans->sort()->values(), 50) : null;

        // Сколько времени мы вообще наблюдаем нишу: за один день никто не успеет
        // ничего продать, и «мёртвой» её называть нечестно.
        $firstSeen = $sell->pluck('first_seen_at')->filter()->min();
        $observationDays = $firstSeen ? (int) $firstSeen->diffInDays(now()) : 0;

        return [
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'description' => $profile->describe(),
                'scoring' => $profile->scoring,
                'last_scan_at' => optional($profile->last_scan_at)->toIso8601String(),
                'last_scanned' => $profile->last_scanned,
            ],
            'period_days' => $days,
            'observation_days' => $observationDays,
            'volume' => [
                'total' => $sell->count(),
                'active' => $active->count(),
                'gone_total' => $gone->count(),
                'inflow' => $inflow->count(),
                'outflow' => $outflow->count(),
                'inflow_per_week' => round($inflow->count() / max(1, $days / 7), 1),
                'outflow_per_week' => round($outflow->count() / max(1, $days / 7), 1),
                'want_buy' => $listings->filter(fn (Listing $l) => $l->listing_kind === 'want_buy')->count(),
                'non_items' => $nonItems->count(),
            ],
            'speed' => [
                'sell_through_percent' => $sellThrough,
                'median_days_to_gone' => $medianLifespan,
                'fast_share_percent' => $lifespans->isNotEmpty()
                    ? round($lifespans->filter(fn (int $d) => $d <= 7)->count() / $lifespans->count() * 100, 1)
                    : null,
                'stale_days_threshold' => $staleAfter,
                'stale_listings' => $stale->count(),
                'stale_share_percent' => $active->count() > 0
                    ? round($stale->count() / $active->count() * 100, 1)
                    : null,
            ],
            'prices' => [
                'samples' => $prices->count(),
                'p25' => $p25,
                'median' => $median,
                'p75' => $p75,
                'spread_percent' => ($median && $p25 && $p75) ? round(($p75 - $p25) / $median * 100, 1) : null,
                'median_previous_period' => $this->percentile($previousPrices, 50),
                'median_change_percent' => $this->change($this->percentile($previousPrices, 50), $median),
                'margin_potential' => $marginPotential,
                'margin_note' => $marginPotential !== null
                    ? sprintf(
                        'Купить по нижнему квартилю %s и продать по медиане %s: %s MDL за вычетом %s подготовки и резерва.',
                        number_format((int) $p25, 0, '.', ' '),
                        number_format((int) $median, 0, '.', ' '),
                        number_format($marginPotential, 0, '.', ' '),
                        number_format($overhead, 0, '.', ' ')
                    )
                    : 'Мало активных объявлений с ценой, чтобы считать запас маржи.',
            ],
            'sellers' => $this->sellerMix($sell),
            'price_moves' => $this->priceMoves($profile),
            'weekly' => $this->weekly($sell, $days),
            'top_sellers' => $this->topSellers($sell),
            'repeats' => $this->repeats($sell),
            'hints' => $this->hints($sell, $active, $prices, $profile, $nonItems),
            'verdict' => $this->verdict(
                $outflow->count(),
                $inflow->count(),
                $active->count(),
                $observationDays,
                $sellThrough,
                $medianLifespan,
                $profile
            ),
        ];
    }

    /**
     * @param  Collection<int, Listing>  $listings
     * @return array<string, mixed>
     */
    private function sellerMix(Collection $listings): array
    {
        $accounts = $listings->pluck('seller_key')->filter()->unique();
        $resellerListings = $listings->filter(fn (Listing $l) => (bool) $l->is_reseller);
        $shopListings = $listings->filter(fn (Listing $l) => $l->seller_type === 'shop');

        return [
            'accounts' => $accounts->count(),
            'reseller_listings' => $resellerListings->count(),
            'reseller_share_percent' => $listings->count() > 0
                ? round($resellerListings->count() / $listings->count() * 100, 1)
                : null,
            'shop_listings' => $shopListings->count(),
            'shop_share_percent' => $listings->count() > 0
                ? round($shopListings->count() / $listings->count() * 100, 1)
                : null,
            'listings_per_account' => $accounts->count() > 0
                ? round($listings->count() / $accounts->count(), 1)
                : null,
        ];
    }

    /**
     * Насколько активно продавцы двигают цены — признак живого торга.
     *
     * @return array<string, mixed>
     */
    private function priceMoves(SearchProfile $profile): array
    {
        $snapshots = ListingSnapshot::query()
            ->whereIn('listing_id', Listing::query()->where('search_profile_id', $profile->id)->select('id'))
            ->whereNotNull('price_mdl')
            ->orderBy('listing_id')
            ->orderBy('id')
            ->get(['listing_id', 'price_mdl']);

        $byListing = $snapshots->groupBy('listing_id')->filter(fn ($group) => $group->count() > 1);
        $cuts = [];

        foreach ($byListing as $group) {
            $first = (int) $group->first()->price_mdl;
            $last = (int) $group->last()->price_mdl;

            if ($first > 0 && $last < $first) {
                $cuts[] = round(($first - $last) / $first * 100, 1);
            }
        }

        return [
            'tracked_listings' => $byListing->count(),
            'with_price_cut' => count($cuts),
            'avg_cut_percent' => $cuts !== [] ? round(array_sum($cuts) / count($cuts), 1) : null,
            'max_cut_percent' => $cuts !== [] ? max($cuts) : null,
        ];
    }

    /**
     * @param  Collection<int, Listing>  $listings
     * @return list<array<string, mixed>>
     */
    private function weekly(Collection $listings, int $days): array
    {
        $weeks = (int) ceil($days / 7);
        $series = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $from = now()->subDays(($i + 1) * 7);
            $to = now()->subDays($i * 7);

            $series[] = [
                'label' => $from->format('d.m'),
                'inflow' => $listings->filter(fn (Listing $l) => $l->first_seen_at && $l->first_seen_at >= $from && $l->first_seen_at < $to)->count(),
                'outflow' => $listings->filter(fn (Listing $l) => $l->gone_at && $l->gone_at >= $from && $l->gone_at < $to)->count(),
            ];
        }

        return $series;
    }

    /**
     * Кто в нише торгует потоком: сколько выставил, сколько уже ушло и как быстро.
     *
     * @param  Collection<int, Listing>  $listings
     * @return list<array<string, mixed>>
     */
    private function topSellers(Collection $listings, int $limit = 10): array
    {
        return $listings
            ->filter(fn (Listing $l) => filled($l->seller_key))
            ->groupBy('seller_key')
            ->map(function (Collection $group, string $key) {
                $gone = $group->filter(fn (Listing $l) => $l->gone_at !== null);
                $lifespans = $gone
                    ->map(fn (Listing $l) => $l->first_seen_at ? (int) $l->first_seen_at->diffInDays($l->gone_at) : null)
                    ->filter(fn (?int $d) => $d !== null)
                    ->values();

                return [
                    'seller_key' => $key,
                    'listings' => $group->count(),
                    'gone' => $gone->count(),
                    'active' => $group->count() - $gone->count(),
                    'is_reseller' => (bool) $group->first()->is_reseller,
                    'seller_type' => $group->first()->seller_type,
                    'median_days_to_gone' => $lifespans->isNotEmpty()
                        ? $this->percentile($lifespans->sort()->values(), 50)
                        : null,
                    'median_price' => $this->percentile(
                        $group->pluck('price_mdl')->filter()->map(fn ($v) => (int) $v)->sort()->values(),
                        50
                    ),
                ];
            })
            ->sortByDesc('listings')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Одно и то же объявление, выставленное повторно с того же аккаунта:
     * товар не продался, а был переставлен — это гасит оптимизм по нише.
     *
     * @param  Collection<int, Listing>  $listings
     * @return array<string, mixed>
     */
    private function repeats(Collection $listings, int $limit = 8): array
    {
        $groups = $listings
            ->filter(fn (Listing $l) => filled($l->seller_key) && filled($l->title))
            ->groupBy(fn (Listing $l) => $l->seller_key.'|'.$this->titleKey($l->title))
            ->filter(fn (Collection $group) => $group->count() > 1);

        $items = $groups
            ->map(function (Collection $group) {
                $sorted = $group->sortBy('first_seen_at')->values();
                $first = $sorted->first();
                $last = $sorted->last();

                return [
                    'title' => $first->title,
                    'times' => $group->count(),
                    'seller_type' => $first->seller_type,
                    'is_reseller' => (bool) $first->is_reseller,
                    'first_seen' => optional($first->first_seen_at)->toDateString(),
                    'last_seen' => optional($last->last_seen_at ?? $last->first_seen_at)->toDateString(),
                    'prices' => $group->pluck('price_mdl')->filter()->map(fn ($v) => (int) $v)->unique()->sort()->values()->all(),
                ];
            })
            ->sortByDesc('times')
            ->take($limit)
            ->values()
            ->all();

        return [
            'groups' => $groups->count(),
            'listings' => $groups->flatten(1)->count(),
            'share_percent' => $listings->count() > 0
                ? round($groups->flatten(1)->count() / $listings->count() * 100, 1)
                : null,
            'items' => $items,
        ];
    }

    private function titleKey(string $title): string
    {
        $normalised = Str::of($title)
            ->lower()
            ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')
            ->squish()
            ->toString();

        return implode(' ', array_slice(explode(' ', $normalised), 0, 6));
    }

    /**
     * Подсказки по настройке источника: обобщённый режим считает рынок по медиане,
     * поэтому смесь разных товаров в одном источнике делает цифры бессмысленными.
     *
     * @param  Collection<int, Listing>  $listings
     * @param  Collection<int, Listing>  $active
     * @param  Collection<int, int>  $prices
     * @return list<array{type: string, text: string}>
     */
    private function hints(
        Collection $listings,
        Collection $active,
        Collection $prices,
        SearchProfile $profile,
        Collection $nonItems
    ): array {
        $hints = [];

        if ($nonItems->isNotEmpty()) {
            $hints[] = [
                'type' => 'non_items',
                'text' => sprintf(
                    'Отброшено как «не сам товар»: %d объявлений (%s). Они не входят в рынок и не получают вердикт — '
                    .'если таких много, добавь их слова в стоп-список источника.',
                    $nonItems->count(),
                    $nonItems->take(2)->map(fn (Listing $l) => '«'.mb_substr((string) $l->title, 0, 34).'»')->implode(', ')
                ),
            ];
        }

        $p25 = $this->percentile($prices, 25);
        $p75 = $this->percentile($prices, 75);

        if ($profile->isPhones() === false && $p25 && $p75 && $p75 >= $p25 * 2) {
            $hints[] = [
                'type' => 'wide_spread',
                'text' => sprintf(
                    'Цены расходятся втрое (%s → %s): похоже, источник собирает разные товары. '
                    .'Сузь ключевые слова или заведи отдельный источник на каждую модель — иначе медиана ни о чём.',
                    number_format($p25, 0, '.', ' '),
                    number_format($p75, 0, '.', ' ')
                ),
            ];
        }

        if ($active->count() < (int) config('dealwatch.generic.min_samples')) {
            $hints[] = [
                'type' => 'too_few',
                'text' => 'Активных объявлений мало — рынок посчитать не на чем. Расширь ключевые слова или сними ограничение по цене.',
            ];
        }

        if (! $profile->last_scan_at) {
            $hints[] = [
                'type' => 'no_scan',
                'text' => 'Перепись каталога ещё не запускалась: без неё не видно, как быстро уходят объявления.',
            ];
        }

        return $hints;
    }

    /**
     * @return array{level: string, label: string, note: string}
     */
    private function verdict(
        int $outflow,
        int $inflow,
        int $active,
        int $observationDays,
        ?float $sellThrough,
        ?int $medianLifespan,
        SearchProfile $profile
    ): array {
        if (! $profile->last_scan_at) {
            return [
                'level' => 'unknown',
                'label' => 'Нет данных',
                'note' => 'Перепись каталога ещё не запускалась: нажми «Сканировать нишу», чтобы измерить скорость продаж.',
            ];
        }

        // Пока ниша наблюдается меньше недели, отсутствие продаж ничего не значит.
        if ($observationDays < 7 && $outflow === 0) {
            return [
                'level' => 'unknown',
                'label' => 'Собираем данные',
                'note' => sprintf(
                    'Ниша наблюдается %d дн. Чтобы измерить скорость продаж, нужна хотя бы неделя переписей.',
                    $observationDays
                ),
            ];
        }

        // Ни движений, ни объявлений — судить не о чем. А вот полка, забитая
        // объявлениями без единой продажи, — уже приговор нише.
        if ($outflow < 3 && $inflow < 5 && $active < 5) {
            return [
                'level' => 'unknown',
                'label' => 'Мало данных',
                'note' => 'Пока слишком мало движений, чтобы судить. Нужно 2–3 переписи подряд.',
            ];
        }

        if ($sellThrough !== null && $sellThrough >= 35 && ($medianLifespan === null || $medianLifespan <= 14)) {
            return [
                'level' => 'hot',
                'label' => 'Живая ниша',
                'note' => sprintf(
                    'Уходит %s%% выставленного%s. Товар оборачивается быстро — здесь можно работать.',
                    $sellThrough,
                    $medianLifespan !== null ? ', медиана '.$medianLifespan.' дн.' : ''
                ),
            ];
        }

        if ($sellThrough !== null && $sellThrough >= 15) {
            return [
                'level' => 'warm',
                'label' => 'Вялая ниша',
                'note' => sprintf(
                    'Уходит только %s%% объявлений%s. Работать можно, но деньги будут лежать в товаре.',
                    $sellThrough,
                    $medianLifespan !== null ? ', медиана '.$medianLifespan.' дн.' : ''
                ),
            ];
        }

        return [
            'level' => 'cold',
            'label' => 'Мёртвая ниша',
            'note' => 'Объявления висят и почти не уходят: спроса нет, вложенные деньги застрянут.',
        ];
    }

    private function change(?int $before, ?int $after): ?float
    {
        if (! $before || ! $after) {
            return null;
        }

        return round(($after - $before) / $before * 100, 1);
    }

    /**
     * @param  Collection<int, int>  $sorted
     */
    private function percentile(Collection $sorted, float $percent): ?int
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        $values = $sorted->values();
        $index = (int) floor(($values->count() - 1) * ($percent / 100));

        return (int) $values->get($index);
    }
}
