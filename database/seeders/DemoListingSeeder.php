<?php

namespace Database\Seeders;

use App\Services\ListingPipeline;
use Illuminate\Database\Seeder;

class DemoListingSeeder extends Seeder
{
    public function run(): void
    {
        /** @var ListingPipeline $pipeline */
        $pipeline = app(ListingPipeline::class);

        $samples = [
            [
                'platform' => '999',
                'external_id' => 'demo-1001',
                'url' => 'https://999.md/ru/demo-1001',
                'title' => 'iPhone 14 Pro 128GB, батарея 89%, срочно',
                'description' => 'Частник, Кишинёв. Face ID работает, iCloud чистый.',
                'price_mdl' => 8700,
                'seller_type' => 'private',
                'seller_phone' => '+37369000001',
                'location' => 'Кишинёв',
                'published_at' => now()->subMinutes(3),
            ],
            [
                'platform' => '999',
                'external_id' => 'demo-1002',
                'url' => 'https://999.md/ru/demo-1002',
                'title' => 'Samsung S23 Ultra 256 GB состояние отличное',
                'description' => 'Продам срочно, полный комплект.',
                'price_mdl' => 7900,
                'seller_type' => 'private',
                'seller_phone' => '+37369000002',
                'location' => 'Кишинёв',
                'published_at' => now()->subMinutes(12),
            ],
            [
                'platform' => '999',
                'external_id' => 'demo-1003',
                'url' => 'https://999.md/ru/demo-1003',
                'title' => 'iPhone 13 Pro Max 256gb 84%',
                'description' => 'Норм состояние, звоните.',
                'price_mdl' => 6800,
                'seller_type' => 'private',
                'seller_phone' => '+37369000003',
                'location' => 'Бельцы',
                'published_at' => now()->subMinutes(25),
            ],
            [
                'platform' => '999',
                'external_id' => 'demo-1004',
                'url' => 'https://999.md/ru/demo-1004',
                'title' => 'iPhone 13 Pro 128',
                'description' => 'Магазин, гарантия 12 месяцев',
                'price_mdl' => 11999,
                'seller_type' => 'shop',
                'location' => 'Кишинёв',
                'published_at' => now()->subHours(5),
            ],
            [
                'platform' => '999',
                'external_id' => 'demo-1005',
                'url' => 'https://999.md/ru/demo-1005',
                'title' => 'iPhone 13 Pro 128GB идеал',
                'description' => 'Частник',
                'price_mdl' => 8900,
                'seller_type' => 'private',
                'location' => 'Кишинёв',
                'published_at' => now()->subHours(2),
            ],
        ];

        foreach ($samples as $sample) {
            // enrich: false — демо-объявлений на 999.md не существует, ходить за ними некуда.
            $pipeline->ingest($sample, notify: false, enrich: false);
        }
    }
}
