<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\IgnoredListing;
use App\Services\Collectors\NineNinetyNineCollector;
use App\Services\ListingCorpusStats;
use App\Services\ListingPipeline;
use App\Services\MarketEvidenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class DealController extends Controller
{
    public function index(Request $request): Response
    {
        $minScore = (int) $request->integer('min_score', 0);
        $maxScore = $request->filled('max_score') ? (int) $request->integer('max_score') : null;
        $scoreRange = $request->string('score_range')->toString() ?: 'all';
        $profitRange = $request->string('profit_range')->toString() ?: 'all';
        $verdict = $request->string('verdict')->toString() ?: 'all';
        $status = $request->string('status')->toString() ?: 'active';
        $sort = $request->string('sort')->toString() ?: 'profit';
        $segment = $request->string('segment')->toString() ?: 'targets';
        $modelKey = $request->string('model')->toString() ?: 'all';

        // Map named score ranges onto min/max (overrides legacy min_score when set).
        if ($scoreRange !== 'all') {
            [$minScore, $maxScore] = match ($scoreRange) {
                '0-59' => [0, 59],
                '60-79' => [60, 79],
                '80+' => [80, null],
                default => [$minScore, $maxScore],
            };
        }

        $applySegment = function ($q) use ($segment) {
            $q->where('status', 'active');
            if ($segment === 'targets') {
                $q->marketSell()
                    ->where('seller_type', 'private')
                    ->where('is_reseller', false);
            } elseif ($segment === 'shops') {
                $q->marketSell()->where('seller_type', 'shop');
            } elseif ($segment === 'want_buy') {
                $q->wantBuy();
            } elseif ($segment === 'resellers') {
                $q->marketSell()->where('is_reseller', true);
            } elseif ($segment === 'private_all') {
                $q->marketSell()->where('seller_type', 'private');
            }
        };

        $query = Deal::query()
            ->with(['listing', 'marketPriceRef'])
            ->whereHas('listing', $applySegment)
            ->when($minScore > 0, fn ($q) => $q->where('deal_score', '>=', $minScore))
            ->when($maxScore !== null, fn ($q) => $q->where('deal_score', '<=', $maxScore))
            ->when($profitRange !== 'all', function ($q) use ($profitRange) {
                match ($profitRange) {
                    'lt800' => $q->where(function ($q2) {
                        $q2->whereNull('potential_profit')->orWhere('potential_profit', '<', 800);
                    }),
                    '800-1499' => $q->whereBetween('potential_profit', [800, 1499]),
                    '1500-2999' => $q->whereBetween('potential_profit', [1500, 2999]),
                    '3000+' => $q->where('potential_profit', '>=', 3000),
                    default => null,
                };
            })
            ->when(in_array($verdict, ['buy', 'check', 'ignore'], true), fn ($q) => $q->where('verdict', $verdict))
            ->when($status === 'active', function ($q) {
                $q->whereIn('user_status', ['new', 'opened', 'called'])
                    ->whereDoesntHave('listing', function ($lq) {
                        $lq->whereIn('external_id', function ($sub) {
                            $sub->select('external_id')->from('ignored_listings')->where('platform', '999');
                        });
                    });
            })
            ->when($status === 'dismissed', fn ($q) => $q->where(function ($q2) {
                $q2->where('user_status', 'dismissed')
                    ->orWhereHas('listing', function ($lq) {
                        $lq->whereIn('external_id', function ($sub) {
                            $sub->select('external_id')->from('ignored_listings')->where('platform', '999');
                        });
                    });
            }))
            ->when(! in_array($status, ['all', 'active', 'dismissed'], true), fn ($q) => $q->where('user_status', $status))
            ->when($modelKey !== 'all', function ($q) use ($modelKey) {
                if (! str_contains($modelKey, '|')) {
                    return;
                }
                [$brand, $model] = explode('|', $modelKey, 2);
                $q->whereHas('listing', function ($lq) use ($brand, $model) {
                    $lq->where('brand', $brand)->where('model', $model);
                });
            });

        if ($status === 'dismissed') {
            $query->orderByDesc('updated_at');
        } elseif ($sort === 'score') {
            $query->orderByDesc('deal_score')->orderByDesc('potential_profit')->orderByDesc('id');
        } elseif ($sort === 'model') {
            $query->join('listings', 'listings.id', '=', 'deals.listing_id')
                ->orderByRaw("listings.brand is null")
                ->orderBy('listings.brand')
                ->orderByRaw("listings.model is null")
                ->orderBy('listings.model')
                ->orderByRaw('listings.storage_gb is null')
                ->orderBy('listings.storage_gb')
                ->orderByDesc('deals.potential_profit')
                ->orderByDesc('deals.deal_score')
                ->select('deals.*');
        } else {
            $query->orderByDesc('potential_profit')->orderByDesc('deal_score')->orderByDesc('id');
        }

        $deals = $query->limit(200)->get();

        $models = Deal::query()
            ->join('listings', 'listings.id', '=', 'deals.listing_id')
            ->whereHas('listing', $applySegment)
            ->whereIn('deals.user_status', ['new', 'opened', 'called'])
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
            ->all();

        $zoneCache = [];
        $evidence = app(MarketEvidenceService::class);

        $deals = $deals->map(function (Deal $deal) use (&$zoneCache, $evidence) {
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
                            'ожид. продажа %s − покупка %s − 300 − 300 = %s MDL',
                            number_format($expectedSale, 0, '.', ' '),
                            number_format((int) $buy, 0, '.', ' '),
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
                    'price' => $deal->listing->price,
                    'price_original' => $deal->listing->price_original,
                    'price_mdl' => $deal->listing->price_mdl ?? $deal->listing->price,
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

        $active = fn ($q) => $q->whereIn('user_status', ['new', 'opened', 'called']);

        $targetListing = fn ($q) => $q
            ->where('status', 'active')
            ->where(function ($q2) {
                $q2->where('listing_kind', 'sell')->orWhereNull('listing_kind');
            })
            ->where('seller_type', 'private')
            ->where('is_reseller', false);

        $profitSum = (int) Deal::query()
            ->tap($active)
            ->where('verdict', 'buy')
            ->whereHas('listing', $targetListing)
            ->sum('potential_profit');

        $corpus = app(ListingCorpusStats::class)->summary(0);

        return Inertia::render('deals/index', [
            'deals' => $deals,
            'stats' => [
                'buy' => Deal::where('verdict', 'buy')->tap($active)
                    ->whereHas('listing', $targetListing)->count(),
                'check' => Deal::where('verdict', 'check')->tap($active)
                    ->whereHas('listing', $targetListing)->count(),
                'fresh' => Deal::where('freshness', 'fresh')->tap($active)
                    ->whereHas('listing', $targetListing)->count(),
                'total' => Deal::query()->tap($active)
                    ->whereHas('listing', $targetListing)->count(),
                'profit_sum' => $profitSum,
                'reseller_deals' => Deal::query()->tap($active)
                    ->whereHas('listing', fn ($q) => $q->marketSell()->where('is_reseller', true))->count(),
                'shop_deals' => Deal::query()->tap($active)
                    ->whereHas('listing', fn ($q) => $q->marketSell()->where('seller_type', 'shop'))->count(),
                'want_buy_deals' => Deal::query()->tap($active)
                    ->whereHas('listing', fn ($q) => $q->wantBuy())->count(),
                'hidden' => IgnoredListing::query()->count(),
            ],
            'corpus' => $corpus,
            'models' => $models,
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
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }

    public function updateStatus(Request $request, Deal $deal): RedirectResponse
    {
        $data = $request->validate([
            'user_status' => 'required|in:new,opened,called,bought,sold,dismissed',
            'purchase_price' => 'nullable|integer|min:0',
            'sale_price' => 'nullable|integer|min:0',
        ]);

        $previous = $deal->user_status;
        $deal->update($data);

        $listing = $deal->listing;
        if ($listing?->external_id) {
            if ($data['user_status'] === 'dismissed') {
                IgnoredListing::remember(
                    (string) ($listing->platform ?: '999'),
                    (string) $listing->external_id,
                    $listing->title
                );
            } elseif ($previous === 'dismissed' && $data['user_status'] !== 'dismissed') {
                IgnoredListing::forget(
                    (string) ($listing->platform ?: '999'),
                    (string) $listing->external_id
                );
            }
        }

        return back();
    }

    public function collect(Request $request): RedirectResponse
    {
        Artisan::call('deals:collect-999', [
            '--no-notify' => $request->boolean('no_notify', true),
        ]);

        return back()->with('success', 'Сбор завершён. '.trim(Artisan::output()));
    }

    public function refreshAnalytics(): RedirectResponse
    {
        Artisan::call('listings:reparse-models', [
            '--rebuild-market' => 1,
            '--recalculate' => 1,
        ]);

        $out = trim(Artisan::output());

        return back()->with(
            'success',
            'Аналитика обновлена: модели переразобраны (title-first), рынок и сделки пересчитаны. '
            .mb_substr($out, 0, 400)
        );
    }

    public function importUrl(Request $request, ListingPipeline $pipeline): RedirectResponse
    {
        $data = $request->validate([
            'url' => 'required|url',
        ]);

        if (! preg_match('#999\.md/(?:ru|ro)/(\d{6,})#', $data['url'], $m)) {
            return back()->with('error', 'Нужна ссылка вида https://999.md/ru/12345678');
        }

        $detail = app(NineNinetyNineCollector::class)->fetchDetail($m[1]);
        if (! $detail) {
            return back()->with('error', 'Не удалось прочитать объявление');
        }

        $deal = $pipeline->ingest($detail, notify: false);

        return back()->with(
            'success',
            $deal
                ? "Сделка #{$deal->id}: score {$deal->deal_score}, verdict {$deal->verdict}"
                : 'Объявление сохранено, но модель не распознана / нет рыночной цены'
        );
    }
}
