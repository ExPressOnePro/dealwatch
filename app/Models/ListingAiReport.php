<?php

namespace App\Models;

use Database\Factories\ListingAiReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingAiReport extends Model
{
    /** @use HasFactory<ListingAiReportFactory> */
    use HasFactory;

    public const KIND_TEXT = 'text';

    public const KIND_VISION = 'vision';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'listing_id',
        'deal_id',
        'user_id',
        'kind',
        'status',
        'model',
        'verdict',
        'condition_score',
        'target_price_mdl',
        'summary',
        'payload',
        'images_analyzed',
        'cost_usd',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'cost_usd' => 'float',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
