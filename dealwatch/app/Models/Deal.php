<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deal extends Model
{
    protected $fillable = [
        'listing_id',
        'market_price_id',
        'market_price',
        'discount_percent',
        'potential_profit',
        'deal_score',
        'verdict',
        'freshness',
        'liquidity',
        'notified',
        'notified_at',
        'user_status',
        'is_favorite',
        'purchase_price',
        'sale_price',
        'cancel_note',
        'completed_at',
        'score_breakdown',
    ];

    protected function casts(): array
    {
        return [
            'notified' => 'boolean',
            'is_favorite' => 'boolean',
            'notified_at' => 'datetime',
            'completed_at' => 'datetime',
            'discount_percent' => 'float',
            'score_breakdown' => 'array',
        ];
    }

    public function netProfit(): ?int
    {
        if ($this->purchase_price === null || $this->sale_price === null) {
            return null;
        }

        return $this->sale_price - $this->purchase_price;
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function marketPriceRef(): BelongsTo
    {
        return $this->belongsTo(MarketPrice::class, 'market_price_id');
    }
}
