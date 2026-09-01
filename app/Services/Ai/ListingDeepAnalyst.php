<?php

namespace App\Services\Ai;

use App\Models\Listing;
use App\Models\ListingAiReport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Разбор одного объявления: полный текст, а по запросу — ещё и фотографии.
 *
 * Фото стоят заметно дороже текста, поэтому vision включается вручную и только
 * для перспективных объявлений. Результат всегда ложится в карточку объявления.
 */
class ListingDeepAnalyst
{
    public function __construct(
        private readonly OpenAiClient $client,
    ) {}

    public function visionAvailable(): bool
    {
        return (bool) config('dealwatch.ai.vision.enabled') && $this->client->configured();
    }

    /**
     * Заполнить заранее созданный отчёт результатом разбора.
     */
    public function run(ListingAiReport $report): ListingAiReport
    {
        $listing = $report->listing;
        $withPhotos = $report->kind === ListingAiReport::KIND_VISION;

        if ($withPhotos && ! $this->visionAvailable()) {
            return $this->fail($report, 'Разбор фотографий выключен в настройках.');
        }

        $images = $withPhotos ? $this->images($listing) : [];

        if ($withPhotos && $images === []) {
            return $this->fail($report, 'У объявления нет фотографий, которые можно разобрать.');
        }

        try {
            $result = $this->client->structured(
                purpose: $withPhotos ? 'listing_vision' : 'listing_text',
                tier: $withPhotos ? 'vision' : 'deep',
                messages: [
                    ['role' => 'system', 'content' => $this->systemPrompt($withPhotos)],
                    ['role' => 'user', 'content' => $this->userContent($listing, $images)],
                ],
                schema: $this->schema(),
                schemaName: 'listing_inspection',
                meta: ['listing_id' => $listing->id, 'images' => count($images)],
            );
        } catch (AiException $e) {
            return $this->fail($report, $e->getMessage());
        }

        $data = $result->data;

        $report->forceFill([
            'status' => ListingAiReport::STATUS_DONE,
            'model' => $result->model,
            'verdict' => $data['verdict'] ?? null,
            'condition_score' => isset($data['condition_score']) ? (int) $data['condition_score'] : null,
            'target_price_mdl' => isset($data['target_price_mdl']) ? (int) $data['target_price_mdl'] : null,
            'summary' => $data['summary'] ?? null,
            'payload' => [
                'defects' => $data['defects'] ?? [],
                'mismatches' => $data['mismatches'] ?? [],
                'questions' => $data['questions'] ?? [],
                'checks_on_meeting' => $data['checks_on_meeting'] ?? [],
                'confidence' => $data['confidence'] ?? null,
                'photo_notes' => $data['photo_notes'] ?? [],
            ],
            'images_analyzed' => count($images),
            'cost_usd' => $result->costUsd,
            'error' => null,
        ])->save();

        return $report->refresh();
    }

    private function fail(ListingAiReport $report, string $message): ListingAiReport
    {
        Log::info('Listing AI report failed: '.$message);

        $report->forceFill([
            'status' => ListingAiReport::STATUS_FAILED,
            'error' => mb_substr($message, 0, 500),
        ])->save();

        return $report->refresh();
    }

    private function systemPrompt(bool $withPhotos): string
    {
        $base = 'Ты осматриваешь б/у телефон вместо перекупщика в Молдове. Все цены в леях (MDL). '
            .'Задача: найти всё, что снижает цену или грозит проблемами, и сказать, до какой цены торговаться. '
            .'Опирайся только на предоставленные данные, ничего не выдумывай. Ответ по-русски.';

        if (! $withPhotos) {
            return $base.' Тебе дан только текст объявления: в defects указывай source="text".';
        }

        return $base.' Кроме текста тебе даны фотографии из объявления. Внимательно посмотри на них: '
            .'трещины и сколы стекла и корпуса, царапины, вздутие или отслоение экрана, пятна и полосы на дисплее, '
            .'следы вскрытия и кривые зазоры, неоригинальные детали, потёртости граней, состояние камер и разъёма, '
            .'посторонние наклейки, следы влаги. Отмечай и то, что противоречит тексту объявления (mismatches): '
            .'например, «идеальное состояние» при явных сколах. Для находок по фото ставь source="photo" '
            .'и в evidence описывай, на каком фото и где именно это видно. Если качество фото не позволяет судить — '
            .'скажи об этом в photo_notes, а не выдумывай дефект.';
    }

    /**
     * @param  list<array<string, mixed>>  $images
     * @return list<array<string, mixed>>
     */
    private function userContent(Listing $listing, array $images): array
    {
        $content = [[
            'type' => 'input_text',
            'text' => json_encode($this->facts($listing), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
        ]];

        foreach ($images as $image) {
            $content[] = $image;
        }

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(Listing $listing): array
    {
        $deal = $listing->deal;
        $valuation = data_get($listing->analyst_report, 'valuation');
        $limit = (int) config('dealwatch.ai.deep_text_limit');

        return array_filter([
            'model' => $listing->displayName(),
            'title' => $listing->title,
            // Полный текст объявления, а не первые пара строк.
            'description' => $listing->description ? mb_substr($listing->description, 0, $limit) : null,
            'storage_gb' => $listing->storage_gb,
            'battery_health' => $listing->battery_health,
            'condition_field' => $listing->condition,
            'price_mdl' => $listing->priceForScoring(),
            'price_original' => $listing->price_original,
            'currency' => $listing->currency,
            'market_mid_mdl' => data_get($valuation, 'market_mid_clean'),
            'expected_sale_mdl' => data_get($valuation, 'expected_sale'),
            'max_buy_for_profit_mdl' => data_get($valuation, 'max_buy_for_profit'),
            'potential_profit_mdl' => $deal?->potential_profit,
            'engine_score' => $deal?->deal_score,
            'engine_verdict' => $deal?->verdict,
            'rule_based_flags' => $listing->analyst_flags,
            'rule_based_comment' => $listing->analyst_comment,
            'seller_type' => $listing->seller_type,
            'seller_listings_count' => $listing->seller_listings_count,
            'is_reseller' => $listing->is_reseller,
            'city' => $listing->location,
            'published_at' => optional($listing->published_at)->toDateTimeString(),
            'economics' => sprintf(
                'прибыль = продажа − цена − %d подготовка − %d резерв; интересно от %d MDL',
                (int) config('dealwatch.economics.prep_cost'),
                (int) config('dealwatch.economics.risk_reserve'),
                (int) config('dealwatch.economics.min_profit')
            ),
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * Фото берём из архива (локальные копии переживут удаление объявления),
     * иначе — прямые ссылки площадки.
     *
     * @return list<array<string, mixed>>
     */
    private function images(Listing $listing): array
    {
        $max = max(1, (int) config('dealwatch.ai.vision.max_images'));
        $detail = (string) config('dealwatch.ai.vision.detail');
        $parts = [];

        $snapshot = $listing->snapshots()->whereNotNull('image_paths')->first();

        if ($snapshot) {
            $disk = Storage::disk((string) config('dealwatch.archive.disk'));

            foreach (array_slice((array) $snapshot->image_paths, 0, $max) as $path) {
                if (! $disk->exists($path)) {
                    continue;
                }

                $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    default => 'image/jpeg',
                };

                $parts[] = [
                    'type' => 'input_image',
                    'image_url' => 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($path)),
                    'detail' => $detail,
                ];
            }
        }

        if ($parts !== []) {
            return $parts;
        }

        foreach (array_slice((array) ($listing->images ?? []), 0, $max) as $url) {
            if (is_string($url) && str_starts_with($url, 'http')) {
                $parts[] = ['type' => 'input_image', 'image_url' => $url, 'detail' => $detail];
            }
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'verdict' => ['type' => 'string', 'enum' => ['take', 'check', 'skip']],
                'condition_score' => ['type' => 'integer'],
                'target_price_mdl' => ['type' => ['integer', 'null']],
                'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'defects' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'source' => ['type' => 'string', 'enum' => ['text', 'photo']],
                            'label' => ['type' => 'string'],
                            'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                            'evidence' => ['type' => 'string'],
                            'price_impact_mdl' => ['type' => ['integer', 'null']],
                        ],
                        'required' => ['source', 'label', 'severity', 'evidence', 'price_impact_mdl'],
                        'additionalProperties' => false,
                    ],
                ],
                'mismatches' => ['type' => 'array', 'items' => ['type' => 'string']],
                'questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'checks_on_meeting' => ['type' => 'array', 'items' => ['type' => 'string']],
                'photo_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => [
                'summary', 'verdict', 'condition_score', 'target_price_mdl', 'confidence',
                'defects', 'mismatches', 'questions', 'checks_on_meeting', 'photo_notes',
            ],
            'additionalProperties' => false,
        ];
    }
}
