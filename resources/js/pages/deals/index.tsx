import { AiAnalysisPanel } from '@/components/deals/ai-analysis-panel';
import { DealCard } from '@/components/deals/deal-card';
import { RunStatus } from '@/components/deals/run-status';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { copyToClipboard, money, screeningMessage, sellerCopyPayload } from '@/lib/deal-format';
import { type BreadcrumbItem } from '@/types';
import { type AiAnalysis, type DealRow, type ModelOption, type PipelineRuns } from '@/types/deal';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { RefreshCw, Search, Sparkles } from 'lucide-react';
import { useState } from 'react';

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
    runs?: PipelineRuns;
    analysis?: AiAnalysis | null;
    ai?: { configured: boolean; vision?: boolean };
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
        profile?: number | string | null;
    };
    sources?: { id: number; name: string; scoring: string; is_active: boolean }[];
    active_source?: { id: number; name: string } | null;
    flash?: { success?: string; error?: string };
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Deals', href: '/deals' }];

export default function DealsIndex({
    deals,
    stats,
    corpus,
    models = [],
    filters,
    flash,
    runs,
    analysis,
    ai,
    sources = [],
    active_source = null,
}: Props) {
    const aiReady = ai?.configured ?? false;
    const visionReady = ai?.vision ?? false;
    const aiHint = aiReady ? undefined : 'Добавь OPENAI_API_KEY в .env, чтобы включить ИИ-разбор';
    const importForm = useForm({ url: '' });
    const aiForm = useForm({ query: '' });
    const [copiedId, setCopiedId] = useState<number | null>(null);
    const [copiedDescId, setCopiedDescId] = useState<number | null>(null);

    const flashCopiedDesc = (dealId: number) => {
        setCopiedDescId(dealId);
        setTimeout(() => setCopiedDescId(null), 2000);
    };

    const copySms = async (deal: DealRow) => {
        const sms = screeningMessage(deal);
        const header = sellerCopyPayload(deal);
        const text = header ? `${header}\n\n---\n\n${sms}` : sms;

        if (await copyToClipboard(text)) {
            setCopiedId(deal.id);
            if (header) {
                flashCopiedDesc(deal.id);
            }
            setTimeout(() => setCopiedId(null), 2000);
        }
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
                        <h1 className="text-2xl font-semibold tracking-tight">
                            DealWatch
                            {active_source && <span className="text-muted-foreground text-lg font-normal"> · {active_source.name}</span>}
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            {active_source
                                ? 'Всё на этой странице — только по выбранному источнику: счётчики, база и объявления.'
                                : 'Живой мониторинг 999.md — потенциальная прибыль по каждому объявлению.'}
                        </p>
                        {sources.length > 1 && (
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <span className="text-muted-foreground text-xs tracking-wide uppercase">Источник</span>
                                <select
                                    className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                                    value={filters.profile ? String(filters.profile) : 'all'}
                                    onChange={(e) => setFilter('profile', e.target.value === 'all' ? null : e.target.value)}
                                >
                                    <option value="all">Все источники</option>
                                    {sources.map((source) => (
                                        <option key={source.id} value={source.id}>
                                            {source.name}
                                        </option>
                                    ))}
                                </select>
                                {filters.profile && (
                                    <>
                                        <Link href={`/niches?profile=${filters.profile}`} className="text-xs underline underline-offset-2">
                                            аналитика источника
                                        </Link>
                                        <Button size="sm" variant="ghost" className="h-7 px-2 text-xs" onClick={() => setFilter('profile', null)}>
                                            показать все
                                        </Button>
                                    </>
                                )}
                            </div>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="default" onClick={() => router.post('/deals/collect', { no_notify: true })}>
                            <RefreshCw className="mr-2 size-4" />
                            Собрать с 999
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={!aiReady}
                            title={aiHint}
                            onClick={() =>
                                router.post(
                                    '/deals/analyze',
                                    { ...filters, profile: filters.profile ?? undefined, query: '' },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Sparkles className="mr-2 size-4" />
                            Разобрать выборку ИИ
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => {
                                if (
                                    confirm('Переразобрать модели по заголовкам, обновить перекупы/куплю и пересчитать рынок? Это займёт ~10–20 сек.')
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
                    <div className="surface-info rounded-xl border px-4 py-3 text-sm">
                        <div className="font-medium">{corpus.label}</div>
                        <div className="mt-1 opacity-80">
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
                                    {corpus.reseller_share_percent != null ? ` (${corpus.reseller_share_percent}% sell)` : ''}
                                </>
                            )}
                            {corpus.want_buy != null && corpus.want_buy > 0 && (
                                <>
                                    {' '}
                                    · куплю: {corpus.want_buy.toLocaleString('ru-MD')}
                                    {corpus.want_buy_share_percent != null ? ` (${corpus.want_buy_share_percent}% базы, вне рынка)` : ''}
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
                    <div className={`rounded-lg border px-3 py-2 text-sm ${flash.error ? 'surface-danger' : 'surface-success'}`}>
                        {flash.error || flash.success}
                    </div>
                )}

                <RunStatus runs={runs} />

                <AiAnalysisPanel analysis={analysis} />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div className="surface-success rounded-xl border px-4 py-3">
                        <div className="text-positive text-xs tracking-wide uppercase">Потенциал BUY</div>
                        <div className="text-positive mt-1 text-2xl font-semibold">{money(stats.profit_sum, 'MDL')}</div>
                    </div>
                    {[
                        ['BUY', stats.buy],
                        ['CHECK', stats.check],
                        ['Fresh', stats.fresh],
                        ['Всего', stats.total],
                    ].map(([label, value]) => (
                        <div key={String(label)} className="rounded-xl border px-4 py-3">
                            <div className="text-muted-foreground text-xs tracking-wide uppercase">{label}</div>
                            <div className="mt-1 text-2xl font-semibold">{value}</div>
                        </div>
                    ))}
                </div>

                <form
                    className="flex flex-col gap-2 sm:flex-row"
                    onSubmit={(e) => {
                        e.preventDefault();
                        aiForm.transform((data) => ({ ...filters, profile: filters.profile ?? undefined, query: data.query }));
                        aiForm.post('/deals/analyze', {
                            preserveScroll: true,
                            onSuccess: () => aiForm.reset(),
                        });
                    }}
                >
                    <Input
                        placeholder={aiReady ? 'Спросить ИИ: «iPhone 13 128 до 8000, без замен»' : 'ИИ выключен: добавь OPENAI_API_KEY в .env'}
                        value={aiForm.data.query}
                        onChange={(e) => aiForm.setData('query', e.target.value)}
                    />
                    <Button
                        type="submit"
                        variant="secondary"
                        title={aiHint}
                        disabled={!aiReady || aiForm.processing || aiForm.data.query.trim() === ''}
                    >
                        <Sparkles className="mr-2 size-4" />
                        Найти и разобрать
                    </Button>
                </form>

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
                    <Button size="sm" variant={filters.sort === 'profit' ? 'default' : 'outline'} onClick={() => setFilter('sort', 'profit')}>
                        По прибыли
                    </Button>
                    <Button size="sm" variant={filters.sort === 'score' ? 'default' : 'outline'} onClick={() => setFilter('sort', 'score')}>
                        По score
                    </Button>
                    <Button size="sm" variant={filters.sort === 'model' ? 'default' : 'outline'} onClick={() => setFilter('sort', 'model')}>
                        По моделям
                    </Button>
                    {['all', 'buy', 'check'].map((v) => (
                        <Button key={v} size="sm" variant={filters.verdict === v ? 'default' : 'outline'} onClick={() => setFilter('verdict', v)}>
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
                    <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">Score</span>
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
                    <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">Прибыль</span>
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
                        <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">Модель</span>
                        <select
                            className="border-input bg-background h-9 max-w-full rounded-md border px-3 text-sm"
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

                    {deals.map((deal, index) => (
                        <DealCard
                            key={deal.id}
                            deal={deal}
                            groupLabel={
                                filters.sort === 'model' && (index === 0 || modelGroupKey(deal) !== modelGroupKey(deals[index - 1]))
                                    ? modelGroupLabel(deal)
                                    : null
                            }
                            dismissedView={filters.status === 'dismissed'}
                            copiedId={copiedId}
                            copiedDescId={copiedDescId}
                            aiConfigured={aiReady}
                            visionAvailable={visionReady}
                            onCopyDescription={copySellerDesc}
                            onCopySms={copySms}
                            onSetStatus={setStatus}
                        />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
