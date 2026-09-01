<?php

namespace App\Console\Commands;

use App\Models\SearchProfile;
use App\Services\Niche\FullNicheRun;
use Illuminate\Console\Command;

class FullNicheAnalysis extends Command
{
    protected $signature = 'niche:full
        {profile : id источника}
        {--depth= : Глубина переписи каталога}';

    protected $description = 'Полный проход по источнику: сбор, перепись, продавцы, оценки и аналитика';

    public function handle(FullNicheRun $run): int
    {
        $profile = SearchProfile::find($this->argument('profile'));

        if (! $profile) {
            $this->error('Источник не найден.');

            return self::FAILURE;
        }

        $this->info("Полный анализ источника «{$profile->name}» ({$profile->describe()})");

        $result = $run->run(
            $profile,
            $this->option('depth') ? (int) $this->option('depth') : null,
            fn (string $step) => $this->line('  → '.$step.'…')
        );

        $niche = $result['niche'];

        $this->newLine();
        $this->table(
            ['Собрано', 'Просмотрено в каталоге', 'Новых', 'Смена цены', 'Ушло', 'Пересчитано'],
            [[
                $result['collect']['fetched'],
                $result['scan']['seen'].' / '.$result['scan']['total'],
                $result['scan']['fresh'],
                $result['scan']['price_changes'],
                $result['scan']['gone'],
                $result['rescored'],
            ]]
        );

        $this->line('<options=bold>'.$niche['verdict']['label'].'</>: '.$niche['verdict']['note']);
        $this->line(sprintf(
            'Объявлений: %d активных · приток %d · ушло %d · наблюдаем %d дн.',
            $niche['volume']['active'],
            $niche['volume']['inflow'],
            $niche['volume']['outflow'],
            $niche['observation_days']
        ));
        $this->line(sprintf(
            'Цены: p25 %s · медиана %s · p75 %s · запас маржи %s MDL',
            $niche['prices']['p25'] ?? '—',
            $niche['prices']['median'] ?? '—',
            $niche['prices']['p75'] ?? '—',
            $niche['prices']['margin_potential'] ?? '—'
        ));
        $this->line(sprintf(
            'Продавцы: %d аккаунтов · перекупов %s%% · магазинов %s%% · залежалось %d',
            $niche['sellers']['accounts'],
            $niche['sellers']['reseller_share_percent'] ?? 0,
            $niche['sellers']['shop_share_percent'] ?? 0,
            $niche['speed']['stale_listings']
        ));

        return self::SUCCESS;
    }
}
