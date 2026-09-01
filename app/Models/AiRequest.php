<?php

namespace App\Models;

use Database\Factories\AiRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiRequest extends Model
{
    /** @use HasFactory<AiRequestFactory> */
    use HasFactory;

    public const STATUS_OK = 'ok';

    public const STATUS_CACHED = 'cached';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'purpose',
        'tier',
        'model',
        'status',
        'input_hash',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'duration_ms',
        'error',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'cost_usd' => 'float',
        ];
    }

    /** Оплаченные обращения: кешированные и заблокированные денег не стоят. */
    public function scopeBillable($query)
    {
        return $query->where('status', self::STATUS_OK);
    }
}
