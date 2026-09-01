<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\MarketPrice;
use Illuminate\Support\Collection;

class MarketEvidenceService
{
    /**
     * Concrete, human-readable foundation for a market price row.
     *
     * @return array{
     *     headline: string,
     *     steps: list<array{title: string, detail: string}>,
     *     calc: array{market_mid: int, buy_max_rule: string, buy_max: int, buy_min: int, sell_low: int, sell_high: int},
     *     observed: array{count: int, min: ?int, median: ?int, max: ?int, private_median: ?int, samples: list<array<string, mixed>>},
     *     confidence: string,
     *     confidence_note: string
     * }
     */
    public function for(MarketPrice $price): array
    {
        $mid = $price->marketMid();
        $basis = $price->basis ?? [];

        $observed = $this->observedListings($price);
        $privateListings = $observed->where('seller_type', 'private');
        $shopListings = $observed->where('seller_type', 'shop');
        $prices = $observed->pluck('price_mdl')->filter()->sort()->values();
        $private = $privateListings->pluck('price_mdl')->filter()->sort()->values();
        $shop = $shopListings->pluck('price_mdl')->filter()->sort()->values();

        $median = $this->median($prices);
        $privateMedian = $this->median($private);
        $shopMedian = $this->median($shop);

        $steps = [
            [
                'title' => '1. Что считаем «рынком продажи»',
                'detail' => 'Цена, за которую частник в Молдове реально продаёт за 1–7 дней на 999.md. '
                    .'Магазинную витрину, trade-in и объявления «куплю» не берём в основание — магазины считаем отдельно, «куплю» это спрос.',
            ],
            [
                'title' => '2. Диапазон продажи (sell_low → sell_high)',
                'detail' => ($basis['anchor'] ?? 'Ориентир частного рынка 999.md')
                    .'. В таблице: '
                    .number_format($price->sell_low, 0, '.', ' ').'–'
                    .number_format($price->sell_high, 0, '.', ' ').' MDL.',
            ],
            [
                'title' => '3. Рыночная цена (market_mid), от которой считается сделка',
                'detail' => 'market_mid = (sell_low + sell_high) / 2 = ('
                    .number_format($price->sell_low, 0, '.', ' ')
                    .' + '.number_format($price->sell_high, 0, '.', ' ')
                    .') / 2 = '
                    .number_format($mid, 0, '.', ' ')
                    .' MDL. Именно эту цифру DealWatch сравнивает с ценой объявления.',
            ],
            [
                'title' => '4. Потолок покупки (buy_max)',
                'detail' => ($basis['buy_rule'] ?? 'Покупка на −15…−20% ниже mid, чтобы после подготовки осталась маржа ≥1500 MDL')
                    .'. В таблице buy_max = '
                    .number_format($price->buy_max, 0, '.', ' ')
                    .' MDL; «забирать сразу» buy_min = '
                    .number_format($price->buy_min, 0, '.', ' ')
                    .' MDL.',
            ],
        ];

        if ($privateMedian) {
            $steps[] = [
                'title' => '5. Проверка по живым объявлениям в нашей базе',
                'detail' => 'Сейчас в базе '.$private->count()
                    .' частных sell-объявлений этой модели; медиана частников = '
                    .number_format($privateMedian, 0, '.', ' ')
                    .' MDL. Магазины: n='.$shop->count()
                    .($shopMedian ? ', медиана '.number_format($shopMedian, 0, '.', ' ').' MDL' : '')
                    .' (считаются отдельно, в mid не входят). Ориентир mid '
                    .number_format($mid, 0, '.', ' ')
                    .' MDL '
                    .($this->closeEnough($mid, $privateMedian)
                        ? 'согласуется с частниками.'
                        : 'пока расходится с частниками — сверяй вручную.'),
            ];
        } else {
            $steps[] = [
                'title' => '5. Проверка по живым объявлениям',
                'detail' => $private->isEmpty() && $shop->isEmpty()
                    ? 'Пока нет sell-объявлений этой модели в базе (куплю исключены). После сборов здесь появятся частники и магазины раздельно.'
                    : 'Частников sell мало (n='.$private->count().'). Магазинов: '.$shop->count()
                        .($shopMedian ? ', медиана витрины '.number_format($shopMedian, 0, '.', ' ').' MDL' : '')
                        .'. Основание рынка — только private.',
            ];
        }

        if (! empty($basis['notes'])) {
            $steps[] = [
                'title' => '6. Доп. условие по модели',
                'detail' => (string) $basis['notes'],
            ];
        }

        $confidence = match (true) {
            $private->count() >= 5 && $this->closeEnough($mid, $privateMedian) => 'высокая',
            $private->count() >= 2 || $prices->count() >= 3 => 'средняя',
            default => 'экспертная',
        };

        $confidenceNote = match ($confidence) {
            'высокая' => 'Ориентир подтверждён несколькими частными объявлениями в базе.',
            'средняя' => 'Есть наблюдения, но выборка ещё небольшая — цифру можно уточнять.',
            default => 'Пока мало живых точек в базе; цена задана как рабочий ориентир частного рынка и будет уточняться сборами.',
        };

        $distribution = $this->buildDistribution($price, $observed, $prices, $private, $shop);

        if ($distribution['total_private'] > 0) {
            $sellBand = collect($distribution['zones'])->firstWhere('key', 'sell_band') ?? [];
            $inBand = (int) ($sellBand['private'] ?? 0);
            $steps[] = [
                'title' => ($basis['notes'] ? '7' : '6').'. Сколько объявлений в каких ценах',
                'detail' => 'Из '.$distribution['total_private'].' частников (sell): '
                    .$inBand.' шт. в полосе продажи sell_low–sell_high. '
                    .'Магазинов в тех же зонах: '.($distribution['total_shop'] ?? 0)
                    .' (медиана витрины '
                    .($distribution['shop_median'] ? number_format($distribution['shop_median'], 0, '.', ' ').' MDL' : '—')
                    .').',
            ];
        }

        return [
            'headline' => $basis['anchor']
                ?? ('Частный рынок 999.md → mid '.number_format($mid, 0, '.', ' ').' MDL'),
            'steps' => $steps,
            'calc' => [
                'market_mid' => $mid,
                'buy_max_rule' => '≈ mid − 15…20% и запас на подготовку/риск',
                'buy_max' => $price->buy_max,
                'buy_min' => $price->buy_min,
                'sell_low' => $price->sell_low,
                'sell_high' => $price->sell_high,
            ],
            'observed' => [
                'count' => $private->count(),
                'private_count' => $private->count(),
                'shop_count' => $shop->count(),
                'min' => $private->first(),
                'median' => $privateMedian,
                'max' => $private->last(),
                'private_median' => $privateMedian,
                'shop_median' => $shopMedian,
                'samples' => $privateListings->sortBy('price_mdl')->take(12)->values()->map(fn (Listing $l) => [
                    'id' => $l->id,
                    'title' => $l->title,
                    'price_original' => $l->price_original,
                    'price_mdl' => $l->price_mdl ?? $l->price,
                    'currency' => $l->currency,
                    'seller_type' => $l->seller_type,
                    'url' => $l->url,
                ])->values()->all(),
                'shop_samples' => $shopListings->sortBy('price_mdl')->take(8)->values()->map(fn (Listing $l) => [
                    'id' => $l->id,
                    'title' => $l->title,
                    'price_original' => $l->price_original,
                    'price_mdl' => $l->price_mdl ?? $l->price,
                    'currency' => $l->currency,
                    'seller_type' => $l->seller_type,
                    'url' => $l->url,
                ])->values()->all(),
            ],
            'distribution' => $distribution,
            'confidence' => $confidence,
            'confidence_note' => $confidenceNote,
        ];
    }

    /**
     * Compact «сколько объявлений в каких ценах» for deal cards.
     * Lightweight: only price + seller_type, cached per market row.
     *
     * @return array{
     *     total_private: int,
     *     total_shop: int,
     *     private_median: ?int,
     *     buy_min: int,
     *     buy_max: int,
     *     sell_low: int,
     *     sell_high: int,
     *     mid: int,
     *     zones: list<array{key: string, short_label: string, from: ?int, to: ?int, tone: string, all: int, private: int, shop: int}>,
     *     ask_zone: ?string,
     *     ask_price: ?int
     * }|null
     */
    public function zonesMini(MarketPrice $price, ?int $askPriceMdl = null): ?array
    {
        $cacheKey = 'market_zones_mini:v2:'.$price->id.':'.optional($price->updated_at)?->timestamp;

        /** @var array<string, mixed>|null $base */
        $base = \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(10), function () use ($price) {
            return $this->buildZonesMiniBase($price);
        });

        if ($base === null) {
            return null;
        }

        $askZone = null;
        if ($askPriceMdl && $askPriceMdl > 0) {
            $askZone = $this->zoneKeyForPrice(
                $askPriceMdl,
                (int) $price->buy_min,
                (int) $price->buy_max,
                (int) $price->sell_low,
                (int) $price->sell_high,
            );
        }

        return array_merge($base, [
            'ask_zone' => $askZone,
            'ask_price' => $askPriceMdl && $askPriceMdl > 0 ? $askPriceMdl : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildZonesMiniBase(MarketPrice $price): ?array
    {
        $buyMin = (int) $price->buy_min;
        $buyMax = (int) $price->buy_max;
        $sellLow = (int) $price->sell_low;
        $sellHigh = (int) $price->sell_high;

        $rows = Listing::query()
            ->where('brand', $price->brand)
            ->where('model', $price->model)
            ->when($price->storage_gb, fn ($q) => $q->where('storage_gb', $price->storage_gb))
            ->marketSell()
            ->whereNotNull('model')
            ->where('parse_confidence', '>=', 0.55)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('is_bait', false)->orWhereNull('is_bait');
            })
            ->where(function ($q) {
                $q->where('price_mdl', '>=', 300)->orWhere('price', '>=', 300);
            })
            ->select(['price_mdl', 'price', 'seller_type'])
            ->limit(800)
            ->get()
            ->map(fn (Listing $l) => [
                'price' => (int) ($l->price_mdl ?? $l->price ?? 0),
                'seller_type' => $l->seller_type === 'shop' ? 'shop' : 'private',
            ])
            ->filter(fn (array $r) => $r['price'] > 0)
            ->values();

        if ($rows->isEmpty()) {
            return null;
        }

        $zoneDefs = [
            'below_buy_min' => ['short' => 'Ниже buy_min', 'from' => null, 'to' => $buyMin, 'tone' => 'danger'],
            'buy_zone' => ['short' => 'Зона покупки', 'from' => $buyMin, 'to' => $buyMax, 'tone' => 'buy'],
            'between' => ['short' => 'Между зонами', 'from' => $buyMax, 'to' => $sellLow, 'tone' => 'neutral'],
            'sell_band' => ['short' => 'Рынок продажи', 'from' => $sellLow, 'to' => $sellHigh, 'tone' => 'market'],
            'above_sell' => ['short' => 'Выше рынка', 'from' => $sellHigh, 'to' => null, 'tone' => 'high'],
        ];

        $zones = [];
        foreach ($zoneDefs as $key => $def) {
            $inZone = $rows->filter(
                fn (array $r) => $this->listingInZone($r['price'], $key, $buyMin, $buyMax, $sellLow, $sellHigh)
            );
            $private = $inZone->where('seller_type', 'private')->count();
            $shop = $inZone->where('seller_type', 'shop')->count();
            $zones[] = [
                'key' => $key,
                'short_label' => $def['short'],
                'from' => $def['from'],
                'to' => $def['to'],
                'tone' => $def['tone'],
                'all' => $private + $shop,
                'private' => $private,
                'shop' => $shop,
            ];
        }

        $privatePrices = $rows->where('seller_type', 'private')->pluck('price')->sort()->values();
        $shopCount = $rows->where('seller_type', 'shop')->count();

        return [
            'total_private' => $privatePrices->count(),
            'total_shop' => $shopCount,
            'private_median' => $this->median($privatePrices),
            'buy_min' => $buyMin,
            'buy_max' => $buyMax,
            'sell_low' => $sellLow,
            'sell_high' => $sellHigh,
            'mid' => $price->marketMid(),
            'zones' => $zones,
        ];
    }

    public function zoneKeyForPrice(int $p, int $buyMin, int $buyMax, int $sellLow, int $sellHigh): ?string
    {
        foreach (['below_buy_min', 'buy_zone', 'between', 'sell_band', 'above_sell'] as $key) {
            if ($this->listingInZone($p, $key, $buyMin, $buyMax, $sellLow, $sellHigh)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Price-band counts so the dealer sees density around foundation numbers.
     *
     * @param  Collection<int, Listing>  $observed
     * @param  Collection<int, mixed>  $prices
     * @param  Collection<int, mixed>  $private
     * @return array<string, mixed>
     */
    private function buildDistribution(
        MarketPrice $price,
        Collection $observed,
        Collection $prices,
        Collection $private,
        Collection $shop,
    ): array {
        $buyMin = (int) $price->buy_min;
        $buyMax = (int) $price->buy_max;
        $sellLow = (int) $price->sell_low;
        $sellHigh = (int) $price->sell_high;
        $mid = $price->marketMid();

        $zoneDefs = [
            'below_buy_min' => [
                'label' => 'Ниже buy_min (дамп / кликбейт / битые)',
                'short' => 'Ниже buy_min',
                'from' => null,
                'to' => $buyMin,
                'tone' => 'danger',
            ],
            'buy_zone' => [
                'label' => 'Зона покупки buy_min → buy_max',
                'short' => 'Зона покупки',
                'from' => $buyMin,
                'to' => $buyMax,
                'tone' => 'buy',
            ],
            'between' => [
                'label' => 'Между buy_max и sell_low',
                'short' => 'Между зонами',
                'from' => $buyMax,
                'to' => $sellLow,
                'tone' => 'neutral',
            ],
            'sell_band' => [
                'label' => 'Рынок продажи sell_low → sell_high',
                'short' => 'Рынок продажи',
                'from' => $sellLow,
                'to' => $sellHigh,
                'tone' => 'market',
            ],
            'above_sell' => [
                'label' => 'Выше sell_high (дорого / витрина)',
                'short' => 'Выше рынка',
                'from' => $sellHigh,
                'to' => null,
                'tone' => 'high',
            ],
        ];

        $zones = [];
        foreach ($zoneDefs as $key => $def) {
            $zoneListings = $observed
                ->filter(fn (Listing $l) => $this->listingInZone(
                    (int) ($l->price_mdl ?? 0),
                    $key,
                    $buyMin,
                    $buyMax,
                    $sellLow,
                    $sellHigh
                ))
                ->sortBy('price_mdl')
                ->values()
                ->map(fn (Listing $l) => [
                    'id' => $l->id,
                    'title' => $l->title,
                    'price_mdl' => (int) ($l->price_mdl ?? $l->price),
                    'price_original' => $l->price_original,
                    'currency' => $l->currency,
                    'seller_type' => $l->seller_type,
                    'url' => $l->url,
                    'is_bait' => (bool) $l->is_bait,
                ])
                ->all();

            $zones[$key] = [
                'key' => $key,
                'label' => $def['label'],
                'short_label' => $def['short'],
                'from' => $def['from'],
                'to' => $def['to'],
                'tone' => $def['tone'],
                'all' => count($zoneListings),
                'private' => collect($zoneListings)->where('seller_type', 'private')->count(),
                'shop' => collect($zoneListings)->where('seller_type', 'shop')->count(),
                'listings' => $zoneListings,
            ];
        }

        $histogram = $this->histogramBuckets(
            $private->isNotEmpty() ? $private : $prices,
            $sellLow,
            $sellHigh,
            $mid
        );

        $totalPrivate = $private->count();
        $totalShop = $shop->count();

        return [
            'total_all' => $totalPrivate + $totalShop,
            'total_private' => $totalPrivate,
            'total_shop' => $totalShop,
            'shop_median' => $this->median($shop),
            'zones' => array_values($zones),
            'histogram' => $histogram,
            'share_in_sell_band_private' => $totalPrivate > 0
                ? round(100 * (collect($zones)->firstWhere('key', 'sell_band')['private'] / $totalPrivate), 1)
                : 0,
            'share_in_buy_zone_private' => $totalPrivate > 0
                ? round(100 * (collect($zones)->firstWhere('key', 'buy_zone')['private'] / $totalPrivate), 1)
                : 0,
            'share_in_sell_band_shop' => $totalShop > 0
                ? round(100 * (collect($zones)->firstWhere('key', 'sell_band')['shop'] / $totalShop), 1)
                : 0,
        ];
    }

    private function listingInZone(
        int $p,
        string $key,
        int $buyMin,
        int $buyMax,
        int $sellLow,
        int $sellHigh,
    ): bool {
        return match ($key) {
            'below_buy_min' => $p < $buyMin,
            'buy_zone' => $p >= $buyMin && $p < $buyMax,
            'between' => $p >= $buyMax && $p < $sellLow,
            'sell_band' => $p >= $sellLow && $p <= $sellHigh,
            'above_sell' => $p > $sellHigh,
            default => false,
        };
    }

    /**
     * @param  Collection<int, mixed>  $values
     */
    private function countZone(
        Collection $values,
        string $key,
        int $buyMin,
        int $buyMax,
        int $sellLow,
        int $sellHigh,
    ): int {
        return $values->filter(
            fn ($v) => $this->listingInZone((int) $v, $key, $buyMin, $buyMax, $sellLow, $sellHigh)
        )->count();
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return list<array{label: string, from: int, to: int, count: int, in_sell_band: bool, in_buy_zone: bool}>
     */
    private function histogramBuckets(Collection $values, int $sellLow, int $sellHigh, int $mid): array
    {
        if ($values->isEmpty()) {
            return [];
        }

        $min = (int) $values->first();
        $max = (int) $values->last();
        if ($max <= $min) {
            return [[
                'label' => number_format($min, 0, '.', ' '),
                'from' => $min,
                'to' => $max,
                'count' => $values->count(),
                'in_sell_band' => $min >= $sellLow && $min <= $sellHigh,
                'in_buy_zone' => false,
            ]];
        }

        // ~8–10 nice buckets
        $span = $max - $min;
        $rawStep = $span / 8;
        $nice = $this->niceStep($rawStep);
        $start = (int) (floor($min / $nice) * $nice);
        $buckets = [];

        for ($from = $start; $from <= $max; $from += $nice) {
            $to = $from + $nice - 1;
            $count = $values->filter(fn ($v) => (int) $v >= $from && (int) $v <= $to)->count();
            if ($count === 0 && ($to < $min || $from > $max)) {
                continue;
            }
            $buckets[] = [
                'label' => number_format($from, 0, '.', ' ').'–'.number_format(min($to, $max + $nice), 0, '.', ' '),
                'from' => $from,
                'to' => $to,
                'count' => $count,
                'in_sell_band' => $from <= $sellHigh && $to >= $sellLow,
                'in_buy_zone' => false,
                'near_mid' => $mid >= $from && $mid <= $to,
            ];
            if (count($buckets) >= 12) {
                break;
            }
        }

        // Drop leading/trailing empty
        while ($buckets !== [] && ($buckets[0]['count'] ?? 0) === 0) {
            array_shift($buckets);
        }
        while ($buckets !== [] && ($buckets[array_key_last($buckets)]['count'] ?? 0) === 0) {
            array_pop($buckets);
        }

        return array_values($buckets);
    }

    private function niceStep(float $raw): int
    {
        if ($raw <= 100) {
            return 100;
        }
        if ($raw <= 250) {
            return 250;
        }
        if ($raw <= 500) {
            return 500;
        }
        if ($raw <= 1000) {
            return 1000;
        }
        if ($raw <= 2000) {
            return 2000;
        }
        if ($raw <= 5000) {
            return 5000;
        }

        return (int) (ceil($raw / 1000) * 1000);
    }

    /**
     * @return Collection<int, Listing>
     */
    private function observedListings(MarketPrice $price): Collection
    {
        return Listing::query()
            ->where('brand', $price->brand)
            ->where('model', $price->model)
            ->when($price->storage_gb, fn ($q) => $q->where('storage_gb', $price->storage_gb))
            ->marketSell()
            ->whereNotNull('model')
            ->where('parse_confidence', '>=', 0.55)
            ->where(function ($q) {
                $q->whereNotNull('price_mdl')->orWhereNotNull('price');
            })
            ->where('status', 'active')
            // Exclude obvious bait from density chart
            ->where(function ($q) {
                $q->where('is_bait', false)->orWhereNull('is_bait');
            })
            ->where(function ($q) {
                $q->where('price_mdl', '>=', 300)->orWhere('price', '>=', 300);
            })
            ->orderBy('price_mdl')
            ->limit(800)
            ->get()
            ->map(function (Listing $l) {
                $l->price_mdl = $l->price_mdl ?? $l->price;

                return $l;
            })
            ->filter(fn (Listing $l) => (int) $l->price_mdl > 0)
            ->values();
    }

    /**
     * @param  Collection<int, mixed>  $values
     */
    private function median(Collection $values): ?int
    {
        if ($values->isEmpty()) {
            return null;
        }

        return (int) $values->get((int) floor(($values->count() - 1) / 2));
    }

    private function closeEnough(int $mid, ?int $observed): bool
    {
        if (! $observed || $mid <= 0) {
            return false;
        }

        return abs($mid - $observed) / $mid <= 0.18;
    }
}
