<?php

namespace App\Console\Commands;

use App\Services\CurrencyRateService;
use Illuminate\Console\Command;

class RefreshCurrencyRates extends Command
{
    protected $signature = 'currency:refresh';

    protected $description = 'Refresh EUR/USD to MDL exchange rates';

    public function handle(CurrencyRateService $rates): int
    {
        $data = $rates->refresh();
        $this->table(['Currency', 'MDL'], [
            ['EUR', $data['EUR']],
            ['USD', $data['USD']],
            ['MDL', 1],
        ]);

        return self::SUCCESS;
    }
}
