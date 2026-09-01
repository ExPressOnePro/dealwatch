<?php

namespace App\Models;

use Database\Factories\MarketPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketPrice extends Model
{
    /** @use HasFactory<MarketPriceFactory> */
    use HasFactory;

    protected $fillable = [
        'brand',
        'model',
        'storage_gb',
        'buy_min',
        'buy_max',
        'sell_low',
        'sell_high',
        'new_price_mdl',
        'new_warranty_months',
        'new_shop',
        'new_note',
        'liquidity',
        'rationale',
        'basis',
        'source',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'basis' => 'array',
        ];
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function marketMid(): int
    {
        return (int) round(($this->sell_low + $this->sell_high) / 2);
    }
}
