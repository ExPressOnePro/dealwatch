<?php

namespace App\Console\Commands;

use App\Models\AiRequest;
use App\Services\Ai\AiBudget;
use Illuminate\Console\Command;

class AiUsageReport extends Command
{
    protected $signature = 'ai:usage {--days=7 : За сколько дней показать расходы}';

    protected $description = 'Показать расходы на OpenAI по дням и остаток дневного лимита';

    public function handle(AiBudget $budget): int
    {
        $days = max(1, (int) $this->option('days'));

        $rows = AiRequest::query()
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->selectRaw('date(created_at) as day, status, count(*) as calls, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(cost_usd) as cost')
            ->groupBy('day', 'status')
            ->orderBy('day')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Обращений к ИИ за период не было.');
        } else {
            $this->table(
                ['Дата', 'Статус', 'Вызовов', 'Вход, токенов', 'Выход, токенов', 'Стоимость, $'],
                $rows->map(fn ($r) => [
                    $r->day,
                    $r->status,
                    $r->calls,
                    number_format((int) $r->input_tokens, 0, '.', ' '),
                    number_format((int) $r->output_tokens, 0, '.', ' '),
                    number_format((float) $r->cost, 4),
                ])->all()
            );
        }

        $today = $budget->today();
        $this->line(sprintf(
            'Сегодня: %d из %d вызовов, $%.4f из $%.2f. Остаток: %d вызовов / $%.4f.',
            $today['calls'],
            $today['calls_limit'],
            $today['cost_usd'],
            $today['cost_limit_usd'],
            $today['calls_left'],
            $today['cost_left_usd']
        ));

        return self::SUCCESS;
    }
}
