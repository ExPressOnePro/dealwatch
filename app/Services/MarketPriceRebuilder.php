<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\MarketPrice;
use Illuminate\Support\Collection;

class MarketPriceRebuilder
{
    /**
     * Rebuild sell/buy ranges from active private listings.
     * $months = 0 → all active ads (no publish-date cutoff).
     *
     * @return array{updated:int, skipped:int, details:list<array<string, mixed>>}
     */
    public function rebuild(int $months = 0, ?int $minSamples = null): array
    {
        $minSamples ??= (int) config('dealwatch.market.min_samples');
        $buyMaxRatio = (float) config('dealwatch.market.buy_max_ratio');
        $buyMinRatio = (float) config('dealwatch.market.buy_min_ratio');

        $since = $months > 0
            ? now('Europe/Chisinau')->subMonths($months)->startOfDay()
            : null;
        $updated = 0;
        $skipped = 0;
        $details = [];

        $prices = MarketPrice::query()->where('is_active', true)->get();

        foreach ($prices as $price) {
            $listings = Listing::query()
                ->where('brand', $price->brand)
                ->where('model', $price->model)
                ->when($price->storage_gb, fn ($q) => $q->where('storage_gb', $price->storage_gb))
                ->marketSell()
                ->where('subject', ListingSubjectClassifier::SUBJECT_ITEM)
                ->where('is_replica', false)
                ->privateSeller()
                ->where(function ($q) {
                    $q->where('is_reseller', false)->orWhereNull('is_reseller');
                })
                ->where('status', 'active')
                ->whereNotNull('model')
                ->where('parse_confidence', '>=', 0.55)
                ->whereNotNull('price_mdl')
                ->where('price_mdl', '>', 0)
                ->when($since, fn ($q) => $q->where('published_at', '>=', $since))
                ->get(['price_mdl', 'price_original', 'currency', 'title', 'url', 'published_at']);

            $values = $listings->pluck('price_mdl')->map(fn ($v) => (int) $v)->sort()->values();
            if ($values->count() < $minSamples) {
                $skipped++;
                $details[] = [
                    'model' => $price->brand.' '.$price->model.($price->storage_gb ? ' '.$price->storage_gb.'GB' : ''),
                    'status' => 'skipped',
                    'samples' => $values->count(),
                    'reason' => "мало частных объявлений (<{$minSamples})",
                ];

                continue;
            }

            // Drop extreme outliers outside 0.55x..1.8x of median before percentiles
            $median = $this->percentile($values, 50);
            $filtered = $values->filter(function (int $v) use ($median) {
                return $v >= (int) ($median * 0.55) && $v <= (int) ($median * 1.8);
            })->values();

            if ($filtered->count() < $minSamples) {
                $filtered = $values;
            }

            $sellLow = $this->percentile($filtered, 25);
            $sellHigh = $this->percentile($filtered, 75);
            if ($sellHigh <= $sellLow) {
                $sellHigh = $sellLow + max(200, (int) round($sellLow * 0.08));
            }
            $mid = (int) round(($sellLow + $sellHigh) / 2);
            $buyMax = (int) round($mid * $buyMaxRatio);
            $buyMin = (int) round($mid * $buyMinRatio);

            $periodFrom = optional($listings->min('published_at'))?->timezone('Europe/Chisinau')->format('d.m.Y');
            $periodTo = optional($listings->max('published_at'))?->timezone('Europe/Chisinau')->format('d.m.Y');

            $samples = $listings->sortBy('price_mdl')->take(8)->map(function (Listing $l) {
                $orig = $l->price_original ?? $l->price_mdl;
                $cur = $l->currency ?: 'MDL';

                return $orig.' '.$cur.($cur !== 'MDL' ? '≈'.$l->price_mdl.' MDL' : '');
            })->implode('; ');

            $rationale = sprintf(
                'Рынок построен по %d частным объявлениям 999.md (sell, без перекупов и без «куплю») за %s–%s. Медиана %s MDL, межквартильный диапазон (p25–p75) %s–%s MDL. Магазины и объявления «куплю» исключены. buy_max = %d%% от mid.',
                $filtered->count(),
                $periodFrom ?? '—',
                $periodTo ?? '—',
                number_format($median, 0, '.', ' '),
                number_format($sellLow, 0, '.', ' '),
                number_format($sellHigh, 0, '.', ' '),
                (int) round($buyMaxRatio * 100)
            );

            $basis = [
                'segment' => 'observed_999_private',
                'anchor' => sprintf(
                    'Живая база MVP: %d частников 999, период %s–%s, медиана %s MDL, p25–p75 %s–%s MDL',
                    $filtered->count(),
                    $periodFrom ?? '—',
                    $periodTo ?? '—',
                    number_format($median, 0, '.', ' '),
                    number_format($sellLow, 0, '.', ' '),
                    number_format($sellHigh, 0, '.', ' ')
                ),
                'buy_rule' => sprintf(
                    'buy_max=%s (%d%% mid), buy_min=%s (%d%% mid)',
                    number_format($buyMax, 0, '.', ' '),
                    (int) round($buyMaxRatio * 100),
                    number_format($buyMin, 0, '.', ' '),
                    (int) round($buyMinRatio * 100)
                ),
                'notes' => 'Примеры цен: '.$samples,
                'sample_count' => (string) $filtered->count(),
                'period' => ($periodFrom ?? '').' – '.($periodTo ?? ''),
            ];

            $price->update([
                'buy_min' => $buyMin,
                'buy_max' => $buyMax,
                'sell_low' => $sellLow,
                'sell_high' => $sellHigh,
                'rationale' => $rationale,
                'basis' => $basis,
                'source' => $months > 0
                    ? 'Автопересчёт из загруженной базы 999.md (частники, ≤'.$months.' мес.)'
                    : 'Автопересчёт из загруженной базы 999.md (частники, все активные)',
            ]);

            $updated++;
            $details[] = [
                'model' => $price->brand.' '.$price->model.($price->storage_gb ? ' '.$price->storage_gb.'GB' : ''),
                'status' => 'updated',
                'samples' => $filtered->count(),
                'mid' => $mid,
                'sell' => $sellLow.'-'.$sellHigh,
            ];
        }

        return compact('updated', 'skipped', 'details');
    }

    /**
     * @param  Collection<int, int>  $sorted
     */
    private function percentile(Collection $sorted, float $p): int
    {
        if ($sorted->isEmpty()) {
            return 0;
        }
        $values = $sorted->values();
        $idx = (int) floor(($values->count() - 1) * ($p / 100));

        return (int) $values->get($idx);
    }
}
