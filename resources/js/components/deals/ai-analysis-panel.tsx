import { Badge } from '@/components/ui/badge';
import { money } from '@/lib/deal-format';
import { type AiAnalysis, type AiAnalysisItem } from '@/types/deal';
import { useState } from 'react';

const VERDICT_TONE: Record<string, string> = {
    take: 'surface-success',
    check: 'surface-warning',
    skip: 'surface-neutral',
};

const VERDICT_LABEL: Record<string, string> = {
    take: 'Звонить',
    check: 'Уточнить',
    skip: 'Мимо',
};

const RISK_LABEL: Record<string, string> = {
    low: 'риск низкий',
    medium: 'риск средний',
    high: 'риск высокий',
};

function AnalysisRow({ item }: { item: AiAnalysisItem }) {
    const [open, setOpen] = useState(false);
    const hasDetails = Boolean(item.reasoning || item.questions?.length || item.red_flags?.length);

    return (
        <li className={`rounded-lg border px-3 py-2 ${VERDICT_TONE[item.ai_verdict] ?? VERDICT_TONE.skip}`}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="outline" className="surface-inset text-[10px]">
                        {VERDICT_LABEL[item.ai_verdict] ?? item.ai_verdict}
                    </Badge>
                    <span className="font-medium">{item.title ?? `Сделка #${item.deal_id}`}</span>
                    {item.price_mdl != null && <span className="text-xs opacity-75">{money(item.price_mdl)}</span>}
                    {item.call_priority != null && (
                        <Badge variant="outline" className="surface-inset border-transparent text-[10px]">
                            приоритет звонка {item.call_priority}
                        </Badge>
                    )}
                </div>
                <div className="flex items-center gap-2 text-xs opacity-70">
                    <span>рейтинг {item.rank}</span>
                    {item.risk && <span>· {RISK_LABEL[item.risk] ?? item.risk}</span>}
                </div>
            </div>

            {item.reason && <p className="mt-1 text-sm leading-snug">{item.reason}</p>}

            {item.target_price_mdl != null && (
                <p className="mt-1 text-sm">
                    Торговаться до <strong>{money(item.target_price_mdl)}</strong>
                </p>
            )}

            {hasDetails && (
                <button type="button" className="mt-1 text-xs underline underline-offset-2 opacity-80" onClick={() => setOpen((v) => !v)}>
                    {open ? 'Свернуть разбор' : 'Показать разбор'}
                </button>
            )}

            {open && (
                <div className="surface-inset mt-2 space-y-2 rounded-md px-2.5 py-2 text-sm">
                    {item.reasoning && <p className="leading-snug">{item.reasoning}</p>}
                    {item.questions && item.questions.length > 0 && (
                        <div>
                            <div className="text-xs font-semibold tracking-wide uppercase opacity-70">Спросить до встречи</div>
                            <ul className="mt-1 list-disc space-y-0.5 pl-4">
                                {item.questions.map((q) => (
                                    <li key={q}>{q}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {item.red_flags && item.red_flags.length > 0 && (
                        <div>
                            <div className="text-xs font-semibold tracking-wide uppercase opacity-70">Проверить на месте</div>
                            <ul className="mt-1 list-disc space-y-0.5 pl-4">
                                {item.red_flags.map((f) => (
                                    <li key={f}>{f}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {item.url && (
                        <a href={item.url} target="_blank" rel="noreferrer" className="inline-block text-xs underline underline-offset-2">
                            Открыть объявление
                        </a>
                    )}
                </div>
            )}
        </li>
    );
}

/**
 * Результат ИИ-разбора выборки: он приходит из очереди, поэтому страница
 * показывает последний завершённый (или идущий) разбор.
 */
export function AiAnalysisPanel({ analysis }: { analysis?: AiAnalysis | null }) {
    const [showAll, setShowAll] = useState(false);

    if (!analysis) {
        return null;
    }

    if (analysis.status === 'running') {
        return (
            <div className="surface-ai rounded-xl border px-4 py-3 text-sm">
                ⚙️ ИИ разбирает выборку{analysis.query ? ` по запросу «${analysis.query}»` : ''}. Обнови страницу через несколько секунд.
            </div>
        );
    }

    if (analysis.status === 'failed') {
        return (
            <div className="surface-danger rounded-xl border px-4 py-3 text-sm">⚠️ ИИ-разбор не удался: {analysis.error ?? 'неизвестная ошибка'}</div>
        );
    }

    const items = showAll ? analysis.items : analysis.items.filter((i) => i.ai_verdict !== 'skip');
    const skipped = analysis.items.length - analysis.items.filter((i) => i.ai_verdict !== 'skip').length;

    return (
        <div className="surface-ai rounded-xl border px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div className="text-info text-xs font-semibold tracking-wide uppercase">
                    Разбор ИИ{analysis.query ? ` · «${analysis.query}»` : ' · текущая выборка'}
                </div>
                <div className="text-xs opacity-70">
                    {analysis.listing_count} объявлений
                    {analysis.cost_usd ? ` · $${analysis.cost_usd.toFixed(4)}` : ''}
                    {analysis.model_screen ? ` · ${analysis.model_screen}` : ''}
                    {analysis.model_deep ? ` + ${analysis.model_deep}` : ''}
                </div>
            </div>

            {analysis.summary && <p className="text-info mt-1 text-sm leading-snug">{analysis.summary}</p>}
            {analysis.recommendation && (
                <p className="surface-ai surface-inset mt-2 rounded-md border px-2.5 py-2 text-sm">
                    <strong>Вывод: </strong>
                    {analysis.recommendation}
                </p>
            )}

            {items.length > 0 && (
                <ul className="mt-3 space-y-2">
                    {items.map((item) => (
                        <AnalysisRow key={item.deal_id} item={item} />
                    ))}
                </ul>
            )}

            {skipped > 0 && (
                <button type="button" className="text-info mt-2 text-xs underline underline-offset-2" onClick={() => setShowAll((v) => !v)}>
                    {showAll ? 'Скрыть отсеянные' : `Показать отсеянные (${skipped})`}
                </button>
            )}
        </div>
    );
}
