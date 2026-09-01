import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Copy, ExternalLink, MessageSquare, Phone, RefreshCw, Search, Star } from 'lucide-react';
import { useState } from 'react';

type Valuation = {
    condition_score: number;
    condition_label: string;
    valuation_confidence: string;
    valuation_confidence_note: string;
    market_mid_clean: number;
    sell_low_clean: number;
    sell_high_clean: number;
    condition_haircut_percent: number;
    negotiation_percent: number;
    fair_ask: number;
    expected_sale: number;
    quick_sale: number;
    optimistic_sale: number;
    penalties: Array<{ code: string; label: string; percent: number }>;
    max_buy_for_profit: number;
    profit_at_expected: number | null;
    profit_at_quick: number | null;
    summary: string;
};

type DealRow = {
    id: number;
    deal_score: number;
    verdict: 'buy' | 'check' | 'ignore';
    freshness: string;
    discount_percent: number | null;
    potential_profit: number | null;
    market_price: number | null;
    liquidity: number | null;
    user_status: string;
    is_favorite?: boolean;
    notified: boolean;
    suspicious?: boolean;
    is_bait?: boolean;
    is_reseller?: boolean;
    listing_kind?: string;
    seller_listings_count?: number;
    analyst_risk?: string | null;
    analyst_comment?: string | null;
    analyst_flags?: string[];
    analyst_report?: {
        known?: string[];
        ask?: string[];
        risks?: Array<{ label: string; probability: number; detail: string }>;
        sms?: string;
        battery_from_text?: number | null;
        valuation?: Valuation;
        brief?: {
            headline?: string;
            model?: {
                label: string;
                title: string;
                site_model?: string | null;
                source?: string | null;
                confidence?: number | null;
                storage_gb?: number | null;
            };
            price_alerts?: string[];
            text_alerts?: string[];
            seller_facts?: string[];
            general?: string[];
            risk_level?: string;
            flags?: string[];
            valuation?: {
                headline?: string;
                expected_sale?: number;
                condition_score?: number;
                condition_label?: string;
            };
        };
        notes?: string[];
    } | null;
    sms_text?: string | null;
    valuation?: Valuation | null;
    market_mid_clean?: number | null;
    price_zones?: {
        total_private: number;
        total_shop: number;
        private_median: number | null;
        buy_min: number;
        buy_max: number;
        sell_low: number;
        sell_high: number;
        mid: number;
        zones: Array<{
            key: string;
            short_label: string;
            from: number | null;
            to: number | null;
            tone: string;
            all: number;
            private: number;
            shop: number;
        }>;
        ask_zone: string | null;
        ask_price: number | null;
    } | null;
    market?: {
        id: number;
        sell_low: number;
        sell_high: number;
        buy_max: number;
        buy_min?: number;
        anchor?: string | null;
        buy_rule?: string | null;
        rationale?: string | null;
        foundation: string;
        calc?: string | null;
    } | null;
    listing: {
        id: number;
        external_id?: string;
        title: string;
        description?: string | null;
        display_name: string;
        brand?: string | null;
        model?: string | null;
        storage_gb?: number | null;
        price: number | null;
        price_original?: number | null;
        price_mdl?: number | null;
        currency?: string | null;
        url: string;
        location: string | null;
        seller_phone: string | null;
        seller_type: string | null;
        platform: string;
        battery_health: number | null;
        published_at: string | null;
        first_seen_at: string | null;
        analyst_comment?: string | null;
        is_bait?: boolean;
        is_reseller?: boolean;
        listing_kind?: string;
        seller_listings_count?: number;
        analyst_risk?: string | null;
        analyst_flags?: string[];
        analyst_report?: {
            known?: string[];
            ask?: string[];
            risks?: Array<{ label: string; probability: number; detail: string }>;
            sms?: string;
            battery_from_text?: number | null;
        } | null;
    };
};

type ModelOption = { key: string; label: string; count: number };

type Props = {
    deals: DealRow[];
    stats: {
        buy: number;
        check: number;
        fresh: number;
        total: number;
        profit_sum: number;
        reseller_deals?: number;
        shop_deals?: number;
        want_buy_deals?: number;
        hidden?: number;
    };
    corpus?: {
        total: number;
        sell_total?: number;
        private: number;
        private_clean?: number;
        private_share_percent?: number;
        shop: number;
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
    models?: ModelOption[];
    filters: {
        min_score: number;
        max_score?: number | null;
        score_range?: string;
        profit_range?: string;
        verdict: string;
        status: string;
        sort: string;
        segment?: string;
        model?: string;
    };
    flash?: { success?: string; error?: string };
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Deals', href: '/deals' }];

function money(value: number | null | undefined, currency = 'MDL'): string {
    if (value == null) return '—';
    return new Intl.NumberFormat('ru-MD').format(value) + ' ' + currency;
}

function listingBuyPrice(deal: DealRow): string {
    const currency = deal.listing.currency || 'MDL';
    const original = deal.listing.price_original ?? deal.listing.price;
    const mdl = deal.listing.price_mdl ?? deal.listing.price;
    if (original == null) return '—';
    if (currency === 'MDL') return money(original, 'MDL');
    return `${money(original, currency)} ≈ ${money(mdl, 'MDL')}`;
}

function profitLabel(value: number | null | undefined): string {
    if (value == null) return '—';
    const sign = value > 0 ? '+' : '';
    return sign + new Intl.NumberFormat('ru-MD').format(value) + ' MDL';
}

function scoreTone(score: number): string {
    if (score >= 80) return 'bg-emerald-600 text-white';
    if (score >= 60) return 'bg-amber-500 text-black';
    return 'bg-zinc-400 text-black';
}

function profitTone(value: number | null | undefined): string {
    if (value == null) return 'text-zinc-500';
    if (value >= 1500) return 'text-emerald-700';
    if (value >= 800) return 'text-lime-700';
    if (value >= 0) return 'text-amber-700';
    return 'text-red-600';
}

function verdictLabel(v: string): string {
    return v === 'buy' ? 'ЗАБИРАТЬ' : v === 'check' ? 'СМОТРЕТЬ' : 'ПРОПУСК';
}

function screeningMessage(deal: DealRow): string {
    const fromReport = deal.sms_text || deal.analyst_report?.sms || deal.listing.analyst_report?.sms;
    if (fromReport) {
        return fromReport;
    }

    const name = deal.listing.display_name || deal.listing.title;
    const price = listingBuyPrice(deal);

    return [
        `Здравствуйте! Интересует ${name} (объявление: ${price}).`,
        'Перед встречей уточните, пожалуйста:',
        '1) Аккумулятор сколько %?',
        '2) Face ID / отпечаток работает?',
        '3) iCloud чистый, без блокировок?',
        '4) Экран/корпус родные? Был ли ремонт?',
        '5) Цена окончательная — сколько?',
        '6) Где и когда удобно посмотреть?',
        'Спасибо!',
    ].join('\n');
}

function phoneForSms(phone: string): string {
    return phone.replace(/[^\d+]/g, '');
}

function sellerDescription(deal: DealRow): string {
    return (deal.listing.description || '').trim();
}

function sellerCopyPayload(deal: DealRow): string {
    const lines: string[] = [];
    const id = deal.listing.external_id;
    const url = deal.listing.url || (id ? `https://999.md/ru/${id}` : '');

    if (id) {
        lines.push(`999.md #${id}`);
    }
    if (url) {
        lines.push(url);
    }
    if (deal.listing.location) {
        lines.push(`Регион: ${deal.listing.location}`);
    }

    const desc = sellerDescription(deal);
    if (desc) {
        if (lines.length) {
            lines.push('');
        }
        lines.push(desc);
    }

    return lines.join('\n');
}

async function copyToClipboard(text: string): Promise<boolean> {
    if (!text) return false;
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        return false;
    }
}

function reportOf(deal: DealRow) {
    return deal.analyst_report || deal.listing.analyst_report || null;
}

function valuationOf(deal: DealRow): Valuation | null {
    return deal.valuation || reportOf(deal)?.valuation || null;
}

const MODEL_SOURCE_LABELS: Record<string, string> = {
    site: 'Модель на сайте',
    title: 'Из заголовка',
    description: 'Из описания',
};

function modelSourceLabel(source?: string | null): string | null {
    if (!source) return null;
    return MODEL_SOURCE_LABELS[source] ?? source;
}

const FLAG_LABELS: Record<string, string> = {
    bait_price: 'Кликбейт-цена',
    negotiable: 'Договорная',
    clickbait_text: 'Цена для просмотров',
    deposit: 'Задаток',
    exchange: 'Обмен',
    incomplete: 'Мало текста',
    parts_or_lock: 'Блок / запчасти',
    replaced_parts: 'Замены',
    no_face_id: 'Нет Face ID',
    face_id_ok: 'Face ID OK',
    wireless_broken: 'NFC/BT/Wi‑Fi',
    low_battery: 'Слабая АКБ',
};

function AnalysisBrief({ deal }: { deal: DealRow }) {
    const report = reportOf(deal);
    const brief = report?.brief;
    const valuation = valuationOf(deal);
    const flags = brief?.flags ?? deal.analyst_flags ?? deal.listing.analyst_flags ?? [];

    const priceAlerts = brief?.price_alerts ?? [];
    const textAlerts = brief?.text_alerts ?? [];
    const sellerFacts = brief?.seller_facts ?? report?.known ?? [];
    const risks = report?.risks ?? [];
    const general = brief?.general ?? [];
    const model = brief?.model ?? {
        label: deal.listing.display_name,
        title: deal.listing.title,
        confidence: null,
        storage_gb: null,
    };
    let headline = brief?.headline || deal.analyst_comment || deal.listing.analyst_comment;
    let generalNotes = [...general];

    // Legacy comments were one long line joined by « · ».
    if (!brief && headline?.includes(' · ')) {
        generalNotes = headline.split(' · ').map((s) => s.trim()).filter(Boolean);
        headline = generalNotes[0] ?? headline;
        generalNotes = generalNotes.slice(1);
    }

    if (!headline && !model && priceAlerts.length === 0 && textAlerts.length === 0 && sellerFacts.length === 0 && risks.length === 0) {
        return null;
    }

    const tone =
        deal.is_bait || deal.analyst_risk === 'high'
            ? 'border-red-200 bg-red-50 text-red-950'
            : deal.analyst_risk === 'medium'
              ? 'border-amber-200 bg-amber-50 text-amber-950'
              : 'border-zinc-200 bg-zinc-50 text-zinc-800';

    const Section = ({
        title,
        items,
        className = '',
    }: {
        title: string;
        items: string[];
        className?: string;
    }) =>
        items.length > 0 ? (
            <div className={className}>
                <div className="text-xs font-semibold uppercase tracking-wide opacity-70">{title}</div>
                <ul className="mt-1 space-y-1">
                    {items.map((item) => (
                        <li key={item} className="flex gap-2 text-sm leading-snug">
                            <span className="mt-2 size-1.5 shrink-0 rounded-full bg-current opacity-40" />
                            <span>{item}</span>
                        </li>
                    ))}
                </ul>
            </div>
        ) : null;

    return (
        <div className={`mt-2 rounded-lg border px-3 py-3 text-sm ${tone}`}>
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="text-xs font-semibold uppercase tracking-wide opacity-80">Разбор модели</div>
                {flags.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                        {flags.slice(0, 6).map((f) => (
                            <Badge key={f} variant="outline" className="border-black/10 bg-white/60 text-[10px]">
                                {FLAG_LABELS[f] ?? f}
                            </Badge>
                        ))}
                    </div>
                )}
            </div>

            {model && (
                <div className="mt-2 rounded-md border border-black/5 bg-white/50 px-2.5 py-2">
                    <div className="text-base font-semibold leading-tight">{model.label}</div>
                    {model.title && model.title !== model.label && (
                        <div className="mt-0.5 text-xs opacity-75">Заголовок: {model.title}</div>
                    )}
                    <div className="mt-1 flex flex-wrap gap-2 text-xs opacity-80">
                        {model.site_model && (
                            <span className="rounded bg-white/70 px-1.5 py-0.5">
                                На сайте: {model.site_model}
                            </span>
                        )}
                        {modelSourceLabel(model.source) && (
                            <span className="rounded bg-white/70 px-1.5 py-0.5">
                                {modelSourceLabel(model.source)}
                            </span>
                        )}
                        {model.storage_gb != null && <span>{model.storage_gb} ГБ</span>}
                        {model.confidence != null && <span>уверенность {model.confidence}%</span>}
                    </div>
                </div>
            )}

            {headline && <p className="mt-2 font-medium leading-snug">{headline}</p>}

            {(brief?.valuation?.headline || valuation) && (
                <div className="mt-2 rounded-md border border-teal-200/80 bg-teal-50/80 px-2.5 py-2 text-teal-950">
                    <div className="text-[10px] font-semibold uppercase tracking-wide text-teal-800">Перепродажа</div>
                    <p className="mt-0.5 text-sm leading-snug">
                        {brief?.valuation?.headline ??
                            `После торга ~${valuation!.expected_sale.toLocaleString('ru-MD')} MDL · ${valuation!.condition_label}`}
                    </p>
                </div>
            )}

            <div className="mt-3 space-y-3">
                <Section title="Цена" items={priceAlerts} className="text-red-900/90" />
                <Section title="Текст объявления" items={textAlerts} className="text-amber-900/90" />
                {sellerFacts.length > 0 && (
                    <div>
                        <div className="text-xs font-semibold uppercase tracking-wide opacity-70">Из описания продавца</div>
                        <ul className="mt-1 flex flex-wrap gap-1.5">
                            {sellerFacts.map((k) => (
                                <li
                                    key={k}
                                    className="rounded-full border border-black/10 bg-white/60 px-2 py-0.5 text-xs font-medium"
                                >
                                    {k}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                {generalNotes.length > 0 && <Section title="Заметки" items={generalNotes} />}
                {risks.length > 0 && (
                    <div>
                        <div className="text-xs font-semibold uppercase tracking-wide opacity-70">Риски</div>
                        <ul className="mt-1 space-y-1.5">
                            {risks.map((r) => (
                                <li key={r.label} className="rounded border border-black/5 bg-white/50 px-2 py-1.5 text-xs">
                                    <div className="flex items-baseline justify-between gap-2">
                                        <span className="font-medium">{r.label}</span>
                                        <span className="shrink-0 font-semibold">~{r.probability}%</span>
                                    </div>
                                    <div className="mt-0.5 opacity-80">{r.detail}</div>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </div>
    );
}

function rangeShort(from: number | null, to: number | null): string {
    if (from == null && to != null) return `< ${new Intl.NumberFormat('ru-MD').format(to)}`;
    if (from != null && to == null) return `> ${new Intl.NumberFormat('ru-MD').format(from)}`;
    if (from != null && to != null) {
        return `${new Intl.NumberFormat('ru-MD').format(from)}–${new Intl.NumberFormat('ru-MD').format(to)}`;
    }
    return '—';
}

const ZONE_BAR: Record<string, string> = {
    below_buy_min: 'bg-red-400',
    buy_zone: 'bg-sky-500',
    between: 'bg-zinc-400',
    sell_band: 'bg-emerald-600',
    above_sell: 'bg-amber-500',
};

const ZONE_CHIP: Record<string, string> = {
    below_buy_min: 'Ниже buy_min',
    buy_zone: 'Зона покупки',
    between: 'Между зонами',
    sell_band: 'Рынок продажи',
    above_sell: 'Выше рынка',
};

function PriceZonesMini({
    zones,
}: {
    zones: NonNullable<DealRow['price_zones']>;
}) {
    const max = Math.max(...zones.zones.map((z) => z.all), 1);
    const askZone = zones.zones.find((z) => z.key === zones.ask_zone);

    return (
        <div className="mt-2 rounded-md border border-emerald-200/80 bg-white/70 px-2.5 py-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div className="text-[10px] font-semibold uppercase tracking-wide text-emerald-800">
                    Сколько объявлений в каких ценах
                </div>
                <div className="text-[10px] text-emerald-900/70">
                    частн. {zones.total_private}
                    {zones.total_shop > 0 ? ` · маг. ${zones.total_shop}` : ''}
                    {zones.private_median != null
                        ? ` · медиана ${new Intl.NumberFormat('ru-MD').format(zones.private_median)}`
                        : ''}
                </div>
            </div>

            <div className="mt-2 grid grid-cols-5 gap-1.5">
                {zones.zones.map((z) => {
                    const h = Math.max(z.all > 0 ? 4 : 2, Math.round((z.all / max) * 40));
                    const isAsk = z.key === zones.ask_zone;
                    return (
                        <div
                            key={z.key}
                            className={`flex flex-col items-center rounded-md px-0.5 py-1 ${
                                isAsk ? 'bg-sky-100 ring-2 ring-sky-500' : ''
                            }`}
                            title={`${z.short_label}: ${z.all} (${z.private} частн.) · ${rangeShort(z.from, z.to)}`}
                        >
                            <div className="text-xs font-bold tabular-nums leading-none">{z.all}</div>
                            <div className="mt-1 flex h-10 w-full items-end justify-center">
                                <div
                                    className={`w-full max-w-8 rounded-t ${ZONE_BAR[z.key] ?? 'bg-zinc-400'}`}
                                    style={{ height: `${h}px` }}
                                />
                            </div>
                            <div className="mt-1 text-center text-[9px] leading-tight text-emerald-900/75">
                                {z.key === 'below_buy_min'
                                    ? 'ниже'
                                    : z.key === 'buy_zone'
                                      ? 'покупка'
                                      : z.key === 'between'
                                        ? 'между'
                                        : z.key === 'sell_band'
                                          ? 'продажа'
                                          : 'выше'}
                            </div>
                            <div className="text-center text-[8px] tabular-nums text-emerald-900/55">
                                {rangeShort(z.from, z.to)}
                            </div>
                        </div>
                    );
                })}
            </div>

            {askZone && zones.ask_price != null && (
                <div className="mt-2 text-xs text-emerald-950">
                    Это объявление:{' '}
                    <strong>{new Intl.NumberFormat('ru-MD').format(zones.ask_price)} MDL</strong>
                    {' → '}
                    <span className="font-medium">{ZONE_CHIP[askZone.key] ?? askZone.short_label}</span>
                    <span className="text-emerald-900/70">
                        {' '}
                        (в зоне {askZone.all} объявл., из них {askZone.private} частн.)
                    </span>
                </div>
            )}
        </div>
    );
}

export default function DealsIndex({ deals, stats, corpus, models = [], filters, flash }: Props) {
    const importForm = useForm({ url: '' });
    const [copiedId, setCopiedId] = useState<number | null>(null);
    const [copiedDescId, setCopiedDescId] = useState<number | null>(null);

    const flashCopiedDesc = (dealId: number) => {
        setCopiedDescId(dealId);
        setTimeout(() => setCopiedDescId(null), 2000);
    };

    const copySellerDesc = async (deal: DealRow) => {
        const text = sellerCopyPayload(deal);
        if (await copyToClipboard(text)) {
            flashCopiedDesc(deal.id);
        }
    };

    const setFilter = (key: string, value: string | number | null) => {
        const next = { ...filters, [key]: value ?? undefined };
        // Clear legacy min_score when using named score_range
        if (key === 'score_range') {
            next.min_score = 0;
            next.max_score = null;
        }
        router.get('/deals', next, { preserveState: true, replace: true });
    };

    const setStatus = (dealId: number, user_status: string) => {
        router.patch(`/deals/${dealId}`, { user_status }, { preserveScroll: true });
    };

    const modelGroupKey = (deal: DealRow) => {
        if (deal.listing.brand && deal.listing.model) {
            return `${deal.listing.brand}|${deal.listing.model}`;
        }
        return deal.listing.display_name || 'unknown';
    };

    const modelGroupLabel = (deal: DealRow) => deal.listing.display_name || deal.listing.title;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Deals" />
            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">DealWatch</h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Живой мониторинг 999.md — потенциальная прибыль по каждому телефону.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="default" onClick={() => router.post('/deals/collect', { no_notify: true })}>
                            <RefreshCw className="mr-2 size-4" />
                            Собрать с 999
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => {
                                if (
                                    confirm(
                                        'Переразобрать модели по заголовкам, обновить перекупы/куплю и пересчитать рынок? Это займёт ~10–20 сек.',
                                    )
                                ) {
                                    router.post('/deals/refresh-analytics');
                                }
                            }}
                        >
                            <RefreshCw className="mr-2 size-4" />
                            Обновить аналитику
                        </Button>
                    </div>
                </div>

                {corpus && (
                    <div className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                        <div className="font-medium">{corpus.label}</div>
                        <div className="mt-1 text-sky-900/80">
                            Sell с ценой: {corpus.with_price.toLocaleString('ru-MD')} · частники:{' '}
                            {(corpus.private_clean ?? corpus.private).toLocaleString('ru-MD')}
                            {corpus.private_share_percent != null ? ` (${corpus.private_share_percent}% sell)` : ''}
                            {' · '}
                            магазины: {corpus.shop.toLocaleString('ru-MD')}
                            {corpus.shop_share_percent != null ? ` (${corpus.shop_share_percent}% sell)` : ''}
                            {corpus.resellers != null && corpus.resellers > 0 && (
                                <>
                                    {' '}
                                    · перекупы: {corpus.resellers.toLocaleString('ru-MD')}
                                    {corpus.reseller_share_percent != null
                                        ? ` (${corpus.reseller_share_percent}% sell)`
                                        : ''}
                                </>
                            )}
                            {corpus.want_buy != null && corpus.want_buy > 0 && (
                                <>
                                    {' '}
                                    · куплю: {corpus.want_buy.toLocaleString('ru-MD')}
                                    {corpus.want_buy_share_percent != null
                                        ? ` (${corpus.want_buy_share_percent}% базы, вне рынка)`
                                        : ''}
                                </>
                            )}
                            {corpus.from && corpus.to ? ` · период ${corpus.from} – ${corpus.to}` : ''}
                            {' · '}
                            <Link href="/market" className="underline underline-offset-2">
                                основания рынка
                            </Link>
                        </div>
                    </div>
                )}

                {(flash?.success || flash?.error) && (
                    <div
                        className={`rounded-lg border px-3 py-2 text-sm ${
                            flash.error
                                ? 'border-red-300 bg-red-50 text-red-800'
                                : 'border-emerald-300 bg-emerald-50 text-emerald-900'
                        }`}
                    >
                        {flash.error || flash.success}
                    </div>
                )}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                        <div className="text-xs uppercase tracking-wide text-emerald-800">Потенциал BUY</div>
                        <div className="mt-1 text-2xl font-semibold text-emerald-900">
                            {money(stats.profit_sum, 'MDL')}
                        </div>
                    </div>
                    {[
                        ['BUY', stats.buy],
                        ['CHECK', stats.check],
                        ['Fresh', stats.fresh],
                        ['Всего', stats.total],
                    ].map(([label, value]) => (
                        <div key={String(label)} className="rounded-xl border px-4 py-3">
                            <div className="text-muted-foreground text-xs uppercase tracking-wide">{label}</div>
                            <div className="mt-1 text-2xl font-semibold">{value}</div>
                        </div>
                    ))}
                </div>

                <form
                    className="flex flex-col gap-2 sm:flex-row"
                    onSubmit={(e) => {
                        e.preventDefault();
                        importForm.post('/deals/import', {
                            preserveScroll: true,
                            onSuccess: () => importForm.reset(),
                        });
                    }}
                >
                    <Input
                        placeholder="Вставь ссылку 999.md/ru/…"
                        value={importForm.data.url}
                        onChange={(e) => importForm.setData('url', e.target.value)}
                    />
                    <Button type="submit" disabled={importForm.processing}>
                        <Search className="mr-2 size-4" />
                        Оценить URL
                    </Button>
                </form>

                <div className="flex flex-wrap gap-2">
                    {[
                        ['targets', 'Частники'],
                        ['shops', `Магазины${stats.shop_deals != null ? ` (${stats.shop_deals})` : ''}`],
                        ['want_buy', `Куплю${stats.want_buy_deals != null ? ` (${stats.want_buy_deals})` : ''}`],
                        ['resellers', `Перекупы${stats.reseller_deals != null ? ` (${stats.reseller_deals})` : ''}`],
                        ['all', 'Все'],
                    ].map(([seg, label]) => (
                        <Button
                            key={seg}
                            size="sm"
                            variant={(filters.segment ?? 'targets') === seg ? 'default' : 'outline'}
                            onClick={() => setFilter('segment', seg)}
                        >
                            {label}
                        </Button>
                    ))}
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant={filters.sort === 'profit' ? 'default' : 'outline'}
                        onClick={() => setFilter('sort', 'profit')}
                    >
                        По прибыли
                    </Button>
                    <Button
                        size="sm"
                        variant={filters.sort === 'score' ? 'default' : 'outline'}
                        onClick={() => setFilter('sort', 'score')}
                    >
                        По score
                    </Button>
                    <Button
                        size="sm"
                        variant={filters.sort === 'model' ? 'default' : 'outline'}
                        onClick={() => setFilter('sort', 'model')}
                    >
                        По моделям
                    </Button>
                    {['all', 'buy', 'check'].map((v) => (
                        <Button
                            key={v}
                            size="sm"
                            variant={filters.verdict === v ? 'default' : 'outline'}
                            onClick={() => setFilter('verdict', v)}
                        >
                            {v === 'all' ? 'Все' : v.toUpperCase()}
                        </Button>
                    ))}
                    <Button
                        size="sm"
                        variant={filters.status === 'dismissed' ? 'default' : 'outline'}
                        onClick={() => setFilter('status', filters.status === 'dismissed' ? 'active' : 'dismissed')}
                    >
                        Скрытые{stats.hidden != null ? ` (${stats.hidden})` : ''}
                    </Button>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-muted-foreground text-xs font-medium uppercase tracking-wide">Score</span>
                    {(
                        [
                            ['all', 'Все'],
                            ['0-59', '0–59'],
                            ['60-79', '60–79'],
                            ['80+', '80+'],
                        ] as const
                    ).map(([key, label]) => (
                        <Button
                            key={key}
                            size="sm"
                            variant={(filters.score_range ?? 'all') === key ? 'default' : 'outline'}
                            onClick={() => setFilter('score_range', key)}
                        >
                            {label}
                        </Button>
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-muted-foreground text-xs font-medium uppercase tracking-wide">Прибыль</span>
                    {(
                        [
                            ['all', 'Все'],
                            ['lt800', '<800'],
                            ['800-1499', '800–1499'],
                            ['1500-2999', '1500–2999'],
                            ['3000+', '3000+'],
                        ] as const
                    ).map(([key, label]) => (
                        <Button
                            key={key}
                            size="sm"
                            variant={(filters.profit_range ?? 'all') === key ? 'default' : 'outline'}
                            onClick={() => setFilter('profit_range', key)}
                        >
                            {label}
                        </Button>
                    ))}
                </div>

                {models.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-muted-foreground text-xs font-medium uppercase tracking-wide">Модель</span>
                        <select
                            className="h-9 max-w-full rounded-md border border-input bg-background px-3 text-sm"
                            value={filters.model ?? 'all'}
                            onChange={(e) => setFilter('model', e.target.value)}
                        >
                            <option value="all">Все модели ({models.length})</option>
                            {models.map((m) => (
                                <option key={m.key} value={m.key}>
                                    {m.label} ({m.count})
                                </option>
                            ))}
                        </select>
                        {(filters.model ?? 'all') !== 'all' && (
                            <Button size="sm" variant="ghost" onClick={() => setFilter('model', 'all')}>
                                Сбросить модель
                            </Button>
                        )}
                    </div>
                )}

                <div className="space-y-3">
                    {deals.length === 0 && (
                        <div className="text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">
                            {filters.status === 'dismissed'
                                ? 'Скрытых объявлений пока нет. Нажми «Скрыть» на карточке — номер сохранится и не вернётся в ленту.'
                                : (filters.segment ?? 'targets') === 'want_buy'
                                ? 'Объявлений «куплю» пока нет. Они хранятся отдельно и не входят в рыночную аналитику.'
                                : (filters.segment ?? 'targets') === 'shops'
                                  ? 'Магазинных sell-объявлений в этом фильтре нет.'
                                  : (filters.segment ?? 'targets') === 'resellers'
                                    ? 'Перекупов с активными сделками пока нет. Запустите sellers:refresh после backfill.'
                                    : 'Пока пусто. Нажми «Собрать с 999».'}
                        </div>
                    )}

                    {deals.map((deal, index) => {
                        const showModelHeader =
                            filters.sort === 'model' &&
                            (index === 0 || modelGroupKey(deal) !== modelGroupKey(deals[index - 1]));

                        return (
                            <div key={deal.id}>
                                {showModelHeader && (
                                    <div className="sticky top-0 z-10 mb-2 rounded-lg border border-zinc-200 bg-zinc-100/95 px-3 py-2 backdrop-blur">
                                        <div className="text-sm font-semibold tracking-tight">
                                            {modelGroupLabel(deal)}
                                        </div>
                                    </div>
                                )}
                                <article className="rounded-xl border p-4 transition hover:border-zinc-400">
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div className="flex gap-3">
                                    <div className="flex flex-col items-center gap-2">
                                        <div
                                            className={`flex size-14 shrink-0 items-center justify-center rounded-xl text-lg font-bold ${scoreTone(deal.deal_score)}`}
                                        >
                                            {deal.deal_score}
                                        </div>
                                        <div className={`text-center text-sm font-bold ${profitTone(deal.potential_profit)}`}>
                                            {profitLabel(deal.potential_profit)}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h2 className="text-lg font-semibold leading-tight">
                                                {deal.listing.display_name}
                                            </h2>
                                            <Badge variant="secondary">{verdictLabel(deal.verdict)}</Badge>
                                            <Badge variant="outline">{deal.freshness}</Badge>
                                            {deal.listing.currency && deal.listing.currency !== 'MDL' && (
                                                <Badge variant="outline">{deal.listing.currency}</Badge>
                                            )}
                                            {deal.suspicious && (
                                                <Badge variant="destructive">
                                                    {deal.is_bait ? 'Кликбейт / не цена' : 'Проверь: слишком дёшево'}
                                                </Badge>
                                            )}
                                            {(deal.listing_kind === 'want_buy' ||
                                                deal.listing.listing_kind === 'want_buy') && (
                                                <Badge
                                                    variant="outline"
                                                    className="border-indigo-300 bg-indigo-50 text-indigo-900"
                                                >
                                                    Куплю · потенциальный покупатель
                                                </Badge>
                                            )}
                                            {(deal.is_reseller || deal.listing.is_reseller) && (
                                                <Badge variant="outline" className="border-violet-300 bg-violet-50 text-violet-900">
                                                    Перекуп · {(deal.seller_listings_count ?? deal.listing.seller_listings_count ?? '?')} тел.
                                                </Badge>
                                            )}
                                            {deal.listing.seller_type === 'shop' &&
                                                deal.listing.listing_kind !== 'want_buy' && (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-orange-300 bg-orange-50 text-orange-950"
                                                    >
                                                        Магазин
                                                    </Badge>
                                                )}
                                            {deal.listing.seller_type &&
                                                deal.listing.seller_type !== 'shop' &&
                                                deal.listing.listing_kind !== 'want_buy' && (
                                                <Badge variant="outline">{deal.listing.seller_type}</Badge>
                                            )}
                                        </div>
                                        <p className="text-muted-foreground mt-1 text-sm">{deal.listing.title}</p>
                                        {sellerDescription(deal) || deal.listing.external_id ? (
                                            <button
                                                type="button"
                                                className="mt-2 w-full rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-left text-sm text-zinc-800 transition hover:border-zinc-400"
                                                onClick={() => copySellerDesc(deal)}
                                                title="Нажми — скопировать № объявления, ссылку, регион и текст"
                                            >
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                                        Объявление 999.md
                                                    </span>
                                                    <span className="text-xs text-zinc-500">
                                                        {copiedDescId === deal.id ? (
                                                            <span className="text-emerald-700">Скопировано</span>
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1">
                                                                <Copy className="size-3" />
                                                                копировать
                                                            </span>
                                                        )}
                                                    </span>
                                                </div>
                                                <div className="mt-1 space-y-0.5 text-xs text-zinc-600">
                                                    {deal.listing.external_id && (
                                                        <div>
                                                            №{' '}
                                                            <a
                                                                href={
                                                                    deal.listing.url ||
                                                                    `https://999.md/ru/${deal.listing.external_id}`
                                                                }
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="font-medium text-sky-700 underline"
                                                                onClick={(e) => e.stopPropagation()}
                                                            >
                                                                {deal.listing.external_id}
                                                            </a>
                                                        </div>
                                                    )}
                                                    {deal.listing.location && (
                                                        <div>Регион: {deal.listing.location}</div>
                                                    )}
                                                </div>
                                                <p className="mt-2 line-clamp-4 whitespace-pre-wrap leading-snug text-sm text-zinc-800">
                                                    {sellerDescription(deal) || (
                                                        <span className="text-zinc-500">
                                                            Описание не загружено — нажми «Обновить аналитику»
                                                        </span>
                                                    )}
                                                </p>
                                            </button>
                                        ) : null}
                                        <AnalysisBrief deal={deal} />
                                        <div className="mt-3 grid gap-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                            <div>
                                                Цена покупки:{' '}
                                                <strong>{listingBuyPrice(deal)}</strong>
                                            </div>
                                            <div>
                                                Дисконт к ожид. продаже:{' '}
                                                <strong>
                                                    {deal.discount_percent != null
                                                        ? `${deal.discount_percent}%`
                                                        : '—'}
                                                </strong>
                                            </div>
                                            <div className={`font-semibold ${profitTone(deal.potential_profit)}`}>
                                                Прибыль после торга: {profitLabel(deal.potential_profit)}
                                            </div>
                                            {deal.listing.battery_health != null && (
                                                <div>Battery {deal.listing.battery_health}%</div>
                                            )}
                                            {deal.listing.location && <div>{deal.listing.location}</div>}
                                        </div>

                                        {valuationOf(deal) && (
                                            <div className="mt-3 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-950">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <div className="text-xs font-semibold uppercase tracking-wide text-teal-800">
                                                        Оценка состояния → перепродажа
                                                    </div>
                                                    <div className="flex flex-wrap gap-1.5">
                                                        <Badge variant="outline" className="border-teal-300 bg-white">
                                                            состояние {valuationOf(deal)!.condition_score}/100
                                                        </Badge>
                                                        <Badge variant="secondary">
                                                            уверенность: {valuationOf(deal)!.valuation_confidence}
                                                        </Badge>
                                                    </div>
                                                </div>
                                                <p className="mt-1 text-sm font-medium">
                                                    {valuationOf(deal)!.condition_label}
                                                </p>
                                                <p className="mt-1 text-xs text-teal-900/80">
                                                    {valuationOf(deal)!.valuation_confidence_note}
                                                </p>

                                                <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                                    <div className="rounded-md border border-teal-200/80 bg-white/70 px-2 py-1.5">
                                                        <div className="text-[10px] uppercase tracking-wide opacity-70">
                                                            Чистый mid рынка
                                                        </div>
                                                        <div className="font-semibold tabular-nums">
                                                            {money(valuationOf(deal)!.market_mid_clean)}
                                                        </div>
                                                    </div>
                                                    <div className="rounded-md border border-teal-200/80 bg-white/70 px-2 py-1.5">
                                                        <div className="text-[10px] uppercase tracking-wide opacity-70">
                                                            Fair ask (с состоянием)
                                                        </div>
                                                        <div className="font-semibold tabular-nums">
                                                            {money(valuationOf(deal)!.fair_ask)}
                                                        </div>
                                                        <div className="text-[10px] opacity-70">
                                                            уценка −{valuationOf(deal)!.condition_haircut_percent}%
                                                        </div>
                                                    </div>
                                                    <div className="rounded-md border border-emerald-300 bg-emerald-100/80 px-2 py-1.5">
                                                        <div className="text-[10px] uppercase tracking-wide text-emerald-900/70">
                                                            Реально после торга
                                                        </div>
                                                        <div className="text-base font-bold tabular-nums text-emerald-900">
                                                            {money(valuationOf(deal)!.expected_sale)}
                                                        </div>
                                                        <div className="text-[10px] text-emerald-900/70">
                                                            торг ≈ −{valuationOf(deal)!.negotiation_percent}%
                                                        </div>
                                                    </div>
                                                    <div className="rounded-md border border-teal-200/80 bg-white/70 px-2 py-1.5">
                                                        <div className="text-[10px] uppercase tracking-wide opacity-70">
                                                            Быстрая продажа
                                                        </div>
                                                        <div className="font-semibold tabular-nums">
                                                            {money(valuationOf(deal)!.quick_sale)}
                                                        </div>
                                                        <div className="text-[10px] opacity-70">
                                                            макс. покупка {money(valuationOf(deal)!.max_buy_for_profit)}
                                                        </div>
                                                    </div>
                                                </div>

                                                {valuationOf(deal)!.penalties.length > 0 && (
                                                    <div className="mt-2">
                                                        <div className="text-xs font-semibold opacity-70">
                                                            Уценка за состояние
                                                        </div>
                                                        <ul className="mt-1 space-y-0.5 text-xs">
                                                            {valuationOf(deal)!.penalties.map((p) => (
                                                                <li key={p.code + p.label} className="flex justify-between gap-2">
                                                                    <span>{p.label}</span>
                                                                    <span className="shrink-0 font-medium">−{p.percent}%</span>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                )}

                                                <div className="mt-2 grid gap-1 text-xs sm:grid-cols-2">
                                                    <div>
                                                        Прибыль при ожидаемой продаже:{' '}
                                                        <strong className={profitTone(valuationOf(deal)!.profit_at_expected)}>
                                                            {profitLabel(valuationOf(deal)!.profit_at_expected)}
                                                        </strong>
                                                    </div>
                                                    <div>
                                                        Если продашь быстро:{' '}
                                                        <strong className={profitTone(valuationOf(deal)!.profit_at_quick)}>
                                                            {profitLabel(valuationOf(deal)!.profit_at_quick)}
                                                        </strong>
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                        {deal.market && (
                                            <div className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-950">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <div className="text-xs font-semibold uppercase tracking-wide text-emerald-800">
                                                        Рынок продажи — с основанием
                                                    </div>
                                                    <Link
                                                        href={`/market/${deal.market.id}`}
                                                        className="text-xs font-medium text-emerald-800 underline"
                                                    >
                                                        Полный разбор
                                                    </Link>
                                                </div>
                                                <div className="mt-1">
                                                    Чистый mid:{' '}
                                                    <strong>{money(deal.market_mid_clean ?? deal.market_price, 'MDL')}</strong>
                                                    <span className="text-emerald-900/80">
                                                        {' '}
                                                        (частник {money(deal.market.sell_low, 'MDL')}–
                                                        {money(deal.market.sell_high, 'MDL')})
                                                    </span>
                                                </div>
                                                {valuationOf(deal) && (
                                                    <div className="mt-1">
                                                        Ожидаемая твоя продажа с учётом состояния/торга:{' '}
                                                        <strong>{money(valuationOf(deal)!.expected_sale)}</strong>
                                                    </div>
                                                )}
                                                {deal.price_zones && <PriceZonesMini zones={deal.price_zones} />}
                                                <div className="mt-1 text-emerald-900/90">{deal.market.foundation}</div>
                                                {deal.market.buy_rule && (
                                                    <div className="mt-1 text-xs">Покупка: {deal.market.buy_rule}</div>
                                                )}
                                                {deal.market.calc && (
                                                    <div className="mt-1 font-mono text-xs text-emerald-900/80">
                                                        Расчёт прибыли: {deal.market.calc}
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                        {!deal.market && deal.market_price != null && (
                                            <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950">
                                                Рынок: {money(deal.market_price, 'MDL')} — основание не привязано к
                                                карточке модели.
                                            </div>
                                        )}
                                    </div>
                                </div>

                                <div className="flex w-full flex-col gap-2 lg:max-w-sm lg:items-end">
                                    <div className="flex flex-wrap gap-2 lg:justify-end">
                                        <Button asChild size="sm">
                                            <a
                                                href={deal.listing.url}
                                                target="_blank"
                                                rel="noreferrer"
                                                onClick={() => {
                                                    void copySellerDesc(deal);
                                                    setStatus(deal.id, 'opened');
                                                }}
                                            >
                                                <ExternalLink className="mr-1 size-4" />
                                                Открыть
                                            </a>
                                        </Button>
                                        {deal.listing.seller_phone && (
                                            <Button asChild size="sm" variant="secondary">
                                                <a
                                                    href={`tel:${deal.listing.seller_phone}`}
                                                    onClick={() => setStatus(deal.id, 'called')}
                                                >
                                                    <Phone className="mr-1 size-4" />
                                                    Позвонить
                                                </a>
                                            </Button>
                                        )}
                                        <Button
                                            size="sm"
                                            variant={deal.is_favorite ? 'default' : 'outline'}
                                            className={deal.is_favorite ? 'bg-amber-500 hover:bg-amber-600' : ''}
                                            onClick={() => {
                                                if (deal.is_favorite) {
                                                    router.delete(`/deals/${deal.id}/favorite`, { preserveScroll: true });
                                                } else {
                                                    void copySellerDesc(deal);
                                                    router.post(`/deals/${deal.id}/favorite`, {}, { preserveScroll: true });
                                                }
                                            }}
                                        >
                                            <Star
                                                className={`mr-1 size-4 ${deal.is_favorite ? 'fill-white' : ''}`}
                                            />
                                            {deal.is_favorite ? 'В избранном' : 'В избранное'}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setStatus(deal.id, 'bought')}
                                        >
                                            Купил
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                setStatus(
                                                    deal.id,
                                                    deal.user_status === 'dismissed' || filters.status === 'dismissed'
                                                        ? 'new'
                                                        : 'dismissed',
                                                )
                                            }
                                        >
                                            {deal.user_status === 'dismissed' || filters.status === 'dismissed'
                                                ? 'Вернуть'
                                                : 'Скрыть'}
                                        </Button>
                                    </div>

                                    <div className="w-full rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-left text-sm text-sky-950">
                                        <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-sky-800">
                                            SMS до встречи
                                        </div>
                                        <pre className="whitespace-pre-wrap font-sans text-[13px] leading-snug text-sky-950/95">
                                            {screeningMessage(deal)}
                                        </pre>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="h-8 border-sky-300 bg-white"
                                                onClick={async () => {
                                                    const sms = screeningMessage(deal);
                                                    const header = sellerCopyPayload(deal);
                                                    const text = header
                                                        ? `${header}\n\n---\n\n${sms}`
                                                        : sms;
                                                    if (await copyToClipboard(text)) {
                                                        setCopiedId(deal.id);
                                                        if (header) {
                                                            flashCopiedDesc(deal.id);
                                                        }
                                                        setTimeout(() => setCopiedId(null), 2000);
                                                    }
                                                }}
                                            >
                                                <Copy className="mr-1 size-3.5" />
                                                {copiedId === deal.id ? 'Скопировано' : 'Текст + SMS'}
                                            </Button>
                                            {deal.listing.seller_phone && (
                                                <Button asChild size="sm" className="h-8">
                                                    <a
                                                        href={`sms:${phoneForSms(deal.listing.seller_phone)}?body=${encodeURIComponent(screeningMessage(deal))}`}
                                                        onClick={() => setStatus(deal.id, 'called')}
                                                    >
                                                        <MessageSquare className="mr-1 size-3.5" />
                                                        Открыть SMS
                                                    </a>
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>
                            </div>
                        );
                    })}
                </div>
            </div>
        </AppLayout>
    );
}
