<?php

namespace App\Models;

use Database\Factories\AiBatchAnalysisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiBatchAnalysis extends Model
{
    /** @use HasFactory<AiBatchAnalysisFactory> */
    use HasFactory;

    public const SOURCE_FILTER = 'filter';

    public const SOURCE_QUERY = 'query';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'source',
        'query',
        'filters',
        'status',
        'listing_count',
        'summary',
        'recommendation',
        'items',
        'model_screen',
        'model_deep',
        'cost_usd',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'items' => 'array',
            'cost_usd' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
