<?php

namespace App\Services;

use App\Models\Listing;

/**
 * Давно не обновлявшееся объявление — частый случай «уже продано, просто не сняли».
 * Площадка показывает дату последнего обновления, по ней и судим.
 */
class ListingStaleness
{
    public const LEVEL_FRESH = 'fresh';

    public const LEVEL_SUSPECT = 'suspect';

    public const LEVEL_DEAD = 'dead';

    public function daysSinceUpdate(Listing $listing): ?int
    {
        $updatedOn = $listing->published_at ?? $listing->first_seen_at;

        return $updatedOn ? (int) $updatedOn->diffInDays(now()) : null;
    }

    /**
     * @return array{days: ?int, level: string, note: ?string}
     */
    public function assess(Listing $listing): array
    {
        $days = $this->daysSinceUpdate($listing);

        // Нулевой порог означает «признак выключен», а не «всё протухло»:
        // без этой защиты пустой конфиг помечал каждое объявление проданным.
        $suspect = (int) config('dealwatch.staleness.suspect_days') ?: 21;
        $dead = (int) config('dealwatch.staleness.dead_days') ?: 60;

        if ($dead < $suspect) {
            $dead = $suspect;
        }

        if ($days === null || $days < $suspect) {
            return ['days' => $days, 'level' => self::LEVEL_FRESH, 'note' => null];
        }

        if ($days >= $dead) {
            return [
                'days' => $days,
                'level' => self::LEVEL_DEAD,
                'note' => sprintf(
                    'Объявление не обновлялось %d дней — скорее всего, товар уже продан или продавец про него забыл. Сначала напиши, потом планируй.',
                    $days
                ),
            ];
        }

        return [
            'days' => $days,
            'level' => self::LEVEL_SUSPECT,
            'note' => sprintf(
                'Висит %d дней без обновления: возможно, уже продано или цена неактуальна.',
                $days
            ),
        ];
    }

    /**
     * Понизить оценку залежавшегося объявления.
     *
     * @param  array<string, mixed>  $breakdown
     * @return array{score: int, verdict: string, breakdown: array<string, mixed>}
     */
    public function apply(Listing $listing, int $score, string $verdict, array $breakdown): array
    {
        $stale = $this->assess($listing);

        $breakdown['listing_age_days'] = $stale['days'];
        $breakdown['staleness'] = $stale['level'];

        if ($stale['note']) {
            $breakdown['stale_note'] = $stale['note'];
        }

        if ($stale['level'] === self::LEVEL_DEAD) {
            $score = min($score, 55);
            $verdict = $verdict === 'buy' ? 'check' : $verdict;
        } elseif ($stale['level'] === self::LEVEL_SUSPECT) {
            $score = min($score, 75);
        }

        return ['score' => $score, 'verdict' => $verdict, 'breakdown' => $breakdown];
    }
}
