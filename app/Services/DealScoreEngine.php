<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\IgnoredListing;
use App\Models\Listing;
use Carbon\CarbonInterface;

class DealScoreEngine
{
    public function __construct(
        private readonly MarketPriceEngine $marketPriceEngine,
        private readonly ListingAnalyst $analyst,
        private readonly ConditionValuationService $valuation,
        private readonly SellerAnalyticsService $sellers,
        private readonly ListingStaleness $staleness,
    ) {}

    public function evaluate(Listing $listing, ?int $prepCost = null, ?int $riskReserve = null): ?Deal
    {
        $prepCost ??= (int) config('dealwatch.economics.prep_cost');
        $riskReserve ??= (int) config('dealwatch.economics.risk_reserve');
        $minProfit = (int) config('dealwatch.economics.min_profit');

        $market = $this->marketPriceEngine->findForListing($listing);
        $analysis = $this->analyst->analyze($listing, $market);

        $fill = [
            'analyst_comment' => $analysis['comment'],
            'analyst_flags' => $analysis['flags'],
            'is_bait' => $analysis['is_bait'],
            'analyst_risk' => $analysis['risk_level'],
            'analyst_report' => $analysis['report'],
        ];

        $batteryFromText = data_get($analysis, 'report.battery_from_text');
        if ($batteryFromText && ! $listing->battery_health) {
            $fill['battery_health'] = $batteryFromText;
        }

        $listing->forceFill($fill)->save();
        $listing->refresh();

        // «Куплю» = potential buyer lead, not a flip deal and not market supply.
        if ($listing->isWantBuy()) {
            $comment = trim(($analysis['comment'] ?? '').' · Объявление «куплю» — потенциальный покупатель, не входит в рыночную аналитику.');
            $report = $analysis['report'] ?? [];
            $report['listing_kind'] = 'want_buy';
            $report['buyer_lead'] = true;
            $listing->forceFill([
                'analyst_comment' => $comment,
                'analyst_report' => $report,
            ])->save();

            $deal = Deal::updateOrCreate(
                ['listing_id' => $listing->id],
                [
                    'market_price_id' => $market?->id,
                    'market_price' => $market?->marketMid(),
                    'discount_percent' => null,
                    'potential_profit' => null,
                    'deal_score' => 0,
                    'verdict' => 'ignore',
                    'freshness' => $this->freshness($listing->published_at ?? $listing->first_seen_at),
                    'liquidity' => $market?->liquidity,
                    'score_breakdown' => [
                        'listing_kind' => 'want_buy',
                        'buyer_lead' => true,
                        'note' => 'Потенциальный покупатель (куплю/cumpăr). Не триггерим покупку и не кормим market foundations.',
                        'analyst_comment' => $comment,
                    ],
                ]
            );

            return $this->applyIgnoredState($listing, $deal);
        }

        // Стекло для iPhone и «на запчасти» распознаются как модель, но товаром не являются.
        if (! $listing->isRealItem()) {
            $label = ListingSubjectClassifier::label($listing->subject, (bool) $listing->is_replica);

            $deal = Deal::updateOrCreate(
                ['listing_id' => $listing->id],
                [
                    'market_price_id' => $market?->id,
                    'market_price' => null,
                    'discount_percent' => null,
                    'potential_profit' => null,
                    'deal_score' => 0,
                    'verdict' => 'ignore',
                    'freshness' => $this->freshness($listing->published_at ?? $listing->first_seen_at),
                    'score_breakdown' => [
                        'subject' => $listing->subject,
                        'is_replica' => (bool) $listing->is_replica,
                        'note' => sprintf('Это %s, а не сам телефон — цену модели к нему не применяем.', $label ?? 'не товар'),
                    ],
                ]
            );

            return $this->applyIgnoredState($listing, $deal);
        }

        // Shop sell ads: keep for separate rubric, never auto BUY/CHECK alerts path.
        $buyPrice = $listing->priceForScoring();
        if (! $buyPrice || $buyPrice <= 0) {
            return null;
        }

        if (! $market) {
            return null;
        }

        $valuation = $this->valuation->value($listing, $market, $analysis, $prepCost, $riskReserve, $minProfit);
        $report = $analysis['report'];
        $report['valuation'] = $valuation;
        $brief = $report['brief'] ?? [];
        $brief['valuation'] = [
            'headline' => sprintf(
                'После торга реально ~%s MDL · состояние %d/100 · уверенность %s',
                number_format($valuation['expected_sale'], 0, '.', ' '),
                $valuation['condition_score'],
                $valuation['valuation_confidence']
            ),
            'expected_sale' => $valuation['expected_sale'],
            'condition_score' => $valuation['condition_score'],
            'condition_label' => $valuation['condition_label'],
        ];
        $report['brief'] = $brief;
        $listing->forceFill([
            'analyst_report' => $report,
            'analyst_comment' => $analysis['comment'],
        ])->save();

        $marketMid = $market->marketMid();
        $expectedSale = $valuation['expected_sale'];
        $discount = (($expectedSale - $buyPrice) / max(1, $expectedSale)) * 100;
        $discountVsClean = (($marketMid - $buyPrice) / max(1, $marketMid)) * 100;
        $profit = $expectedSale - $buyPrice - $prepCost - $riskReserve;
        $freshness = $this->freshness($listing->published_at ?? $listing->first_seen_at);

        $breakdown = [
            'discount' => $this->scoreDiscount($discount),
            'profit' => $this->scoreProfit($profit),
            'liquidity' => (int) round(($market->liquidity / 10) * 100),
            'freshness' => $this->scoreFreshness($freshness),
            'private_seller' => $listing->seller_type === 'shop' ? 40 : 85,
            'parse_confidence' => (int) round(($listing->parse_confidence ?? 0) * 100),
            'condition_score' => $valuation['condition_score'],
            'valuation_confidence' => $valuation['valuation_confidence'],
            'analyst_flags' => $analysis['flags'],
            'analyst_comment' => $analysis['comment'],
            'valuation' => $valuation,
            'discount_vs_clean_mid' => round($discountVsClean, 2),
        ];

        $weights = (array) config('dealwatch.score.weights');

        $score = (int) round(
            $breakdown['discount'] * (float) ($weights['discount'] ?? 0)
            + $breakdown['profit'] * (float) ($weights['profit'] ?? 0)
            + $breakdown['liquidity'] * (float) ($weights['liquidity'] ?? 0)
            + $breakdown['freshness'] * (float) ($weights['freshness'] ?? 0)
            + $breakdown['private_seller'] * (float) ($weights['private_seller'] ?? 0)
            + $breakdown['parse_confidence'] * (float) ($weights['parse_confidence'] ?? 0)
            + $valuation['condition_score'] * (float) ($weights['condition'] ?? 0)
            + $this->scoreValuationConfidence($valuation['valuation_confidence']) * (float) ($weights['valuation_confidence'] ?? 0)
        );

        $score = max(0, min(100, $score));

        $buyRule = (array) config('dealwatch.score.verdict.buy');
        $checkRule = (array) config('dealwatch.score.verdict.check');
        $floorRule = (array) config('dealwatch.score.verdict.floor');

        $verdict = match (true) {
            $score >= (int) $buyRule['score']
                && $profit >= (int) $buyRule['profit']
                && $discount >= (float) $buyRule['discount'] => 'buy',
            $score >= (int) $checkRule['score'] && $profit >= (int) $checkRule['profit'] => 'check',
            default => 'ignore',
        };

        if ($profit < (int) $floorRule['profit'] || $discount < (float) $floorRule['discount']) {
            $verdict = 'ignore';
            $score = min($score, 55);
        }

        // Buy ceiling vs condition-adjusted economics
        if ($buyPrice > $valuation['max_buy_for_profit'] && $buyPrice > $market->buy_max) {
            $verdict = 'ignore';
            $score = min($score, 45);
        } elseif ($buyPrice > $valuation['max_buy_for_profit']) {
            $verdict = 'check';
            $score = min($score, 58);
        }

        $suspicious = $buyPrice < ($market->buy_min * 0.5) || $discountVsClean >= 45;
        if ($suspicious) {
            $breakdown['risk'] = 20;
            $verdict = 'check';
            $score = min($score, 72);
        }

        if ($analysis['is_bait'] || in_array($analysis['risk_level'], ['high', 'medium'], true)) {
            $breakdown['risk'] = max((int) ($breakdown['risk'] ?? 0), $analysis['risk_level'] === 'high' ? 40 : 25);
            $breakdown['bait'] = true;
            if ($analysis['is_bait'] || $analysis['risk_level'] === 'high') {
                $verdict = 'ignore';
                $score = min($score, 35);
            } else {
                $verdict = 'check';
                $score = min($score, 55);
            }
        }

        // Heavy defects: never auto-BUY even if cheap
        if ($valuation['condition_score'] < 55 && $verdict === 'buy') {
            $verdict = 'check';
            $score = min($score, 70);
        }

        $sellerProfile = $this->sellers->profile($listing);
        if ($sellerProfile['is_reseller']) {
            $breakdown['reseller'] = true;
            $breakdown['seller_listings_count'] = $sellerProfile['seller_listings_count'];
            $breakdown['seller_note'] = $sellerProfile['note'];
            $verdict = 'ignore';
            $score = min($score, 35);
        }

        if ($listing->seller_type === 'shop') {
            $breakdown['shop'] = true;
            $breakdown['shop_note'] = 'Магазин — отдельная рубрика, не цель перекупа частников.';
            $verdict = 'ignore';
            $score = min($score, 45);
        }

        // Давно не обновлявшееся объявление скорее всего уже неактуально.
        ['score' => $score, 'verdict' => $verdict, 'breakdown' => $breakdown] =
            $this->staleness->apply($listing, $score, $verdict, $breakdown);

        $deal = Deal::updateOrCreate(
            ['listing_id' => $listing->id],
            [
                'market_price_id' => $market->id,
                'market_price' => $expectedSale,
                'discount_percent' => round($discount, 2),
                'potential_profit' => $profit,
                'deal_score' => $score,
                'verdict' => $verdict,
                'freshness' => $freshness,
                'liquidity' => $market->liquidity,
                'score_breakdown' => $breakdown,
            ]
        );

        return $this->applyIgnoredState($listing, $deal);
    }

    private function applyIgnoredState(Listing $listing, Deal $deal): Deal
    {
        if (! IgnoredListing::isIgnored((string) $listing->platform, (string) $listing->external_id)) {
            return $deal;
        }

        if ($deal->user_status !== Deal::STATUS_DISMISSED) {
            $deal->forceFill(['user_status' => Deal::STATUS_DISMISSED])->save();
        }

        return $deal->refresh();
    }

    private function scoreValuationConfidence(string $level): int
    {
        return match ($level) {
            'высокая' => 90,
            'средняя' => 60,
            default => 30,
        };
    }

    private function freshness(?CarbonInterface $at): string
    {
        if (! $at) {
            return 'new';
        }

        $minutes = $at->diffInMinutes(now());

        return match (true) {
            $minutes <= 10 => 'fresh',
            $minutes <= 60 => 'new',
            $minutes <= 1440 => 'old',
            default => 'stale',
        };
    }

    private function scoreDiscount(float $discount): int
    {
        return match (true) {
            $discount >= 25 => 100,
            $discount >= 20 => 90,
            $discount >= 15 => 75,
            $discount >= 10 => 50,
            $discount >= 5 => 25,
            default => 5,
        };
    }

    private function scoreProfit(int $profit): int
    {
        return match (true) {
            $profit >= 3000 => 100,
            $profit >= 2000 => 90,
            $profit >= 1500 => 80,
            $profit >= 1000 => 60,
            $profit >= 500 => 35,
            default => 10,
        };
    }

    private function scoreFreshness(string $freshness): int
    {
        return match ($freshness) {
            'fresh' => 100,
            'new' => 80,
            'old' => 40,
            default => 15,
        };
    }
}
