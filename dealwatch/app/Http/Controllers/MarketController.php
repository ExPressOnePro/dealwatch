<?php

namespace App\Http\Controllers;

use App\Models\MarketPrice;
use App\Services\CurrencyRateService;
use App\Services\ListingCorpusStats;
use App\Services\MarketEvidenceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketController extends Controller
{
    public function __construct(
        private readonly MarketEvidenceService $evidence,
    ) {}

    public function index(Request $request): Response
    {
        $brand = $request->string('brand')->toString();

        $prices = MarketPrice::query()
            ->where('is_active', true)
            ->when($brand !== '', fn ($q) => $q->where('brand', $brand))
            ->orderBy('brand')
            ->orderBy('model')
            ->orderBy('storage_gb')
            ->get()
            ->map(fn (MarketPrice $p) => $this->serializePrice($p, withEvidence: true));

        $brands = MarketPrice::query()
            ->where('is_active', true)
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand');

        return Inertia::render('market/index', [
            'prices' => $prices,
            'brands' => $brands,
            'filters' => ['brand' => $brand ?: 'all'],
            'methodology' => $this->methodology(),
            'corpus' => app(ListingCorpusStats::class)->summary(0),
            'rates' => app(CurrencyRateService::class)->rates(),
        ]);
    }

    public function show(MarketPrice $marketPrice): Response
    {
        return Inertia::render('market/show', [
            'price' => $this->serializePrice($marketPrice, withEvidence: true),
            'methodology' => $this->methodology(),
            'corpus' => app(ListingCorpusStats::class)->summary(0),
            'rates' => app(CurrencyRateService::class)->rates(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePrice(MarketPrice $p, bool $withEvidence = false): array
    {
        $mid = $p->marketMid();
        $targetBuy = (int) round($mid * 0.80);
        $evidence = $withEvidence ? $this->evidence->for($p) : null;

        return [
            'id' => $p->id,
            'brand' => $p->brand,
            'model' => $p->model,
            'storage_gb' => $p->storage_gb,
            'display_name' => trim($p->brand.' '.$p->model.($p->storage_gb ? ' '.$p->storage_gb.' GB' : '')),
            'buy_min' => $p->buy_min,
            'buy_max' => $p->buy_max,
            'sell_low' => $p->sell_low,
            'sell_high' => $p->sell_high,
            'market_mid' => $mid,
            'target_buy' => $targetBuy,
            'new_retail' => [
                'price_mdl' => $p->new_price_mdl,
                'warranty_months' => $p->new_warranty_months,
                'shop' => $p->new_shop,
                'note' => $p->new_note,
                'vs_mid_discount_percent' => $p->new_price_mdl && $p->new_price_mdl > 0
                    ? round((1 - $mid / $p->new_price_mdl) * 100, 1)
                    : null,
            ],
            'expected_profit_min' => max(0, $p->sell_low - $p->buy_max - 600),
            'expected_profit_max' => max(0, $p->sell_high - $p->buy_min - 600),
            'liquidity' => $p->liquidity,
            'rationale' => $p->rationale,
            'basis' => $p->basis,
            'source' => $p->source,
            'evidence' => $evidence,
            'foundation_short' => $evidence['headline']
                ?? data_get($p->basis, 'anchor')
                ?? 'Частный рынок 999.md',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function methodology(): array
    {
        return [
            'title' => 'Правило: нет основания — нет цены',
            'summary' => 'Каждая рыночная цифра должна отвечать на вопрос: «откуда это?» — диапазон частной продажи на 999, расчёт mid, правило покупки и живые примеры.',
            'rules' => [
                'Сначала основание (якорь частного рынка), потом число.',
                'market_mid = (sell_low + sell_high) / 2 — от него считаются дисконт и прибыль.',
                'buy_max ≈ −15…−20% от mid, чтобы после подготовки осталось ≥1500 MDL.',
                'Магазин / гарантия / trade-in — не рынок перепродажи.',
                'EUR/USD сначала в MDL, потом сравнение с mid.',
                'Ориентиры sell/buy пересчитываются из частных объявлений в загруженной базе (до 2 месяцев).',
                'Живые объявления в базе — основание mid и проверка правила покупки.',
            ],
            'formula' => 'Прибыль = market_mid − цена_объявления_MDL − 300 подготовка − 300 риск',
        ];
    }
}
