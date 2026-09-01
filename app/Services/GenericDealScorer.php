<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\IgnoredListing;
use App\Models\Listing;
use Carbon\CarbonInterface;

/**
 * Оценка объявлений из источников без справочника моделей.
 *
 * Рынок здесь — медиана цен по объявлениям того же источника, а не заранее
 * заведённые диапазоны. Формула прибыли и пороги вердикта общие с телефонами,
 * чтобы лента читалась одинаково.
 */
class GenericDealScorer
{
    public function __construct(
        private readonly ProfileMarketStats $stats,
        private readonly SellerAnalyticsService $sellers,
        private readonly ListingStaleness $staleness,
    ) {}

    public function score(Listing $listing, ?int $prepCost = null, ?int $riskReserve = null): ?Deal
    {
        $profile = $listing->searchProfile;
        $price = $listing->priceForScoring();

        if (! $profile || ! $price || $price <= 0) {
            return null;
        }

        // Запчасти, аксессуары, услуги и реплики нельзя сравнивать с ценой
        // самого товара: «Piese JBL Charge» за 350 лей — это не находка.
        if (! $listing->isRealItem()) {
            return $this->saveNonItem($listing);
        }

        $prepCost ??= (int) config('dealwatch.economics.prep_cost');
        $riskReserve ??= (int) config('dealwatch.economics.risk_reserve');

        $market = $this->stats->for($profile, $price);
        $freshness = $this->freshness($listing->published_at ?? $listing->first_seen_at);

        if (! $market['enough']) {
            return $this->save($listing, [
                'market_price' => $market['median'],
                'discount_percent' => null,
                'potential_profit' => null,
                'deal_score' => 0,
                'verdict' => 'ignore',
                'freshness' => $freshness,
                'score_breakdown' => [
                    'mode' => 'generic',
                    'profile' => $profile->name,
                    'market' => $market,
                    'note' => sprintf(
                        'Мало данных для рынка: %d объявлений из нужных %d. Ориентир появится, когда источник наберёт статистику.',
                        $market['samples'],
                        (int) config('dealwatch.generic.min_samples')
                    ),
                ],
            ]);
        }

        $median = (int) $market['median'];
        $negotiation = (float) config('dealwatch.generic.negotiation_percent');
        $expectedSale = (int) round($median * (1 - $negotiation / 100));

        $discount = (($expectedSale - $price) / max(1, $expectedSale)) * 100;
        $profit = $expectedSale - $price - $prepCost - $riskReserve;

        $breakdown = [
            'mode' => 'generic',
            'profile' => $profile->name,
            'market' => $market,
            'discount' => $this->scoreDiscount($discount),
            'profit' => $this->scoreProfit($profit),
            'freshness' => $this->scoreFreshness($freshness),
            'private_seller' => $listing->seller_type === 'shop' ? 40 : 85,
            'expected_sale' => $expectedSale,
            'negotiation_percent' => $negotiation,
            'calc' => sprintf(
                'медиана источника %s − торг %s%% = %s → минус цена %s, подготовка %s и резерв %s',
                number_format($median, 0, '.', ' '),
                $negotiation,
                number_format($expectedSale, 0, '.', ' '),
                number_format($price, 0, '.', ' '),
                number_format($prepCost, 0, '.', ' '),
                number_format($riskReserve, 0, '.', ' ')
            ),
        ];

        // Веса те же, что у телефонов, за вычетом того, чего здесь нет:
        // состояния по тексту, ликвидности модели и уверенности парсера.
        $score = (int) round(
            $breakdown['discount'] * 0.45
            + $breakdown['profit'] * 0.30
            + $breakdown['freshness'] * 0.15
            + $breakdown['private_seller'] * 0.10
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

        // Подозрительно дёшево относительно рынка — чаще всего описка или развод.
        if ($market['p25'] && $price < (int) $market['p25'] * 0.5) {
            $breakdown['risk'] = 25;
            $breakdown['risk_note'] = 'Цена сильно ниже даже нижнего квартиля источника — проверь, что это не приманка.';
            $verdict = $verdict === 'buy' ? 'check' : $verdict;
            $score = min($score, 70);
        }

        $sellerProfile = $this->sellers->profile($listing);
        if ($sellerProfile['is_reseller']) {
            $breakdown['reseller'] = true;
            $breakdown['seller_note'] = $sellerProfile['note'];
            $verdict = 'ignore';
            $score = min($score, 35);
        }

        if ($listing->seller_type === 'shop') {
            $breakdown['shop'] = true;
            $verdict = 'ignore';
            $score = min($score, 45);
        }

        ['score' => $score, 'verdict' => $verdict, 'breakdown' => $breakdown] =
            $this->staleness->apply($listing, $score, $verdict, $breakdown);

        return $this->save($listing, [
            'market_price' => $expectedSale,
            'discount_percent' => round($discount, 2),
            'potential_profit' => $profit,
            'deal_score' => $score,
            'verdict' => $verdict,
            'freshness' => $freshness,
            'score_breakdown' => $breakdown,
        ]);
    }

    /** Не сам товар: показываем в ленте, но без вердикта и потенциала. */
    private function saveNonItem(Listing $listing): Deal
    {
        $label = ListingSubjectClassifier::label($listing->subject, (bool) $listing->is_replica);

        return $this->save($listing, [
            'market_price' => null,
            'discount_percent' => null,
            'potential_profit' => null,
            'deal_score' => 0,
            'verdict' => 'ignore',
            'freshness' => $this->freshness($listing->published_at ?? $listing->first_seen_at),
            'score_breakdown' => [
                'mode' => 'generic',
                'subject' => $listing->subject,
                'is_replica' => (bool) $listing->is_replica,
                'note' => sprintf(
                    'Это %s, а не сам товар — сравнивать с рыночной ценой нельзя, в рынок источника объявление тоже не входит.',
                    $label ?? 'не товар'
                ),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function save(Listing $listing, array $attributes): Deal
    {
        $deal = Deal::updateOrCreate(['listing_id' => $listing->id], $attributes);

        if (IgnoredListing::isIgnored((string) $listing->platform, (string) $listing->external_id)
            && $deal->user_status !== Deal::STATUS_DISMISSED) {
            $deal->forceFill(['user_status' => Deal::STATUS_DISMISSED])->save();
        }

        return $deal->refresh();
    }

    private function freshness(?CarbonInterface $at): string
    {
        if (! $at) {
            return 'new';
        }

        return match (true) {
            $at->diffInMinutes(now()) <= 10 => 'fresh',
            $at->diffInMinutes(now()) <= 60 => 'new',
            $at->diffInMinutes(now()) <= 1440 => 'old',
            default => 'stale',
        };
    }

    private function scoreDiscount(float $discount): int
    {
        return match (true) {
            $discount >= 35 => 100,
            $discount >= 25 => 90,
            $discount >= 18 => 75,
            $discount >= 12 => 55,
            $discount >= 6 => 30,
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
