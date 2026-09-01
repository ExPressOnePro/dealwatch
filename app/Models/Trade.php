<?php

namespace App\Models;

use Database\Factories\TradeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trade extends Model
{
    /** @use HasFactory<TradeFactory> */
    use HasFactory;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_BOUGHT = 'bought';

    public const STATUS_LISTED = 'listed';

    public const STATUS_SOLD = 'sold';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_BOUGHT,
        self::STATUS_LISTED,
        self::STATUS_SOLD,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'user_id',
        'listing_id',
        'deal_id',
        'snapshot_id',
        'title',
        'brand',
        'model',
        'storage_gb',
        'status',
        'purchase_price',
        'purchase_date',
        'sale_price',
        'sale_date',
        'sale_channel',
        'buyer_note',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'sale_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ListingSnapshot::class, 'snapshot_id');
    }

    /** @return HasMany<TradeExpense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(TradeExpense::class);
    }

    /** @param  Builder<Trade>  $query */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /** @param  Builder<Trade>  $query */
    public function scopeSold(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SOLD);
    }

    public function expensesTotal(): int
    {
        return (int) $this->expenses->sum('amount');
    }

    /** Во что телефон обошёлся: покупка плюс всё, что в него вложено. */
    public function totalCost(): ?int
    {
        if ($this->purchase_price === null) {
            return null;
        }

        return (int) $this->purchase_price + $this->expensesTotal();
    }

    public function netProfit(): ?int
    {
        $cost = $this->totalCost();

        if ($cost === null || $this->sale_price === null) {
            return null;
        }

        return (int) $this->sale_price - $cost;
    }

    public function roiPercent(): ?float
    {
        $cost = $this->totalCost();
        $profit = $this->netProfit();

        if ($cost === null || $cost <= 0 || $profit === null) {
            return null;
        }

        return round($profit / $cost * 100, 1);
    }

    /** Сколько дней телефон лежал до продажи. */
    public function holdDays(): ?int
    {
        if (! $this->purchase_date) {
            return null;
        }

        $end = $this->sale_date ?? now();

        return (int) $this->purchase_date->diffInDays($end);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_SOLD, self::STATUS_CANCELLED], true);
    }
}
