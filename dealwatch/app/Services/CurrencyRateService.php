<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyRateService
{
    /**
     * Convert amount to MDL.
     */
    public function toMdl(float|int $amount, string $currency): int
    {
        $currency = strtoupper($currency);

        if ($currency === 'MDL' || $currency === '') {
            return (int) round($amount);
        }

        $rate = $this->rateToMdl($currency);

        return (int) round($amount * $rate);
    }

    public function rateToMdl(string $currency): float
    {
        $currency = strtoupper($currency);
        $rates = $this->rates();

        return (float) ($rates[$currency] ?? 1.0);
    }

    /**
     * @return array{EUR: float, USD: float, MDL: float}
     */
    public function rates(): array
    {
        return Cache::remember('currency_rates_mdl', now()->addHours(6), function () {
            return $this->fetchRates();
        });
    }

    /**
     * @return array{EUR: float, USD: float, MDL: float}
     */
    public function refresh(): array
    {
        $rates = $this->fetchRates();
        Cache::put('currency_rates_mdl', $rates, now()->addHours(6));

        return $rates;
    }

    /**
     * @return array{EUR: float, USD: float, MDL: float}
     */
    private function fetchRates(): array
    {
        $fallback = [
            'EUR' => (float) config('services.currency.eur_to_mdl', 19.30),
            'USD' => (float) config('services.currency.usd_to_mdl', 17.50),
            'MDL' => 1.0,
        ];

        try {
            // frankfurter.app — free FX API, no key
            $response = Http::timeout(10)->get('https://api.frankfurter.app/latest', [
                'from' => 'EUR',
                'to' => 'MDL,USD',
            ]);

            if ($response->successful()) {
                $eurMdl = (float) $response->json('rates.MDL');
                $eurUsd = (float) $response->json('rates.USD');
                if ($eurMdl > 0) {
                    $fallback['EUR'] = $eurMdl;
                }
                if ($eurMdl > 0 && $eurUsd > 0) {
                    $fallback['USD'] = $eurMdl / $eurUsd;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Currency rate fetch failed: '.$e->getMessage());
        }

        return $fallback;
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
