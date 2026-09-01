import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink } from 'lucide-react';

type Evidence = {
    headline: string;
    confidence: string;
    confidence_note: string;
    steps: Array<{ title: string; detail: string }>;
    calc: {
        market_mid: number;
        buy_max_rule: string;
        buy_max: number;
        buy_min: number;
        sell_low: number;
        sell_high: number;
    };
    observed: {
        count: number;
        private_count?: number;
        shop_count?: number;
        shop_median?: number | null;
        min: number | null;
        median: number | null;
        max: number | null;
        private_median: number | null;
        samples: Array<{
            id: number;
            title: string;
            price_original: number | null;
            price_mdl: number | null;
            currency: string | null;
            seller_type: string | null;
            url: string;
        }>;
        shop_samples?: Array<{
            id: number;
            title: string;
            price_original: number | null;
            price_mdl: number | null;
            currency: string | null;
            seller_type: string | null;
            url: string;
        }>;
    };
    distribution?: {
        total_all: number;
        total_private: number;
        total_shop?: number;
        shop_median?: number | null;
        share_in_sell_band_private: number;
        share_in_buy_zone_private: number;
        share_in_sell_band_shop?: number;
        zones: Array<{
            key: string;
            label: string;
            short_label?: string;
            from: number | null;
            to: number | null;
            tone: string;
            all: number;
            private: number;
            shop?: number;
            listings: Array<{
                id: number;
                title: string;
                price_mdl: number;
                price_original: number | null;
                currency: string | null;
                seller_type: string | null;
                url: string;
                is_bait?: boolean;
            }>;
        }>;
        histogram: Array<{
            label: string;
            from: number;
            to: number;
            count: number;
            in_sell_band: boolean;
            near_mid?: boolean;
        }>;
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
    target_buy: number;
    expected_profit_min: number;
    expected_profit_max: number;
    liquidity: number;
    rationale: string | null;
    basis: Record<string, string> | null;
    source: string | null;
    new_retail?: {
        price_mdl: number | null;
        warranty_months: number | null;
        shop: string | null;
        note: string | null;
        vs_mid_discount_percent: number | null;
    };
    evidence: Evidence;
};

type Props = {
    price: MarketRow;
    methodology: { title: string; summary: string; rules: string[]; formula: string };
    corpus?: {
        total: number;
        private: number;
        private_clean?: number;
        resellers?: number;
        reseller_share_percent?: number;
        from: string | null;
        to: string | null;
        label: string;
    };
    rates: { EUR: number; USD: number; MDL: number };
};

function money(v: number | null | undefined): string {
    if (v == null) return '—';
    return new Intl.NumberFormat('ru-MD').format(v) + ' MDL';
}

function zoneTone(tone: string): string {
    if (tone === 'danger') return 'surface-danger';
    if (tone === 'buy') return 'surface-info';
    if (tone === 'market') return 'surface-success';
    if (tone === 'high') return 'surface-warning';
    return 'surface-neutral';
}

function rangeLabel(from: number | null, to: number | null): string {
    if (from == null && to != null) return `< ${money(to)}`;
    if (from != null && to == null) return `> ${money(from)}`;
    if (from != null && to != null) return `${money(from)} – ${money(to)}`;
    return '—';
}

export default function MarketShow({ price, methodology, corpus, rates }: Props) {
    const evidence = price.evidence;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Рынок продаж', href: '/market' },
        { title: price.display_name, href: `/market/${price.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Основание · ${price.display_name}`} />
            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div>
                    <Button asChild variant="ghost" size="sm" className="mb-2 -ml-2">
                        <Link href="/market">
                            <ArrowLeft className="mr-1 size-4" />
                            Все модели
                        </Link>
                    </Button>
                    <h1 className="text-2xl font-semibold tracking-tight">{price.display_name}</h1>
                    <div className="mt-2 flex flex-wrap gap-2">
                        <Badge variant="outline">ликвидность {price.liquidity}/10</Badge>
                        <Badge variant="secondary">уверенность: {evidence.confidence}</Badge>
                    </div>
                </div>

                {corpus && (
                    <div className="surface-info rounded-xl border px-4 py-3 text-sm">
                        <div className="font-medium">{corpus.label}</div>
                    </div>
                )}

                <section className="surface-success rounded-xl border-2 p-4">
                    <div className="text-positive text-xs font-semibold tracking-wide uppercase">Основание рыночной цены (не просто число)</div>
                    <p className="mt-2 text-lg leading-snug font-semibold">{evidence.headline}</p>
                    <p className="mt-2 text-sm opacity-90">{evidence.confidence_note}</p>
                    <p className="mt-3 text-sm">{price.rationale}</p>
                </section>

                <section className="rounded-xl border p-4">
                    <h2 className="text-base font-semibold">Пошагово: как получили цифры</h2>
                    <div className="mt-3 space-y-3">
                        {evidence.steps.map((step) => (
                            <div key={step.title} className="bg-muted rounded-lg border px-3 py-3 dark:bg-zinc-900/40">
                                <div className="font-semibold">{step.title}</div>
                                <p className="text-muted-foreground mt-1 text-sm leading-relaxed">{step.detail}</p>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="rounded-xl border p-4">
                    <h2 className="text-base font-semibold">Итоговые цифры (после основания)</h2>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="rounded-xl border p-3">
                            <div className="text-muted-foreground text-xs">sell_low → sell_high</div>
                            <div className="mt-1 font-semibold">
                                {money(evidence.calc.sell_low)} – {money(evidence.calc.sell_high)}
                            </div>
                        </div>
                        <div className="surface-success rounded-xl border p-3">
                            <div className="text-positive text-xs">market_mid (б/у частник)</div>
                            <div className="text-positive mt-1 text-xl font-semibold">{money(evidence.calc.market_mid)}</div>
                        </div>
                        <div className="rounded-xl border p-3">
                            <div className="text-muted-foreground text-xs">buy_max / buy_min</div>
                            <div className="mt-1 font-semibold">
                                {money(evidence.calc.buy_max)} / {money(evidence.calc.buy_min)}
                            </div>
                            <div className="text-muted-foreground mt-1 text-xs">{evidence.calc.buy_max_rule}</div>
                        </div>
                        <div className="rounded-xl border p-3">
                            <div className="text-muted-foreground text-xs">Ожидаемая маржа</div>
                            <div className="text-positive mt-1 font-semibold">
                                {money(price.expected_profit_min)} – {money(price.expected_profit_max)}
                            </div>
                        </div>
                    </div>

                    <div className="surface-ai mt-3 rounded-xl border p-3">
                        <div className="text-info text-xs font-semibold tracking-wide uppercase">Новый в магазине (с гарантией)</div>
                        {price.new_retail?.price_mdl ? (
                            <>
                                <div className="mt-1 text-xl font-semibold tabular-nums">{money(price.new_retail.price_mdl)}</div>
                                <div className="mt-1 text-sm">
                                    Гарантия: <strong>{price.new_retail.warranty_months ?? '—'} мес.</strong>
                                    {price.new_retail.vs_mid_discount_percent != null && (
                                        <span>
                                            {' '}
                                            · б/у mid дешевле витрины на <strong>{price.new_retail.vs_mid_discount_percent}%</strong>
                                        </span>
                                    )}
                                </div>
                                {price.new_retail.shop && <div className="mt-1 text-xs opacity-80">{price.new_retail.shop}</div>}
                                {price.new_retail.note && <div className="mt-1 text-xs">{price.new_retail.note}</div>}
                            </>
                        ) : (
                            <div className="mt-1 text-sm">
                                {price.new_retail?.note || 'Как новый с витрины сейчас почти не продаётся — ориентир только б/у частники.'}
                            </div>
                        )}
                    </div>

                    <p className="text-muted-foreground mt-3 font-mono text-xs">{methodology.formula}</p>
                    <p className="text-muted-foreground mt-1 text-xs">
                        Курс конвертации: EUR={rates.EUR} · USD={rates.USD.toFixed(2)}
                    </p>
                </section>

                {evidence.distribution && evidence.distribution.total_all > 0 && (
                    <section className="rounded-xl border p-4">
                        <h2 className="text-base font-semibold">Сколько объявлений в каких ценах</h2>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Столбец = объём зоны. Основание рынка — частники; магазины считаются отдельно (частники:{' '}
                            {evidence.distribution.total_private}
                            {evidence.distribution.total_shop != null ? `, магазины: ${evidence.distribution.total_shop}` : ''}; «куплю» не входят).
                        </p>

                        {(() => {
                            const maxAll = Math.max(...evidence.distribution.zones.map((z) => z.all), 1);
                            const chartH = 140;

                            return (
                                <div className="mt-4 grid gap-3 lg:grid-cols-5">
                                    {evidence.distribution.zones.map((zone) => {
                                        const barPx = Math.max(zone.all > 0 ? 10 : 0, Math.round((zone.all / maxAll) * chartH));
                                        const share =
                                            evidence.distribution!.total_all > 0
                                                ? Math.round((zone.all / evidence.distribution!.total_all) * 100)
                                                : 0;

                                        return (
                                            <div key={zone.key} className={`flex flex-col overflow-hidden rounded-xl border ${zoneTone(zone.tone)}`}>
                                                {/* Histogram bar for THIS column */}
                                                <div className="surface-inset flex flex-col items-center border-b px-2 pt-3 pb-2">
                                                    <div className="text-lg leading-none font-bold tabular-nums">{zone.all}</div>
                                                    <div className="mt-0.5 text-[10px] opacity-70">
                                                        {zone.private} частн.
                                                        {zone.shop != null ? ` · ${zone.shop} маг.` : ''} · {share}%
                                                    </div>
                                                    <div className="mt-2 flex w-full items-end justify-center" style={{ height: chartH }}>
                                                        <div
                                                            className={`w-full max-w-[72px] rounded-t-md ${
                                                                zone.tone === 'market'
                                                                    ? 'bg-emerald-600'
                                                                    : zone.tone === 'buy'
                                                                      ? 'bg-sky-500'
                                                                      : zone.tone === 'danger'
                                                                        ? 'bg-red-500'
                                                                        : zone.tone === 'high'
                                                                          ? 'bg-amber-500'
                                                                          : 'bg-zinc-400'
                                                            }`}
                                                            style={{ height: `${barPx}px` }}
                                                            title={`${zone.label}: ${zone.all} объявл.`}
                                                        />
                                                    </div>
                                                </div>

                                                <div className="surface-inset border-b px-2.5 py-2">
                                                    <div className="text-xs leading-snug font-semibold">{zone.short_label || zone.label}</div>
                                                    <div className="mt-0.5 text-[10px] opacity-80">{rangeLabel(zone.from, zone.to)}</div>
                                                </div>

                                                <div className="flex-1 space-y-1.5 overflow-y-auto p-2" style={{ maxHeight: 420 }}>
                                                    {zone.listings.length === 0 && (
                                                        <div className="text-muted-foreground px-1 py-2 text-xs">Пусто</div>
                                                    )}
                                                    {zone.listings.map((item) => (
                                                        <a
                                                            key={item.id}
                                                            href={item.url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="hover:border-border surface-inset block rounded-md border px-2 py-1.5 text-xs"
                                                        >
                                                            <div className="font-semibold tabular-nums">{money(item.price_mdl)}</div>
                                                            <div className="mt-0.5 line-clamp-2 leading-snug opacity-90">{item.title}</div>
                                                            <div className="mt-0.5 flex flex-wrap gap-1 opacity-70">
                                                                {item.seller_type && <span>{item.seller_type}</span>}
                                                                {item.currency && item.currency !== 'MDL' && (
                                                                    <span>
                                                                        · {item.price_original} {item.currency}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </a>
                                                    ))}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            );
                        })()}
                    </section>
                )}

                <section className="rounded-xl border p-4">
                    <h2 className="text-base font-semibold">Живые доказательства из сборов</h2>
                    <p className="text-muted-foreground mt-1 text-sm">Конкретные объявления из нашей базы. Это проверка ориентира.</p>
                    <div className="mt-3 grid gap-3 sm:grid-cols-4">
                        <div className="rounded-lg border px-3 py-2 text-sm">
                            <div className="text-muted-foreground text-xs">Частники (sell)</div>
                            <div className="font-semibold">{evidence.observed.private_count ?? evidence.observed.count}</div>
                        </div>
                        <div className="rounded-lg border px-3 py-2 text-sm">
                            <div className="text-muted-foreground text-xs">Медиана частников</div>
                            <div className="font-semibold">{money(evidence.observed.private_median)}</div>
                        </div>
                        <div className="rounded-lg border px-3 py-2 text-sm">
                            <div className="text-muted-foreground text-xs">Магазины (отдельно)</div>
                            <div className="font-semibold">
                                {evidence.observed.shop_count ?? 0}
                                {evidence.observed.shop_median != null ? ` · ${money(evidence.observed.shop_median)}` : ''}
                            </div>
                        </div>
                        <div className="rounded-lg border px-3 py-2 text-sm">
                            <div className="text-muted-foreground text-xs">Min – Max частников</div>
                            <div className="font-semibold">
                                {money(evidence.observed.min)} – {money(evidence.observed.max)}
                            </div>
                        </div>
                    </div>

                    <div className="mt-4 space-y-2">
                        <div className="text-xs font-semibold tracking-wide uppercase opacity-70">Частники</div>
                        {evidence.observed.samples.length === 0 && (
                            <p className="text-muted-foreground text-sm">
                                Пока нет частных sell-объявлений — после «Собрать с 999» здесь появятся примеры.
                            </p>
                        )}
                        {evidence.observed.samples.map((item) => (
                            <a
                                key={item.id}
                                href={item.url}
                                target="_blank"
                                rel="noreferrer"
                                className="hover:border-border flex items-start justify-between gap-3 rounded-lg border px-3 py-2 text-sm"
                            >
                                <div>
                                    <div className="font-medium">{item.title}</div>
                                    <div className="text-muted-foreground mt-1">
                                        {item.price_original != null ? `${item.price_original} ${item.currency || 'MDL'}` : '—'}
                                        {item.currency && item.currency !== 'MDL'
                                            ? ` ≈ ${money(item.price_mdl)}`
                                            : item.price_mdl != null && item.currency === 'MDL'
                                              ? ` · ${money(item.price_mdl)}`
                                              : ''}
                                        {item.seller_type ? ` · ${item.seller_type}` : ''}
                                    </div>
                                </div>
                                <ExternalLink className="text-muted-foreground mt-1 size-4 shrink-0" />
                            </a>
                        ))}
                    </div>

                    {(evidence.observed.shop_samples?.length ?? 0) > 0 && (
                        <div className="mt-6 space-y-2">
                            <div className="text-xs font-semibold tracking-wide uppercase opacity-70">Магазины (не в основании mid)</div>
                            {evidence.observed.shop_samples!.map((item) => (
                                <a
                                    key={item.id}
                                    href={item.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="surface-warning flex items-start justify-between gap-3 rounded-lg border px-3 py-2 text-sm hover:border-orange-400"
                                >
                                    <div>
                                        <div className="font-medium">{item.title}</div>
                                        <div className="text-muted-foreground mt-1">
                                            {item.price_original != null ? `${item.price_original} ${item.currency || 'MDL'}` : '—'}
                                            {item.currency && item.currency !== 'MDL'
                                                ? ` ≈ ${money(item.price_mdl)}`
                                                : item.price_mdl != null && item.currency === 'MDL'
                                                  ? ` · ${money(item.price_mdl)}`
                                                  : ''}
                                            {' · shop'}
                                        </div>
                                    </div>
                                    <ExternalLink className="text-muted-foreground mt-1 size-4 shrink-0" />
                                </a>
                            ))}
                        </div>
                    )}
                </section>

                {price.source && <p className="text-muted-foreground text-xs">Источник ориентира: {price.source}</p>}
            </div>
        </AppLayout>
    );
}
