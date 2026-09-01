<?php

namespace App\Services\Archive;

use App\Models\Listing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Жива ли страница объявления. 999.md отдаёт 404 и на снятые, и на никогда
 * не существовавшие объявления — этого достаточно, чтобы понять, что телефон
 * больше не продаётся (чаще всего — продан).
 */
class ListingLivenessChecker
{
    private const GONE_MARKERS = [
        'объявление не найдено',
        'anunțul nu a fost găsit',
        'nu a fost găsit',
        'объявление истекло',
        'anunțul a expirat',
    ];

    /**
     * @return bool|null true — живо, false — снято, null — проверить не удалось
     */
    public function isAlive(Listing $listing): ?bool
    {
        if (blank($listing->url)) {
            return null;
        }

        try {
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get($listing->url);
        } catch (\Throwable $e) {
            Log::info('Liveness check failed for '.$listing->external_id.': '.$e->getMessage());

            return null;
        }

        if (in_array($response->status(), [404, 410], true)) {
            return false;
        }

        if (! $response->successful()) {
            return null;
        }

        $html = mb_strtolower($response->body());

        foreach (self::GONE_MARKERS as $marker) {
            if (str_contains($html, $marker)) {
                return false;
            }
        }

        return true;
    }
}
