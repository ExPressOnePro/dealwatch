<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('currency:refresh')->hourly();
Schedule::command('deals:collect-999')->everyThreeMinutes()->withoutOverlapping();
Schedule::command('listings:check-gone --limit=100')->everySixHours()->withoutOverlapping();
// Перепись каталога раз в сутки: по ней считается скорость продаж в нишах.
Schedule::command('niche:scan')->dailyAt('05:00')->withoutOverlapping();
