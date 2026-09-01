<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\MarketPrice;
use Illuminate\Support\Collection;

class MarketPriceEngine
{
    public function findForListing(Listing $listing): ?MarketPrice
    {
        if (! $listing->brand || ! $listing->model) {
            return null;
        }

        $query = MarketPrice::query()
            ->where('is_active', true)
            ->where('brand', $listing->brand)
            ->where('model', $listing->model);

        if ($listing->storage_gb) {
            $exact = (clone $query)->where('storage_gb', $listing->storage_gb)->first();
            if ($exact) {
                return $exact;
            }
        }

        return $query->whereNull('storage_gb')->first()
            ?? $query->orderBy('storage_gb')->first();
    }

    public function allActive(): Collection
    {
        return MarketPrice::query()->where('is_active', true)->get();
    }
}
