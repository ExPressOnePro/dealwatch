import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { KeyRound, Plug, RefreshCw } from 'lucide-react';

type Field = {
    key: string;
    type: 'bool' | 'int' | 'float' | 'string' | 'secret';
    label: string;
    hint?: string | null;
    /** Значение переопределено в базе (иначе действует .env). */
    overridden: boolean;
    value?: string | number | boolean | null;
    masked?: string | null;
};

type Group = { title: string; fields: Field[] };

type Props = {
    groups: Record<string, Group>;
    models: string[];
    usage: {
        calls: number;
        cost_usd: number;
        calls_limit: number;
        cost_limit_usd: number;
        calls_left: number;
        cost_left_usd: number;
    };
    spend: { last_7_days: number; calls_7_days: number };
    rates: { source: string; rate_date: string | null; age_hours: number | null; stale: boolean; rates: Record<string, number> };
    flash?: { success?: string; error?: string; ping?: { ok: boolean; message: string } };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Настройки', href: '/settings/profile' },
    { title: 'Администрирование', href: '/admin/settings' },
];

const MODEL_FIELDS = ['ai.model_screen', 'ai.model_deep'];

export default function AdminSettings({ groups, models, usage, spend, rates, flash }: Props) {
    const initial: Record<string, string | boolean> = {};
    Object.values(groups).forEach((group) =>
        group.fields.forEach((field) => {
            initial[field.key] = field.type === 'bool' ? Boolean(field.value) : field.type === 'secret' ? '' : (field.value ?? '').toString();
        }),
    );

    const form = useForm<{ values: Record<string, string | boolean> }>({ values: initial });

    const setValue = (key: string, value: string | boolean) => form.setData('values', { ...form.data.values, [key]: value });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/admin/settings', { preserveScroll: true });
    };

    const forgetSecret = (field: Field) => {
        if (confirm(`Удалить сохранённое значение «${field.label}»? Снова начнёт действовать значение из .env.`)) {
            router.delete('/admin/settings/secret', { data: { key: field.key }, preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Администрирование" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Настройки приложения"
                        description="Ключи интеграций, модели ИИ и лимиты расхода. Значения хранятся в базе и перекрывают .env."
                    />

                    {flash?.success && <div className="surface-success rounded-lg border px-3 py-2 text-sm">{flash.success}</div>}
                    {flash?.error && <div className="surface-danger rounded-lg border px-3 py-2 text-sm">{flash.error}</div>}
                    {flash?.ping && (
                        <div className={`rounded-lg border px-3 py-2 text-sm ${flash.ping.ok ? 'surface-success' : 'surface-danger'}`}>
                            {flash.ping.ok ? '✅ ' : '⚠️ '}
                            {flash.ping.message}
                        </div>
                    )}

                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="rounded-xl border px-4 py-3">
                            <div className="text-muted-foreground text-xs tracking-wide uppercase">Сегодня потрачено</div>
                            <div className="mt-1 text-xl font-semibold">${usage.cost_usd.toFixed(4)}</div>
                            <div className="text-muted-foreground text-xs">лимит ${usage.cost_limit_usd.toFixed(2)}</div>
                        </div>
                        <div className="rounded-xl border px-4 py-3">
                            <div className="text-muted-foreground text-xs tracking-wide uppercase">Обращений сегодня</div>
                            <div className="mt-1 text-xl font-semibold">
                                {usage.calls} / {usage.calls_limit}
                            </div>
                            <div className="text-muted-foreground text-xs">осталось {usage.calls_left}</div>
                        </div>
                        <div className="rounded-xl border px-4 py-3">
                            <div className="text-muted-foreground text-xs tracking-wide uppercase">За 7 дней</div>
                            <div className="mt-1 text-xl font-semibold">${spend.last_7_days.toFixed(4)}</div>
                            <div className="text-muted-foreground text-xs">{spend.calls_7_days} обращений</div>
                        </div>
                    </div>

                    <div className={`rounded-xl border px-4 py-3 text-sm ${rates?.stale ? 'surface-warning' : 'surface-info'}`}>
                        <div className="text-xs font-semibold tracking-wide uppercase opacity-80">Курс валют</div>
                        <div className="mt-1">
                            1 EUR = {rates?.rates?.EUR?.toFixed(4)} MDL · 1 USD = {rates?.rates?.USD?.toFixed(4)} MDL
                        </div>
                        <div className="text-xs opacity-80">
                            {rates?.source === 'bnm' ? 'официальный курс Нацбанка Молдовы' : 'аварийные значения из .env'}
                            {rates?.rate_date ? ` · на ${rates.rate_date}` : ''}
                            {rates?.stale ? ' · давно не обновлялся, запусти php artisan currency:refresh' : ''}
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.post('/admin/settings/test-connection', {}, { preserveScroll: true })}
                        >
                            <Plug className="mr-2 size-4" />
                            Проверить ключ
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.post('/admin/settings/refresh-models', {}, { preserveScroll: true })}
                        >
                            <RefreshCw className="mr-2 size-4" />
                            Обновить список моделей
                        </Button>
                    </div>

                    <form onSubmit={submit} className="space-y-8">
                        {Object.entries(groups).map(([groupKey, group]) => (
                            <section key={groupKey} className="space-y-4 rounded-xl border p-4">
                                <h3 className="text-sm font-semibold tracking-tight">{group.title}</h3>

                                <div className="grid gap-4 md:grid-cols-2">
                                    {group.fields.map((field) => (
                                        <div key={field.key} className="space-y-1.5">
                                            <div className="flex items-baseline justify-between gap-2">
                                                <Label htmlFor={field.key}>{field.label}</Label>
                                                {field.overridden && (
                                                    <span className="text-muted-foreground text-[10px] tracking-wide uppercase">задано здесь</span>
                                                )}
                                            </div>

                                            {field.type === 'bool' ? (
                                                <div className="flex items-center gap-2">
                                                    <Checkbox
                                                        id={field.key}
                                                        checked={Boolean(form.data.values[field.key])}
                                                        onCheckedChange={(checked) => setValue(field.key, checked === true)}
                                                    />
                                                    <span className="text-muted-foreground text-sm">
                                                        {form.data.values[field.key] ? 'включено' : 'выключено'}
                                                    </span>
                                                </div>
                                            ) : field.type === 'secret' ? (
                                                <div className="flex gap-2">
                                                    <Input
                                                        id={field.key}
                                                        type="password"
                                                        autoComplete="new-password"
                                                        placeholder={field.masked ? `${field.masked} — оставь пустым, чтобы не менять` : 'не задан'}
                                                        value={String(form.data.values[field.key] ?? '')}
                                                        onChange={(e) => setValue(field.key, e.target.value)}
                                                    />
                                                    {field.overridden && (
                                                        <Button type="button" variant="ghost" onClick={() => forgetSecret(field)}>
                                                            <KeyRound className="size-4" />
                                                        </Button>
                                                    )}
                                                </div>
                                            ) : (
                                                <>
                                                    <Input
                                                        id={field.key}
                                                        type={field.type === 'string' ? 'text' : 'number'}
                                                        step={field.type === 'float' ? '0.01' : undefined}
                                                        list={MODEL_FIELDS.includes(field.key) && models.length > 0 ? 'openai-models' : undefined}
                                                        value={String(form.data.values[field.key] ?? '')}
                                                        onChange={(e) => setValue(field.key, e.target.value)}
                                                    />
                                                </>
                                            )}

                                            {field.hint && <p className="text-muted-foreground text-xs">{field.hint}</p>}
                                            {form.errors[`values.${field.key}` as keyof typeof form.errors] && (
                                                <p className="text-negative text-xs">
                                                    {String(form.errors[`values.${field.key}` as keyof typeof form.errors])}
                                                </p>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </section>
                        ))}

                        {models.length > 0 && (
                            <datalist id="openai-models">
                                {models.map((model) => (
                                    <option key={model} value={model} />
                                ))}
                            </datalist>
                        )}

                        <div className="flex items-center gap-3">
                            <Button type="submit" disabled={form.processing}>
                                Сохранить настройки
                            </Button>
                            {form.recentlySuccessful && <span className="text-muted-foreground text-sm">Сохранено</span>}
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
