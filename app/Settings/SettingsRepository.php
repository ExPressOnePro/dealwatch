<?php

namespace App\Settings;

use App\Models\AppSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Настройки из базы поверх .env. Значения кешируются: apply() вызывается
 * на каждый запрос, лишний поход в базу тут ни к чему.
 */
class SettingsRepository
{
    private const CACHE_KEY = 'dealwatch:settings';

    /**
     * Исходные значения конфига (из .env) — чтобы удалённая настройка
     * возвращала прежнее значение, а не застревала в памяти воркера.
     *
     * @var array<string, mixed>
     */
    private array $baseline = [];

    /**
     * @return array<string, mixed> Значения в приведённом к типу виде.
     */
    public function all(): array
    {
        $raw = Cache::get(self::CACHE_KEY);

        if (! is_array($raw)) {
            $raw = $this->load();
            // Не forever: queue:work живёт часами, и без TTL он никогда не узнает,
            // что настройки изменили в админке.
            Cache::put(self::CACHE_KEY, $raw, now()->addSeconds(30));
        }

        $values = [];
        foreach ($raw as $key => $stored) {
            $definition = SettingsSchema::find($key);
            if (! $definition) {
                continue;
            }

            $value = $stored['value'];
            if ($value !== null && ($stored['is_encrypted'] ?? false)) {
                try {
                    $value = Crypt::decryptString($value);
                } catch (\Throwable $e) {
                    Log::warning("Не удалось расшифровать настройку {$key}: ".$e->getMessage());

                    continue;
                }
            }

            if ($value === null || $value === '') {
                continue;
            }

            $values[$key] = $definition->cast($value);
        }

        return $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function set(string $key, mixed $value): void
    {
        $definition = SettingsSchema::find($key);

        if (! $definition) {
            throw new \InvalidArgumentException("Неизвестная настройка: {$key}");
        }

        if ($value === null || $value === '') {
            $this->forget($key);

            return;
        }

        $stored = $definition->isSecret()
            ? Crypt::encryptString((string) $value)
            : (string) ($definition->type === 'bool' ? (int) $definition->cast($value) : $definition->cast($value));

        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'is_encrypted' => $definition->isSecret()]
        );

        $this->flush();
    }

    public function forget(string $key): void
    {
        AppSetting::query()->where('key', $key)->delete();
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Наложить настройки на конфиг приложения.
     */
    public function apply(): void
    {
        try {
            $values = $this->all();
        } catch (QueryException) {
            // Миграции ещё не прогнаны — работаем на значениях из .env.
            return;
        }

        $schema = SettingsSchema::all();

        foreach ($schema as $key => $definition) {
            // Запоминаем, что было в .env, при первом применении.
            if (! array_key_exists($key, $this->baseline)) {
                $this->baseline[$key] = config($definition->configPath);
            }

            $value = $values[$key] ?? $this->baseline[$key];
            config([$definition->configPath => $value]);
        }
    }

    /**
     * @return array<string, array{value: ?string, is_encrypted: bool}>
     */
    private function load(): array
    {
        return AppSetting::query()
            ->get(['key', 'value', 'is_encrypted'])
            ->mapWithKeys(fn (AppSetting $s) => [
                $s->key => ['value' => $s->value, 'is_encrypted' => (bool) $s->is_encrypted],
            ])
            ->all();
    }
}
