<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Listing extends Model
{
    protected $fillable = [
        'platform',
        'external_id',
        'url',
        'title',
        'site_model',
        'description',
        'price',
        'price_original',
        'price_mdl',
        'currency',
        'seller_name',
        'seller_phone',
        'seller_type',
        'listing_kind',
        'seller_key',
        'seller_listings_count',
        'is_reseller',
        'location',
        'images',
        'published_at',
        'first_seen_at',
        'last_seen_at',
        'status',
        'brand',
        'model',
        'model_source',
        'storage_gb',
        'battery_health',
        'condition',
        'parse_confidence',
        'analyst_comment',
        'analyst_flags',
        'is_bait',
        'analyst_risk',
        'analyst_report',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'raw_data' => 'array',
            'analyst_flags' => 'array',
            'analyst_report' => 'array',
            'is_bait' => 'boolean',
            'is_reseller' => 'boolean',
            'listing_kind' => 'string',
            'published_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'parse_confidence' => 'float',
        ];
    }

    public function deal(): HasOne
    {
        return $this->hasOne(Deal::class);
    }

    /** Sell ads that feed market foundations (exclude «куплю»). */
    public function scopeMarketSell($query)
    {
        return $query->where(function ($q) {
            $q->where('listing_kind', 'sell')->orWhereNull('listing_kind');
        });
    }

    public function scopeWantBuy($query)
    {
        return $query->where('listing_kind', 'want_buy');
    }

    public function scopePrivateSeller($query)
    {
        return $query->where('seller_type', 'private');
    }

    public function scopeShopSeller($query)
    {
        return $query->where('seller_type', 'shop');
    }

    public function isWantBuy(): bool
    {
        return $this->listing_kind === 'want_buy';
    }

    public function isShop(): bool
    {
        return $this->seller_type === 'shop';
    }

    public function displayName(): string
    {
        $parts = array_filter([
            $this->brand,
            $this->model,
            $this->storage_gb ? $this->storage_gb.' GB' : null,
        ]);

        return $parts ? implode(' ', $parts) : $this->title;
    }

    public function priceForScoring(): ?int
    {
        return $this->price_mdl ?? $this->price;
    }

    public function formattedOriginalPrice(): string
    {
        $amount = $this->price_original ?? $this->price;
        if ($amount === null) {
            return '—';
        }

        return number_format((int) $amount, 0, '.', ' ').' '.($this->currency ?: 'MDL');
    }
}
