import { type DealRow } from '@/types/deal';

export function rangeShort(from: number | null, to: number | null): string {
    if (from == null && to != null) return `< ${new Intl.NumberFormat('ru-MD').format(to)}`;
    if (from != null && to == null) return `> ${new Intl.NumberFormat('ru-MD').format(from)}`;
    if (from != null && to != null) {
        return `${new Intl.NumberFormat('ru-MD').format(from)}–${new Intl.NumberFormat('ru-MD').format(to)}`;
    }
    return '—';
}

const ZONE_BAR: Record<string, string> = {
    below_buy_min: 'bg-red-400',
    buy_zone: 'bg-sky-500',
    between: 'bg-zinc-400',
    sell_band: 'bg-emerald-600',
    above_sell: 'bg-amber-500',
};

const ZONE_CHIP: Record<string, string> = {
    below_buy_min: 'Ниже buy_min',
    buy_zone: 'Зона покупки',
    between: 'Между зонами',
    sell_band: 'Рынок продажи',
    above_sell: 'Выше рынка',
};

export function PriceZonesMini({ zones }: { zones: NonNullable<DealRow['price_zones']> }) {
    const max = Math.max(...zones.zones.map((z) => z.all), 1);
    const askZone = zones.zones.find((z) => z.key === zones.ask_zone);

    return (
        <div className="surface-inset mt-2 rounded-md border border-transparent px-2.5 py-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div className="text-positive text-[10px] font-semibold tracking-wide uppercase">Сколько объявлений в каких ценах</div>
                <div className="text-[10px] opacity-70">
                    частн. {zones.total_private}
                    {zones.total_shop > 0 ? ` · маг. ${zones.total_shop}` : ''}
                    {zones.private_median != null ? ` · медиана ${new Intl.NumberFormat('ru-MD').format(zones.private_median)}` : ''}
                </div>
            </div>

            <div className="mt-2 grid grid-cols-5 gap-1.5">
                {zones.zones.map((z) => {
                    const h = Math.max(z.all > 0 ? 4 : 2, Math.round((z.all / max) * 40));
                    const isAsk = z.key === zones.ask_zone;
                    return (
                        <div
                            key={z.key}
                            className={`flex flex-col items-center rounded-md px-0.5 py-1 ${isAsk ? 'bg-sky-100 ring-2 ring-sky-500' : ''}`}
                            title={`${z.short_label}: ${z.all} (${z.private} частн.) · ${rangeShort(z.from, z.to)}`}
                        >
                            <div className="text-xs leading-none font-bold tabular-nums">{z.all}</div>
                            <div className="mt-1 flex h-10 w-full items-end justify-center">
                                <div className={`w-full max-w-8 rounded-t ${ZONE_BAR[z.key] ?? 'bg-zinc-400'}`} style={{ height: `${h}px` }} />
                            </div>
                            <div className="mt-1 text-center text-[9px] leading-tight opacity-75">
                                {z.key === 'below_buy_min'
                                    ? 'ниже'
                                    : z.key === 'buy_zone'
                                      ? 'покупка'
                                      : z.key === 'between'
                                        ? 'между'
                                        : z.key === 'sell_band'
                                          ? 'продажа'
                                          : 'выше'}
                            </div>
                            <div className="text-center text-[8px] tabular-nums opacity-55">{rangeShort(z.from, z.to)}</div>
                        </div>
                    );
                })}
            </div>

            {askZone && zones.ask_price != null && (
                <div className="text-positive mt-2 text-xs">
                    Это объявление: <strong>{new Intl.NumberFormat('ru-MD').format(zones.ask_price)} MDL</strong>
                    {' → '}
                    <span className="font-medium">{ZONE_CHIP[askZone.key] ?? askZone.short_label}</span>
                    <span className="opacity-70">
                        {' '}
                        (в зоне {askZone.all} объявл., из них {askZone.private} частн.)
                    </span>
                </div>
            )}
        </div>
    );
}
