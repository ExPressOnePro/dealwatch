import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { money } from '@/lib/deal-format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ExternalLink, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

type Expense = { label: string; amount: number };

type Trade = {
    id: number;
    title: string;
    brand?: string | null;
    model?: string | null;
    storage_gb?: number | null;
    status: string;
    purchase_price?: number | null;
    purchase_date?: string | null;
    sale_price?: number | null;
    sale_date?: string | null;
    sale_channel?: string | null;
    buyer_note?: string | null;
    notes?: string | null;
    expenses: Expense[];
    expenses_total: number;
    total_cost?: number | null;
    net_profit?: number | null;
    roi_percent?: number | null;
    hold_days?: number | null;
    listing?: { id: number; url: string; gone_at?: string | null; archived: boolean } | null;
};

type Summary = {
    trades: number;
    open: number;
    sold: number;
    cancelled: number;
    turnover: number;
    cost: number;
    profit: number;
    expenses: number;
    avg_profit: number;
    avg_roi: number | null;
    avg_hold_days: number | null;
    locked_money: number;
};

type Props = {
    trades: Trade[];
    summary: Summary;
    filters: { status: string; from?: string | null; to?: string | null; model?: string | null };
    statuses: string[];
    flash?: { success?: string; error?: string };
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Мои сделки', href: '/trades' }];

const STATUS_LABEL: Record<string, string> = {
    planned: 'Планирую',
    bought: 'Куплено',
    listed: 'Выставлено',
    sold: 'Продано',
    cancelled: 'Отменено',
};

const STATUS_TONE: Record<string, string> = {
    planned: 'surface-neutral',
    bought: 'surface-info',
    listed: 'surface-warning',
    sold: 'surface-success',
    cancelled: 'surface-danger',
};

function profitTone(value?: number | null): string {
    if (value == null) return 'text-muted-foreground';
    if (value > 0) return 'text-positive';
    if (value < 0) return 'text-negative';
    return 'text-muted-foreground';
}

type EditableTrade = {
    title: string;
    brand: string;
    model: string;
    storage_gb: string;
    status: string;
    purchase_price: string;
    purchase_date: string;
    sale_price: string;
    sale_date: string;
    sale_channel: string;
    buyer_note: string;
    notes: string;
    expenses: Expense[];
};

function toEditable(trade: Trade): EditableTrade {
    return {
        title: trade.title,
        brand: trade.brand ?? '',
        model: trade.model ?? '',
        storage_gb: trade.storage_gb ? String(trade.storage_gb) : '',
        status: trade.status,
        purchase_price: trade.purchase_price != null ? String(trade.purchase_price) : '',
        purchase_date: trade.purchase_date ?? '',
        sale_price: trade.sale_price != null ? String(trade.sale_price) : '',
        sale_date: trade.sale_date ?? '',
        sale_channel: trade.sale_channel ?? '',
        buyer_note: trade.buyer_note ?? '',
        notes: trade.notes ?? '',
        expenses: trade.expenses ?? [],
    };
}

function TradeEditor({ trade, onClose, statuses }: { trade: Trade; onClose: () => void; statuses: string[] }) {
    const form = useForm<EditableTrade>(toEditable(trade));

    const setExpense = (index: number, patch: Partial<Expense>) => {
        const next = form.data.expenses.map((e, i) => (i === index ? { ...e, ...patch } : e));
        form.setData('expenses', next);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(`/trades/${trade.id}`, { preserveScroll: true, onSuccess: onClose });
    };

    const field = (key: keyof EditableTrade, label: string, type = 'text') => (
        <div className="space-y-1.5">
            <Label htmlFor={`${trade.id}-${key}`}>{label}</Label>
            <Input
                id={`${trade.id}-${key}`}
                type={type}
                value={String(form.data[key] ?? '')}
                onChange={(e) => form.setData(key, e.target.value as never)}
            />
        </div>
    );

    return (
        <form onSubmit={submit} className="bg-muted mt-3 space-y-4 rounded-lg border p-3">
            <div className="grid gap-3 md:grid-cols-2">
                {field('title', 'Название')}
                <div className="space-y-1.5">
                    <Label htmlFor={`${trade.id}-status`}>Статус</Label>
                    <select
                        id={`${trade.id}-status`}
                        className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm"
                        value={form.data.status}
                        onChange={(e) => form.setData('status', e.target.value)}
                    >
                        {statuses.map((s) => (
                            <option key={s} value={s}>
                                {STATUS_LABEL[s] ?? s}
                            </option>
                        ))}
                    </select>
                </div>
                {field('purchase_price', 'Покупка, MDL', 'number')}
                {field('purchase_date', 'Дата покупки', 'date')}
                {field('sale_price', 'Продажа, MDL', 'number')}
                {field('sale_date', 'Дата продажи', 'date')}
                {field('sale_channel', 'Канал продажи')}
                {field('buyer_note', 'Покупатель / заметка')}
            </div>

            <div className="space-y-2">
                <Label>Дополнительные расходы</Label>
                {form.data.expenses.map((expense, index) => (
                    <div key={index} className="flex gap-2">
                        <Input
                            placeholder="Например, замена стекла"
                            value={expense.label}
                            onChange={(e) => setExpense(index, { label: e.target.value })}
                        />
                        <Input
                            type="number"
                            className="w-32"
                            value={String(expense.amount)}
                            onChange={(e) => setExpense(index, { amount: Number(e.target.value) })}
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() =>
                                form.setData(
                                    'expenses',
                                    form.data.expenses.filter((_, i) => i !== index),
                                )
                            }
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => form.setData('expenses', [...form.data.expenses, { label: '', amount: 0 }])}
                >
                    <Plus className="mr-1 size-3.5" />
                    Добавить расход
                </Button>
            </div>

            <div className="space-y-1.5">
                <Label htmlFor={`${trade.id}-notes`}>Заметки</Label>
                <textarea
                    id={`${trade.id}-notes`}
                    className="border-input min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                />
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button type="submit" disabled={form.processing}>
                    Сохранить
                </Button>
                <Button type="button" variant="ghost" onClick={onClose}>
                    Отмена
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    className="text-negative ml-auto"
                    onClick={() => {
                        if (confirm('Удалить сделку из журнала?')) {
                            router.delete(`/trades/${trade.id}`);
                        }
                    }}
                >
                    <Trash2 className="mr-1 size-4" />
                    Удалить
                </Button>
            </div>
        </form>
    );
}

export default function TradesIndex({ trades, summary, filters, statuses, flash }: Props) {
    const [editing, setEditing] = useState<number | null>(null);
    const addForm = useForm({ title: '', purchase_price: '' });

    const setFilter = (key: string, value: string) => {
        router.get('/trades', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Мои сделки" />
            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Мои сделки</h1>
                    <p className="text-muted-foreground mt-1 text-sm">Что купил, во что вложился, за сколько продал — и сколько на этом заработал.</p>
                </div>

                {flash?.success && <div className="surface-success rounded-lg border px-3 py-2 text-sm">{flash.success}</div>}
                {flash?.error && <div className="surface-danger rounded-lg border px-3 py-2 text-sm">{flash.error}</div>}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="surface-success rounded-xl border px-4 py-3">
                        <div className="text-positive text-xs tracking-wide uppercase">Чистая прибыль</div>
                        <div className={`mt-1 text-2xl font-semibold ${profitTone(summary.profit)}`}>{money(summary.profit)}</div>
                        <div className="text-xs opacity-70">
                            средняя {money(summary.avg_profit)}
                            {summary.avg_roi != null ? ` · ROI ${summary.avg_roi}%` : ''}
                        </div>
                    </div>
                    <div className="rounded-xl border px-4 py-3">
                        <div className="text-muted-foreground text-xs tracking-wide uppercase">Оборот</div>
                        <div className="mt-1 text-2xl font-semibold">{money(summary.turnover)}</div>
                        <div className="text-muted-foreground text-xs">расходы {money(summary.expenses)}</div>
                    </div>
                    <div className="rounded-xl border px-4 py-3">
                        <div className="text-muted-foreground text-xs tracking-wide uppercase">Сделок</div>
                        <div className="mt-1 text-2xl font-semibold">
                            {summary.sold} / {summary.trades}
                        </div>
                        <div className="text-muted-foreground text-xs">
                            в работе {summary.open}
                            {summary.avg_hold_days != null ? ` · оборот ${summary.avg_hold_days} дн.` : ''}
                        </div>
                    </div>
                    <div className="rounded-xl border px-4 py-3">
                        <div className="text-muted-foreground text-xs tracking-wide uppercase">Заморожено</div>
                        <div className="mt-1 text-2xl font-semibold">{money(summary.locked_money)}</div>
                        <div className="text-muted-foreground text-xs">в непроданных телефонах</div>
                    </div>
                </div>

                <div className="flex flex-wrap items-end gap-2">
                    <div className="space-y-1">
                        <Label htmlFor="status-filter" className="text-xs">
                            Статус
                        </Label>
                        <select
                            id="status-filter"
                            className="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                            value={filters.status ?? 'all'}
                            onChange={(e) => setFilter('status', e.target.value)}
                        >
                            <option value="all">все</option>
                            {statuses.map((s) => (
                                <option key={s} value={s}>
                                    {STATUS_LABEL[s] ?? s}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="from-filter" className="text-xs">
                            С
                        </Label>
                        <Input
                            id="from-filter"
                            type="date"
                            className="w-40"
                            value={filters.from ?? ''}
                            onChange={(e) => setFilter('from', e.target.value)}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="to-filter" className="text-xs">
                            По
                        </Label>
                        <Input
                            id="to-filter"
                            type="date"
                            className="w-40"
                            value={filters.to ?? ''}
                            onChange={(e) => setFilter('to', e.target.value)}
                        />
                    </div>
                    <Link href="/reports" className="text-sm underline underline-offset-2">
                        Статистика по товарам
                    </Link>
                </div>

                <form
                    className="flex flex-col gap-2 sm:flex-row"
                    onSubmit={(e) => {
                        e.preventDefault();
                        addForm.post('/trades', { preserveScroll: true, onSuccess: () => addForm.reset() });
                    }}
                >
                    <Input
                        placeholder="Добавить сделку вручную: «iPhone 12 128 GB»"
                        value={addForm.data.title}
                        onChange={(e) => addForm.setData('title', e.target.value)}
                    />
                    <Input
                        type="number"
                        className="sm:w-40"
                        placeholder="Покупка, MDL"
                        value={addForm.data.purchase_price}
                        onChange={(e) => addForm.setData('purchase_price', e.target.value)}
                    />
                    <Button type="submit" disabled={addForm.processing || addForm.data.title.trim() === ''}>
                        <Plus className="mr-1 size-4" />
                        Добавить
                    </Button>
                </form>

                <div className="space-y-3">
                    {trades.length === 0 && (
                        <div className="text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">
                            Журнал пуст. Нажми «В журнал» на карточке сделки в ленте или добавь товар вручную.
                        </div>
                    )}

                    {trades.map((trade) => (
                        <article key={trade.id} className="rounded-xl border p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="font-semibold">{trade.title}</h2>
                                        <Badge variant="outline" className={STATUS_TONE[trade.status] ?? ''}>
                                            {STATUS_LABEL[trade.status] ?? trade.status}
                                        </Badge>
                                        {trade.listing?.gone_at && (
                                            <Badge variant="outline" className="surface-neutral">
                                                объявление снято {trade.listing.gone_at}
                                            </Badge>
                                        )}
                                        {trade.listing?.archived && (
                                            <Badge variant="outline" className="surface-ai">
                                                в архиве
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="text-muted-foreground mt-1 text-sm">
                                        Покупка {money(trade.purchase_price)}
                                        {trade.purchase_date ? ` · ${trade.purchase_date}` : ''}
                                        {trade.expenses_total > 0 ? ` · расходы ${money(trade.expenses_total)}` : ''}
                                        {trade.sale_price != null ? ` → продажа ${money(trade.sale_price)}` : ''}
                                        {trade.sale_date ? ` · ${trade.sale_date}` : ''}
                                        {trade.sale_channel ? ` · ${trade.sale_channel}` : ''}
                                    </div>
                                </div>

                                <div className="text-right">
                                    <div className={`text-xl font-semibold ${profitTone(trade.net_profit)}`}>
                                        {trade.net_profit != null ? money(trade.net_profit) : '—'}
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        {trade.roi_percent != null ? `ROI ${trade.roi_percent}%` : 'прибыль появится после продажи'}
                                        {trade.hold_days != null ? ` · ${trade.hold_days} дн.` : ''}
                                    </div>
                                </div>
                            </div>

                            {trade.notes && <p className="text-muted-foreground mt-2 text-sm">{trade.notes}</p>}

                            <div className="mt-3 flex flex-wrap gap-2">
                                <Button size="sm" variant="outline" onClick={() => setEditing(editing === trade.id ? null : trade.id)}>
                                    {editing === trade.id ? 'Свернуть' : 'Редактировать'}
                                </Button>
                                {trade.listing?.url && (
                                    <Button asChild size="sm" variant="ghost">
                                        <a href={trade.listing.url} target="_blank" rel="noreferrer">
                                            <ExternalLink className="mr-1 size-4" />
                                            Объявление
                                        </a>
                                    </Button>
                                )}
                            </div>

                            {editing === trade.id && <TradeEditor trade={trade} statuses={statuses} onClose={() => setEditing(null)} />}
                        </article>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
