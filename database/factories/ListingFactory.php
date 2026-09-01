<?php

namespace Database\Factories;

use App\Models\Listing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $externalId = (string) $this->faker->unique()->numberBetween(10_000_000, 99_999_999);

        return [
            'platform' => '999',
            'external_id' => $externalId,
            'url' => 'https://999.md/ru/'.$externalId,
            'title' => 'iPhone 13 128GB',
            'description' => 'Состояние отличное, батарея 92%, Face ID работает, iCloud чистый.',
            'price_original' => 8000,
            'price_mdl' => 8000,
            'currency' => 'MDL',
            'seller_name' => 'seller_'.$externalId,
            'seller_phone' => '+3736900'.$this->faker->numberBetween(1000, 9999),
            'seller_type' => 'private',
            'listing_kind' => 'sell',
            'seller_listings_count' => 1,
            'is_reseller' => false,
            'location' => 'Кишинёв',
            'published_at' => now()->subMinutes(5),
            'first_seen_at' => now()->subMinutes(5),
            'last_seen_at' => now(),
            'status' => 'active',
            'brand' => 'Apple',
            'model' => 'iPhone 13',
            'model_source' => 'title',
            'storage_gb' => 128,
            'battery_health' => 92,
            'parse_confidence' => 0.9,
        ];
    }

    public function shop(): static
    {
        return $this->state(['seller_type' => 'shop']);
    }

    public function reseller(): static
    {
        return $this->state(['is_reseller' => true, 'seller_listings_count' => 7]);
    }

    public function wantBuy(): static
    {
        return $this->state([
            'listing_kind' => 'want_buy',
            'title' => 'Куплю iPhone 13 в любом состоянии',
        ]);
    }
}
