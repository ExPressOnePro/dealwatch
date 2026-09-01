<?php

namespace App\Models;

use Database\Factories\ListingSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingSnapshot extends Model
{
    /** @use HasFactory<ListingSnapshotFactory> */
    use HasFactory;

    public const REASON_FIRST_SEEN = 'first_seen';

    public const REASON_PRICE_CHANGE = 'price_change';

    public const REASON_ARCHIVED = 'archived';

    public const REASON_GONE = 'gone';

    protected $fillable = [
        'listing_id',
        'reason',
        'payload',
        'price_mdl',
        'image_paths',
        'html_path',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'image_paths' => 'array',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function hasMedia(): bool
    {
        return ! empty($this->image_paths) || filled($this->html_path);
    }
}
