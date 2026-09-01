<?php

namespace Database\Seeders;

use App\Models\MarketPrice;
use Illuminate\Database\Seeder;

class NewRetailPriceSeeder extends Seeder
{
    /**
     * Official-ish Moldova shop RRP (new + warranty) for comparison vs private used mid.
     * Older gens often discontinued — null price + note.
     */
    public function run(): void
    {
        $shop = 'Ориентир витрины MD (Darwin / Enter / iStyle) · новый + гарантия';

        // brand, model, storage, price MDL|null, warranty months|null, note
        $rows = [
            ['Apple', 'iPhone 12', 128, null, null, 'Снят с витрины как новый — только б/у / восстановленный'],
            ['Apple', 'iPhone 12 Pro', 128, null, null, 'Снят с витрины как новый'],
            ['Apple', 'iPhone 12 Pro Max', 128, null, null, 'Снят с витрины как новый'],
            ['Apple', 'iPhone 12 Pro Max', 256, null, null, 'Снят с витрины как новый'],
            ['Apple', 'iPhone 12 Pro Max', 512, null, null, 'Снят с витрины как новый'],

            ['Apple', 'iPhone 13', 128, null, null, 'Новый почти не держится на витрине — смотри б/у частников'],
            ['Apple', 'iPhone 13 Pro', 128, null, null, 'Снят с основной витрины как новый'],
            ['Apple', 'iPhone 13 Pro Max', 128, null, null, 'Снят с витрины как новый'],
            ['Apple', 'iPhone 13 Pro Max', 256, null, null, 'Снят с витрины как новый'],

            ['Apple', 'iPhone 14', 128, 16999, 12, 'Новый с гарантией магазина (если ещё в наличии)'],
            ['Apple', 'iPhone 14 Pro', 128, null, null, 'Снят с витрины как новый — ориентир только б/у'],
            ['Apple', 'iPhone 14 Pro', 256, null, null, 'Снят с витрины как новый'],
            ['Apple', 'iPhone 14 Pro Max', 128, null, null, 'Снят с витрины как новый'],
            ['Apple', 'iPhone 14 Pro Max', 256, null, null, 'Снят с витрины как новый'],

            ['Apple', 'iPhone 15', 128, 18999, 12, 'Новый, гарантия магазина ~12 мес.'],
            ['Apple', 'iPhone 15 Pro', 128, 24999, 12, 'Новый Pro, гарантия магазина'],
            ['Apple', 'iPhone 15 Pro Max', 256, 28999, 12, 'Новый Pro Max, гарантия магазина'],

            ['Apple', 'iPhone 16', 128, 21999, 12, 'Новый, официальная/магазинная гарантия'],
            ['Apple', 'iPhone 16 Pro', 128, 27999, 12, 'Новый Pro + гарантия'],
            ['Apple', 'iPhone 16 Pro Max', 256, 32999, 12, 'Новый Pro Max + гарантия'],

            ['Apple', 'iPhone 17 Pro', 256, 36999, 12, 'Новый на витрине + гарантия (цены плывут)'],
            ['Apple', 'iPhone 17 Pro Max', 256, 41999, 12, 'Новый Max + гарантия'],
            ['Apple', 'iPhone 17 Pro Max', 512, 46999, 12, 'Новый 512 GB + гарантия'],

            ['Samsung', 'S22 Ultra', 256, null, null, 'Снят с витрины как новый'],
            ['Samsung', 'S23 Ultra', 256, null, null, 'Редко как новый — обычно б/у'],
            ['Samsung', 'S24', 128, 14999, 24, 'Новый Samsung, часто 24 мес. гарантии'],
            ['Samsung', 'S24 Ultra', 256, 24999, 24, 'Новый Ultra + гарантия магазина'],
            ['Samsung', 'S24 Ultra', 512, 27999, 24, 'Новый Ultra 512 + гарантия'],
            ['Samsung', 'Galaxy A15', null, 3999, 24, 'Новый бюджетник + гарантия'],

            ['Google', 'Pixel 8 Pro', null, 17999, 12, 'Новый Pixel (наличие нестабильное)'],
            ['Xiaomi', '13 Pro', null, null, null, 'Снят / редкий как новый'],
            ['Xiaomi', 'Poco F7 Pro', null, 12999, 12, 'Новый Poco + гарантия (ориентир)'],
            ['Xiaomi', 'Redmi Note 13 Pro+', null, 8999, 12, 'Новый Redmi + гарантия'],
            ['Xiaomi', 'Redmi Note 14', 256, 6999, 12, 'Новый Note 14 + гарантия'],
        ];

        foreach ($rows as [$brand, $model, $storage, $price, $warranty, $note]) {
            MarketPrice::query()
                ->where('brand', $brand)
                ->where('model', $model)
                ->when(
                    $storage === null,
                    fn ($q) => $q->whereNull('storage_gb'),
                    fn ($q) => $q->where('storage_gb', $storage)
                )
                ->update([
                    'new_price_mdl' => $price,
                    'new_warranty_months' => $warranty,
                    'new_shop' => $shop,
                    'new_note' => $note,
                ]);
        }
    }
}
