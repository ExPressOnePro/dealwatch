import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ExternalLink, Copy, Star, XCircle } from 'lucide-react';
import { useState } from 'react';

type FavoriteItem = {
    id: number;
    deal_score: number;
    verdict: string;
    potential_profit: number | null;
    market_price: number | null;
    user_status: string;
    purchase_price: number | null;
    sale_price: number | null;
    net_profit: number | null;
    cancel_note: string | null;
    completed_at: string | null;
    is_favorite: boolean;
    listing: {
        id: number;
        external_id?: string;
        title: string;
        description?: string | null;
        display_name: string;
        price_mdl: number | null;
        price_original: number | null;
        currency: string | null;
        url: string;
        location: string | null;
        seller_phone: string | null;
        seller_type: string | null;
        listing_kind?: string | null;
        is_reseller?: boolean;
    };
};

type Props = {
    items: FavoriteItem[];
    stats: {
        active_count: number;
        completed_count: number;
        cancelled_count: number;
        turnover: number;
        total_purchase: number;
        net_profit: number;
        avg_profit: number;
    };
    tab: string;
    flash?: { success?: string; error?: string };
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Избранное', href: '/favorites' }];

function money(value: number | null | undefined): string {
    if (value == null) return '—';
    return new Intl.NumberFormat('ru-MD').format(value) + ' MDL';
}

function profitTone(value: number | null | undefined): string {
    if (value == null) return 'text-zinc-500';
    if (value >= 1500) return 'text-emerald-700';
    if (value >= 0) return 'text-amber-700';
    return 'text-red-600';
}

async function copySellerText(text: string): Promise<boolean> {
    if (!text.trim()) return false;
    try {
        await navigator.clipboard.writeText(text.trim());
        return true;
    } catch {
        return false;
    }
}

function sellerCopyPayload(item: FavoriteItem): string {
    const lines: string[] = [];
    const id = item.listing.external_id;
    const url = item.listing.url || (id ? `https://999.md/ru/${id}` : '');

    if (id) lines.push(`999.md #${id}`);
    if (url) lines.push(url);
    if (item.listing.location) lines.push(`Регион: ${item.listing.location}`);

    const desc = (item.listing.description || '').trim();
    if (desc) {
        if (lines.length) lines.push('');
        lines.push(desc);
    }

    return lines.join('\n');
}

function SellerTextBlock({ item, copied, onCopy }: { item: FavoriteItem; copied: boolean; onCopy: () => void }) {
    const desc = (item.listing.description || '').trim();
    const id = item.listing.external_id;
    if (!desc && !id) return null;

    return (
        <button
            type="button"
            className="mt-2 w-full rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-left text-sm text-zinc-800 transition hover:border-zinc-400"
            onClick={onCopy}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Объявление 999.md</span>
                <span className="text-xs text-zinc-500">
                    {copied ? (
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
                {id && (
                    <div>
                        №{' '}
                        <a
                            href={item.listing.url || `https://999.md/ru/${id}`}
                            target="_blank"
                            rel="noreferrer"
                            className="font-medium text-sky-700 underline"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {id}
                        </a>
                    </div>
                )}
                {item.listing.location && <div>Регион: {item.listing.location}</div>}
            </div>
            {desc ? (
                <p className="mt-2 line-clamp-4 whitespace-pre-wrap leading-snug text-sm">{desc}</p>
            ) : (
                <p className="mt-2 text-xs text-zinc-500">Описание не загружено — обновите через «Обновить аналитику»</p>
            )}
        </button>
    );
}

function CompleteForm({ item }: { item: FavoriteItem }) {
    const form = useForm({
        purchase_price: item.listing.price_mdl ?? '',
        sale_price: item.market_price ?? '',
    });
    const [open, setOpen] = useState(false);

    if (!open) {
        return (
            <Button size="sm" variant="default" onClick={() => setOpen(true)}>
                Завершить сделку
            </Button>
        );
    }

    return (
        <form
            className="w-full space-y-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/favorites/${item.id}/complete`, {
                    preserveScroll: true,
                });
            }}
        >
            <div className="text-xs font-semibold uppercase tracking-wide text-emerald-800">
                Купил → продал
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
                <div>
                    <label className="text-muted-foreground mb-1 block text-xs">Цена покупки</label>
                    <Input
                        type="number"
                        min={1}
                        value={form.data.purchase_price}
                        onChange={(e) => form.setData('purchase_price', e.target.value)}
                        placeholder="MDL"
                    />
                </div>
                <div>
                    <label className="text-muted-foreground mb-1 block text-xs">Цена продажи</label>
                    <Input
                        type="number"
                        min={1}
                        value={form.data.sale_price}
                        onChange={(e) => form.setData('sale_price', e.target.value)}
                        placeholder="MDL"
                    />
                </div>
            </div>
            {form.data.purchase_price && form.data.sale_price && (
                <div className="text-sm">
                    Прибыль:{' '}
                    <strong
                        className={profitTone(
                            Number(form.data.sale_price) - Number(form.data.purchase_price),
                        )}
                    >
                        {money(Number(form.data.sale_price) - Number(form.data.purchase_price))}
                    </strong>
                </div>
            )}
            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={form.processing}>
                    Сохранить
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setOpen(false)}>
                    Отмена
                </Button>
            </div>
        </form>
    );
}

function CancelForm({ item }: { item: FavoriteItem }) {
    const form = useForm({ cancel_note: '' });
    const [open, setOpen] = useState(false);

    if (!open) {
        return (
            <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                <XCircle className="mr-1 size-4" />
                Не беру
            </Button>
        );
    }

    return (
        <form
            className="w-full space-y-2 rounded-lg border border-red-200 bg-red-50 p-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/favorites/${item.id}/cancel`, { preserveScroll: true });
            }}
        >
            <div className="text-xs font-semibold uppercase tracking-wide text-red-800">
                Отмена покупки
            </div>
            <Input
                placeholder="Почему не берёшь? (опционально)"
                value={form.data.cancel_note}
                onChange={(e) => form.setData('cancel_note', e.target.value)}
            />
            <div className="flex gap-2">
                <Button type="submit" size="sm" variant="destructive" disabled={form.processing}>
                    Отменить покупку
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setOpen(false)}>
                    Назад
                </Button>
            </div>
        </form>
    );
}

export default function FavoritesIndex({ items, stats, tab, flash }: Props) {
    const [copiedDescId, setCopiedDescId] = useState<number | null>(null);

    const copyDesc = async (item: FavoriteItem) => {
        const ok = await copySellerText(sellerCopyPayload(item));
        if (ok) {
            setCopiedDescId(item.id);
            setTimeout(() => setCopiedDescId(null), 2000);
        }
    };

    const setTab = (t: string) => {
        router.get('/favorites', { tab: t }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Избранное" />
            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Избранное</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Устройства, которые отслеживаешь. Закрой сделку с ценами или отмени покупку.
                    </p>
                </div>

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

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                        <div className="text-xs uppercase tracking-wide text-emerald-800">Чистая прибыль</div>
                        <div className={`mt-1 text-2xl font-semibold ${profitTone(stats.net_profit)}`}>
                            {money(stats.net_profit)}
                        </div>
                        <div className="mt-1 text-xs text-emerald-900/70">
                            {stats.completed_count} завершённых · ср. {money(stats.avg_profit)}
                        </div>
                    </div>
                    <div className="rounded-xl border px-4 py-3">
                        <div className="text-muted-foreground text-xs uppercase tracking-wide">Оборот (продажи)</div>
                        <div className="mt-1 text-2xl font-semibold">{money(stats.turnover)}</div>
                        <div className="text-muted-foreground mt-1 text-xs">
                            Закупка: {money(stats.total_purchase)}
                        </div>
                    </div>
                    <div className="rounded-xl border px-4 py-3">
                        <div className="text-muted-foreground text-xs uppercase tracking-wide">В работе</div>
                        <div className="mt-1 text-2xl font-semibold">{stats.active_count}</div>
                    </div>
                    <div className="rounded-xl border px-4 py-3">
                        <div className="text-muted-foreground text-xs uppercase tracking-wide">Отменено</div>
                        <div className="mt-1 text-2xl font-semibold">{stats.cancelled_count}</div>
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    {[
                        ['active', `В работе (${stats.active_count})`],
                        ['completed', `Завершённые (${stats.completed_count})`],
                        ['cancelled', `Отменённые (${stats.cancelled_count})`],
                    ].map(([key, label]) => (
                        <Button
                            key={key}
                            size="sm"
                            variant={tab === key ? 'default' : 'outline'}
                            onClick={() => setTab(key)}
                        >
                            {label}
                        </Button>
                    ))}
                </div>

                <div className="space-y-3">
                    {items.length === 0 && (
                        <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                            {tab === 'active'
                                ? 'Пока пусто. На странице Deals нажми ★ «В избранное» на интересном объявлении.'
                                : tab === 'completed'
                                  ? 'Завершённых сделок пока нет.'
                                  : 'Отменённых покупок пока нет.'}
                            {tab === 'active' && (
                                <div className="mt-3">
                                    <Button asChild variant="outline" size="sm">
                                        <Link href="/deals">К Deals</Link>
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}

                    {items.map((item) => (
                        <article key={item.id} className="rounded-xl border p-4">
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div className="flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Star className="size-4 fill-amber-400 text-amber-500" />
                                        <h2 className="text-lg font-semibold">{item.listing.display_name}</h2>
                                        <Badge variant="secondary">score {item.deal_score}</Badge>
                                        {tab === 'completed' && (
                                            <Badge variant="outline" className="border-emerald-300 bg-emerald-50">
                                                Завершено
                                            </Badge>
                                        )}
                                        {tab === 'cancelled' && (
                                            <Badge variant="outline" className="border-red-300 bg-red-50">
                                                Не беру
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="text-muted-foreground mt-1 text-sm">{item.listing.title}</p>
                                    <SellerTextBlock
                                        item={item}
                                        copied={copiedDescId === item.id}
                                        onCopy={() => void copyDesc(item)}
                                    />
                                    <div className="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                                        <div>
                                            Цена в объявлении:{' '}
                                            <strong>{money(item.listing.price_mdl)}</strong>
                                        </div>
                                        {item.listing.location && <div>{item.listing.location}</div>}
                                    </div>

                                    {tab === 'completed' && (
                                        <div className="mt-3 grid gap-2 rounded-lg border border-emerald-200 bg-emerald-50/60 p-3 text-sm sm:grid-cols-3">
                                            <div>
                                                <div className="text-xs opacity-70">Купил</div>
                                                <div className="font-semibold">{money(item.purchase_price)}</div>
                                            </div>
                                            <div>
                                                <div className="text-xs opacity-70">Продал</div>
                                                <div className="font-semibold">{money(item.sale_price)}</div>
                                            </div>
                                            <div>
                                                <div className="text-xs opacity-70">Прибыль</div>
                                                <div className={`font-bold ${profitTone(item.net_profit)}`}>
                                                    {money(item.net_profit)}
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {tab === 'cancelled' && item.cancel_note && (
                                        <div className="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-950">
                                            {item.cancel_note}
                                        </div>
                                    )}
                                </div>

                                <div className="flex w-full flex-col gap-2 lg:max-w-sm">
                                    <div className="flex flex-wrap gap-2">
                                        <Button asChild size="sm" variant="outline">
                                            <a href={item.listing.url} target="_blank" rel="noreferrer">
                                                <ExternalLink className="mr-1 size-4" />
                                                999.md
                                            </a>
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => router.delete(`/deals/${item.id}/favorite`)}
                                        >
                                            Убрать ★
                                        </Button>
                                    </div>

                                    {tab === 'active' && (
                                        <>
                                            <CompleteForm item={item} />
                                            <CancelForm item={item} />
                                        </>
                                    )}
                                </div>
                            </div>
                        </article>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
