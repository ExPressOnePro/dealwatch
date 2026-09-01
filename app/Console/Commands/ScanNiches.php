<?php

namespace App\Console\Commands;

use App\Models\SearchProfile;
use App\Services\Niche\NicheScanner;
use Illuminate\Console\Command;

class ScanNiches extends Command
{
    protected $signature = 'niche:scan
        {--profile= : Только один источник (id)}
        {--depth= : Сколько объявлений просмотреть в каталоге}';

    protected $description = 'Перепись каталога по источникам: что ещё висит, что ушло, как двигаются цены';

    public function handle(NicheScanner $scanner): int
    {
        $profiles = SearchProfile::query()
            ->when($this->option('profile'), fn ($q) => $q->whereKey($this->option('profile')), fn ($q) => $q->active())
            ->get();

        if ($profiles->isEmpty()) {
            $this->warn('Нет источников для переписи.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($profiles as $profile) {
            $this->line("Перепись «{$profile->name}»…");
            $stats = $scanner->scan($profile, $this->option('depth') ? (int) $this->option('depth') : null);

            $rows[] = [
                $profile->name,
                $stats['seen'].' / '.$stats['total'],
                $stats['fresh'],
                $stats['updated'],
                $stats['price_changes'],
                $stats['gone'],
            ];
        }

        $this->table(['Источник', 'Просмотрено', 'Новых', 'Подтверждено', 'Смена цены', 'Ушло'], $rows);

        return self::SUCCESS;
    }
}
