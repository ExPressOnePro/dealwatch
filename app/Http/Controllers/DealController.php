<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeDealBatch;
use App\Jobs\AnalyzeListingDeep;
use App\Jobs\CollectListings;
use App\Jobs\RefreshAnalytics;
use App\Models\AiBatchAnalysis;
use App\Models\Deal;
use App\Models\IgnoredListing;
use App\Models\ListingAiReport;
use App\Models\SearchProfile;
use App\Services\Ai\ListingDeepAnalyst;
use App\Services\Ai\OpenAiClient;
use App\Services\Collectors\NineNinetyNineCollector;
use App\Services\DealFeedQuery;
use App\Services\DealFeedStats;
use App\Services\ListingCorpusStats;
use App\Services\ListingPipeline;
use App\Services\ListingSubjectClassifier;
use App\Services\MarketEvidenceService;
use App\Services\PipelineRunStatus;
use App\Services\StatsCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DealController extends Controller
{
    public function index(
        Request $request,
        MarketEvidenceService $evidence,
        ListingCorpusStats $corpusStats,
        PipelineRunStatus $runs,
        DealFeedStats $feedStats,
        DealFeedQuery $feed,
    ): Response {
        $minScore = (int) $request->integer('min_score', 0);
        $maxScore = $request->filled('max_score') ? (int) $request->integer('max_score') : null;
        $scoreRange = $request->string('score_range')->toString() ?: 'all';
        $profitRange = $request->string('profit_range')->toString() ?: 'all';
        $verdict = $request->string('verdict')->toString() ?: 'all';
        $status = $request->string('status')->toString() ?: 'active';
        if (! in_array($status, [...Deal::STATUSES, 'all', 'active'], true)) {
            $status = 'active';
        }
        $sort = $request->string('sort')->toString() ?: 'profit';
        $segment = $request->string('segment')->toString() ?: 'targets';
        $modelKey = $request->string('model')->toString() ?: 'all';
        $profileId = $request->integer('profile') ?: null;

        // Map named score ranges onto min/max (overrides legacy min_score when set).
        if ($scoreRange !== 'all') {
            [$minScore, $maxScore] = match ($scoreRange) {
                '0-59' => [0, 59],
                '60-79' => [60, 79],
                '80+' => [80, null],
                default => [$minScore, $maxScore],
            };
        }

        $filters = [
            'segment' => $segment,
            'status' => $status,
            'verdict' => $verdict,
            'min_score' => $minScore,
            'max_score' => $maxScore,
            'profit_range' => $profitRange,
            'model' => $modelKey,
            'profile' => $profileId,
        ];

        $query = $feed->build($filters);

        if ($status === Deal::STATUS_DISMISSED) {
            $query->orderByDesc('deals.updated_at');
        } elseif ($sort === 'score') {
            $query->orderByDesc('deals.deal_score')->orderByDesc('deals.potential_profit')->orderByDesc('deals.id');
        } elseif ($sort === 'model') {
            $query->orderByRaw('listings.brand is null')
                ->orderBy('listings.brand')
                ->orderByRaw('listings.model is null')
                ->orderBy('listings.model')
                ->orderByRaw('listings.storage_gb is null')
                ->orderBy('listings.storage_gb')
                ->orderByDesc('deals.potential_profit')
                ->orderByDesc('deals.deal_score');
        } else {
            $query->orderByDesc('deals.potential_profit')->orderByDesc('deals.deal_score')->orderByDesc('deals.id');
        }

        $deals = $query->limit(200)->get();

        // Разборы конкретных объявлений — одним запросом на всю страницу.
        $aiReports = ListingAiReport::query()
            ->whereIn('listing_id', $deals->pluck('listing_id')->all())
            ->orderByDesc('id')
            ->get()
            ->groupBy('listing_id');

        $models = StatsCache::remember('dealwatch:stats:models:'.$segment.($profileId ? ':'.$profileId : ''), fn () => Deal::query()
            ->join('listings', 'listings.id', '=', 'deals.listing_id')
            ->tap(fn ($q) => $feed->applySegment($q, $segment))
            ->when($profileId, fn ($q) => $q->where('listings.search_profile_id', $profileId))
            ->whereIn('deals.user_status', Deal::ACTIVE_STATUSES)
            ->whereNotNull('listings.brand')
            ->whereNotNull('listings.model')
            ->selectRaw('listings.brand, listings.model, count(*) as deals_count')
            ->groupBy('listings.brand', 'listings.model')
            ->orderBy('listings.brand')
            ->orderBy('listings.model')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->brand.'|'.$row->model,
                'label' => trim($row->brand.' '.$row->model),
                'count' => (int) $row->deals_count,
            ])
            ->values()
            ->all());

        $zoneCache = [];
        $prepCost = (int) config('dealwatch.economics.prep_cost');
        $riskReserve = (int) config('dealwatch.economics.risk_reserve');

        $deals = $deals->map(function (Deal $deal) use (&$zoneCache, $evidence, $prepCost, $riskReserve, $aiReports) {
            $market = $deal->marketPriceRef;
            $buy = $deal->listing->priceForScoring();
            $valuation = data_get($deal->listing->analyst_report, 'valuation')
                ?? data_get($deal->score_breakdown, 'valuation');
            $cleanMid = (int) (data_get($valuation, 'market_mid_clean') ?: ($market?->marketMid() ?? $deal->market_price));
            $expectedSale = (int) (data_get($valuation, 'expected_sale') ?: $deal->market_price);

            $priceZones = null;
            if ($market) {
                $cacheKey = (string) $market->id;
                if (! array_key_exists($cacheKey, $zoneCache)) {
                    try {
                        $zoneCache[$cacheKey] = $evidence->zonesMini($market);
                    } catch (\Throwable) {
                        $zoneCache[$cacheKey] = null;
                    }
                }
                $base = $zoneCache[$cacheKey];
                if ($base) {
                    $ask = (int) ($buy ?? 0);
                    $priceZones = $base;
                    $priceZones['ask_price'] = $ask > 0 ? $ask : null;
                    $priceZones['ask_zone'] = $ask > 0
                        ? $evidence->zoneKeyForPrice(
                            $ask,
                            (int) $market->buy_min,
                            (int) $market->buy_max,
                            (int) $market->sell_low,
                            (int) $market->sell_high,
                        )
                        : null;
                }
            }

            return [
                'id' => $deal->id,
                'deal_score' => $deal->deal_score,
                'verdict' => $deal->verdict,
                'freshness' => $deal->freshness,
                'discount_percent' => $deal->discount_percent,
                'potential_profit' => $deal->potential_profit,
                'market_price' => $expectedSale,
                'market_mid_clean' => $cleanMid,
                'liquidity' => $deal->liquidity,
                'user_status' => $deal->user_status,
                'is_favorite' => (bool) $deal->is_favorite,
                'notified' => $deal->notified,
                'subject' => $deal->listing->subject,
                'subject_label' => ListingSubjectClassifier::label(
                    $deal->listing->subject,
                    (bool) $deal->listing->is_replica
                ),
                'staleness' => data_get($deal->score_breakdown, 'staleness'),
                'listing_age_days' => data_get($deal->score_breakdown, 'listing_age_days'),
                'stale_note' => data_get($deal->score_breakdown, 'stale_note'),
                'suspicious' => (bool) data_get($deal->score_breakdown, 'risk')
                    || (bool) $deal->listing->is_bait
                    || in_array($deal->listing->analyst_risk, ['high', 'medium'], true),
                'is_bait' => (bool) $deal->listing->is_bait,
                'is_reseller' => (bool) $deal->listing->is_reseller,
                'listing_kind' => $deal->listing->listing_kind ?? 'sell',
                'seller_listings_count' => (int) $deal->listing->seller_listings_count,
                'analyst_risk' => $deal->listing->analyst_risk,
                'analyst_comment' => $deal->listing->analyst_comment,
                'analyst_flags' => $deal->listing->analyst_flags ?? [],
                'analyst_report' => $deal->listing->analyst_report,
                'sms_text' => data_get($deal->listing->analyst_report, 'sms'),
                'valuation' => $valuation,
                'ai_reports' => $this->serializeAiReports($aiReports->get($deal->listing_id)),
                'price_zones' => $priceZones,
                'market' => $market ? [
                    'id' => $market->id,
                    'sell_low' => $market->sell_low,
                    'sell_high' => $market->sell_high,
                    'buy_max' => $market->buy_max,
                    'buy_min' => $market->buy_min,
                    'anchor' => data_get($market->basis, 'anchor'),
                    'buy_rule' => data_get($market->basis, 'buy_rule'),
                    'rationale' => $market->rationale,
                    'foundation' => data_get($market->basis, 'anchor')
                        ?: 'Частный рынок 999.md → mid '.number_format($cleanMid, 0, '.', ' ').' MDL',
                    'calc' => $buy && $expectedSale
                        ? sprintf(
                            'ожид. продажа %s − покупка %s − %s подготовка − %s риск = %s MDL',
                            number_format($expectedSale, 0, '.', ' '),
                            number_format((int) $buy, 0, '.', ' '),
                            number_format($prepCost, 0, '.', ' '),
                            number_format($riskReserve, 0, '.', ' '),
                            number_format((int) $deal->potential_profit, 0, '.', ' ')
                        )
                        : null,
                ] : null,
                'listing' => [
                    'id' => $deal->listing->id,
                    'external_id' => $deal->listing->external_id,
                    'title' => $deal->listing->title,
                    'description' => $deal->listing->description,
                    'display_name' => $deal->listing->displayName(),
                    'brand' => $deal->listing->brand,
                    'model' => $deal->listing->model,
                    'storage_gb' => $deal->listing->storage_gb,
                    'price_original' => $deal->listing->price_original,
                    'price_mdl' => $deal->listing->price_mdl,
                    'currency' => $deal->listing->currency,
                    'url' => $deal->listing->url,
                    'location' => $deal->listing->location,
                    'seller_phone' => $deal->listing->seller_phone,
                    'seller_type' => $deal->listing->seller_type,
                    'platform' => $deal->listing->platform,
                    'battery_health' => $deal->listing->battery_health,
                    'published_at' => optional($deal->listing->published_at)->toIso8601String(),
                    'first_seen_at' => optional($deal->listing->first_seen_at)->toIso8601String(),
                    'analyst_comment' => $deal->listing->analyst_comment,
                    'is_bait' => (bool) $deal->listing->is_bait,
                    'is_reseller' => (bool) $deal->listing->is_reseller,
                    'listing_kind' => $deal->listing->listing_kind ?? 'sell',
                    'seller_listings_count' => (int) $deal->listing->seller_listings_count,
                    'analyst_risk' => $deal->listing->analyst_risk,
                    'analyst_flags' => $deal->listing->analyst_flags ?? [],
                    'analyst_report' => $deal->listing->analyst_report,
                ],
            ];
        });

        // Выбран источник — вся страница показывает только его.
        $corpus = $corpusStats->summary(0, $profileId);

        return Inertia::render('deals/index', [
            'deals' => $deals,
            'stats' => $feedStats->headline($profileId),
            'runs' => $runs->all(),
            'analysis' => $this->latestAnalysis($request, $profileId),
            'ai' => [
                'configured' => app(OpenAiClient::class)->configured(),
                'vision' => app(ListingDeepAnalyst::class)->visionAvailable(),
            ],
            'corpus' => $corpus,
            'models' => $models,
            'active_source' => $profileId
                ? SearchProfile::query()->find($profileId)?->only(['id', 'name'])
                : null,
            'sources' => SearchProfile::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'scoring', 'is_active'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'scoring' => $p->scoring, 'is_active' => (bool) $p->is_active]),
            'filters' => [
                'min_score' => $minScore,
                'max_score' => $maxScore,
                'score_range' => $scoreRange,
                'profit_range' => $profitRange,
                'verdict' => $verdict,
                'status' => $status,
                'sort' => $sort,
                'segment' => $segment,
                'model' => $modelKey,
                'profile' => $profileId,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }

    /**
     * Последний текстовый и последний фото-разбор объявления.
     *
     * @param  Collection<int, ListingAiReport>|null  $reports
     * @return array<string, mixed>
     */
    private function serializeAiReports(mixed $reports): array
    {
        $reports = $reports ?: collect();

        $pick = fn (string $kind) => $reports->firstWhere('kind', $kind);

        $serialize = fn (?ListingAiReport $report) => $report ? [
            'id' => $report->id,
            'kind' => $report->kind,
            'status' => $report->status,
            'model' => $report->model,
            'verdict' => $report->verdict,
            'condition_score' => $report->condition_score,
            'target_price_mdl' => $report->target_price_mdl,
            'summary' => $report->summary,
            'defects' => data_get($report->payload, 'defects', []),
            'mismatches' => data_get($report->payload, 'mismatches', []),
            'questions' => data_get($report->payload, 'questions', []),
            'checks_on_meeting' => data_get($report->payload, 'checks_on_meeting', []),
            'photo_notes' => data_get($report->payload, 'photo_notes', []),
            'confidence' => data_get($report->payload, 'confidence'),
            'images_analyzed' => $report->images_analyzed,
            'cost_usd' => $report->cost_usd,
            'error' => $report->error,
            'created_at' => optional($report->created_at)->toIso8601String(),
        ] : null;

        return [
            'text' => $serialize($pick(ListingAiReport::KIND_TEXT)),
            'vision' => $serialize($pick(ListingAiReport::KIND_VISION)),
        ];
    }

    /**
     * Последний ИИ-разбор пользователя за сутки — его показываем над лентой.
     *
     * @return array<string, mixed>|null
     */
    private function latestAnalysis(Request $request, ?int $profileId): ?array
    {
        // Разбор относится к конкретной выборке: чужой источник показывать нельзя,
        // иначе на странице велосипедов висит вывод про телефоны.
        $analysis = AiBatchAnalysis::query()
            ->when($request->user(), fn ($q) => $q->where('user_id', $request->user()->id))
            ->where('created_at', '>=', now()->subDay())
            ->latest('id')
            ->limit(20)
            ->get()
            ->first(fn (AiBatchAnalysis $item) => (int) data_get($item->filters, 'profile') === (int) $profileId);

        if (! $analysis) {
            return null;
        }

        return [
            'id' => $analysis->id,
            'status' => $analysis->status,
            'source' => $analysis->source,
            'query' => $analysis->query,
            'listing_count' => $analysis->listing_count,
            'summary' => $analysis->summary,
            'recommendation' => $analysis->recommendation,
            'items' => $analysis->items ?? [],
            'cost_usd' => $analysis->cost_usd,
            'model_screen' => $analysis->model_screen,
            'model_deep' => $analysis->model_deep,
            'error' => $analysis->error,
            'created_at' => $analysis->created_at?->toIso8601String(),
        ];
    }

    public function updateStatus(Request $request, Deal $deal): RedirectResponse
    {
        $data = $request->validate([
            'user_status' => ['required', Rule::in(Deal::STATUSES)],
            'purchase_price' => 'nullable|integer|min:0',
            'sale_price' => 'nullable|integer|min:0',
        ]);

        $previous = $deal->user_status;
        $deal->update($data);
        StatsCache::flush();

        $listing = $deal->listing;
        if ($listing?->external_id) {
            if ($data['user_status'] === Deal::STATUS_DISMISSED) {
                IgnoredListing::remember(
                    (string) ($listing->platform ?: '999'),
                    (string) $listing->external_id,
                    $listing->title
                );
            } elseif ($previous === Deal::STATUS_DISMISSED && $data['user_status'] !== Deal::STATUS_DISMISSED) {
                IgnoredListing::forget(
                    (string) ($listing->platform ?: '999'),
                    (string) $listing->external_id
                );
            }
        }

        return back();
    }

    public function collect(Request $request, PipelineRunStatus $status): RedirectResponse
    {
        $status->queued(PipelineRunStatus::COLLECT);

        CollectListings::dispatch(notify: ! $request->boolean('no_notify', true));

        return back()->with('success', 'Сбор запущен в фоне — результат появится здесь, как только задача отработает.');
    }

    /** Убрать плашку о прогоне: она живёт сутки и сама не исчезает. */
    public function dismissRun(Request $request, PipelineRunStatus $status): RedirectResponse
    {
        $key = $request->string('key')->toString();

        if (! in_array($key, [PipelineRunStatus::COLLECT, PipelineRunStatus::ANALYTICS, PipelineRunStatus::AI_BATCH], true)) {
            return back()->with('error', 'Неизвестный статус.');
        }

        $status->forget($key);

        return back();
    }

    /**
     * Разобрать одно объявление: текст, а по желанию — ещё и фотографии.
     * Фото стоят дороже, поэтому включаются отдельной кнопкой.
     */
    public function aiReport(Request $request, Deal $deal, ListingDeepAnalyst $analyst, OpenAiClient $client): RedirectResponse
    {
        $withPhotos = $request->boolean('with_photos');

        if (! $client->configured()) {
            return back()->with('error', 'ИИ не настроен: добавь ключ OpenAI в админских настройках.');
        }

        if ($withPhotos && ! $analyst->visionAvailable()) {
            return back()->with('error', 'Разбор фотографий выключен — включи его в админских настройках.');
        }

        $deal->loadMissing('listing');

        $report = ListingAiReport::create([
            'listing_id' => $deal->listing_id,
            'deal_id' => $deal->id,
            'user_id' => $request->user()?->id,
            'kind' => $withPhotos ? ListingAiReport::KIND_VISION : ListingAiReport::KIND_TEXT,
            'status' => ListingAiReport::STATUS_RUNNING,
        ]);

        AnalyzeListingDeep::dispatch($report->id);

        return back()->with(
            'success',
            $withPhotos
                ? 'ИИ смотрит фотографии объявления — результат появится в карточке.'
                : 'ИИ разбирает объявление — результат появится в карточке.'
        );
    }

    /**
     * Отправить текущую выборку (или свободный запрос) на ИИ-разбор.
     */
    public function analyze(Request $request, PipelineRunStatus $status): RedirectResponse
    {
        $data = $request->validate([
            'query' => 'nullable|string|max:300',
            'profile' => 'nullable|integer|exists:search_profiles,id',
            'segment' => 'nullable|string|in:'.implode(',', DealFeedQuery::SEGMENTS),
            'status' => 'nullable|string',
            'verdict' => 'nullable|string',
            'profit_range' => 'nullable|string',
            'score_range' => 'nullable|string',
            'model' => 'nullable|string',
            'min_score' => 'nullable|integer|min:0|max:100',
            'max_score' => 'nullable|integer|min:0|max:100',
        ]);

        $query = trim((string) ($data['query'] ?? ''));

        $analysis = AiBatchAnalysis::create([
            'user_id' => $request->user()?->id,
            'source' => $query !== '' ? AiBatchAnalysis::SOURCE_QUERY : AiBatchAnalysis::SOURCE_FILTER,
            'query' => $query !== '' ? $query : null,
            'filters' => [
                'profile' => $request->integer('profile') ?: null,
                'segment' => $data['segment'] ?? 'targets',
                'status' => $data['status'] ?? 'active',
                'verdict' => $data['verdict'] ?? 'all',
                'profit_range' => $data['profit_range'] ?? 'all',
                'model' => $data['model'] ?? 'all',
                'min_score' => (int) ($data['min_score'] ?? 0),
                'max_score' => $data['max_score'] ?? null,
            ],
            'status' => AiBatchAnalysis::STATUS_RUNNING,
        ]);

        $status->queued(PipelineRunStatus::AI_BATCH);
        AnalyzeDealBatch::dispatch($analysis->id);

        return back()->with('success', 'ИИ разбирает выборку — результат появится на этой странице.');
    }

    public function refreshAnalytics(PipelineRunStatus $status): RedirectResponse
    {
        $status->queued(PipelineRunStatus::ANALYTICS);

        RefreshAnalytics::dispatch();

        return back()->with('success', 'Пересчёт аналитики запущен в фоне — это занимает несколько минут.');
    }

    public function importUrl(
        Request $request,
        ListingPipeline $pipeline,
        NineNinetyNineCollector $collector,
    ): RedirectResponse {
        $data = $request->validate([
            'url' => 'required|url',
        ]);

        if (! preg_match('#999\.md/(?:ru|ro)/(\d{6,})#', $data['url'], $m)) {
            return back()->with('error', 'Нужна ссылка вида https://999.md/ru/12345678');
        }

        $detail = $collector->fetchDetail($m[1]);
        if (! $detail) {
            return back()->with('error', 'Не удалось прочитать объявление');
        }

        // Карточка уже прочитана целиком — повторно ходить на 999 незачем.
        $deal = $pipeline->ingest($detail, notify: false, enrich: false);

        return back()->with(
            'success',
            $deal
                ? "Сделка #{$deal->id}: score {$deal->deal_score}, verdict {$deal->verdict}"
                : 'Объявление сохранено, но модель не распознана / нет рыночной цены'
        );
    }
}
