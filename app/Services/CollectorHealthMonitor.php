<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 999.md отдаёт данные через приватный GraphQL: смена id полей или фильтров
 * на их стороне не ломает приложение, а просто обнуляет выдачу. Чтобы такая
 * тишина не выглядела как «сегодня нет объявлений», считаем подряд идущие
 * пустые сборы и зовём в Telegram, когда их становится слишком много.
 */
class CollectorHealthMonitor
{
    private const EMPTY_RUNS_KEY = 'dealwatch:collector:empty_runs';

    private const ALERT_SENT_KEY = 'dealwatch:collector:alert_sent';

    public function __construct(
        private readonly TelegramNotifier $telegram,
    ) {}

    /**
     * @return int Текущая серия пустых прогонов (0 — сбор живой).
     */
    public function recordRun(int $fetched): int
    {
        if ($fetched > 0) {
            Cache::forget(self::EMPTY_RUNS_KEY);
            Cache::forget(self::ALERT_SENT_KEY);

            return 0;
        }

        $streak = $this->emptyStreak() + 1;
        Cache::put(self::EMPTY_RUNS_KEY, $streak, now()->addDay());

        $threshold = max(1, (int) config('dealwatch.collector.empty_runs_before_alert'));
        if ($streak < $threshold) {
            return $streak;
        }

        Log::error('999 collector returned nothing '.$streak.' runs in a row — integration likely broken');

        if (! Cache::get(self::ALERT_SENT_KEY)) {
            $this->telegram->notifyText(
                "⚠️ DealWatch: сбор с 999.md пуст {$streak} прогонов подряд. "
                .'Похоже, сломалась интеграция (изменились поля GraphQL или включилась защита). '
                .'Проверь: php artisan deals:collect-999'
            );
            Cache::put(self::ALERT_SENT_KEY, true, now()->addHours(6));
        }

        return $streak;
    }

    public function emptyStreak(): int
    {
        return (int) Cache::get(self::EMPTY_RUNS_KEY, 0);
    }
}
