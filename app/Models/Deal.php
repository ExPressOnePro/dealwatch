<?php

namespace App\Models;

use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_OPENED = 'opened';

    public const STATUS_CALLED = 'called';

    public const STATUS_BOUGHT = 'bought';

    public const STATUS_SOLD = 'sold';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Все допустимые значения user_status. */
    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_OPENED,
        self::STATUS_CALLED,
        self::STATUS_BOUGHT,
        self::STATUS_SOLD,
        self::STATUS_DISMISSED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** Сделки в работе: они и составляют ленту. */
    public const ACTIVE_STATUSES = [
        self::STATUS_NEW,
        self::STATUS_OPENED,
        self::STATUS_CALLED,
    ];

    /** Закрытые сделки: живут в избранном, из ленты уходят. */
    public const CLOSED_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

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

    public function scopeActive($query)
    {
        return $query->whereIn('user_status', self::ACTIVE_STATUSES);
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
