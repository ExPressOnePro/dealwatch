import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

type Evidence = {
    headline: string;
    confidence: string;
    confidence_note: string;
    steps: Array<{ title: string; detail: string }>;
    observed: {
        count: number;
        private_count?: number;
        median: number | null;
        private_median: number | null;
    };
    distribution?: {
        total_private: number;
        share_in_sell_band_private: number;
        share_in_buy_zone_private: number;
        zones: Array<{ key: string; private: number; all: number; label: string; tone: string }>;
        histogram: Array<{ label: string; count: number; in_sell_band: boolean; from: number }>;
    };
};

type MarketRow = {
    id: number;
    display_name: string;
    buy_min: number;
    buy_max: number;
    sell_low: number;
    sell_high: number;
    market_mid: number;
    expected_profit_min: number;
    expected_profit_max: number;
    liquidity: number;
    rationale: string | null;
    basis: Record<string, string> | null;
    foundation_short: string;
    new_retail?: {
        price_mdl: number | null;
        warranty_months: number | null;
        shop: string | null;
        note: string | null;
        vs_mid_discount_percent: number | null;
    };
    evidence: Evidence | null;
};

type Props = {
    prices: MarketRow[];
    brands: string[];
    filters: { brand: string };
    methodology: {
        title: string;
        summary: string;
        rules: string[];
        formula: string;
    };
    corpus?: {
        total: number;
        private: number;
        private_clean?: number;
        shop?: number;
        shop_share_percent?: number;
        resellers?: number;
        reseller_share_percent?: number;
        want_buy?: number;
        want_buy_share_percent?: number;
        with_price: number;
        from: string | null;
        to: string | null;
        label: string;
    };
    rates: { EUR: number; USD: number; MDL: number };
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Рынок продаж', href: '/market' }];

function money(v: number): string {
    return new Intl.NumberFormat('ru-MD').format(v) + ' MDL';
}

function confidenceTone(level: string): string {
    if (level === 'высокая') return 'surface-success';
    if (level === 'средняя') return 'surface-warning';
    return 'surface-neutral';
}

export default function MarketIndex({ prices, brands, filters, methodology, corpus, rates }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Рынок продаж" />
            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Рынок продаж</h1>
                        <p className="text-muted-foreground mt-1 max-w-3xl text-sm">
                            Каждая цена здесь с основанием: якорь частного рынка → расчёт mid → правило покупки → проверка живыми объявлениями.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        onClick={() => {
                            if (confirm('Переразобрать модели по заголовкам и пересчитать рынок? ~10–20 сек.')) {
                                router.post('/deals/refresh-analytics');
                            }
                        }}
                    >
                        Обновить аналитику
                    </Button>
                </div>

                {corpus && (
                    <div className="surface-info rounded-xl border px-4 py-3 text-sm">
                        <div className="font-medium">{corpus.label}</div>
                        <div className="mt-1 opacity-80">
                            Основание рынка — только частные sell (без магазинов, перекупов и «куплю»). С ценой:{' '}
                            {corpus.with_price.toLocaleString('ru-MD')} · частники: {(corpus.private_clean ?? corpus.private).toLocaleString('ru-MD')}
                            {corpus.shop != null && (
                                <>
                                    {' '}
                                    · магазины: {corpus.shop.toLocaleString('ru-MD')}
                                    {corpus.shop_share_percent != null ? ` (${corpus.shop_share_percent}% sell)` : ''}
                                </>
                            )}
                            {corpus.want_buy != null && corpus.want_buy > 0 && <> · куплю: {corpus.want_buy.toLocaleString('ru-MD')} (вне рынка)</>}
                            {corpus.resellers != null && corpus.resellers > 0 && (
                                <>
                                    {' '}
                                    · перекупы: {corpus.resellers.toLocaleString('ru-MD')}
                                    {corpus.reseller_share_percent != null ? ` (${corpus.reseller_share_percent}%)` : ''}
                                </>
                            )}
                            {corpus.from && corpus.to ? ` · ${corpus.from} – ${corpus.to}` : ''}
                        </div>
                    </div>
                )}

                <section className="surface-warning rounded-xl border p-4">
                    <h2 className="text-base font-semibold">{methodology.title}</h2>
                    <p className="mt-1 text-sm">{methodology.summary}</p>
                    <ul className="mt-3 list-disc space-y-1 pl-5 text-sm">
                        {methodology.rules.map((rule) => (
                            <li key={rule}>{rule}</li>
                        ))}
                    </ul>
                    <p className="surface-inset mt-3 rounded-lg border border-amber-200 px-3 py-2 font-mono text-xs">{methodology.formula}</p>
                    <p className="mt-2 text-xs opacity-80">
                        Курс: 1 EUR = {rates.EUR} MDL · 1 USD = {rates.USD.toFixed(2)} MDL
                    </p>
                </section>

                <div className="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant={filters.brand === 'all' || !filters.brand ? 'default' : 'outline'}
                        onClick={() => router.get('/market', {}, { preserveState: true })}
                    >
                        Все бренды
                    </Button>
                    {brands.map((brand) => (
                        <Button
                            key={brand}
                            size="sm"
                            variant={filters.brand === brand ? 'default' : 'outline'}
                            onClick={() => router.get('/market', { brand }, { preserveState: true })}
                        >
                            {brand}
                        </Button>
                    ))}
                </div>

                <div className="space-y-3">
                    {prices.map((row) => (
                        <Link key={row.id} href={`/market/${row.id}`} className="hover:border-border block rounded-xl border p-4 transition">
                            <div className="flex flex-wrap items-center gap-2">
                                <h3 className="text-lg font-semibold">{row.display_name}</h3>
                                <Badge variant="outline">ликвидность {row.liquidity}/10</Badge>
                                {row.evidence && (
                                    <Badge className={confidenceTone(row.evidence.confidence)}>основание: {row.evidence.confidence}</Badge>
                                )}
                            </div>

                            <div className="surface-success mt-3 rounded-lg border px-3 py-2 text-sm">
                                <div className="text-positive text-xs font-semibold tracking-wide uppercase">Основание рыночной цены</div>
                                <div className="mt-1 font-medium">{row.foundation_short}</div>
                                {row.basis?.buy_rule && <div className="mt-1 opacity-80">Правило покупки: {row.basis.buy_rule}</div>}
                                {row.evidence?.observed?.private_median != null && (
                                    <div className="mt-1 text-xs">
                                        Живая медиана частников в базе: {money(row.evidence.observed.private_median)} (n=
                                        {row.evidence.observed.private_count ?? row.evidence.observed.count})
                                    </div>
                                )}
                                {row.evidence?.distribution && row.evidence.distribution.total_private > 0 && (
                                    <div className="mt-3">
                                        <div className="text-xs font-semibold opacity-80">Объём по 5 ценовым зонам</div>
                                        <div className="mt-1.5 grid grid-cols-5 gap-1">
                                            {(() => {
                                                const max = Math.max(...row.evidence.distribution.zones.map((z) => z.all), 1);
                                                return row.evidence.distribution.zones.map((z) => {
                                                    const px = Math.max(3, Math.round((z.all / max) * 36));
                                                    const tone =
                                                        z.key === 'sell_band'
                                                            ? 'bg-emerald-600'
                                                            : z.key === 'buy_zone'
                                                              ? 'bg-sky-500'
                                                              : z.key === 'below_buy_min'
                                                                ? 'bg-red-400'
                                                                : z.key === 'above_sell'
                                                                  ? 'bg-amber-500'
                                                                  : 'bg-emerald-900/30';
                                                    return (
                                                        <div key={z.key} className="flex flex-col items-center">
                                                            <div className="text-[10px] font-semibold tabular-nums">{z.all}</div>
                                                            <div className="flex h-9 w-full items-end justify-center">
                                                                <div
                                                                    className={`w-full rounded-t ${tone}`}
                                                                    style={{ height: `${px}px` }}
                                                                    title={`${z.label}: ${z.all}`}
                                                                />
                                                            </div>
                                                        </div>
                                                    );
                                                });
                                            })()}
                                        </div>
                                        <div className="mt-1 grid grid-cols-5 gap-1 text-center text-[9px] opacity-70">
                                            {['ниже', 'покупка', 'между', 'продажа', 'выше'].map((label) => (
                                                <span key={label}>{label}</span>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>

                            {row.new_retail && (
                                <div className="surface-ai mt-3 rounded-lg border px-3 py-2 text-sm">
                                    <div className="text-info text-xs font-semibold tracking-wide uppercase">Новый в магазине</div>
                                    {row.new_retail.price_mdl ? (
                                        <div className="mt-1">
                                            <strong>{money(row.new_retail.price_mdl)}</strong>
                                            {row.new_retail.warranty_months ? ` · гарантия ${row.new_retail.warranty_months} мес.` : ''}
                                            {row.new_retail.vs_mid_discount_percent != null
                                                ? ` · б/у mid −${row.new_retail.vs_mid_discount_percent}% к витрине`
                                                : ''}
                                        </div>
                                    ) : (
                                        <div className="mt-1 text-xs">{row.new_retail.note || 'На витрине как новый почти не встречается'}</div>
                                    )}
                                </div>
                            )}

                            <p className="text-muted-foreground mt-3 text-sm">{row.rationale}</p>

                            <div className="mt-3 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <div className="text-muted-foreground text-xs">Продажа (частник)</div>
                                    <div className="font-semibold">
                                        {money(row.sell_low)} – {money(row.sell_high)}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground text-xs">market_mid (для сделок)</div>
                                    <div className="text-positive font-semibold">{money(row.market_mid)}</div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground text-xs">Макс. покупка</div>
                                    <div className="font-semibold">{money(row.buy_max)}</div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground text-xs">Ожидаемая маржа</div>
                                    <div className="text-positive font-semibold">
                                        +{new Intl.NumberFormat('ru-MD').format(row.expected_profit_min)}–
                                        {new Intl.NumberFormat('ru-MD').format(row.expected_profit_max)} MDL
                                    </div>
                                </div>
                            </div>
                            <div className="text-muted-foreground mt-3 text-sm">Полный разбор основания →</div>
                        </Link>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
