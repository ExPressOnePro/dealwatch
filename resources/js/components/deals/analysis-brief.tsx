import { Badge } from '@/components/ui/badge';
import { FLAG_LABELS, modelSourceLabel, reportOf, valuationOf } from '@/lib/deal-format';
import { type DealRow } from '@/types/deal';

export function AnalysisBrief({ deal }: { deal: DealRow }) {
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
        generalNotes = headline
            .split(' · ')
            .map((s) => s.trim())
            .filter(Boolean);
        headline = generalNotes[0] ?? headline;
        generalNotes = generalNotes.slice(1);
    }

    if (!headline && !model && priceAlerts.length === 0 && textAlerts.length === 0 && sellerFacts.length === 0 && risks.length === 0) {
        return null;
    }

    const tone =
        deal.is_bait || deal.analyst_risk === 'high' ? 'surface-danger' : deal.analyst_risk === 'medium' ? 'surface-warning' : 'surface-neutral';

    const Section = ({ title, items, className = '' }: { title: string; items: string[]; className?: string }) =>
        items.length > 0 ? (
            <div className={className}>
                <div className="text-xs font-semibold tracking-wide uppercase opacity-70">{title}</div>
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
                <div className="text-xs font-semibold tracking-wide uppercase opacity-80">Разбор модели</div>
                {flags.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                        {flags.slice(0, 6).map((f) => (
                            <Badge key={f} variant="outline" className="surface-inset text-[10px]">
                                {FLAG_LABELS[f] ?? f}
                            </Badge>
                        ))}
                    </div>
                )}
            </div>

            {model && (
                <div className="surface-inset mt-2 rounded-md border px-2.5 py-2">
                    <div className="text-base leading-tight font-semibold">{model.label}</div>
                    {model.title && model.title !== model.label && <div className="mt-0.5 text-xs opacity-75">Заголовок: {model.title}</div>}
                    <div className="mt-1 flex flex-wrap gap-2 text-xs opacity-80">
                        {model.site_model && <span className="surface-inset rounded px-1.5 py-0.5">На сайте: {model.site_model}</span>}
                        {modelSourceLabel(model.source) && (
                            <span className="surface-inset rounded px-1.5 py-0.5">{modelSourceLabel(model.source)}</span>
                        )}
                        {model.storage_gb != null && <span>{model.storage_gb} ГБ</span>}
                        {model.confidence != null && <span>уверенность {model.confidence}%</span>}
                    </div>
                </div>
            )}

            {headline && <p className="mt-2 leading-snug font-medium">{headline}</p>}

            {(brief?.valuation?.headline || valuation) && (
                <div className="surface-success mt-2 rounded-md border px-2.5 py-2">
                    <div className="text-positive text-[10px] font-semibold tracking-wide uppercase">Перепродажа</div>
                    <p className="mt-0.5 text-sm leading-snug">
                        {brief?.valuation?.headline ??
                            `После торга ~${valuation!.expected_sale.toLocaleString('ru-MD')} MDL · ${valuation!.condition_label}`}
                    </p>
                </div>
            )}

            <div className="mt-3 space-y-3">
                <Section title="Цена" items={priceAlerts} className="text-negative" />
                <Section title="Текст объявления" items={textAlerts} className="opacity-90" />
                {sellerFacts.length > 0 && (
                    <div>
                        <div className="text-xs font-semibold tracking-wide uppercase opacity-70">Из описания продавца</div>
                        <ul className="mt-1 flex flex-wrap gap-1.5">
                            {sellerFacts.map((k) => (
                                <li key={k} className="surface-inset rounded-full border px-2 py-0.5 text-xs font-medium">
                                    {k}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                {generalNotes.length > 0 && <Section title="Заметки" items={generalNotes} />}
                {risks.length > 0 && (
                    <div>
                        <div className="text-xs font-semibold tracking-wide uppercase opacity-70">Риски</div>
                        <ul className="mt-1 space-y-1.5">
                            {risks.map((r) => (
                                <li key={r.label} className="surface-inset rounded border px-2 py-1.5 text-xs">
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
