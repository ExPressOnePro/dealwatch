<?php

namespace App\Services;

use App\Models\Listing;

class SellerAnalyticsService
{
    /** More than this many active phone ads on 999 → likely flipper/reseller. */
    public const RESELLER_THRESHOLD = 3;

    /**
     * Stable account id: 999 owner id → login → phone.
     */
    public function resolveKey(Listing $listing): ?string
    {
        $ownerId = data_get($listing->raw_data, 'owner.id');
        if ($ownerId) {
            return '999:owner:'.(int) $ownerId;
        }

        $login = trim((string) ($listing->seller_name ?? ''));
        if ($login !== '') {
            return '999:login:'.mb_strtolower($login);
        }

        $phone = $this->normalizePhone($listing->seller_phone);
        if ($phone !== null) {
            return '999:phone:'.$phone;
        }

        return null;
    }

    /**
     * Update one listing's seller_key and recount its account.
     */
    public function refreshListing(Listing $listing): Listing
    {
        $key = $this->resolveKey($listing);
        if (! $key) {
            $listing->forceFill([
                'seller_listings_count' => 1,
                'is_reseller' => false,
            ])->save();

            return $listing;
        }

        if ($listing->seller_key !== $key) {
            $listing->forceFill(['seller_key' => $key])->save();
        }

        $this->refreshForKey($key);

        return $listing->fresh();
    }

    public function refreshForKey(string $key, int $threshold = self::RESELLER_THRESHOLD): void
    {
        $cnt = Listing::query()
            ->where('platform', '999')
            ->where('status', 'active')
            ->marketSell()
            ->where('seller_key', $key)
            ->count();

        $isReseller = $cnt > $threshold;

        Listing::query()
            ->where('seller_key', $key)
            ->where('status', 'active')
            ->update([
                'seller_listings_count' => max(1, $cnt),
                'is_reseller' => $isReseller,
            ]);
    }

    /**
     * Recompute seller_key, listing counts and is_reseller for active 999 listings.
     *
     * @return array{
     *     keys_assigned: int,
     *     unique_sellers: int,
     *     reseller_accounts: int,
     *     reseller_listings: int,
     *     reseller_share_percent: float,
     *     private_non_reseller: int
     * }
     */
    public function refreshAll(int $threshold = self::RESELLER_THRESHOLD): array
    {
        $keysAssigned = 0;

        Listing::query()
            ->where('platform', '999')
            ->where('status', 'active')
            ->chunkById(250, function ($listings) use (&$keysAssigned) {
                foreach ($listings as $listing) {
                    $key = $this->resolveKey($listing);
                    if ($key && $listing->seller_key !== $key) {
                        $listing->forceFill(['seller_key' => $key])->save();
                        $keysAssigned++;
                    } elseif ($key && ! $listing->seller_key) {
                        $listing->forceFill(['seller_key' => $key])->save();
                        $keysAssigned++;
                    }
                }
            });

        $counts = Listing::query()
            ->where('platform', '999')
            ->where('status', 'active')
            ->marketSell()
            ->whereNotNull('seller_key')
            ->groupBy('seller_key')
            ->selectRaw('seller_key, COUNT(*) as cnt')
            ->pluck('cnt', 'seller_key');

        $resellerAccounts = 0;
        $resellerListings = 0;

        foreach ($counts as $key => $cnt) {
            $cnt = (int) $cnt;
            $isReseller = $cnt > $threshold;

            Listing::query()
                ->where('seller_key', $key)
                ->where('status', 'active')
                ->update([
                    'seller_listings_count' => $cnt,
                    'is_reseller' => $isReseller,
                ]);

            if ($isReseller) {
                $resellerAccounts++;
                $resellerListings += $cnt;
            }
        }

        Listing::query()
            ->where('platform', '999')
            ->where('status', 'active')
            ->whereNull('seller_key')
            ->update([
                'seller_listings_count' => 1,
                'is_reseller' => false,
            ]);

        $totalActive = Listing::query()
            ->where('platform', '999')
            ->where('status', 'active')
            ->count();

        $privateNonReseller = Listing::query()
            ->where('platform', '999')
            ->where('status', 'active')
            ->where('is_reseller', false)
            ->where('seller_type', 'private')
            ->count();

        return [
            'keys_assigned' => $keysAssigned,
            'unique_sellers' => $counts->count(),
            'reseller_accounts' => $resellerAccounts,
            'reseller_listings' => $resellerListings,
            'reseller_share_percent' => $totalActive > 0
                ? round(100 * $resellerListings / $totalActive, 1)
                : 0.0,
            'private_non_reseller' => $privateNonReseller,
        ];
    }

    /**
     * @return array{
     *     is_reseller: bool,
     *     seller_listings_count: int,
     *     seller_key: ?string,
     *     note: string
     * }
     */
    public function profile(Listing $listing): array
    {
        $count = (int) ($listing->seller_listings_count ?: 1);
        $isReseller = (bool) $listing->is_reseller;

        $note = $isReseller
            ? sprintf(
                'Аккаунт с %d активными телефонами на 999 — вероятный перекуп; не триггерим BUY/алерты.',
                $count
            )
            : ($count > 1
                ? sprintf('У продавца %d телефона в базе — пока в норме для частника.', $count)
                : 'Один телефон у аккаунта — типичный частник.');

        return [
            'is_reseller' => $isReseller,
            'seller_listings_count' => $count,
            'seller_key' => $listing->seller_key,
            'note' => $note,
        ];
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if (! $digits || strlen($digits) < 8) {
            return null;
        }

        return $digits;
    }
}
