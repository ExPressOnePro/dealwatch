<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeExpense extends Model
{
    protected $fillable = ['trade_id', 'label', 'amount'];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
