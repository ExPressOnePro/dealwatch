<?php

namespace Database\Factories;

use App\Models\SearchProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchProfile>
 */
class SearchProfileFactory extends Factory
{
    protected $model = SearchProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Телефоны 999.md',
            'platform' => '999',
            'subcategory_id' => 40,
            'category_label' => 'Telefoane mobile',
            'scoring' => SearchProfile::SCORING_PHONES,
            'per_run' => 40,
            'notify' => true,
            'is_active' => true,
        ];
    }

    /** Источник без справочника моделей: рынок считается по самим объявлениям. */
    public function generic(): static
    {
        return $this->state([
            'name' => 'Велосипеды',
            'subcategory_id' => null,
            'category_id' => 658,
            'category_label' => 'Transport',
            'query' => 'велосипед',
            'scoring' => SearchProfile::SCORING_GENERIC,
        ]);
    }
}
