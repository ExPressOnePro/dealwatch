import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { money } from '@/lib/deal-format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';

type Niche = {
    profile: { id: number; name: string; description: string; scoring: string; last_scan_at: string | null; last_scanned: number };
    period_days: number;
    volume: {
        total: number;
        active: number;
        gone_total: number;
        inflow: number;
        outflow: number;
        inflow_per_week: number;
        outflow_per_week: number;
        want_buy: number;
        non_items: number;
    };
    speed: {
        sell_through_percent: number | null;
        median_days_to_gone: number | null;
        fast_share_percent: number | null;
        stale_days_threshold: number;
        stale_listings: number;
        stale_share_percent: number | null;
    };
    prices: {
        samples: number;
        p25: number | null;
        median: number | null;
        p75: number | null;
        spread_percent: number | null;
        median_previous_period: number | null;
        median_change_percent: number | null;
        margin_potential: number | null;
        margin_note: string;
    };
    sellers: {
        accounts: number;
        reseller_listings: number;
        reseller_share_percent: number | null;
        shop_listings: number;
        shop_share_percent: number | null;
        listings_per_account: number | null;
    };
    price_moves: { tracked_listings: number; with_price_cut: number; avg_cut_percent: number | null; max_cut_percent: number | null };
    weekly: { label: string; inflow: number; outflow: number }[];
    top_sellers: {
        seller_key: string;
        listings: number;
        gone: number;
        active: number;
        is_reseller: boolean;
        seller_type: string | null;
        median_days_to_gone: number | null;
        median_price: number | null;
    }[];
    repeats: {
        groups: number;
        listings: number;
        share_percent: number | null;
        items: { title: string; times: number; is_reseller: boolean; first_seen: string | null; last_seen: string | null; prices: number[] }[];
    };
    hints: { type: string; text: string }[];
    verdict: { level: string; label: string; note: string };
};

type NicheListing = {
    id: number;
    title: string | null;
    raw_title: string | null;
    url: string | null;
    price_mdl: number | null;
    potential_profit: number | null;
    discount_percent: number | null;
    deal_score: number;
    verdict: string;
    freshness: string;
    seller_type: string | null;
    is_reseller: boolean;
    city: string | null;
    subject_label: string | null;
    staleness: string | null;
    listing_age_days: number | null;
    note: string | null;
    ai_summary: string | null;
    calc: string | null;
};

type Props = {
    profiles: { id: number; name: string; description: string; is_active: boolean }[];
    selected_id: number | null;
    days: number;
    niche: Niche | null;
    listings: NicheListing[];
    verdict_counts: { all: number; buy: number; check: number; ignore: number };
    listing_filters: { verdict: string; sort: string };
    flash?: { success?: string; error?: string };
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Ниши', href: '/niches' }];

const VERDICT_TONE_ROW: Record<string, string> = {
    buy: 'surface-success',
    check: 'surface-warning',
    ignore: 'surface-neutral',
};

const VERDICT_TONE: Record<string, string> = {
    hot: 'surface-success',
    warm: 'surface-warning',
    cold: 'surface-danger',
    unknown: 'surface-neutral',
};

function Metric({ label, value, hint }: { label: string; value: string; hint?: string | null }) {
    return (
        <div className="rounded-xl border px-4 py-3">
            <div className="text-muted-foreground text-xs tracking-wide uppercase">{label}</div>
            <div className="mt-1 text-2xl font-semibold">{value}</div>
            {hint && <div className="text-muted-foreground text-xs">{hint}</div>}
        </div>
    );
}

function FlowChart({ rows }: { rows: Niche['weekly'] }) {
    if (rows.length === 0) return null;
    const max = Math.max(...rows.flatMap((r) => [r.inflow, r.outflow]), 1);

    return (
        <div className="flex items-end gap-4 overflow-x-auto pb-1">
            {rows.map((row) => (
                <div key={row.label} className="flex min-w-16 flex-col items-center gap-1">
                    <div className="flex h-28 items-end gap-1">
                        <div
                            className="w-5 rounded-t bg-sky-400"
                            style={{ height: `${Math.max(3, (row.inflow / max) * 110)}px` }}
                            title={`Появилось: ${row.inflow}`}
                        />
                        <div
                            className="w-5 rounded-t bg-emerald-500"
                            style={{ height: `${Math.max(3, (row.outflow / max) * 110)}px` }}
                            title={`Ушло: ${row.outflow}`}
                        />
                    </div>
                    <div className="text-muted-foreground text-[10px]">{row.label}</div>
                    <div className="text-[10px] tabular-nums">
                        <span className="text-info">+{row.inflow}</span> / <span className="text-positive">−{row.outflow}</span>
                    </div>
                </div>
            ))}
        </div>
    );
}

const VERDICT_TAB: Record<string, string> = { all: 'Все', buy: 'Забирать', check: 'Смотреть', ignore: 'Пропуск' };
const SORT_LABEL: Record<string, string> = { profit: 'по прибыли', score: 'по score', price: 'по цене', age: 'по свежести' };

export default function Niches({ profiles, selected_id, days, niche, listings = [], verdict_counts, listing_filters, flash }: Props) {
    const go = (patch: Record<string, string | number>) => {
        router.get(
            '/niches',
            {
                profile: selected_id ?? undefined,
                days,
                verdict: listing_filters?.verdict ?? 'all',
                sort: listing_filters?.sort ?? 'profit',
                ...patch,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Анализ ниш" />
            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Анализ ниш</h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Живая ли категория: как быстро уходят объявления, кто в ней торгует и какой запас маржи.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            className="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                            value={selected_id ?? ''}
                            onChange={(e) => go({ profile: e.target.value })}
                        >
                            {profiles.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                    {p.is_active ? '' : ' (выключен)'}
                                </option>
                            ))}
                        </select>
                        <select
                            className="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                            value={days}
                            onChange={(e) => go({ days: e.target.value })}
                        >
                            {[7, 14, 30, 60, 90].map((d) => (
                                <option key={d} value={d}>
                                    {d} дней
                                </option>
                            ))}
                        </select>
                        {selected_id && (
                            <>
                                <Button onClick={() => router.post(`/niches/${selected_id}/full`, {}, { preserveScroll: true })}>
                                    <RefreshCw className="mr-1 size-4" />
                                    Полный анализ
                                </Button>
                                <Button variant="outline" onClick={() => router.post(`/niches/${selected_id}/scan`, {}, { preserveScroll: true })}>
                                    Только перепись
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {flash?.success && <div className="surface-success rounded-lg border px-3 py-2 text-sm">{flash.success}</div>}

                {!niche && (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">
                        Ниш пока нет. Заведи источник на странице{' '}
                        <Link href="/sources" className="underline">
                            Источники
                        </Link>
                        .
                    </div>
                )}

                {niche && (
                    <>
                        <div className={`rounded-xl border px-4 py-3 ${VERDICT_TONE[niche.verdict.level] ?? VERDICT_TONE.unknown}`}>
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <div className="text-lg font-semibold">{niche.verdict.label}</div>
                                <div className="text-xs opacity-75">
                                    {niche.profile.description}
                                    {niche.profile.last_scan_at
                                        ? ` · перепись ${new Date(niche.profile.last_scan_at).toLocaleString('ru-MD')} (${niche.profile.last_scanned} объявлений)`
                                        : ' · перепись не запускалась'}
                                </div>
                            </div>
                            <p className="mt-1 text-sm">{niche.verdict.note}</p>
                        </div>

                        {niche.hints.length > 0 && (
                            <div className="surface-warning space-y-1.5 rounded-xl border px-4 py-3 text-sm">
                                <div className="text-xs font-semibold tracking-wide uppercase">Настройка источника</div>
                                {niche.hints.map((hint) => (
                                    <p key={hint.type} className="leading-snug">
                                        {hint.text}
                                    </p>
                                ))}
                                <Link href="/sources" className="inline-block text-xs underline underline-offset-2">
                                    изменить источник
                                </Link>
                            </div>
                        )}

                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Metric
                                label="Уходит с площадки"
                                value={niche.speed.sell_through_percent != null ? `${niche.speed.sell_through_percent}%` : '—'}
                                hint={`ушло ${niche.volume.outflow} за ${niche.period_days} дн.`}
                            />
                            <Metric
                                label="Медиана до снятия"
                                value={niche.speed.median_days_to_gone != null ? `${niche.speed.median_days_to_gone} дн.` : '—'}
                                hint={niche.speed.fast_share_percent != null ? `за неделю уходит ${niche.speed.fast_share_percent}%` : null}
                            />
                            <Metric
                                label="Запас маржи"
                                value={niche.prices.margin_potential != null ? money(niche.prices.margin_potential) : '—'}
                                hint={niche.prices.median != null ? `медиана ${money(niche.prices.median)}` : null}
                            />
                            <Metric
                                label="Приток в неделю"
                                value={`${niche.volume.inflow_per_week}`}
                                hint={`уходит ${niche.volume.outflow_per_week} · активных ${niche.volume.active}`}
                            />
                        </div>

                        <section className="space-y-3 rounded-xl border p-4">
                            <h2 className="text-sm font-semibold tracking-tight">Приток и выбытие по неделям</h2>
                            <FlowChart rows={niche.weekly} />
                            <p className="text-muted-foreground text-xs">
                                Синие столбики — появилось объявлений, зелёные — ушло с площадки (косвенный признак продажи).
                            </p>
                        </section>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <section className="space-y-2 rounded-xl border p-4">
                                <h2 className="text-sm font-semibold tracking-tight">Цены</h2>
                                <div className="text-sm">
                                    p25 <strong>{money(niche.prices.p25)}</strong> · медиана <strong>{money(niche.prices.median)}</strong> · p75{' '}
                                    <strong>{money(niche.prices.p75)}</strong>
                                    {niche.prices.spread_percent != null && (
                                        <span className="text-muted-foreground"> · разброс {niche.prices.spread_percent}%</span>
                                    )}
                                </div>
                                {niche.prices.median_change_percent != null && (
                                    <div className="text-sm">
                                        Медиана к прошлому периоду:{' '}
                                        <strong className={niche.prices.median_change_percent > 0 ? 'text-negative' : 'text-positive'}>
                                            {niche.prices.median_change_percent > 0 ? '+' : ''}
                                            {niche.prices.median_change_percent}%
                                        </strong>
                                    </div>
                                )}
                                <p className="text-muted-foreground text-sm">{niche.prices.margin_note}</p>
                                <div className="text-muted-foreground text-sm">
                                    Продавцы двигают цену: {niche.price_moves.with_price_cut} из {niche.price_moves.tracked_listings} отслеженных
                                    {niche.price_moves.avg_cut_percent != null ? `, в среднем −${niche.price_moves.avg_cut_percent}%` : ''}
                                </div>
                                <div className="text-muted-foreground text-sm">
                                    Залежалось (без обновления ≥ {niche.speed.stale_days_threshold} дн.): {niche.speed.stale_listings}
                                    {niche.speed.stale_share_percent != null ? ` (${niche.speed.stale_share_percent}% активных)` : ''} — часть из них
                                    уже продана, просто не снята.
                                </div>
                            </section>

                            <section className="space-y-2 rounded-xl border p-4">
                                <h2 className="text-sm font-semibold tracking-tight">Кто торгует</h2>
                                <div className="text-sm">
                                    Аккаунтов: <strong>{niche.sellers.accounts}</strong>
                                    {niche.sellers.listings_per_account != null && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · в среднем {niche.sellers.listings_per_account} объявл. на аккаунт
                                        </span>
                                    )}
                                </div>
                                <div className="text-sm">
                                    Перекупы: <strong>{niche.sellers.reseller_share_percent ?? 0}%</strong> объявлений · магазины:{' '}
                                    <strong>{niche.sellers.shop_share_percent ?? 0}%</strong>
                                </div>
                                <div className="text-muted-foreground text-sm">
                                    Объявлений «куплю» в нише: {niche.volume.want_buy}
                                    {niche.volume.non_items > 0 && ` · запчастей, аксессуаров и реплик: ${niche.volume.non_items}`}
                                </div>
                                {niche.repeats.groups > 0 && (
                                    <div className="text-sm">
                                        Перевыставления: <strong>{niche.repeats.groups}</strong> товаров публиковались повторно
                                        {niche.repeats.share_percent != null ? ` (${niche.repeats.share_percent}% объявлений)` : ''} — верный признак,
                                        что они не продаются.
                                    </div>
                                )}
                            </section>
                        </div>

                        <section className="space-y-3 rounded-xl border p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-sm font-semibold tracking-tight">Объявления ниши ({verdict_counts?.all ?? 0})</h2>
                                <div className="flex flex-wrap items-center gap-2">
                                    {(['all', 'buy', 'check', 'ignore'] as const).map((v) => (
                                        <Button
                                            key={v}
                                            size="sm"
                                            variant={(listing_filters?.verdict ?? 'all') === v ? 'default' : 'outline'}
                                            onClick={() => go({ verdict: v })}
                                        >
                                            {VERDICT_TAB[v]}
                                            <span className="ml-1 opacity-70">{verdict_counts?.[v] ?? 0}</span>
                                        </Button>
                                    ))}
                                    <select
                                        className="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                                        value={listing_filters?.sort ?? 'profit'}
                                        onChange={(e) => go({ sort: e.target.value })}
                                    >
                                        {Object.entries(SORT_LABEL).map(([key, label]) => (
                                            <option key={key} value={key}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                    <Link
                                        href={`/deals?profile=${selected_id}&segment=all`}
                                        className="text-muted-foreground text-xs underline underline-offset-2"
                                    >
                                        открыть в ленте
                                    </Link>
                                </div>
                            </div>

                            {listings.length === 0 && (
                                <p className="text-muted-foreground text-sm">
                                    По этому фильтру объявлений нет. Запусти «Полный анализ», если источник только что создан.
                                </p>
                            )}

                            <ul className="space-y-2">
                                {listings.map((item) => (
                                    <li key={item.id} className="rounded-lg border px-3 py-2">
                                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge variant="outline" className={VERDICT_TONE_ROW[item.verdict] ?? ''}>
                                                    {VERDICT_TAB[item.verdict] ?? item.verdict}
                                                </Badge>
                                                {item.url ? (
                                                    <a
                                                        href={item.url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="font-medium underline underline-offset-2"
                                                    >
                                                        {item.title || item.raw_title}
                                                    </a>
                                                ) : (
                                                    <span className="font-medium">{item.title || item.raw_title}</span>
                                                )}
                                                {item.seller_type === 'shop' && (
                                                    <Badge variant="outline" className="text-[10px]">
                                                        магазин
                                                    </Badge>
                                                )}
                                                {item.is_reseller && (
                                                    <Badge variant="outline" className="surface-ai text-[10px]">
                                                        перекуп
                                                    </Badge>
                                                )}
                                                {item.subject_label && (
                                                    <Badge variant="outline" className="surface-warning text-[10px]">
                                                        {item.subject_label}
                                                    </Badge>
                                                )}
                                                {item.staleness === 'suspect' && (
                                                    <Badge variant="outline" className="surface-warning text-[10px]">
                                                        без обновления {item.listing_age_days} дн.
                                                    </Badge>
                                                )}
                                                {item.staleness === 'dead' && (
                                                    <Badge variant="outline" className="surface-neutral text-[10px]">
                                                        висит {item.listing_age_days} дн. — возможно, продано
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="text-right text-sm">
                                                <div>
                                                    <strong>{money(item.price_mdl)}</strong>
                                                    <span className="text-muted-foreground"> · score {item.deal_score}</span>
                                                </div>
                                                <div className={(item.potential_profit ?? 0) > 0 ? 'text-positive' : 'text-muted-foreground'}>
                                                    {item.potential_profit != null
                                                        ? `потенциал ${money(item.potential_profit)}`
                                                        : 'потенциал не считался'}
                                                    {item.discount_percent != null ? ` · −${Math.round(item.discount_percent)}%` : ''}
                                                </div>
                                            </div>
                                        </div>
                                        {(item.calc || item.note || item.ai_summary) && (
                                            <div className="text-muted-foreground mt-1 space-y-0.5 text-xs">
                                                {item.calc && <div>{item.calc}</div>}
                                                {item.note && <div>{item.note}</div>}
                                                {item.ai_summary && <div>ИИ: {item.ai_summary}</div>}
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>

                        {niche.top_sellers.length > 0 && (
                            <section className="space-y-3 rounded-xl border p-4">
                                <h2 className="text-sm font-semibold tracking-tight">Активные продавцы ниши</h2>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead className="text-muted-foreground text-left text-xs uppercase">
                                            <tr>
                                                <th className="py-2 pr-3">Аккаунт</th>
                                                <th className="py-2 pr-3">Объявлений</th>
                                                <th className="py-2 pr-3">Ушло</th>
                                                <th className="py-2 pr-3">Висит</th>
                                                <th className="py-2 pr-3">Медиана до снятия</th>
                                                <th className="py-2">Медиана цены</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {niche.top_sellers.map((seller) => (
                                                <tr key={seller.seller_key} className="border-t">
                                                    <td className="py-2 pr-3">
                                                        <span className="font-medium">{seller.seller_key.replace('999:', '')}</span>
                                                        {seller.is_reseller && (
                                                            <Badge variant="outline" className="surface-ai ml-2 text-[10px]">
                                                                перекуп
                                                            </Badge>
                                                        )}
                                                        {seller.seller_type === 'shop' && (
                                                            <Badge variant="outline" className="ml-2 text-[10px]">
                                                                магазин
                                                            </Badge>
                                                        )}
                                                    </td>
                                                    <td className="py-2 pr-3 tabular-nums">{seller.listings}</td>
                                                    <td className="py-2 pr-3 tabular-nums">{seller.gone}</td>
                                                    <td className="py-2 pr-3 tabular-nums">{seller.active}</td>
                                                    <td className="py-2 pr-3 tabular-nums">{seller.median_days_to_gone ?? '—'}</td>
                                                    <td className="py-2 tabular-nums">{money(seller.median_price)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        )}

                        {niche.repeats.items.length > 0 && (
                            <section className="space-y-2 rounded-xl border p-4">
                                <h2 className="text-sm font-semibold tracking-tight">Товары, которые публикуют снова и снова</h2>
                                <ul className="space-y-1.5 text-sm">
                                    {niche.repeats.items.map((item) => (
                                        <li
                                            key={item.title}
                                            className="flex flex-wrap items-baseline justify-between gap-2 border-b pb-1.5 last:border-0"
                                        >
                                            <span>
                                                {item.title}
                                                {item.is_reseller && <span className="text-muted-foreground"> · перекуп</span>}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {item.times} публикаций
                                                {item.prices.length > 1
                                                    ? ` · цены ${item.prices.map((p) => money(p)).join(' → ')}`
                                                    : item.prices.length === 1
                                                      ? ` · ${money(item.prices[0])}`
                                                      : ''}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
