<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTestEnvironment();

        // Ни один тест не должен ходить в 999.md, Telegram или за курсом валют.
        Http::preventStrayRequests();
    }

    /**
     * Предохранитель: RefreshDatabase чистит ту базу, которую видит приложение.
     * Если тестовое окружение не применилось (например, из-за закешированного
     * конфига в bootstrap/cache), тесты снесут рабочие данные — лучше упасть сразу.
     */
    private function assertTestEnvironment(): void
    {
        $database = config('database.connections.'.config('database.default').'.database');

        if (config('app.env') === 'testing' && ($database === ':memory:' || str_contains((string) $database, 'testing'))) {
            return;
        }

        $this->fail(
            'Тесты запущены не в тестовом окружении (APP_ENV='.config('app.env').', база: '.$database.'). '
            .'Скорее всего, мешает закешированный конфиг: выполните `php artisan config:clear` '
            .'или удалите bootstrap/cache/config.php.'
        );
    }
}
