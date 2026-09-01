<?php

namespace App\Services;

use App\Models\Listing;

/**
 * Что именно продают в объявлении: сам товар, запчасти к нему, аксессуар,
 * услугу — и оригинал ли это.
 *
 * Без этого «Piese JBL Charge» за 350 лей сравнивается с ценой колонки и
 * выглядит находкой века, а реплика за 399 — вдвойне.
 */
class ListingSubjectClassifier
{
    public const SUBJECT_ITEM = 'item';

    public const SUBJECT_PARTS = 'parts';

    public const SUBJECT_ACCESSORY = 'accessory';

    public const SUBJECT_SERVICE = 'service';

    public const SUBJECTS = [self::SUBJECT_ITEM, self::SUBJECT_PARTS, self::SUBJECT_ACCESSORY, self::SUBJECT_SERVICE];

    /** Ремонт и установка — услуга, а не товар. */
    private const SERVICE = '/\b(ремонт|починк|перепайк|прошивк|диагностик|обслуживани|reparat|repar[ăa]m|service|montaj|instalare|cur[ăa][țt]are)\w*/iu';

    /** Запчасти и «на разбор». */
    private const PARTS = '/(\bpiese\b|\bpiesa\b|запчаст\w*|\bна\s+запчаст\w*|\bна\s+разбор\w*|dezmembr\w*|\bдетал[иья]\b|\bплат[аы]\b|\bplac[ăa]\b|шлейф\w*|динамик\w*|мембран\w*|\bкнопк\w*\s+для|\b(аккумулятор|батаре[яи]|acumulator|baterie)\s+(для|pentru|de\s+la)\b)/iu';

    /** Аксессуары: чехлы, кабели, стёкла, ремешки. */
    private const ACCESSORY = '/(\bhus[ăa]\b|чехол\w*|\bкабел[ья]\b|\bcablu\b|зарядк\w*|зарядно\w*|\bincarcator\b|\b[îi]nc[ăa]rc[ăa]tor\b|adaptor\w*|\bстекл\w*|\bsticl[ăa]\b|\bfolie\b|пл[её]нк\w*|ремеш\w*|\bcurea\b|\bсумк\w*|\bgeant[ăa]\b|подставк\w*|\bsuport\b|держател\w*|креплени\w*)/iu';

    /** Копии и реплики — другой товар и другая цена. */
    private const REPLICA = '/(\bреплик\w*|\breplic[ăa]\b|\bкопи[яи]\b|\bcopie\b|\bаналог\b|подделк\w*|\bfake\b|\bне\s+оригинал\w*|\bлюкс\s*коп\w*|\b1:1\b)/iu';

    /** Признаки, что это всё-таки сам товар (комплект «с чехлом» и т.п.). */
    private const ITEM_HINT = '/(\bв\s+комплекте\b|\bс\s+чехлом\b|\bcu\s+hus[ăa]\b|\bподарок\b|\bcadou\b)/iu';

    /**
     * @return array{subject: string, replica: bool, matched: list<string>}
     */
    public function classify(?string $title, ?string $description = null): array
    {
        $title = trim((string) $title);
        // Заголовок решает: в описании часто перечисляют, что ещё есть у продавца.
        $head = mb_strtolower($title);
        $body = mb_strtolower(trim($head."\n".mb_substr((string) $description, 0, 400)));

        $matched = [];
        $subject = self::SUBJECT_ITEM;

        // «Не был в ремонте» — это характеристика товара, а не предложение услуги.
        $negatedService = (bool) preg_match(
            '/\b(nu\s+a\s+fost\s+repar\w*|f[ăa]r[ăa]\s+repara\w*|не\s+ремонтировал\w*|без\s+ремонт\w*|не\s+был\s+в\s+ремонт\w*)/iu',
            $body
        );

        foreach ([
            self::SUBJECT_SERVICE => self::SERVICE,
            self::SUBJECT_PARTS => self::PARTS,
            self::SUBJECT_ACCESSORY => self::ACCESSORY,
        ] as $candidate => $pattern) {
            if ($candidate === self::SUBJECT_SERVICE && $negatedService) {
                continue;
            }

            if (preg_match($pattern, $head, $m)) {
                $subject = $candidate;
                $matched[] = trim($m[0]);
                break;
            }
        }

        // В описании ловим только явные «запчасти»: услуги там чаще упоминаются
        // с отрицанием («nu a fost reparat», «не ремонтировался»), а аксессуар —
        // как бонус к товару.
        if ($subject === self::SUBJECT_ITEM && ! preg_match(self::ITEM_HINT, $body) && preg_match(self::PARTS, $body, $m)) {
            $subject = self::SUBJECT_PARTS;
            $matched[] = trim($m[0]);
        }

        $replica = false;
        if (preg_match(self::REPLICA, $body, $m)) {
            $replica = true;
            $matched[] = trim($m[0]);
        }

        return [
            'subject' => $subject,
            'replica' => $replica,
            'matched' => array_values(array_unique(array_filter($matched))),
        ];
    }

    /**
     * @return array{subject: string, replica: bool, matched: list<string>}
     */
    public function apply(Listing $listing): array
    {
        $result = $this->classify($listing->title, $listing->description);

        if ($listing->subject !== $result['subject'] || (bool) $listing->is_replica !== $result['replica']) {
            $listing->forceFill([
                'subject' => $result['subject'],
                'is_replica' => $result['replica'],
            ])->save();
        }

        return $result;
    }

    /** Годится ли объявление как ориентир рынка и как сделка. */
    public function isRealItem(Listing $listing): bool
    {
        return ($listing->subject ?? self::SUBJECT_ITEM) === self::SUBJECT_ITEM && ! $listing->is_replica;
    }

    public static function label(?string $subject, bool $replica = false): ?string
    {
        $label = match ($subject) {
            self::SUBJECT_PARTS => 'запчасти',
            self::SUBJECT_ACCESSORY => 'аксессуар',
            self::SUBJECT_SERVICE => 'услуга',
            default => null,
        };

        if ($replica) {
            $label = $label ? $label.' · реплика' : 'реплика';
        }

        return $label;
    }
}
