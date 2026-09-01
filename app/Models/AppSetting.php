<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value', 'is_encrypted'];

    protected function casts(): array
    {
        return ['is_encrypted' => 'boolean'];
    }
}
