<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\MarketPrice;

class ConditionValuationService
{
    /**
     * Value a unit by market foundation ± condition ± expected buyer haggling.
     *
     * @param  array<string, mixed>  $analysis  from ListingAnalyst::analyze()
     * @return array{
     *     condition_score: int,
     *     condition_label: string,
     *     valuation_confidence: string,
     *     valuation_confidence_note: string,
     *     market_mid_clean: int,
     *     sell_low_clean: int,
     *     sell_high_clean: int,
     *     condition_haircut_percent: float,
     *     negotiation_percent: float,
     *     fair_ask: int,
     *     expected_sale: int,
     *     quick_sale: int,
     *     optimistic_sale: int,
     *     penalties: list<array{code: string, label: string, percent: float}>,
     *     max_buy_for_profit: int,
     *     profit_at_expected: ?int,
     *     profit_at_quick: ?int,
     *     summary: string
     * }
     */
    public function value(
        Listing $listing,
        MarketPrice $market,
        array $analysis,
        ?int $prepCost = null,
        ?int $riskReserve = null,
        ?int $minProfit = null,
    ): array {
        $prepCost ??= (int) config('dealwatch.economics.prep_cost');
        $riskReserve ??= (int) config('dealwatch.economics.risk_reserve');
        $minProfit ??= (int) config('dealwatch.economics.min_profit');

        $mid = $market->marketMid();
        $sellLow = (int) $market->sell_low;
        $sellHigh = (int) $market->sell_high;
        $flags = $analysis['flags'] ?? [];
        $report = $analysis['report'] ?? [];
        $known = $report['known'] ?? [];

        $penalties = $this->penalties($listing, $flags, $report);
        $haircut = min(55.0, array_sum(array_column($penalties, 'percent')));

        $conditionScore = (int) max(5, round(100 - $haircut * 1.6));
        $conditionLabel = match (true) {
            $conditionScore >= 85 => 'хорошее / близко к рынку',
            $conditionScore >= 70 => 'рабочее с замечаниями',
            $conditionScore >= 50 => 'после ремонтов / уценка',
            default => 'тяжёлое состояние — рынок другой',
        };

        // Adjusted private-market ask for THIS unit (before buyer haggle)
        $fairAsk = (int) round($mid * (1 - $haircut / 100));
        $fairAsk = max((int) round($sellLow * (1 - $haircut / 100) * 0.95), $fairAsk);
        // Keep inside a sensible band vs clean sell range
        $floor = (int) round($sellLow * (1 - min(0.45, $haircut / 100)));
        $ceil = (int) round($sellHigh * (1 - $haircut / 100 * 0.85));
        $fairAsk = max($floor, min($ceil, $fairAsk));

        // Every buyer haggles — stronger haircut when defects are visible
        $negotiation = 5.0;
        if ($haircut >= 20) {
            $negotiation = 9.0;
        } elseif ($haircut >= 10) {
            $negotiation = 7.0;
        } elseif ($listing->seller_type === 'shop') {
            $negotiation = 4.0;
        }

        $expectedSale = (int) round($fairAsk * (1 - $negotiation / 100));
        $quickSale = (int) round($fairAsk * (1 - ($negotiation + 4) / 100));
        $optimisticSale = (int) round($fairAsk * (1 - max(2.0, $negotiation - 3) / 100));

        $confidence = $this->confidence($listing, $flags, $known);
        $buyPrice = $listing->priceForScoring();

        $profitExpected = $buyPrice ? $expectedSale - $buyPrice - $prepCost - $riskReserve : null;
        $profitQuick = $buyPrice ? $quickSale - $buyPrice - $prepCost - $riskReserve : null;
        $maxBuy = $expectedSale - $prepCost - $riskReserve - $minProfit;

        $summary = sprintf(
            'Чистый mid %s MDL → с учётом состояния fair ask %s → после торга покупателя реально ~%s MDL (быстро ~%s). Оценка состояния: %s (%d/100), уверенность в цифре: %s.',
            number_format($mid, 0, '.', ' '),
            number_format($fairAsk, 0, '.', ' '),
            number_format($expectedSale, 0, '.', ' '),
            number_format($quickSale, 0, '.', ' '),
            $conditionLabel,
            $conditionScore,
            $confidence['level']
        );

        return [
            'condition_score' => $conditionScore,
            'condition_label' => $conditionLabel,
            'valuation_confidence' => $confidence['level'],
            'valuation_confidence_note' => $confidence['note'],
            'market_mid_clean' => $mid,
            'sell_low_clean' => $sellLow,
            'sell_high_clean' => $sellHigh,
            'condition_haircut_percent' => round($haircut, 1),
            'negotiation_percent' => $negotiation,
            'fair_ask' => $fairAsk,
            'expected_sale' => $expectedSale,
            'quick_sale' => $quickSale,
            'optimistic_sale' => $optimisticSale,
            'penalties' => $penalties,
            'max_buy_for_profit' => max(0, $maxBuy),
            'profit_at_expected' => $profitExpected,
            'profit_at_quick' => $profitQuick,
            'summary' => $summary,
        ];
    }

    /**
     * @param  list<string>  $flags
     * @param  array<string, mixed>  $report
     * @return list<array{code: string, label: string, percent: float}>
     */
    private function penalties(Listing $listing, array $flags, array $report): array
    {
        $penalties = [];
        $battery = $listing->battery_health ?? data_get($report, 'battery_from_text');

        if (in_array('parts_or_lock', $flags, true)) {
            $penalties[] = ['code' => 'parts_or_lock', 'label' => 'Блокировка / на запчасти', 'percent' => 40.0];
        }
        if (in_array('no_face_id', $flags, true)) {
            $penalties[] = ['code' => 'no_face_id', 'label' => 'Нет Face ID', 'percent' => 22.0];
        }
        if (in_array('wireless_broken', $flags, true)) {
            $penalties[] = ['code' => 'wireless_broken', 'label' => 'NFC/Bluetooth/Wi‑Fi не работают', 'percent' => 12.0];
        }
        if (in_array('replaced_parts', $flags, true)) {
            $known = mb_strtolower(implode(' ', $report['known'] ?? []));
            $pct = 8.0;
            if (str_contains($known, 'камер')) {
                $pct += 6.0;
            }
            if (str_contains($known, 'экран')) {
                $pct += 5.0;
            }
            if (str_contains($known, 'аккумулятор')) {
                $pct += 2.0;
            }
            $penalties[] = ['code' => 'replaced_parts', 'label' => 'Заменённые запчасти', 'percent' => min(18.0, $pct)];
        }
        if ($battery !== null) {
            $b = (int) $battery;
            if ($b > 0 && $b < 80) {
                $penalties[] = ['code' => 'battery', 'label' => "АКБ {$b}% — скоро замена", 'percent' => 6.0];
            } elseif ($b > 0 && $b < 85) {
                $penalties[] = ['code' => 'battery', 'label' => "АКБ {$b}%", 'percent' => 3.0];
            } elseif ($b > 0 && $b < 90) {
                $penalties[] = ['code' => 'battery', 'label' => "АКБ {$b}%", 'percent' => 1.5];
            }
        } elseif (in_array('low_battery', $flags, true)) {
            $penalties[] = ['code' => 'battery', 'label' => 'Слабый АКБ', 'percent' => 4.0];
        }

        // Thin description → buyer assumes worst and haggles harder (valuation uncertainty, mild haircut on ask)
        $descLen = mb_strlen(trim(($listing->title ?? '').' '.($listing->description ?? '')));
        if ($descLen < 40 || in_array('incomplete', $flags, true)) {
            $penalties[] = ['code' => 'unknown_condition', 'label' => 'Мало данных о состоянии — рынок закладывает риск', 'percent' => 5.0];
        }

        return $penalties;
    }

    /**
     * @param  list<string>  $flags
     * @param  list<string>  $known
     * @return array{level: string, note: string}
     */
    private function confidence(Listing $listing, array $flags, array $known): array
    {
        $points = 0;
        if (mb_strlen((string) $listing->description) >= 80) {
            $points += 2;
        } elseif (mb_strlen((string) $listing->description) >= 30) {
            $points += 1;
        }
        if ($listing->battery_health || collect($known)->contains(fn ($k) => str_contains($k, 'АКБ'))) {
            $points += 2;
        }
        if (in_array('face_id_ok', $flags, true) || in_array('no_face_id', $flags, true)) {
            $points += 1;
        }
        if (in_array('replaced_parts', $flags, true) || in_array('wireless_broken', $flags, true)) {
            $points += 1; // defects disclosed → more accurate (even if worse)
        }
        if (in_array('incomplete', $flags, true) || in_array('bait_price', $flags, true) || in_array('negotiable', $flags, true)) {
            $points -= 2;
        }

        return match (true) {
            $points >= 5 => [
                'level' => 'высокая',
                'note' => 'Состояние хорошо раскрыто в тексте — цифру перепродажи можно опираться.',
            ],
            $points >= 2 => [
                'level' => 'средняя',
                'note' => 'Часть состояния ясна, но покупатель всё равно будет торговаться и проверять лично.',
            ],
            default => [
                'level' => 'низкая',
                'note' => 'Мало фактов о состоянии — оценка грубая; закладывай больший запас на торг и сюрпризы.',
            ],
        };
    }
}
