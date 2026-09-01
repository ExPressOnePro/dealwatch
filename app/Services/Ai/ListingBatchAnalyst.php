<?php

namespace App\Services\Ai;

use App\Models\Deal;
use Illuminate\Support\Collection;

/**
 * Двухуровневый разбор выборки объявлений.
 *
 * Дешёвая модель прогоняет всю пачку и ранжирует её, дорогая детально
 * разбирает только финалистов — так стоимость держится в разумных рамках
 * даже на больших выборках.
 *
 * В модель уходят только характеристики товара и сделки: телефон и имя
 * продавца не покидают приложение.
 */
class ListingBatchAnalyst
{
    public function __construct(
        private readonly OpenAiClient $client,
    ) {}

    /**
     * @param  Collection<int, Deal>  $deals
     * @return array{
     *     summary: string,
     *     recommendation: ?string,
     *     items: list<array<string, mixed>>,
     *     cost_usd: float,
     *     model_screen: ?string,
     *     model_deep: ?string,
     *     listing_count: int
     * }
     */
    public function analyze(Collection $deals, ?string $query = null, int $deepLimit = 5): array
    {
        $batchSize = (int) config('dealwatch.ai.batch_size');
        $deals = $deals->take($batchSize)->values();

        if ($deals->isEmpty()) {
            return [
                'summary' => 'В выборке нет объявлений — нечего разбирать.',
                'recommendation' => null,
                'items' => [],
                'cost_usd' => 0.0,
                'model_screen' => null,
                'model_deep' => null,
                'listing_count' => 0,
            ];
        }

        $screen = $this->screen($deals, $query);
        $cost = $screen->costUsd;

        $ranked = collect($screen->data['items'] ?? [])
            ->filter(fn ($item) => is_array($item) && isset($item['listing_id']))
            ->sortByDesc(fn ($item) => (int) ($item['rank'] ?? 0))
            ->values();

        $finalists = $ranked
            ->filter(fn ($item) => in_array($item['verdict'] ?? 'skip', ['take', 'check'], true))
            ->take(max(0, $deepLimit))
            ->values();

        $deepItems = [];
        $recommendation = null;
        $modelDeep = null;

        if ($finalists->isNotEmpty()) {
            $deepDeals = $deals->filter(
                fn (Deal $deal) => $finalists->contains(fn ($item) => (int) $item['listing_id'] === $deal->id)
            )->values();

            $deep = $this->deep($deepDeals, $query);
            $cost += $deep->costUsd;
            $modelDeep = $deep->model;
            $recommendation = $this->stringOrNull($deep->data['recommendation'] ?? null);

            foreach ($deep->data['items'] ?? [] as $item) {
                if (is_array($item) && isset($item['listing_id'])) {
                    $deepItems[(int) $item['listing_id']] = $item;
                }
            }
        }

        $byId = $deals->keyBy('id');
        $items = $ranked->map(function (array $item) use ($byId, $deepItems) {
            $id = (int) $item['listing_id'];
            /** @var Deal|null $deal */
            $deal = $byId->get($id);
            $deep = $deepItems[$id] ?? null;

            return [
                'deal_id' => $id,
                'title' => $deal?->listing?->displayName(),
                'url' => $deal?->listing?->url,
                'price_mdl' => $deal?->listing?->priceForScoring(),
                'engine_score' => $deal?->deal_score,
                'engine_verdict' => $deal?->verdict,
                'ai_verdict' => $item['verdict'] ?? 'skip',
                'rank' => (int) ($item['rank'] ?? 0),
                'risk' => $item['risk'] ?? null,
                'reason' => $item['reason'] ?? null,
                'call_priority' => $deep['call_priority'] ?? null,
                'target_price_mdl' => $deep['target_price_mdl'] ?? null,
                'reasoning' => $deep['reasoning'] ?? null,
                'questions' => $deep['questions'] ?? [],
                'red_flags' => $deep['red_flags'] ?? [],
            ];
        })->all();

        return [
            'summary' => $this->stringOrNull($screen->data['summary'] ?? null) ?? 'Разбор выполнен.',
            'recommendation' => $recommendation,
            'items' => $items,
            'cost_usd' => round($cost, 6),
            'model_screen' => $screen->model,
            'model_deep' => $modelDeep,
            'listing_count' => $deals->count(),
        ];
    }

    /**
     * @param  Collection<int, Deal>  $deals
     */
    private function screen(Collection $deals, ?string $query): AiResult
    {
        $economics = $this->economicsLine();

        return $this->client->structured(
            purpose: 'batch_screen',
            tier: 'screen',
            messages: [
                [
                    'role' => 'system',
                    'content' => 'Ты помогаешь перекупщику б/у телефонов в Молдове выбрать, кому звонить первым. '
                        ."Все цены в молдавских леях (MDL). {$economics} "
                        .'Оценивай каждое объявление по данным, которые тебе дали: запас маржи, состояние, риск обмана, '
                        .'свежесть, тип продавца. Ничего не выдумывай — если данных мало, так и пиши в reason и снижай rank. '
                        .'verdict: take — стоит звонить сейчас; check — нужно уточнить детали; skip — мимо. '
                        .'rank: 0–100, чем выше, тем раньше звонить. reason — одно короткое предложение по-русски.',
                ],
                [
                    'role' => 'user',
                    'content' => $this->userMessage($deals, $query),
                ],
            ],
            schema: [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'listing_id' => ['type' => 'integer'],
                                'verdict' => ['type' => 'string', 'enum' => ['take', 'check', 'skip']],
                                'rank' => ['type' => 'integer'],
                                'risk' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                                'reason' => ['type' => 'string'],
                            ],
                            'required' => ['listing_id', 'verdict', 'rank', 'risk', 'reason'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['summary', 'items'],
                'additionalProperties' => false,
            ],
            schemaName: 'batch_screening',
            meta: ['deals' => $deals->pluck('id')->all(), 'query' => $query],
        );
    }

    /**
     * @param  Collection<int, Deal>  $deals
     */
    private function deep(Collection $deals, ?string $query): AiResult
    {
        $economics = $this->economicsLine();

        return $this->client->structured(
            purpose: 'batch_deep',
            tier: 'deep',
            messages: [
                [
                    'role' => 'system',
                    'content' => "Ты опытный перекупщик б/у телефонов в Молдове. Разбери финалистов выборки. {$economics} "
                        .'Для каждого объявления: call_priority 1–5 (1 — звонить первым), target_price_mdl — за сколько реально '
                        .'сторговать с учётом состояния, reasoning — 1–2 предложения почему, questions — что спросить до встречи, '
                        .'red_flags — на что смотреть при осмотре. В конце recommendation — общий вывод по выборке. '
                        .'Опирайся только на переданные данные, не придумывай характеристик.',
                ],
                [
                    'role' => 'user',
                    'content' => $this->userMessage($deals, $query),
                ],
            ],
            schema: [
                'type' => 'object',
                'properties' => [
                    'recommendation' => ['type' => 'string'],
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'listing_id' => ['type' => 'integer'],
                                'call_priority' => ['type' => 'integer'],
                                'target_price_mdl' => ['type' => ['integer', 'null']],
                                'reasoning' => ['type' => 'string'],
                                'questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'red_flags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'required' => ['listing_id', 'call_priority', 'target_price_mdl', 'reasoning', 'questions', 'red_flags'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['recommendation', 'items'],
                'additionalProperties' => false,
            ],
            schemaName: 'batch_deep_dive',
            meta: ['deals' => $deals->pluck('id')->all(), 'query' => $query],
        );
    }

    private function economicsLine(): string
    {
        return sprintf(
            'Прибыль считается так: ожидаемая цена продажи − цена покупки − %d подготовка − %d резерв на риск; '
            .'сделка интересна от %d MDL прибыли.',
            (int) config('dealwatch.economics.prep_cost'),
            (int) config('dealwatch.economics.risk_reserve'),
            (int) config('dealwatch.economics.min_profit')
        );
    }

    /**
     * @param  Collection<int, Deal>  $deals
     */
    private function userMessage(Collection $deals, ?string $query): string
    {
        $payload = [
            'request' => $query ?: 'Общий разбор текущей выборки ленты.',
            'listings' => $deals->map(fn (Deal $deal) => $this->compact($deal))->all(),
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
    }

    /**
     * Компактная карточка для модели: только товар и экономика сделки,
     * без телефона и имени продавца.
     *
     * @return array<string, mixed>
     */
    private function compact(Deal $deal): array
    {
        $listing = $deal->listing;
        $valuation = data_get($listing?->analyst_report, 'valuation');

        return array_filter([
            'listing_id' => $deal->id,
            'model' => $listing?->displayName(),
            'title' => $listing?->title,
            'storage_gb' => $listing?->storage_gb,
            'battery_health' => $listing?->battery_health,
            'price_mdl' => $listing?->priceForScoring(),
            'market_mid_mdl' => data_get($valuation, 'market_mid_clean') ?? $deal->market_price,
            'expected_sale_mdl' => data_get($valuation, 'expected_sale') ?? $deal->market_price,
            'potential_profit_mdl' => $deal->potential_profit,
            'discount_percent' => $deal->discount_percent,
            'engine_score' => $deal->deal_score,
            'engine_verdict' => $deal->verdict,
            'condition_score' => data_get($valuation, 'condition_score'),
            'condition_label' => data_get($valuation, 'condition_label'),
            'analyst_flags' => $listing?->analyst_flags,
            'analyst_risk' => $listing?->analyst_risk,
            'is_bait' => $listing?->is_bait,
            'seller_type' => $listing?->seller_type,
            'seller_listings_count' => $listing?->seller_listings_count,
            'is_reseller' => $listing?->is_reseller,
            'city' => $listing?->location,
            'published_at' => optional($listing?->published_at)->toDateTimeString(),
            'freshness' => $deal->freshness,
            'description' => $listing?->description ? mb_substr($listing->description, 0, 400) : null,
        ], fn ($value) => $value !== null && $value !== []);
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
