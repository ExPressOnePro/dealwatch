<?php

namespace App\Settings;

/**
 * Описание одной настройки: как валидировать, куда положить в конфиг
 * и можно ли показывать значение в интерфейсе.
 */
readonly class SettingDefinition
{
    public function __construct(
        public string $key,
        public string $type,          // bool | int | float | string | secret
        public string $configPath,
        public string $group,         // ai | economics | collector | telegram
        public string $label,
        public ?string $hint = null,
        public ?float $min = null,
        public ?float $max = null,
    ) {}

    public function isSecret(): bool
    {
        return $this->type === 'secret';
    }

    /** Правила валидации формы админки. */
    public function rules(): array
    {
        $rules = match ($this->type) {
            'bool' => ['nullable', 'boolean'],
            'int' => ['nullable', 'integer'],
            'float' => ['nullable', 'numeric'],
            default => ['nullable', 'string', 'max:255'],
        };

        if ($this->min !== null) {
            $rules[] = 'min:'.$this->min;
        }

        if ($this->max !== null) {
            $rules[] = 'max:'.$this->max;
        }

        return $rules;
    }

    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            'float' => (float) $value,
            default => (string) $value,
        };
    }
}
