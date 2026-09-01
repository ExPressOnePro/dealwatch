<?php

namespace App\Services;

use App\Models\CurrencyRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Курс валют к молдавскому лею.
 *
 * Источник — официальный курс Национального банка Молдовы: по нему живут
 * ценники на 999.md. Полученные значения ложатся в базу, поэтому при недоступном
 * банке используется последний реальный курс, а не зашитая в .env цифра.
 */
class CurrencyRateService
{
    private const CACHE_KEY = 'currency_rates_mdl';

    private const BNM_URL = 'https://www.bnm.md/en/official_exchange_rates';

    /** Курс старше этого срока считаем подозрительным. */
    private const STALE_HOURS = 48;

    public function toMdl(float|int $amount, string $currency): int
    {
        $currency = strtoupper($currency);

        if ($currency === 'MDL' || $currency === '') {
            return (int) round($amount);
        }

        return (int) round($amount * $this->rateToMdl($currency));
    }

    public function rateToMdl(string $currency): float
    {
        $currency = strtoupper($currency);

        return (float) ($this->rates()[$currency] ?? 1.0);
    }

    /**
     * @return array<string, float>
     */
    public function rates(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(6), fn () => $this->resolve());
    }

    /**
     * @return array<string, float>
     */
    public function refresh(): array
    {
        $fetched = $this->fetchFromBnm();

        if ($fetched !== []) {
            $this->store($fetched);
        }

        $rates = $this->resolve();
        Cache::put(self::CACHE_KEY, $rates, now()->addHours(6));

        return $rates;
    }

    /**
     * Откуда взят действующий курс и насколько он свежий — это нужно показывать,
     * а не прятать: на устаревшем курсе вся экономика сделки уезжает.
     *
     * @return array{source: string, rate_date: ?string, age_hours: ?int, stale: bool, rates: array<string, float>}
     */
    public function status(): array
    {
        $latest = CurrencyRate::latestFor('EUR');
        $ageHours = $latest?->rate_date ? (int) $latest->rate_date->diffInHours(now()) : null;

        return [
            'source' => $latest?->source ?? 'fallback',
            'rate_date' => optional($latest?->rate_date)->toDateString(),
            'age_hours' => $ageHours,
            'stale' => $latest === null || ($ageHours !== null && $ageHours > self::STALE_HOURS),
            'rates' => $this->rates(),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function resolve(): array
    {
        $rates = ['MDL' => 1.0];

        foreach (['EUR', 'USD'] as $code) {
            $stored = CurrencyRate::latestFor($code);

            $rates[$code] = $stored?->rate
                ?? (float) config('services.currency.'.strtolower($code).'_to_mdl', $code === 'EUR' ? 19.30 : 17.50);
        }

        return $rates;
    }

    /**
     * Официальный курс НБМ приходит XML-документом на конкретную дату.
     *
     * @return array<string, array{rate: float, date: string}>
     */
    private function fetchFromBnm(): array
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get(self::BNM_URL, [
                    'get_xml' => 1,
                    'date' => now('Europe/Chisinau')->format('d.m.Y'),
                ]);
        } catch (\Throwable $e) {
            Log::warning('BNM rates request failed: '.$e->getMessage());

            return [];
        }

        if (! $response->successful()) {
            Log::warning('BNM rates request failed', ['status' => $response->status()]);

            return [];
        }

        $xml = $response->body();
        $date = preg_match('/<ValCurs[^>]*Date="(\d{2}\.\d{2}\.\d{4})"/', $xml, $m)
            ? Carbon::createFromFormat('d.m.Y', $m[1], 'Europe/Chisinau')->startOfDay()
            : now('Europe/Chisinau')->startOfDay();

        $rates = [];

        foreach (['EUR', 'USD'] as $code) {
            if (! preg_match(
                '#<CharCode>'.$code.'</CharCode>.*?<Nominal>(\d+)</Nominal>.*?<Value>([\d.]+)</Value>#s',
                $xml,
                $found
            )) {
                continue;
            }

            $nominal = max(1, (int) $found[1]);
            $value = (float) $found[2];

            if ($value > 0) {
                // Курс задаётся за номинал (обычно 1, но не всегда).
                $rates[$code] = ['rate' => round($value / $nominal, 4), 'date' => $date->toDateString()];
            }
        }

        return $rates;
    }

    /**
     * @param  array<string, array{rate: float, date: string}>  $fetched
     */
    private function store(array $fetched): void
    {
        foreach ($fetched as $code => $data) {
            CurrencyRate::updateOrCreate(
                ['code' => $code, 'rate_date' => $data['date']],
                ['rate' => $data['rate'], 'source' => 'bnm']
            );
        }
    }

    public function normalizeUnit(?string $unit): string
    {
        $unit = strtoupper((string) $unit);

        return match (true) {
            str_contains($unit, 'EUR') => 'EUR',
            str_contains($unit, 'USD') => 'USD',
            str_contains($unit, 'MDL') => 'MDL',
            default => 'MDL',
        };
    }
}
