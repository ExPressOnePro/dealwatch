<?php

namespace Tests\Feature\Deals;

use App\Models\Listing;
use App\Models\MarketPrice;
use App\Services\MarketPriceRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketPriceRebuilderTest extends TestCase
{
    use RefreshDatabase;

    private function privateListings(array $prices): void
    {
        foreach ($prices as $price) {
            Listing::factory()->create(['price_mdl' => $price, 'price_original' => $price]);
        }
    }

    public function test_ranges_come_from_private_listing_percentiles(): void
    {
        $market = MarketPrice::factory()->create();
        $this->privateListings([10000, 10500, 11000, 11500, 12000, 12500]);
        // шум, который не должен попасть в основание
        Listing::factory()->shop()->create(['price_mdl' => 20000]);
        Listing::factory()->reseller()->create(['price_mdl' => 4000]);
        Listing::factory()->wantBuy()->create(['price_mdl' => 3000]);

        $result = app(MarketPriceRebuilder::class)->rebuild();

        $market->refresh();
        $this->assertSame(1, $result['updated']);
        $this->assertSame(10500, $market->sell_low);
        $this->assertSame(11500, $market->sell_high);
        $this->assertSame(9020, $market->buy_max);   // 82 % от mid 11 000
        $this->assertSame(7700, $market->buy_min);   // 70 % от mid
        $this->assertStringContainsString('6 частным объявлениям', $market->rationale);
    }

    public function test_buy_ratios_come_from_config(): void
    {
        config()->set('dealwatch.market.buy_max_ratio', 0.75);
        config()->set('dealwatch.market.buy_min_ratio', 0.6);

        $market = MarketPrice::factory()->create();
        $this->privateListings([10000, 10500, 11000, 11500, 12000, 12500]);

        app(MarketPriceRebuilder::class)->rebuild();

        $market->refresh();
        $this->assertSame(8250, $market->buy_max);
        $this->assertSame(6600, $market->buy_min);
        $this->assertStringContainsString('75% от mid', $market->rationale);
    }

    public function test_model_without_enough_samples_is_skipped(): void
    {
        $market = MarketPrice::factory()->create();
        $original = $market->only(['buy_min', 'buy_max', 'sell_low', 'sell_high']);
        $this->privateListings([10000, 11000]);

        $result = app(MarketPriceRebuilder::class)->rebuild();

        $this->assertSame(1, $result['skipped']);
        $this->assertSame($original, $market->fresh()->only(['buy_min', 'buy_max', 'sell_low', 'sell_high']));
    }
}
