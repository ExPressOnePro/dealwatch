import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { money } from '@/lib/deal-format';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';

type Category = { id: number; label: string; children: { id: number; label: string }[] };

type Market = { samples: number; median: number | null; p25: number | null; p75: number | null; enough: boolean } | null;

type Profile = {
    id: number;
    name: string;
    description: string;
    category_id: number | null;
    subcategory_id: number | null;
    category_label: string | null;
    query: string | null;
    exclude_keywords: string[];
    price_min: number | null;
    price_max: number | null;
    per_run: number;
    scoring: string;
    notify: boolean;
    is_active: boolean;
    last_run_at: string | null;
    last_found: number;
    active_listings: number;
    market: Market;
};

type Props = {
    profiles: Profile[];
    categories: Category[];
    scorings: string[];
    flash?: { success?: string; error?: string };
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Источники', href: '/sources' }];

const SCORING_LABEL: Record<string, string> = {
    phones: 'Телефоны (справочник моделей)',
    generic: 'Любой товар (рынок по объявлениям источника)',
};

type FormShape = {
    name: string;
    category_id: string;
    subcategory_id: string;
    category_label: string;
    query: string;
    exclude_keywords: string;
    price_min: string;
    price_max: string;
    per_run: string;
    scoring: string;
    notify: boolean;
    is_active: boolean;
};

function emptyForm(): FormShape {
    return {
        name: '',
        category_id: '',
        subcategory_id: '',
        category_label: '',
        query: '',
        exclude_keywords: '',
        price_min: '',
        price_max: '',
        per_run: '40',
        scoring: 'generic',
        notify: true,
        is_active: true,
    };
}

function toForm(profile: Profile): FormShape {
    return {
        name: profile.name,
        category_id: profile.category_id ? String(profile.category_id) : '',
        subcategory_id: profile.subcategory_id ? String(profile.subcategory_id) : '',
        category_label: profile.category_label ?? '',
        query: profile.query ?? '',
        exclude_keywords: (profile.exclude_keywords ?? []).join(', '),
        price_min: profile.price_min != null ? String(profile.price_min) : '',
        price_max: profile.price_max != null ? String(profile.price_max) : '',
        per_run: String(profile.per_run),
        scoring: profile.scoring,
        notify: profile.notify,
        is_active: profile.is_active,
    };
}

function ProfileForm({
    categories,
    scorings,
    initial,
    profileId,
    onDone,
}: {
    categories: Category[];
    scorings: string[];
    initial: FormShape;
    profileId?: number;
    onDone: () => void;
}) {
    const form = useForm<FormShape>(initial);

    const section = categories.find((c) => String(c.id) === form.data.category_id);

    const payload = () => ({
        ...form.data,
        exclude_keywords: form.data.exclude_keywords
            .split(',')
            .map((w) => w.trim())
            .filter(Boolean),
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform(payload);

        if (profileId) {
            form.patch(`/sources/${profileId}`, { preserveScroll: true, onSuccess: onDone });
        } else {
            form.post('/sources', { preserveScroll: true, onSuccess: onDone });
        }
    };

    return (
        <form onSubmit={submit} className="bg-muted space-y-4 rounded-xl border p-4">
            <div className="grid gap-3 md:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="name">Название источника</Label>
                    <Input
                        id="name"
                        placeholder="Например: MacBook до 20 000"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    {form.errors.name && <p className="text-negative text-xs">{form.errors.name}</p>}
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="query">Ключевые слова (поиск на 999.md)</Label>
                    <Input id="query" placeholder="macbook pro" value={form.data.query} onChange={(e) => form.setData('query', e.target.value)} />
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="category">Раздел каталога</Label>
                    <select
                        id="category"
                        className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm"
                        value={form.data.category_id}
                        onChange={(e) => {
                            const chosen = categories.find((c) => String(c.id) === e.target.value);
                            form.setData('category_id', e.target.value);
                            form.setData('subcategory_id', '');
                            form.setData('category_label', chosen?.label ?? '');
                        }}
                    >
                        <option value="">весь каталог</option>
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.label}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="subcategory">Подкатегория</Label>
                    <select
                        id="subcategory"
                        className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm"
                        value={form.data.subcategory_id}
                        disabled={!section}
                        onChange={(e) => {
                            const chosen = section?.children.find((c) => String(c.id) === e.target.value);
                            form.setData('subcategory_id', e.target.value);
                            if (chosen) form.setData('category_label', chosen.label);
                        }}
                    >
                        <option value="">весь раздел</option>
                        {(section?.children ?? []).map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.label}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="exclude">Стоп-слова (через запятую)</Label>
                    <Input
                        id="exclude"
                        placeholder="запчасти, на разбор, куплю"
                        value={form.data.exclude_keywords}
                        onChange={(e) => form.setData('exclude_keywords', e.target.value)}
                    />
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="scoring">Как оценивать</Label>
                    <select
                        id="scoring"
                        className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm"
                        value={form.data.scoring}
                        onChange={(e) => form.setData('scoring', e.target.value)}
                    >
                        {scorings.map((s) => (
                            <option key={s} value={s}>
                                {SCORING_LABEL[s] ?? s}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-1.5">
                        <Label htmlFor="price_min">Цена от, MDL</Label>
                        <Input id="price_min" type="number" value={form.data.price_min} onChange={(e) => form.setData('price_min', e.target.value)} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="price_max">до</Label>
                        <Input id="price_max" type="number" value={form.data.price_max} onChange={(e) => form.setData('price_max', e.target.value)} />
                        {form.errors.price_max && <p className="text-negative text-xs">{form.errors.price_max}</p>}
                    </div>
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="per_run">Объявлений за прогон</Label>
                    <Input id="per_run" type="number" value={form.data.per_run} onChange={(e) => form.setData('per_run', e.target.value)} />
                    {form.errors.per_run && <p className="text-negative text-xs">{form.errors.per_run}</p>}
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-6">
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v === true)} />
                    собирать по расписанию
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox checked={form.data.notify} onCheckedChange={(v) => form.setData('notify', v === true)} />
                    слать алерты в Telegram
                </label>
            </div>

            <div className="flex gap-2">
                <Button type="submit" disabled={form.processing}>
                    {profileId ? 'Сохранить' : 'Создать источник'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Отмена
                </Button>
            </div>
        </form>
    );
}

export default function Sources({ profiles, categories, scorings, flash }: Props) {
    const [editing, setEditing] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Источники" />
            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Источники объявлений</h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Что именно DealWatch ищет на 999.md: категория, ключевые слова, границы цены. Телефоны считаются по справочнику моделей,
                            остальные товары — по медиане цен внутри источника.
                        </p>
                    </div>
                    <Button onClick={() => setCreating((v) => !v)}>
                        <Plus className="mr-1 size-4" />
                        Новый источник
                    </Button>
                </div>

                {flash?.success && <div className="surface-success rounded-lg border px-3 py-2 text-sm">{flash.success}</div>}
                {flash?.error && <div className="surface-danger rounded-lg border px-3 py-2 text-sm">{flash.error}</div>}

                {creating && <ProfileForm categories={categories} scorings={scorings} initial={emptyForm()} onDone={() => setCreating(false)} />}

                <div className="space-y-3">
                    {profiles.map((profile) => (
                        <article key={profile.id} className={`rounded-xl border p-4 ${profile.is_active ? '' : 'opacity-60'}`}>
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="font-semibold">{profile.name}</h2>
                                        <Badge variant="outline">{profile.scoring === 'phones' ? 'телефоны' : 'общий рынок'}</Badge>
                                        {!profile.is_active && <Badge variant="outline">выключен</Badge>}
                                        {!profile.notify && <Badge variant="outline">без алертов</Badge>}
                                    </div>
                                    <div className="text-muted-foreground mt-1 text-sm">{profile.description}</div>
                                    {profile.exclude_keywords.length > 0 && (
                                        <div className="text-muted-foreground mt-1 text-xs">кроме: {profile.exclude_keywords.join(', ')}</div>
                                    )}
                                </div>

                                <div className="text-right text-sm">
                                    <div>
                                        объявлений в базе: <strong>{profile.active_listings}</strong>
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        {profile.last_run_at
                                            ? `последний сбор ${new Date(profile.last_run_at).toLocaleString('ru-MD')} · найдено ${profile.last_found}`
                                            : 'ещё не запускался'}
                                    </div>
                                    {profile.market && (
                                        <div className="text-muted-foreground text-xs">
                                            {profile.market.enough
                                                ? `рынок: медиана ${money(profile.market.median)} по ${profile.market.samples} объявлениям`
                                                : `рынок считается: ${profile.market.samples} объявлений, нужно больше`}
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div className="mt-3 flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => router.post(`/sources/${profile.id}/collect`, {}, { preserveScroll: true })}
                                >
                                    <RefreshCw className="mr-1 size-4" />
                                    Собрать сейчас
                                </Button>
                                <Button size="sm" variant="outline" onClick={() => setEditing(editing === profile.id ? null : profile.id)}>
                                    {editing === profile.id ? 'Свернуть' : 'Настроить'}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="text-negative"
                                    onClick={() => {
                                        if (confirm(`Удалить источник «${profile.name}»? Собранные объявления останутся.`)) {
                                            router.delete(`/sources/${profile.id}`, { preserveScroll: true });
                                        }
                                    }}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>

                            {editing === profile.id && (
                                <div className="mt-3">
                                    <ProfileForm
                                        categories={categories}
                                        scorings={scorings}
                                        initial={toForm(profile)}
                                        profileId={profile.id}
                                        onDone={() => setEditing(null)}
                                    />
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
