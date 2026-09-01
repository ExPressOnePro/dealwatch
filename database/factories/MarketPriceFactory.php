<?php

namespace Database\Factories;

use App\Models\MarketPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketPrice>
 */
class MarketPriceFactory extends Factory
{
    protected $model = MarketPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brand' => 'Apple',
            'model' => 'iPhone 13',
            'storage_gb' => 128,
            'buy_min' => 8000,
            'buy_max' => 9400,
            'sell_low' => 11000,
            'sell_high' => 12000,
            'liquidity' => 8,
            'is_active' => true,
        ];
    }
}
