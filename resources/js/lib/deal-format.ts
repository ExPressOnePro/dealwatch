import { type AnalystReport, type DealRow, type Valuation } from '@/types/deal';

// Форматирование и мелкая логика карточки сделки — общее для ленты и избранного.

export function money(value: number | null | undefined, currency = 'MDL'): string {
    if (value == null) return '—';
    return new Intl.NumberFormat('ru-MD').format(value) + ' ' + currency;
}

export function listingBuyPrice(deal: DealRow): string {
    const currency = deal.listing.currency || 'MDL';
    const original = deal.listing.price_original ?? deal.listing.price_mdl;
    const mdl = deal.listing.price_mdl;
    if (original == null) return '—';
    if (currency === 'MDL') return money(original, 'MDL');
    return `${money(original, currency)} ≈ ${money(mdl, 'MDL')}`;
}

export function profitLabel(value: number | null | undefined): string {
    if (value == null) return '—';
    const sign = value > 0 ? '+' : '';
    return sign + new Intl.NumberFormat('ru-MD').format(value) + ' MDL';
}

export function scoreTone(score: number): string {
    // Насыщенные заливки читаются в обеих темах, поэтому цвет текста фиксируем.
    if (score >= 80) return 'bg-emerald-600 text-white';
    if (score >= 60) return 'bg-amber-500 text-zinc-950';
    return 'bg-zinc-500 text-white';
}

export function profitTone(value: number | null | undefined): string {
    if (value == null) return 'text-muted-foreground';
    if (value >= 800) return 'text-positive';
    if (value >= 0) return 'text-warning';

    return 'text-negative';
}

export function verdictLabel(v: string): string {
    return v === 'buy' ? 'ЗАБИРАТЬ' : v === 'check' ? 'СМОТРЕТЬ' : 'ПРОПУСК';
}

export function screeningMessage(deal: DealRow): string {
    const fromReport = deal.sms_text || deal.analyst_report?.sms || deal.listing.analyst_report?.sms;
    if (fromReport) {
        return fromReport;
    }

    const name = deal.listing.display_name || deal.listing.title;
    const price = listingBuyPrice(deal);

    return [
        `Здравствуйте! Интересует ${name} (объявление: ${price}).`,
        'Перед встречей уточните, пожалуйста:',
        '1) Аккумулятор сколько %?',
        '2) Face ID / отпечаток работает?',
        '3) iCloud чистый, без блокировок?',
        '4) Экран/корпус родные? Был ли ремонт?',
        '5) Цена окончательная — сколько?',
        '6) Где и когда удобно посмотреть?',
        'Спасибо!',
    ].join('\n');
}

export function phoneForSms(phone: string): string {
    return phone.replace(/[^\d+]/g, '');
}

export function sellerDescription(deal: DealRow): string {
    return (deal.listing.description || '').trim();
}

export function sellerCopyPayload(deal: DealRow): string {
    const lines: string[] = [];
    const id = deal.listing.external_id;
    const url = deal.listing.url || (id ? `https://999.md/ru/${id}` : '');

    if (id) {
        lines.push(`999.md #${id}`);
    }
    if (url) {
        lines.push(url);
    }
    if (deal.listing.location) {
        lines.push(`Регион: ${deal.listing.location}`);
    }

    const desc = sellerDescription(deal);
    if (desc) {
        if (lines.length) {
            lines.push('');
        }
        lines.push(desc);
    }

    return lines.join('\n');
}

export async function copyToClipboard(text: string): Promise<boolean> {
    if (!text) return false;
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        return false;
    }
}

export function reportOf(deal: DealRow): AnalystReport | null {
    return deal.analyst_report || deal.listing.analyst_report || null;
}

export function valuationOf(deal: DealRow): Valuation | null {
    return deal.valuation || reportOf(deal)?.valuation || null;
}

export const MODEL_SOURCE_LABELS: Record<string, string> = {
    site: 'Модель на сайте',
    title: 'Из заголовка',
    description: 'Из описания',
};

export function modelSourceLabel(source?: string | null): string | null {
    if (!source) return null;
    return MODEL_SOURCE_LABELS[source] ?? source;
}

export const FLAG_LABELS: Record<string, string> = {
    bait_price: 'Кликбейт-цена',
    negotiable: 'Договорная',
    clickbait_text: 'Цена для просмотров',
    deposit: 'Задаток',
    exchange: 'Обмен',
    incomplete: 'Мало текста',
    parts_or_lock: 'Блок / запчасти',
    replaced_parts: 'Замены',
    no_face_id: 'Нет Face ID',
    face_id_ok: 'Face ID OK',
    wireless_broken: 'NFC/BT/Wi‑Fi',
    low_battery: 'Слабая АКБ',
};
