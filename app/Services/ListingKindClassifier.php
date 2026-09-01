<?php

namespace App\Services;

use App\Models\Listing;

class ListingKindClassifier
{
    public const KIND_SELL = 'sell';

    public const KIND_WANT_BUY = 'want_buy';

    /**
     * Detect "куплю / cumpăr" buyer ads vs normal sell listings.
     * Title intent is primary; description only if title is empty/ambiguous.
     */
    public function classify(?string $title, ?string $description = null): string
    {
        $title = trim((string) $title);
        if ($title === '') {
            return self::KIND_SELL;
        }

        $t = mb_strtolower($title);

        // Shop retail / trade-in / credit ads are always sell supply.
        if ($this->looksLikeRetailSell($t)) {
            return self::KIND_SELL;
        }

        if ($this->looksLikeSell($t)) {
            return self::KIND_SELL;
        }

        if ($this->looksLikeWantBuyLead($t)) {
            return self::KIND_WANT_BUY;
        }

        // Do not use description: shop/spam bodies often say «cumpărăm vechiul» (trade-in).
        return self::KIND_SELL;
    }

    public function apply(Listing $listing): string
    {
        $kind = $this->classify($listing->title, $listing->description);
        if ($listing->listing_kind !== $kind) {
            $listing->forceFill(['listing_kind' => $kind])->save();
        }

        return $kind;
    }

    private function looksLikeRetailSell(string $text): bool
    {
        return (bool) preg_match(
            '/\b(trade[\s\-]?in|tradein|schimb\s+prin|credit\s+0\s*%|avans\s+0|garan[tț]ie|recondi[tț]ionat|magazin|oficial)\b/u',
            $text
        );
    }

    private function looksLikeSell(string $text): bool
    {
        return (bool) preg_match(
            '/\b(продаю|продам|продажа|v[aâ]nd|vinde|de\s+v[aâ]nzare|for\s+sale|schimb\s+iphone)\b/u',
            $text
        );
    }

    /** Title/lead shaped like a buyer looking for phones. */
    private function looksLikeWantBuyLead(string $text): bool
    {
        $withoutBought = preg_replace(
            '/\b(куплен[аоы]?|купленн\w*|cump[aă]rat[ăa]?|cumparat[ăa]?|purchased)\b/u',
            ' ',
            $text
        ) ?? $text;

        // Strong: starts with buyer verb.
        if (preg_match(
            '/^\s*(куплю|покупаю|купка|скупаю|ищу|ищем|cump[aă]r|cumpar|cump[aă]rare|caut)\b/u',
            $withoutBought
        )) {
            return true;
        }

        // "Куплю телефоны…", "Cumpăr iPhone/Samsung…"
        if (preg_match(
            '/\b(куплю|покупаю|готов\s+купить|скупк\w*|cump[aă]r\b|cumpar\s+urgent|cump[aă]rare\s+telefon)/u',
            $withoutBought
        )) {
            return true;
        }

        if (preg_match('/\bcaut\s+(iphone|samsung|pixel|xiaomi|telefon|apple)\b/u', $withoutBought)) {
            return true;
        }

        return false;
    }
}
