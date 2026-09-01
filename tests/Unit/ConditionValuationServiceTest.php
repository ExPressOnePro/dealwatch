<?php

namespace Tests\Unit;

use App\Models\Listing;
use App\Models\MarketPrice;
use App\Services\ConditionValuationService;
use Tests\TestCase;

class ConditionValuationServiceTest extends TestCase
{
    private ConditionValuationService $valuation;

    private MarketPrice $market;

    protected function setUp(): void
    {
        parent::setUp();

        $this->valuation = new ConditionValuationService;
        // mid = 11 500
        $this->market = new MarketPrice([
            'brand' => 'Apple',
            'model' => 'iPhone 13',
            'storage_gb' => 128,
            'buy_min' => 8000,
            'buy_max' => 9400,
            'sell_low' => 11000,
            'sell_high' => 12000,
            'liquidity' => 8,
        ]);
    }

    private function listing(array $attributes = []): Listing
    {
        return new Listing(array_merge([
            'title' => 'iPhone 13 128GB',
            'description' => 'Состояние отличное, батарея 92%, Face ID работает, комплект полный, чеки на месте.',
            'price_mdl' => 8000,
            'seller_type' => 'private',
            'battery_health' => 92,
        ], $attributes));
    }

    /**
     * @param  list<string>  $flags
     * @param  list<string>  $known
     */
    private function analysis(array $flags = [], array $known = []): array
    {
        return ['flags' => $flags, 'report' => ['known' => $known]];
    }

    public function test_clean_unit_keeps_market_price(): void
    {
        $result = $this->valuation->value($this->listing(), $this->market, $this->analysis());

        $this->assertSame(100, $result['condition_score']);
        $this->assertSame(0.0, $result['condition_haircut_percent']);
        $this->assertSame(11500, $result['market_mid_clean']);
        // торг покупателя −5 % от честной цены
        $this->assertSame(10925, $result['expected_sale']);
        $this->assertLessThan($result['expected_sale'], $result['quick_sale']);
    }

    public function test_missing_face_id_drops_the_valuation(): void
    {
        $clean = $this->valuation->value($this->listing(), $this->market, $this->analysis());
        $broken = $this->valuation->value($this->listing(), $this->market, $this->analysis(['no_face_id']));

        $this->assertLessThan($clean['expected_sale'], $broken['expected_sale']);
        $this->assertLessThan($clean['condition_score'], $broken['condition_score']);
        $this->assertSame('no_face_id', $broken['penalties'][0]['code']);
    }

    public function test_locked_device_is_capped_by_the_haircut_ceiling(): void
    {
        $result = $this->valuation->value(
            $this->listing(),
            $this->market,
            $this->analysis(['parts_or_lock', 'no_face_id', 'wireless_broken', 'replaced_parts'], ['экран', 'камера'])
        );

        $this->assertSame(55.0, $result['condition_haircut_percent']);
        $this->assertSame('тяжёлое состояние — рынок другой', $result['condition_label']);
    }

    public function test_max_buy_price_follows_config(): void
    {
        config()->set('dealwatch.economics.prep_cost', 300);
        config()->set('dealwatch.economics.risk_reserve', 300);
        config()->set('dealwatch.economics.min_profit', 1500);

        $result = $this->valuation->value($this->listing(), $this->market, $this->analysis());
        $this->assertSame($result['expected_sale'] - 2100, $result['max_buy_for_profit']);

        config()->set('dealwatch.economics.min_profit', 2500);
        $stricter = $this->valuation->value($this->listing(), $this->market, $this->analysis());

        $this->assertSame($result['max_buy_for_profit'] - 1000, $stricter['max_buy_for_profit']);
    }

    public function test_thin_description_lowers_confidence(): void
    {
        $rich = $this->valuation->value($this->listing(), $this->market, $this->analysis(['face_id_ok']));
        $thin = $this->valuation->value(
            $this->listing(['description' => 'iPhone', 'battery_health' => null]),
            $this->market,
            $this->analysis(['incomplete'])
        );

        $this->assertSame('высокая', $rich['valuation_confidence']);
        $this->assertSame('низкая', $thin['valuation_confidence']);
    }
}
