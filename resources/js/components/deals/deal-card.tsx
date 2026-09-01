import { AiListingReportPanel } from '@/components/deals/ai-listing-report';
import { AnalysisBrief } from '@/components/deals/analysis-brief';
import { PriceZonesMini } from '@/components/deals/price-zones-mini';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    listingBuyPrice,
    money,
    phoneForSms,
    profitLabel,
    profitTone,
    scoreTone,
    screeningMessage,
    sellerDescription,
    valuationOf,
    verdictLabel,
} from '@/lib/deal-format';
import { type DealRow } from '@/types/deal';
import { Link, router } from '@inertiajs/react';
import { Copy, ExternalLink, MessageSquare, Phone, Star } from 'lucide-react';

type Props = {
    deal: DealRow;
    /** Заголовок группы моделей — только у первой карточки группы. */
    groupLabel: string | null;
    /** Лента скрытых объявлений: «Скрыть» превращается в «Вернуть». */
    dismissedView: boolean;
    copiedId: number | null;
    copiedDescId: number | null;
    aiConfigured: boolean;
    visionAvailable: boolean;
    onCopyDescription: (deal: DealRow) => void;
    onCopySms: (deal: DealRow) => void;
    onSetStatus: (dealId: number, status: string) => void;
};

export function DealCard({
    deal,
    groupLabel,
    dismissedView,
    copiedId,
    copiedDescId,
    aiConfigured,
    visionAvailable,
    onCopyDescription,
    onCopySms,
    onSetStatus,
}: Props) {
    return (
        <div>
            {groupLabel && (
                <div className="surface-neutral sticky top-0 z-10 mb-2 rounded-lg border px-3 py-2 backdrop-blur">
                    <div className="text-sm font-semibold tracking-tight">{groupLabel}</div>
                </div>
            )}
            <article className="hover:border-border rounded-xl border p-4 transition">
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
                                <h2 className="text-lg leading-tight font-semibold">{deal.listing.display_name}</h2>
                                <Badge variant="secondary">{verdictLabel(deal.verdict)}</Badge>
                                <Badge variant="outline">{deal.freshness}</Badge>
                                {deal.listing.currency && deal.listing.currency !== 'MDL' && <Badge variant="outline">{deal.listing.currency}</Badge>}
                                {deal.suspicious && (
                                    <Badge variant="destructive">{deal.is_bait ? 'Кликбейт / не цена' : 'Проверь: слишком дёшево'}</Badge>
                                )}
                                {(deal.listing_kind === 'want_buy' || deal.listing.listing_kind === 'want_buy') && (
                                    <Badge variant="outline" className="surface-ai">
                                        Куплю · потенциальный покупатель
                                    </Badge>
                                )}
                                {(deal.is_reseller || deal.listing.is_reseller) && (
                                    <Badge variant="outline" className="surface-ai">
                                        Перекуп · {deal.seller_listings_count ?? deal.listing.seller_listings_count ?? '?'} тел.
                                    </Badge>
                                )}
                                {deal.listing.seller_type === 'shop' && deal.listing.listing_kind !== 'want_buy' && (
                                    <Badge variant="outline" className="surface-warning">
                                        Магазин
                                    </Badge>
                                )}
                                {deal.listing.seller_type && deal.listing.seller_type !== 'shop' && deal.listing.listing_kind !== 'want_buy' && (
                                    <Badge variant="outline">{deal.listing.seller_type}</Badge>
                                )}
                            </div>
                            <p className="text-muted-foreground mt-1 text-sm">{deal.listing.title}</p>
                            {sellerDescription(deal) || deal.listing.external_id ? (
                                <button
                                    type="button"
                                    className="surface-neutral hover:border-border mt-2 w-full rounded-lg border px-3 py-2 text-left text-sm transition"
                                    onClick={() => onCopyDescription(deal)}
                                    title="Нажми — скопировать № объявления, ссылку, регион и текст"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">Объявление 999.md</span>
                                        <span className="text-muted-foreground text-xs">
                                            {copiedDescId === deal.id ? (
                                                <span className="text-positive">Скопировано</span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1">
                                                    <Copy className="size-3" />
                                                    копировать
                                                </span>
                                            )}
                                        </span>
                                    </div>
                                    <div className="text-muted-foreground mt-1 space-y-0.5 text-xs">
                                        {deal.listing.external_id && (
                                            <div>
                                                №{' '}
                                                <a
                                                    href={deal.listing.url || `https://999.md/ru/${deal.listing.external_id}`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-info font-medium underline"
                                                    onClick={(e) => e.stopPropagation()}
                                                >
                                                    {deal.listing.external_id}
                                                </a>
                                            </div>
                                        )}
                                        {deal.listing.location && <div>Регион: {deal.listing.location}</div>}
                                    </div>
                                    <p className="text-foreground mt-2 line-clamp-4 text-sm leading-snug whitespace-pre-wrap">
                                        {sellerDescription(deal) || (
                                            <span className="text-muted-foreground">Описание не загружено — нажми «Обновить аналитику»</span>
                                        )}
                                    </p>
                                </button>
                            ) : null}
                            <AnalysisBrief deal={deal} />
                            <AiListingReportPanel
                                dealId={deal.id}
                                reports={deal.ai_reports}
                                aiConfigured={aiConfigured}
                                visionAvailable={visionAvailable}
                            />
                            <div className="mt-3 grid gap-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                <div>
                                    Цена покупки: <strong>{listingBuyPrice(deal)}</strong>
                                </div>
                                <div>
                                    Дисконт к ожид. продаже: <strong>{deal.discount_percent != null ? `${deal.discount_percent}%` : '—'}</strong>
                                </div>
                                <div className={`font-semibold ${profitTone(deal.potential_profit)}`}>
                                    Прибыль после торга: {profitLabel(deal.potential_profit)}
                                </div>
                                {deal.listing.battery_health != null && <div>Battery {deal.listing.battery_health}%</div>}
                                {deal.listing.location && <div>{deal.listing.location}</div>}
                            </div>

                            {valuationOf(deal) && (
                                <div className="surface-success mt-3 rounded-lg border px-3 py-2 text-sm">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="text-positive text-xs font-semibold tracking-wide uppercase">
                                            Оценка состояния → перепродажа
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            <Badge variant="outline" className="bg-card border-transparent">
                                                состояние {valuationOf(deal)!.condition_score}/100
                                            </Badge>
                                            <Badge variant="secondary">уверенность: {valuationOf(deal)!.valuation_confidence}</Badge>
                                        </div>
                                    </div>
                                    <p className="mt-1 text-sm font-medium">{valuationOf(deal)!.condition_label}</p>
                                    <p className="mt-1 text-xs opacity-80">{valuationOf(deal)!.valuation_confidence_note}</p>

                                    <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                        <div className="surface-inset rounded-md border border-transparent px-2 py-1.5">
                                            <div className="text-[10px] tracking-wide uppercase opacity-70">Чистый mid рынка</div>
                                            <div className="font-semibold tabular-nums">{money(valuationOf(deal)!.market_mid_clean)}</div>
                                        </div>
                                        <div className="surface-inset rounded-md border border-transparent px-2 py-1.5">
                                            <div className="text-[10px] tracking-wide uppercase opacity-70">Fair ask (с состоянием)</div>
                                            <div className="font-semibold tabular-nums">{money(valuationOf(deal)!.fair_ask)}</div>
                                            <div className="text-[10px] opacity-70">уценка −{valuationOf(deal)!.condition_haircut_percent}%</div>
                                        </div>
                                        <div className="surface-success rounded-md border px-2 py-1.5">
                                            <div className="text-[10px] tracking-wide uppercase opacity-70">Реально после торга</div>
                                            <div className="text-positive text-base font-bold tabular-nums">
                                                {money(valuationOf(deal)!.expected_sale)}
                                            </div>
                                            <div className="text-[10px] opacity-70">торг ≈ −{valuationOf(deal)!.negotiation_percent}%</div>
                                        </div>
                                        <div className="surface-inset rounded-md border border-transparent px-2 py-1.5">
                                            <div className="text-[10px] tracking-wide uppercase opacity-70">Быстрая продажа</div>
                                            <div className="font-semibold tabular-nums">{money(valuationOf(deal)!.quick_sale)}</div>
                                            <div className="text-[10px] opacity-70">макс. покупка {money(valuationOf(deal)!.max_buy_for_profit)}</div>
                                        </div>
                                    </div>

                                    {valuationOf(deal)!.penalties.length > 0 && (
                                        <div className="mt-2">
                                            <div className="text-xs font-semibold opacity-70">Уценка за состояние</div>
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
                                <div className="surface-success mt-3 rounded-lg border px-3 py-2 text-sm">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="text-positive text-xs font-semibold tracking-wide uppercase">
                                            Рынок продажи — с основанием
                                        </div>
                                        <Link href={`/market/${deal.market.id}`} className="text-positive text-xs font-medium underline">
                                            Полный разбор
                                        </Link>
                                    </div>
                                    <div className="mt-1">
                                        Чистый mid: <strong>{money(deal.market_mid_clean ?? deal.market_price, 'MDL')}</strong>
                                        <span className="opacity-80">
                                            {' '}
                                            (частник {money(deal.market.sell_low, 'MDL')}–{money(deal.market.sell_high, 'MDL')})
                                        </span>
                                    </div>
                                    {valuationOf(deal) && (
                                        <div className="mt-1">
                                            Ожидаемая твоя продажа с учётом состояния/торга:{' '}
                                            <strong>{money(valuationOf(deal)!.expected_sale)}</strong>
                                        </div>
                                    )}
                                    {deal.price_zones && <PriceZonesMini zones={deal.price_zones} />}
                                    <div className="mt-1 opacity-90">{deal.market.foundation}</div>
                                    {deal.market.buy_rule && <div className="mt-1 text-xs">Покупка: {deal.market.buy_rule}</div>}
                                    {deal.market.calc && <div className="mt-1 font-mono text-xs opacity-80">Расчёт прибыли: {deal.market.calc}</div>}
                                </div>
                            )}
                            {!deal.market && deal.market_price != null && (
                                <div className="surface-warning mt-3 rounded-lg border px-3 py-2 text-sm">
                                    Рынок: {money(deal.market_price, 'MDL')} — основание не привязано к карточке модели.
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
                                        void onCopyDescription(deal);
                                        onSetStatus(deal.id, 'opened');
                                    }}
                                >
                                    <ExternalLink className="mr-1 size-4" />
                                    Открыть
                                </a>
                            </Button>
                            {deal.listing.seller_phone && (
                                <Button asChild size="sm" variant="secondary">
                                    <a href={`tel:${deal.listing.seller_phone}`} onClick={() => onSetStatus(deal.id, 'called')}>
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
                                        void onCopyDescription(deal);
                                        router.post(`/deals/${deal.id}/favorite`, {}, { preserveScroll: true });
                                    }
                                }}
                            >
                                <Star className={`mr-1 size-4 ${deal.is_favorite ? 'fill-white' : ''}`} />
                                {deal.is_favorite ? 'В избранном' : 'В избранное'}
                            </Button>
                            <Button size="sm" variant="outline" onClick={() => onSetStatus(deal.id, 'bought')}>
                                Купил
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => onSetStatus(deal.id, deal.user_status === 'dismissed' || dismissedView ? 'new' : 'dismissed')}
                            >
                                {deal.user_status === 'dismissed' || dismissedView ? 'Вернуть' : 'Скрыть'}
                            </Button>
                        </div>

                        <div className="surface-info w-full rounded-lg border px-3 py-2 text-left text-sm">
                            <div className="text-info mb-1 text-xs font-semibold tracking-wide uppercase">SMS до встречи</div>
                            <pre className="font-sans text-[13px] leading-snug whitespace-pre-wrap opacity-95">{screeningMessage(deal)}</pre>
                            <div className="mt-2 flex flex-wrap gap-2">
                                <Button size="sm" variant="outline" className="bg-card h-8 border-transparent" onClick={() => onCopySms(deal)}>
                                    <Copy className="mr-1 size-3.5" />
                                    {copiedId === deal.id ? 'Скопировано' : 'Текст + SMS'}
                                </Button>
                                {deal.listing.seller_phone && (
                                    <Button asChild size="sm" className="h-8">
                                        <a
                                            href={`sms:${phoneForSms(deal.listing.seller_phone)}?body=${encodeURIComponent(screeningMessage(deal))}`}
                                            onClick={() => onSetStatus(deal.id, 'called')}
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
}
