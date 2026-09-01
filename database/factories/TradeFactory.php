<?php

namespace Database\Factories;

use App\Models\Trade;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trade>
 */
class TradeFactory extends Factory
{
    protected $model = Trade::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => 'iPhone 13 128 GB',
            'brand' => 'Apple',
            'model' => 'iPhone 13',
            'storage_gb' => 128,
            'status' => Trade::STATUS_SOLD,
            'purchase_price' => 8000,
            'purchase_date' => now()->subDays(10)->toDateString(),
            'sale_price' => 11000,
            'sale_date' => now()->subDays(3)->toDateString(),
            'sale_channel' => '999.md',
        ];
    }

    public function open(): static
    {
        return $this->state([
            'status' => Trade::STATUS_BOUGHT,
            'sale_price' => null,
            'sale_date' => null,
            'sale_channel' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => Trade::STATUS_CANCELLED,
            'sale_price' => null,
            'sale_date' => null,
        ]);
    }
}
