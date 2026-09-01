<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'market_price' => 11000,
            'discount_percent' => 20.0,
            'potential_profit' => 2000,
            'deal_score' => 85,
            'verdict' => 'buy',
            'freshness' => 'fresh',
            'liquidity' => 8,
            'user_status' => Deal::STATUS_NEW,
        ];
    }
}
