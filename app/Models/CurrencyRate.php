<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyRate extends Model
{
    protected $fillable = ['code', 'rate', 'source', 'rate_date'];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'rate_date' => 'datetime',
        ];
    }

    /** Последний известный курс валюты. */
    public static function latestFor(string $code): ?self
    {
        return static::query()
            ->where('code', strtoupper($code))
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->first();
    }
}
