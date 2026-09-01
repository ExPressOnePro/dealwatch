import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { money } from '@/lib/deal-format';
import { type AiListingReport, type AiListingReports } from '@/types/deal';
import { router } from '@inertiajs/react';
import { Camera, ScanText } from 'lucide-react';

const SEVERITY_TONE: Record<string, string> = {
    high: 'surface-danger',
    medium: 'surface-warning',
    low: 'surface-neutral bg-card',
};

const VERDICT_LABEL: Record<string, string> = {
    take: 'Брать',
    check: 'Проверить',
    skip: 'Мимо',
};

function Section({ title, items }: { title: string; items: string[] }) {
    if (!items || items.length === 0) return null;

    return (
        <div>
            <div className="text-xs font-semibold tracking-wide uppercase opacity-70">{title}</div>
            <ul className="mt-1 list-disc space-y-0.5 pl-4 text-sm">
                {items.map((item) => (
                    <li key={item}>{item}</li>
                ))}
            </ul>
        </div>
    );
}

function ReportBody({ report }: { report: AiListingReport }) {
    if (report.status === 'running') {
        return (
            <p className="text-sm">
                ⚙️ {report.kind === 'vision' ? 'ИИ смотрит фотографии' : 'ИИ разбирает текст объявления'}… Обнови страницу через несколько секунд.
            </p>
        );
    }

    if (report.status === 'failed') {
        return <p className="text-negative text-sm">⚠️ Разбор не удался: {report.error ?? 'неизвестная ошибка'}</p>;
    }

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                {report.verdict && (
                    <Badge variant="outline" className="surface-inset">
                        {VERDICT_LABEL[report.verdict] ?? report.verdict}
                    </Badge>
                )}
                {report.condition_score != null && <span className="text-xs opacity-75">состояние {report.condition_score}/100</span>}
                {report.target_price_mdl != null && (
                    <span className="text-xs opacity-75">
                        торговаться до <strong>{money(report.target_price_mdl)}</strong>
                    </span>
                )}
                {report.confidence && <span className="text-xs opacity-60">уверенность: {report.confidence}</span>}
                {report.images_analyzed ? <span className="text-xs opacity-60">фото: {report.images_analyzed}</span> : null}
            </div>

            {report.summary && <p className="text-sm leading-snug">{report.summary}</p>}

            {report.defects.length > 0 && (
                <div>
                    <div className="text-xs font-semibold tracking-wide uppercase opacity-70">Найденные дефекты</div>
                    <ul className="mt-1 space-y-1.5">
                        {report.defects.map((defect, index) => (
                            <li
                                key={`${defect.label}-${index}`}
                                className={`rounded border px-2 py-1.5 text-sm ${SEVERITY_TONE[defect.severity] ?? ''}`}
                            >
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <span className="font-medium">
                                        {defect.source === 'photo' ? '📷 ' : '📝 '}
                                        {defect.label}
                                    </span>
                                    {defect.price_impact_mdl != null && <span className="text-xs">−{money(defect.price_impact_mdl)}</span>}
                                </div>
                                {defect.evidence && <div className="mt-0.5 text-xs opacity-80">{defect.evidence}</div>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <Section title="Расходится с описанием" items={report.mismatches} />
            <Section title="Спросить продавца" items={report.questions} />
            <Section title="Проверить на встрече" items={report.checks_on_meeting} />
            <Section title="Про качество фото" items={report.photo_notes} />

            <div className="text-[10px] opacity-60">
                {report.model}
                {report.cost_usd ? ` · $${report.cost_usd.toFixed(4)}` : ''}
                {report.created_at ? ` · ${new Date(report.created_at).toLocaleString('ru-MD')}` : ''}
            </div>
        </div>
    );
}

/**
 * Разбор конкретного объявления: текст всегда, фотографии — по кнопке,
 * потому что каждое фото заметно дороже текста.
 */
export function AiListingReportPanel({
    dealId,
    reports,
    aiConfigured,
    visionAvailable,
}: {
    dealId: number;
    reports?: AiListingReports;
    aiConfigured: boolean;
    visionAvailable: boolean;
}) {
    const text = reports?.text;
    const vision = reports?.vision;
    const busy = text?.status === 'running' || vision?.status === 'running';

    const run = (withPhotos: boolean) => {
        router.post(`/deals/${dealId}/ai-report`, { with_photos: withPhotos }, { preserveScroll: true });
    };

    return (
        <div className="surface-ai mt-2 rounded-lg border px-3 py-2.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="text-info text-xs font-semibold tracking-wide uppercase">Разбор ИИ</div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        className="bg-card h-8"
                        disabled={!aiConfigured || busy}
                        title={aiConfigured ? 'Разобрать текст объявления' : 'Добавь ключ OpenAI в админских настройках'}
                        onClick={() => run(false)}
                    >
                        <ScanText className="mr-1 size-3.5" />
                        {text ? 'Разобрать заново' : 'Разобрать текст'}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        className="bg-card h-8"
                        disabled={!visionAvailable || busy}
                        title={
                            visionAvailable ? 'Показать фотографии модели — дороже текстового разбора' : 'Разбор фото выключен в админских настройках'
                        }
                        onClick={() => run(true)}
                    >
                        <Camera className="mr-1 size-3.5" />
                        {vision ? 'Пересмотреть фото' : 'Разобрать фото'}
                    </Button>
                </div>
            </div>

            {!text && !vision && (
                <p className="mt-1.5 text-xs opacity-80">
                    Полный разбор текста, а по кнопке — осмотр фотографий: сколы, трещины, следы вскрытия и расхождения с описанием.
                </p>
            )}

            {text && (
                <div className="surface-inset mt-2 rounded-md border border-transparent px-2.5 py-2">
                    <div className="text-info text-[10px] font-semibold tracking-wide uppercase">По тексту</div>
                    <div className="mt-1">
                        <ReportBody report={text} />
                    </div>
                </div>
            )}

            {vision && (
                <div className="surface-inset mt-2 rounded-md border border-transparent px-2.5 py-2">
                    <div className="text-info text-[10px] font-semibold tracking-wide uppercase">По фотографиям</div>
                    <div className="mt-1">
                        <ReportBody report={vision} />
                    </div>
                </div>
            )}
        </div>
    );
}
