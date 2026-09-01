import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { money } from '@/lib/deal-format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

type ModelRow = {
    label: string;
    trades: number;
    sold: number;
    profit: number;
    avg_profit: number;
    avg_roi: number | null;
    avg_hold_days: number | null;
    avg_purchase: number | null;
    avg_sale: number | null;
};

type MonthRow = { month: string; sold: number; turnover: number; profit: number };
type ChannelRow = { channel: string; sold: number; profit: number; avg_profit: number };

type Props = {
    summary: {
        trades: number;
        sold: number;
        open: number;
        turnover: number;
        profit: number;
        expenses: number;
        avg_profit: number;
        avg_roi: number | null;
        avg_hold_days: number | null;
        locked_money: number;
    };
    by_model: ModelRow[];
    by_month: MonthRow[];
    by_channel: ChannelRow[];
    filters: { from?: string | null; to?: string | null };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Мои сделки', href: '/trades' },
    { title: 'Отчёты', href: '/reports' },
];

function MonthChart({ rows }: { rows: MonthRow[] }) {
    if (rows.length === 0) {
        return <p className="text-muted-foreground text-sm">Пока нет проданных телефонов с датой продажи.</p>;
    }

    const max = Math.max(...rows.map((r) => Math.abs(r.profit)), 1);

    return (
        <div className="flex items-end gap-3 overflow-x-auto pb-1">
            {rows.map((row) => {
                const height = Math.max(4, Math.round((Math.abs(row.profit) / max) * 120));
                return (
                    <div key={row.month} className="flex min-w-14 flex-col items-center gap-1">
                        <div className="text-xs font-semibold tabular-nums">{money(row.profit)}</div>
                        <div className="flex h-32 w-full items-end justify-center">
                            <div
                                className={`w-8 rounded-t ${row.profit >= 0 ? 'bg-emerald-500' : 'bg-red-400'}`}
                                style={{ height: `${height}px` }}
                                title={`${row.month}: продано ${row.sold}, оборот ${row.turnover}`}
                            />
                        </div>
                        <div className="text-muted-foreground text-[10px]">{row.month}</div>
                        <div className="text-muted-foreground text-[10px]">{row.sold} шт.</div>
                    </div>
                );
            })}
        </div>
    );
}

export default function Reports({ summary, by_model, by_month, by_channel, filters }: Props) {
    const setFilter = (key: string, value: string) => {
        router.get('/reports', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Отчёты" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Статистика по товарам</h1>
                        <p className="text-muted-foreground mt-1 text-sm">На чём вы реально зарабатываете и как быстро оборачиваются деньги.</p>
                    </div>
                    <div className="flex items-end gap-2">
                        <div className="space-y-1">
                            <Label htmlFor="from" className="text-xs">
                                С
                            </Label>
                            <Input
                                id="from"
                                type="date"
                                className="w-40"
                                value={filters.from ?? ''}
                                onChange={(e) => setFilter('from', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="to" className="text-xs">
                                По
                            </Label>
                            <Input id="to" type="date" className="w-40" value={filters.to ?? ''} onChange={(e) => setFilter('to', e.target.value)} />
                        </div>
                        <Link href="/trades" className="pb-2 text-sm underline underline-offset-2">
                            К журналу
                        </Link>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="surface-success rounded-xl border px-4 py-3">
                        <div className="text-positive text-xs tracking-wide uppercase">Прибыль</div>
                        <div className="text-positive mt-1 text-2xl font-semibold">{money(summary.profit)}</div>
                        <div className="text-xs opacity-70">{summary.avg_roi != null ? `средний ROI ${summary.avg_roi}%` : '—'}</div>
                    </div>
                    <div className="rounded-xl border px-4 py-3">
                        <div className="text-muted-foreground text-xs tracking-wide uppercase">Оборот</div>
                        <div className="mt-1 text-2xl font-semibold">{money(summary.turnover)}</div>
                        <div className="text-muted-foreground text-xs">продано {summary.sold}</div>
                    </div>
                    <div className="rounded-xl border px-4 py-3">
                        <div className="text-muted-foreground text-xs tracking-wide uppercase">Средняя сделка</div>
                        <div className="mt-1 text-2xl font-semibold">{money(summary.avg_profit)}</div>
                        <div className="text-muted-foreground text-xs">
                            {summary.avg_hold_days != null ? `оборот ${summary.avg_hold_days} дн.` : 'срок пока не считается'}
                        </div>
                    </div>
                    <div className="rounded-xl border px-4 py-3">
                        <div className="text-muted-foreground text-xs tracking-wide uppercase">В работе</div>
                        <div className="mt-1 text-2xl font-semibold">{money(summary.locked_money)}</div>
                        <div className="text-muted-foreground text-xs">{summary.open} телефонов не продано</div>
                    </div>
                </div>

                <section className="space-y-3 rounded-xl border p-4">
                    <h2 className="text-sm font-semibold tracking-tight">Прибыль по месяцам</h2>
                    <MonthChart rows={by_month} />
                </section>

                <section className="space-y-3 rounded-xl border p-4">
                    <h2 className="text-sm font-semibold tracking-tight">По моделям</h2>
                    {by_model.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Пока нет сделок в журнале.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground text-left text-xs uppercase">
                                    <tr>
                                        <th className="py-2 pr-3">Модель</th>
                                        <th className="py-2 pr-3">Сделок</th>
                                        <th className="py-2 pr-3">Продано</th>
                                        <th className="py-2 pr-3">Средняя покупка</th>
                                        <th className="py-2 pr-3">Средняя продажа</th>
                                        <th className="py-2 pr-3">Средняя маржа</th>
                                        <th className="py-2 pr-3">ROI</th>
                                        <th className="py-2 pr-3">Оборот, дн.</th>
                                        <th className="py-2">Прибыль</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {by_model.map((row) => (
                                        <tr key={row.label} className="border-t">
                                            <td className="py-2 pr-3 font-medium">{row.label}</td>
                                            <td className="py-2 pr-3 tabular-nums">{row.trades}</td>
                                            <td className="py-2 pr-3 tabular-nums">{row.sold}</td>
                                            <td className="py-2 pr-3 tabular-nums">{row.avg_purchase != null ? money(row.avg_purchase) : '—'}</td>
                                            <td className="py-2 pr-3 tabular-nums">{row.avg_sale != null ? money(row.avg_sale) : '—'}</td>
                                            <td className="py-2 pr-3 tabular-nums">{money(row.avg_profit)}</td>
                                            <td className="py-2 pr-3 tabular-nums">{row.avg_roi != null ? `${row.avg_roi}%` : '—'}</td>
                                            <td className="py-2 pr-3 tabular-nums">{row.avg_hold_days ?? '—'}</td>
                                            <td className={`py-2 font-semibold tabular-nums ${row.profit >= 0 ? 'text-positive' : 'text-negative'}`}>
                                                {money(row.profit)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="space-y-3 rounded-xl border p-4">
                    <h2 className="text-sm font-semibold tracking-tight">По каналам продажи</h2>
                    {by_channel.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Укажи канал продажи в сделке — здесь появится сравнение.</p>
                    ) : (
                        <ul className="space-y-2">
                            {by_channel.map((row) => (
                                <li key={row.channel} className="flex items-baseline justify-between gap-3 border-b pb-2 last:border-0">
                                    <span className="font-medium">{row.channel}</span>
                                    <span className="text-muted-foreground text-sm">
                                        продано {row.sold} · средняя {money(row.avg_profit)} ·{' '}
                                        <strong className={row.profit >= 0 ? 'text-positive' : 'text-negative'}>{money(row.profit)}</strong>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
