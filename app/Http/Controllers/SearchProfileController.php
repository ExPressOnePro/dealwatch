<?php

namespace App\Http\Controllers;

use App\Jobs\CollectListings;
use App\Models\SearchProfile;
use App\Services\Collectors\CategoryCatalog;
use App\Services\PipelineRunStatus;
use App\Services\ProfileMarketStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Источники объявлений: что именно DealWatch ищет на площадке.
 */
class SearchProfileController extends Controller
{
    public function __construct(
        private readonly CategoryCatalog $catalog,
        private readonly ProfileMarketStats $stats,
    ) {}

    public function index(Request $request): Response
    {
        $profiles = SearchProfile::query()
            ->withCount(['listings as active_listings' => fn ($q) => $q->where('status', 'active')])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (SearchProfile $profile) => [
                'id' => $profile->id,
                'name' => $profile->name,
                'description' => $profile->describe(),
                'category_id' => $profile->category_id,
                'subcategory_id' => $profile->subcategory_id,
                'category_label' => $profile->category_label,
                'query' => $profile->query,
                'exclude_keywords' => $profile->exclude_keywords ?? [],
                'price_min' => $profile->price_min,
                'price_max' => $profile->price_max,
                'per_run' => $profile->per_run,
                'scoring' => $profile->scoring,
                'notify' => $profile->notify,
                'is_active' => $profile->is_active,
                'last_run_at' => optional($profile->last_run_at)->toIso8601String(),
                'last_found' => $profile->last_found,
                'active_listings' => $profile->active_listings,
                'market' => $profile->isPhones() ? null : $this->stats->for($profile),
            ]);

        return Inertia::render('sources/index', [
            'profiles' => $profiles,
            'categories' => $this->catalog->tree(),
            'scorings' => SearchProfile::SCORINGS,
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $profile = SearchProfile::create($this->validated($request) + ['platform' => '999']);

        return back()->with('success', "Источник «{$profile->name}» создан. Нажми «Собрать», чтобы проверить его сразу.");
    }

    public function update(Request $request, SearchProfile $searchProfile): RedirectResponse
    {
        $searchProfile->update($this->validated($request));
        $this->stats->forget($searchProfile);

        return back()->with('success', 'Источник обновлён.');
    }

    public function destroy(SearchProfile $searchProfile): RedirectResponse
    {
        $name = $searchProfile->name;
        $searchProfile->delete();

        return back()->with('success', "Источник «{$name}» удалён. Собранные объявления остались в базе.");
    }

    /** Разовый сбор по одному источнику — удобно после настройки. */
    public function collect(SearchProfile $searchProfile, PipelineRunStatus $status): RedirectResponse
    {
        $status->queued(PipelineRunStatus::COLLECT);
        CollectListings::dispatch(notify: false, profileId: $searchProfile->id);

        return back()->with('success', "Сбор по источнику «{$searchProfile->name}» запущен в фоне.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $request->merge([
            'exclude_keywords' => array_values(array_filter(
                array_map('trim', (array) $request->input('exclude_keywords', [])),
                fn ($word) => $word !== ''
            )),
        ]);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'category_id' => 'nullable|integer|min:1',
            'subcategory_id' => 'nullable|integer|min:1',
            'category_label' => 'nullable|string|max:120',
            'query' => 'nullable|string|max:120',
            'exclude_keywords' => 'nullable|array|max:20',
            'exclude_keywords.*' => 'string|max:60',
            'price_min' => 'nullable|integer|min:0',
            'price_max' => array_filter([
                'nullable', 'integer', 'min:0',
                $request->filled('price_min') ? 'gte:price_min' : null,
            ]),
            'per_run' => 'required|integer|min:5|max:200',
            'scoring' => ['required', Rule::in(SearchProfile::SCORINGS)],
            'notify' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $data['exclude_keywords'] = ($data['exclude_keywords'] ?? []) ?: null;

        return $data;
    }
}
