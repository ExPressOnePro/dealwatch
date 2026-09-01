<?php

namespace App\Models;

use Database\Factories\SearchProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchProfile extends Model
{
    /** @use HasFactory<SearchProfileFactory> */
    use HasFactory;

    /** Телефоны: справочник моделей и рыночные диапазоны. */
    public const SCORING_PHONES = 'phones';

    /** Любой другой товар: рынок считается по объявлениям самого профиля. */
    public const SCORING_GENERIC = 'generic';

    public const SCORINGS = [self::SCORING_PHONES, self::SCORING_GENERIC];

    protected $fillable = [
        'name',
        'platform',
        'category_id',
        'subcategory_id',
        'category_label',
        'query',
        'exclude_keywords',
        'price_min',
        'price_max',
        'per_run',
        'scoring',
        'notify',
        'is_active',
        'last_run_at',
        'last_found',
        'last_scan_at',
        'last_scanned',
        'scan_depth',
    ];

    protected function casts(): array
    {
        return [
            'exclude_keywords' => 'array',
            'notify' => 'boolean',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'last_scan_at' => 'datetime',
        ];
    }

    /** @return HasMany<Listing, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isPhones(): bool
    {
        return $this->scoring === self::SCORING_PHONES;
    }

    /** Что показать в интерфейсе: категория и ключевые слова. */
    public function describe(): string
    {
        $parts = array_filter([
            $this->category_label,
            $this->query ? '«'.$this->query.'»' : null,
            $this->price_min || $this->price_max
                ? trim(($this->price_min ? 'от '.$this->price_min : '').' '.($this->price_max ? 'до '.$this->price_max : '')).' MDL'
                : null,
        ]);

        return $parts ? implode(' · ', $parts) : 'весь каталог';
    }

    /** Слово из стоп-списка в заголовке или описании — объявление не берём. */
    public function isExcluded(string $title, ?string $description = null): bool
    {
        $words = array_filter(array_map('trim', (array) ($this->exclude_keywords ?? [])));

        if ($words === []) {
            return false;
        }

        $haystack = mb_strtolower($title.' '.($description ?? ''));

        foreach ($words as $word) {
            if ($word !== '' && str_contains($haystack, mb_strtolower($word))) {
                return true;
            }
        }

        return false;
    }
}
