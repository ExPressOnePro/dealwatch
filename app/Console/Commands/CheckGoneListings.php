<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\Archive\ListingArchivist;
use App\Services\Archive\ListingLivenessChecker;
use Illuminate\Console\Command;

class CheckGoneListings extends Command
{
    protected $signature = 'listings:check-gone
        {--limit=50 : Сколько объявлений проверить за прогон}
        {--hours= : Не встречалось в выдаче столько часов (по умолчанию из конфига)}';

    protected $description = 'Отметить объявления, снятые с площадки, сохранив последний снимок';

    public function handle(ListingLivenessChecker $checker, ListingArchivist $archivist): int
    {
        $hours = (int) ($this->option('hours') ?: config('dealwatch.archive.gone_check_after_hours'));
        $limit = max(1, (int) $this->option('limit'));

        $listings = Listing::query()
            ->where('status', 'active')
            ->whereNull('gone_at')
            ->where(function ($q) use ($hours) {
                $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subHours($hours));
            })
            // Сначала то, что важно лично нам: архив и объявления из сделок.
            ->orderByDesc('archived')
            ->orderBy('last_seen_at')
            ->limit($limit)
            ->get();

        if ($listings->isEmpty()) {
            $this->info('Нечего проверять: все объявления свежие.');

            return self::SUCCESS;
        }

        $gone = 0;
        $alive = 0;
        $unknown = 0;

        foreach ($listings as $listing) {
            $result = $checker->isAlive($listing);

            if ($result === false) {
                $archivist->markGone($listing);
                $gone++;
            } elseif ($result === true) {
                $listing->forceFill(['last_seen_at' => now()])->save();
                $alive++;
            } else {
                $unknown++;
            }

            usleep(300_000);
        }

        $this->table(
            ['Проверено', 'Снято с площадки', 'Живы', 'Не удалось'],
            [[$listings->count(), $gone, $alive, $unknown]]
        );

        return self::SUCCESS;
    }
}
