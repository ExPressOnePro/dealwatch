<?php

namespace App\Console\Commands;

use App\Models\SearchProfile;
use App\Services\ListingPipeline;
use Illuminate\Console\Command;

class Collect999Listings extends Command
{
    protected $signature = 'deals:collect-999
        {--no-notify : Do not send Telegram alerts}
        {--profile= : Собрать только один источник (id)}';

    protected $description = 'Collect listings from configured 999.md sources and score deals';

    public function handle(ListingPipeline $pipeline): int
    {
        $notify = ! $this->option('no-notify');

        if ($profileId = $this->option('profile')) {
            $profile = SearchProfile::find($profileId);

            if (! $profile) {
                $this->error("Источник #{$profileId} не найден.");

                return self::FAILURE;
            }

            $this->info("Собираю источник «{$profile->name}» ({$profile->describe()})…");
            $stats = $pipeline->collectProfile($profile, $notify) + ['empty_streak' => 0, 'profiles' => []];
        } else {
            $this->info('Собираю все включённые источники 999.md…');
            $stats = $pipeline->collectAll($notify);
        }

        $this->table(
            ['Fetched', 'Ingested', 'Deals', 'Alerts'],
            [[$stats['fetched'], $stats['ingested'], $stats['deals'], $stats['alerts']]]
        );

        foreach ($stats['profiles'] ?? [] as $row) {
            $this->line(sprintf('  %-32s найдено %3d · сделок %3d', $row['name'], $row['fetched'], $row['deals']));
        }

        if ($stats['fetched'] === 0) {
            $this->warn(sprintf(
                'Пустой сбор (%d подряд). 999.md мог сменить поля GraphQL или включить защиту. Демо-данные: php artisan db:seed --class=DemoListingSeeder',
                $stats['empty_streak']
            ));
        }

        return self::SUCCESS;
    }
}
