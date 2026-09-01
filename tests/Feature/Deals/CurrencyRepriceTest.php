<?php

namespace Tests\Feature\Deals;

use App\Models\Listing;
use App\Models\MarketPrice;
use App\Services\DealScoreEngine;
use App\Services\ListingRepricer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CurrencyRepriceTest extends TestCase
{
    use RefreshDatabase;

    private function bnmXml(float $eur, float $usd): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><ValCurs Date="'.now()->format('d.m.Y').'" name="Official exchange rate">'
            .'<Valute ID="47"><NumCode>978</NumCode><CharCode>EUR</CharCode><Nominal>1</Nominal><Name>Euro</Name><Value>'.$eur.'</Value></Valute>'
            .'<Valute ID="44"><NumCode>840</NumCode><CharCode>USD</CharCode><Nominal>1</Nominal><Name>US Dollar</Name><Value>'.$usd.'</Value></Valute>'
            .'</ValCurs>';
    }

    public function test_currency_listings_follow_the_fresh_rate(): void
    {
        Cache::put('currency_rates_mdl', ['EUR' => 20.0, 'USD' => 18.0, 'MDL' => 1.0], now()->addHour());

        $listing = Listing::factory()->create([
            'currency' => 'EUR',
            'price_original' => 400,
            'price_mdl' => 8000,
        ]);
        $mdlListing = Listing::factory()->create([
            'currency' => 'MDL',
            'price_original' => 8000,
            'price_mdl' => 8000,
        ]);

        Cache::put('currency_rates_mdl', ['EUR' => 19.0, 'USD' => 18.0, 'MDL' => 1.0], now()->addHour());

        $updated = app(ListingRepricer::class)->repriceActive();

        $this->assertSame(1, $updated);
        $this->assertSame(7600, $listing->fresh()->price_mdl);
        $this->assertSame(8000, $mdlListing->fresh()->price_mdl);
    }

    public function test_command_rescores_deals_after_repricing(): void
    {
        MarketPrice::factory()->create();
        Http::fake(['www.bnm.md/*' => Http::response($this->bnmXml(15.0, 17.0))]);
        Cache::put('currency_rates_mdl', ['EUR' => 20.0, 'USD' => 18.0, 'MDL' => 1.0], now()->addHour());

        $listing = Listing::factory()->create([
            'currency' => 'EUR',
            'price_original' => 400,
            'price_mdl' => 8000,
        ]);

        app(DealScoreEngine::class)->evaluate($listing);
        $before = $listing->deal->fresh()->potential_profit;

        $this->artisan('currency:refresh')->assertSuccessful();

        $after = $listing->fresh()->deal->potential_profit;

        $this->assertSame(6000, $listing->fresh()->price_mdl);
        $this->assertGreaterThan($before, $after);
    }
}
