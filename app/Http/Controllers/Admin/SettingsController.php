<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Services\Ai\AiBudget;
use App\Services\Ai\OpenAiClient;
use App\Settings\SettingDefinition;
use App\Settings\SettingsRepository;
use App\Settings\SettingsSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly OpenAiClient $client,
        private readonly AiBudget $budget,
    ) {}

    public function edit(Request $request): Response
    {
        $this->settings->apply();

        return Inertia::render('admin/settings', [
            'groups' => $this->groups(),
            'models' => $this->client->availableModels(),
            'usage' => $this->budget->today(),
            'spend' => [
                'last_7_days' => round((float) AiRequest::query()
                    ->billable()
                    ->where('created_at', '>=', now()->subDays(7))
                    ->sum('cost_usd'), 4),
                'calls_7_days' => AiRequest::query()
                    ->billable()
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count(),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'ping' => $request->session()->get('ping'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $schema = SettingsSchema::all();

        // Ключи настроек содержат точки, а точка в правилах означает вложенность —
        // экранируем, иначе values['ai.api_key'] превратится в values[ai][api_key].
        $rules = [];
        foreach ($schema as $key => $definition) {
            $rules['values.'.str_replace('.', '\\.', $key)] = $definition->rules();
        }

        $request->validate($rules);

        /** @var array<string, mixed> $values */
        $values = $request->input('values', []);

        foreach ($schema as $key => $definition) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            // Пустое поле секрета означает «оставить как есть», а не «стереть».
            if ($definition->isSecret() && ($value === null || $value === '')) {
                continue;
            }

            $this->settings->set($key, $value);
        }

        return back()->with('success', 'Настройки сохранены.');
    }

    /** Стереть секрет (ключ OpenAI, токен бота) — отдельным действием, чтобы не сделать это случайно. */
    public function forget(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => 'required|string',
        ]);

        $definition = SettingsSchema::find($data['key']);

        if (! $definition) {
            return back()->with('error', 'Неизвестная настройка.');
        }

        $this->settings->forget($definition->key);

        return back()->with('success', 'Значение удалено — снова действует значение из .env.');
    }

    public function testConnection(): RedirectResponse
    {
        // Настройки могли поменяться в этом же процессе — берём свежие.
        $this->settings->apply();

        return back()->with('ping', $this->client->ping());
    }

    public function refreshModels(): RedirectResponse
    {
        $this->settings->apply();

        $models = $this->client->availableModels(fresh: true);

        return back()->with(
            'success',
            $models === []
                ? 'Список моделей получить не удалось — проверь ключ.'
                : 'Список моделей обновлён: '.count($models).' шт.'
        );
    }

    /**
     * Значения для формы. Секреты наружу не отдаются — только признак «задан» и хвост.
     *
     * @return array<string, mixed>
     */
    private function groups(): array
    {
        $groups = [];

        foreach (SettingsSchema::all() as $key => $definition) {
            $groups[$definition->group][] = $this->field($definition);
        }

        return [
            'ai' => ['title' => 'ИИ-разбор (OpenAI)', 'fields' => $groups['ai'] ?? []],
            'economics' => ['title' => 'Экономика сделки', 'fields' => $groups['economics'] ?? []],
            'collector' => ['title' => 'Сбор с 999.md', 'fields' => $groups['collector'] ?? []],
            'telegram' => ['title' => 'Telegram', 'fields' => $groups['telegram'] ?? []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function field(SettingDefinition $definition): array
    {
        $stored = $this->settings->has($definition->key);

        // Что реально действует прямо сейчас: значение из базы, иначе из .env.
        $effective = $stored
            ? $this->settings->get($definition->key)
            : config($definition->configPath);

        return [
            'key' => $definition->key,
            'type' => $definition->type,
            'label' => $definition->label,
            'hint' => $definition->hint,
            'overridden' => $stored,
            'value' => $definition->isSecret() ? null : $effective,
            'masked' => $definition->isSecret() ? $this->mask((string) $effective) : null,
        ];
    }

    private function mask(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return mb_strlen($value) <= 8
            ? str_repeat('•', mb_strlen($value))
            : str_repeat('•', 8).mb_substr($value, -4);
    }
}
