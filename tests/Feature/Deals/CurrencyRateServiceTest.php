<?php

namespace Tests\Feature\Deals;

use App\Models\CurrencyRate;
use App\Services\CurrencyRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CurrencyRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private function bnmXml(float $eur = 20.1195, float $usd = 17.2729, int $nominal = 1, ?string $date = null): string
    {
        $date ??= now()->format('d.m.Y');

        return '<?xml version="1.0" encoding="UTF-8"?><ValCurs Date="'.$date.'" name="Official exchange rate">'
            .'<Valute ID="47"><NumCode>978</NumCode><CharCode>EUR</CharCode><Nominal>'.$nominal.'</Nominal><Name>Euro</Name><Value>'.$eur.'</Value></Valute>'
            .'<Valute ID="44"><NumCode>840</NumCode><CharCode>USD</CharCode><Nominal>1</Nominal><Name>US Dollar</Name><Value>'.$usd.'</Value></Valute>'
            .'</ValCurs>';
    }

    public function test_official_rate_is_fetched_and_stored(): void
    {
        Http::fake(['www.bnm.md/*' => Http::response($this->bnmXml())]);

        $rates = app(CurrencyRateService::class)->refresh();

        $this->assertSame(20.1195, $rates['EUR']);
        $this->assertSame(17.2729, $rates['USD']);

        $stored = CurrencyRate::latestFor('EUR');
        $this->assertSame('bnm', $stored->source);
        $this->assertSame(20.1195, $stored->rate);
    }

    public function test_rate_is_divided_by_nominal(): void
    {
        // Некоторые валюты банк публикует за 10 или 100 единиц.
        Http::fake(['www.bnm.md/*' => Http::response($this->bnmXml(eur: 201.195, nominal: 10))]);

        $this->assertSame(20.1195, app(CurrencyRateService::class)->refresh()['EUR']);
    }

    public function test_last_known_rate_is_used_when_the_bank_is_unreachable(): void
    {
        CurrencyRate::create(['code' => 'EUR', 'rate' => 20.5, 'source' => 'bnm', 'rate_date' => now()->subDay()]);
        Http::fake(fn () => throw new ConnectionException('timeout'));

        // Курс из базы, а не зашитая в .env цифра.
        $this->assertSame(20.5, app(CurrencyRateService::class)->refresh()['EUR']);
    }

    public function test_env_value_is_only_the_last_resort(): void
    {
        config(['services.currency.eur_to_mdl' => 19.30]);
        Http::fake(['www.bnm.md/*' => Http::response('', 500)]);

        $this->assertSame(19.30, app(CurrencyRateService::class)->refresh()['EUR']);
    }

    public function test_status_reports_source_and_staleness(): void
    {
        Http::fake();
        CurrencyRate::create(['code' => 'EUR', 'rate' => 20.0, 'source' => 'bnm', 'rate_date' => now()->subDays(5)]);
        Cache::forget('currency_rates_mdl');

        $status = app(CurrencyRateService::class)->status();

        $this->assertSame('bnm', $status['source']);
        $this->assertTrue($status['stale']);
        $this->assertGreaterThan(48, $status['age_hours']);
    }

    public function test_fresh_rate_is_not_marked_stale(): void
    {
        Http::fake(['www.bnm.md/*' => Http::response($this->bnmXml())]);
        app(CurrencyRateService::class)->refresh();

        $status = app(CurrencyRateService::class)->status();

        $this->assertFalse($status['stale']);
        $this->assertSame(now()->toDateString(), $status['rate_date']);
    }

    public function test_conversion_uses_the_official_rate(): void
    {
        Http::fake(['www.bnm.md/*' => Http::response($this->bnmXml())]);
        $service = app(CurrencyRateService::class);
        $service->refresh();

        // 490 EUR по курсу 20.1195 — именно так считается цена объявления.
        $this->assertSame(9859, $service->toMdl(490, 'EUR'));
        $this->assertSame(1000, $service->toMdl(1000, 'MDL'));
    }
}
