<?php

namespace App\Services;

use App\Models\SearchProfile;
use Illuminate\Support\Facades\Cache;

/**
 * Общая обвязка кеша для витринных счётчиков: они считаются по всей базе
 * (десятки тысяч строк), поэтому живут в кеше и сбрасываются на действиях
 * пользователя, а не ждут TTL.
 */
class StatsCache
{
    private const KEYS = [
        'dealwatch:stats:feed',
        'dealwatch:stats:corpus',
    ];

    /**
     * @template T
     *
     * @param  callable(): T  $build
     * @return T
     */
    public static function remember(string $key, callable $build): mixed
    {
        $seconds = (int) config('dealwatch.stats.cache_seconds');

        if ($seconds <= 0) {
            return $build();
        }

        return Cache::remember($key, now()->addSeconds($seconds), $build);
    }

    /** Сбросить счётчики после действия, которое меняет ленту. */
    public static function flush(): void
    {
        foreach (self::KEYS as $key) {
            Cache::forget($key);
        }

        // У счётчиков есть разрезы: окно по месяцам, сегмент и выбранный источник.
        $profiles = SearchProfile::query()->pluck('id')->push(null);

        foreach ($profiles as $profileId) {
            $suffix = $profileId ? ':'.$profileId : '';

            Cache::forget('dealwatch:stats:feed'.$suffix);

            foreach ([0, 1, 2, 3, 6, 12] as $months) {
                Cache::forget('dealwatch:stats:corpus:'.$months.$suffix);
            }

            foreach (DealFeedQuery::SEGMENTS as $segment) {
                Cache::forget('dealwatch:stats:models:'.$segment.$suffix);
            }
        }
    }
}
