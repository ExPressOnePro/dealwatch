<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IgnoredListing extends Model
{
    protected $fillable = [
        'platform',
        'external_id',
        'note',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }

    public static function isIgnored(string $platform, string $externalId): bool
    {
        if ($externalId === '') {
            return false;
        }

        return static::query()
            ->where('platform', $platform)
            ->where('external_id', $externalId)
            ->exists();
    }

    public static function remember(string $platform, string $externalId, ?string $note = null): self
    {
        return static::updateOrCreate(
            ['platform' => $platform, 'external_id' => $externalId],
            ['note' => $note, 'dismissed_at' => now()]
        );
    }

    public static function forget(string $platform, string $externalId): void
    {
        static::query()
            ->where('platform', $platform)
            ->where('external_id', $externalId)
            ->delete();
    }
}
