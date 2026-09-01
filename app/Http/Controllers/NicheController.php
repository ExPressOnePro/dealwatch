<?php

namespace App\Http\Controllers;

use App\Jobs\RunFullNicheAnalysis;
use App\Jobs\ScanNiche;
use App\Models\Deal;
use App\Models\SearchProfile;
use App\Services\ListingSubjectClassifier;
use App\Services\Niche\NicheAnalytics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Аналитика ниш: живая ли категория, как быстро уходит товар и сколько
 * реально можно заработать на разнице цен.
 */
class NicheController extends Controller
{
    public function __construct(
        private readonly NicheAnalytics $analytics,
    ) {}

    public function index(Request $request): Response
    {
        $days = (int) min(180, max(7, $request->integer('days', 30)));
        $verdict = $request->string('verdict')->toString() ?: 'all';
        $sort = $request->string('sort')->toString() ?: 'profit';
        $profiles = SearchProfile::query()->orderByDesc('is_active')->orderBy('name')->get();

        $selectedId = $request->integer('profile') ?: $profiles->first()?->id;
        $selected = $profiles->firstWhere('id', $selectedId);

        return Inertia::render('niches/index', [
            'profiles' => $profiles->map(fn (SearchProfile $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->describe(),
                'is_active' => (bool) $p->is_active,
            ]),
            'selected_id' => $selected?->id,
            'days' => $days,
            'niche' => $selected ? $this->analytics->forProfile($selected, $days) : null,
            'listings' => $selected ? $this->listings($selected, $verdict, $sort) : [],
            'verdict_counts' => $selected ? $this->verdictCounts($selected) : [],
            'listing_filters' => ['verdict' => $verdict, 'sort' => $sort],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }

    /**
     * Объявления источника со всеми разборами — чтобы не искать их в общей ленте.
     *
     * @return list<array<string, mixed>>
     */
    private function listings(SearchProfile $profile, string $verdict, string $sort, int $limit = 100): array
    {
        return Deal::query()
            ->select('deals.*')
            ->with(['listing', 'listing.aiReports'])
            ->join('listings', 'listings.id', '=', 'deals.listing_id')
            ->where('listings.search_profile_id', $profile->id)
            ->where('listings.status', 'active')
            ->whereIn('deals.user_status', Deal::ACTIVE_STATUSES)
            ->when(in_array($verdict, ['buy', 'check', 'ignore'], true), fn ($q) => $q->where('deals.verdict', $verdict))
            // Сортируем по цене самого объявления и по дате обновления на площадке,
            // а не по производным полям сделки.
            ->when($sort === 'score', fn ($q) => $q->orderByDesc('deals.deal_score'))
            ->when($sort === 'price', fn ($q) => $q->orderBy('listings.price_mdl'))
            ->when($sort === 'age', fn ($q) => $q->orderByDesc('listings.published_at'))
            ->when($sort === 'profit', fn ($q) => $q->orderByDesc('deals.potential_profit'))
            ->orderByDesc('deals.deal_score')
            ->limit($limit)
            ->get()
            ->map(function (Deal $deal) {
                $listing = $deal->listing;
                $aiReport = $listing?->aiReports->first();

                return [
                    'id' => $deal->id,
                    'title' => $listing?->displayName(),
                    'raw_title' => $listing?->title,
                    'url' => $listing?->url,
                    'price_mdl' => $listing?->priceForScoring(),
                    'potential_profit' => $deal->potential_profit,
                    'discount_percent' => $deal->discount_percent,
                    'deal_score' => $deal->deal_score,
                    'verdict' => $deal->verdict,
                    'freshness' => $deal->freshness,
                    'seller_type' => $listing?->seller_type,
                    'is_reseller' => (bool) $listing?->is_reseller,
                    'city' => $listing?->location,
                    'subject_label' => ListingSubjectClassifier::label(
                        $listing?->subject,
                        (bool) $listing?->is_replica
                    ),
                    'staleness' => data_get($deal->score_breakdown, 'staleness'),
                    'listing_age_days' => data_get($deal->score_breakdown, 'listing_age_days'),
                    'note' => data_get($deal->score_breakdown, 'stale_note')
                        ?? data_get($deal->score_breakdown, 'note')
                        ?? $listing?->analyst_comment,
                    'ai_summary' => $aiReport?->summary,
                    'calc' => data_get($deal->score_breakdown, 'calc'),
                ];
            })
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function verdictCounts(SearchProfile $profile): array
    {
        $rows = Deal::query()
            ->join('listings', 'listings.id', '=', 'deals.listing_id')
            ->where('listings.search_profile_id', $profile->id)
            ->where('listings.status', 'active')
            ->whereIn('deals.user_status', Deal::ACTIVE_STATUSES)
            ->selectRaw('deals.verdict, count(*) as total')
            ->groupBy('deals.verdict')
            ->pluck('total', 'verdict');

        return [
            'all' => (int) $rows->sum(),
            'buy' => (int) ($rows['buy'] ?? 0),
            'check' => (int) ($rows['check'] ?? 0),
            'ignore' => (int) ($rows['ignore'] ?? 0),
        ];
    }

    /** Полный проход: сбор, перепись, продавцы, оценки и аналитика разом. */
    public function full(SearchProfile $searchProfile): RedirectResponse
    {
        RunFullNicheAnalysis::dispatch($searchProfile->id);

        return back()->with(
            'success',
            "Полный анализ «{$searchProfile->name}» запущен: соберём свежее, пройдём каталог, пересчитаем продавцов и оценки."
        );
    }

    /** Разовая перепись каталога — она и даёт данные о скорости продаж. */
    public function scan(SearchProfile $searchProfile): RedirectResponse
    {
        ScanNiche::dispatch($searchProfile->id);

        return back()->with(
            'success',
            "Перепись «{$searchProfile->name}» запущена: проходим каталог и отмечаем, что ещё висит."
        );
    }
}
