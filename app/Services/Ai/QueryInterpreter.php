<?php

namespace App\Services\Ai;

use App\Services\CurrencyRateService;
use App\Services\PhoneNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * Свободный запрос («iPhone 13 128 до 8000, без замен») → структура для фильтра по базе.
 *
 * Сначала пробуем распознать локально: это бесплатно и покрывает большинство
 * запросов вида «модель + бюджет». К модели обращаемся, только если своих
 * средств не хватило.
 */
class QueryInterpreter
{
    public function __construct(
        private readonly PhoneNormalizer $normalizer,
        private readonly OpenAiClient $client,
    ) {}

    /**
     * @return array{brand: ?string, model: ?string, storage_gb: ?int, max_price_mdl: ?int, must_have: list<string>, avoid: list<string>, source: string}
     */
    public function interpret(string $query): array
    {
        $query = trim($query);

        $local = $this->interpretLocally($query);
        if ($local['model'] !== null) {
            return $local;
        }

        if (! $this->client->configured()) {
            return $local;
        }

        try {
            return $this->interpretWithAi($query, $local);
        } catch (AiException $e) {
            Log::info('Query interpretation fell back to local parsing: '.$e->getMessage());

            return $local;
        }
    }

    /**
     * @return array{brand: ?string, model: ?string, storage_gb: ?int, max_price_mdl: ?int, must_have: list<string>, avoid: list<string>, source: string}
     */
    private function interpretLocally(string $query): array
    {
        $parsed = $this->normalizer->parse($query);

        return [
            'brand' => $parsed['brand'],
            'model' => $parsed['model'],
            'storage_gb' => $parsed['storage_gb'] ?? null,
            'max_price_mdl' => $this->extractBudget($query),
            'must_have' => [],
            'avoid' => [],
            'source' => 'local',
        ];
    }

    /**
     * «до 8000», «не дороже 8 000 лей», «до 400 евро» → потолок в MDL.
     */
    private function extractBudget(string $query): ?int
    {
        $text = mb_strtolower($query);

        if (! preg_match('/(?:до|не\s+дороже|максимум|макс\.?|под)\s*(\d[\d\s.]{2,})\s*(евро|eur|€|\$|usd|долл\w*)?/u', $text, $m)) {
            return null;
        }

        $amount = (int) preg_replace('/\D+/', '', $m[1]);
        if ($amount <= 0) {
            return null;
        }

        $currency = match (true) {
            isset($m[2]) && preg_match('/евро|eur|€/u', $m[2]) === 1 => 'EUR',
            isset($m[2]) && preg_match('/\$|usd|долл/u', $m[2]) === 1 => 'USD',
            default => 'MDL',
        };

        return $currency === 'MDL'
            ? $amount
            : app(CurrencyRateService::class)->toMdl($amount, $currency);
    }

    /**
     * @param  array<string, mixed>  $local
     * @return array{brand: ?string, model: ?string, storage_gb: ?int, max_price_mdl: ?int, must_have: list<string>, avoid: list<string>, source: string}
     */
    private function interpretWithAi(string $query, array $local): array
    {
        $result = $this->client->structured(
            purpose: 'parse_query',
            tier: 'screen',
            messages: [
                [
                    'role' => 'system',
                    'content' => 'Ты помощник перекупщика б/у телефонов в Молдове. Разбери запрос пользователя '
                        .'в структуру поиска по базе объявлений. Бренд и модель пиши в каноничном виде '
                        .'(«Apple» + «iPhone 13 Pro», «Samsung» + «S23 Ultra»). Бюджет переводи в молдавские леи (MDL): '
                        .'евро ≈ 19.5 MDL, доллар ≈ 17.5 MDL. Ничего не выдумывай: если данных нет — null или пустой список.',
                ],
                ['role' => 'user', 'content' => $query],
            ],
            schema: [
                'type' => 'object',
                'properties' => [
                    'brand' => ['type' => ['string', 'null']],
                    'model' => ['type' => ['string', 'null']],
                    'storage_gb' => ['type' => ['integer', 'null']],
                    'max_price_mdl' => ['type' => ['integer', 'null']],
                    'must_have' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'avoid' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['brand', 'model', 'storage_gb', 'max_price_mdl', 'must_have', 'avoid'],
                'additionalProperties' => false,
            ],
            schemaName: 'search_query',
            meta: ['query' => mb_substr($query, 0, 200)],
        );

        $data = $result->data;

        return [
            'brand' => $this->stringOrNull($data['brand'] ?? null),
            'model' => $this->stringOrNull($data['model'] ?? null),
            'storage_gb' => isset($data['storage_gb']) ? (int) $data['storage_gb'] ?: null : null,
            'max_price_mdl' => isset($data['max_price_mdl'])
                ? ((int) $data['max_price_mdl'] ?: null)
                : $local['max_price_mdl'],
            'must_have' => array_values(array_filter(array_map('strval', (array) ($data['must_have'] ?? [])))),
            'avoid' => array_values(array_filter(array_map('strval', (array) ($data['avoid'] ?? [])))),
            'source' => 'ai',
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
