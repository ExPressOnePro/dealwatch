<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'dealer@dealwatch.test'],
            [
                'name' => 'Dealer',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $this->call([
            MarketPriceSeeder::class,
            NewRetailPriceSeeder::class,
            DemoListingSeeder::class,
        ]);
    }
}
