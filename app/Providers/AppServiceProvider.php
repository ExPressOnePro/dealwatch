<?php

namespace App\Providers;

use App\Settings\SettingsRepository;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingsRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Настройки из админки перекрывают .env — до того, как их прочитает
        // кто-либо ещё (роуты, джобы, клиент OpenAI).
        $this->app->make(SettingsRepository::class)->apply();

        // queue:work бутстрапится один раз и живёт часами: без этого ключ,
        // добавленный в админке, не дойдёт до фоновых задач до перезапуска.
        Queue::before(fn () => $this->app->make(SettingsRepository::class)->apply());
    }
}
