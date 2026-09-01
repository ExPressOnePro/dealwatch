<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\SearchProfile;
use App\Services\ListingScorer;
use App\Services\ListingSubjectClassifier;
use App\Services\ProfileMarketStats;
use Illuminate\Console\Command;

class ClassifyListingSubjects extends Command
{
    protected $signature = 'listings:classify-subjects {--recalculate : Пересчитать сделки после разметки}';

    protected $description = 'Разметить объявления: сам товар, запчасти, аксессуар, услуга или реплика';

    public function handle(ListingSubjectClassifier $classifier, ListingScorer $scorer, ProfileMarketStats $stats): int
    {
        $counts = [];
        $changed = 0;

        // Сначала размечаем всё, и только потом считаем: иначе рынок источника
        // будет посчитан по ещё не отфильтрованным запчастям.
        Listing::query()->chunkById(300, function ($listings) use ($classifier, &$counts, &$changed) {
            foreach ($listings as $listing) {
                $before = [$listing->subject, (bool) $listing->is_replica];
                $result = $classifier->apply($listing);

                $key = $result['subject'].($result['replica'] ? ' + реплика' : '');
                $counts[$key] = ($counts[$key] ?? 0) + 1;

                if ($before !== [$result['subject'], $result['replica']]) {
                    $changed++;
                }
            }
        });

        if ($this->option('recalculate')) {
            SearchProfile::all()->each(fn (SearchProfile $profile) => $stats->forget($profile));

            $rescored = 0;
            Listing::query()->where('status', 'active')->chunkById(300, function ($listings) use ($scorer, &$rescored) {
                foreach ($listings as $listing) {
                    $scorer->score($listing);
                    $rescored++;
                }
            });

            $this->line("Пересчитано сделок: {$rescored}");
        }

        ksort($counts);
        $this->table(
            ['Предмет объявления', 'Объявлений'],
            collect($counts)->map(fn ($count, $key) => [$key, $count])->values()->all()
        );
        $this->info("Изменено: {$changed}");

        return self::SUCCESS;
    }
}
