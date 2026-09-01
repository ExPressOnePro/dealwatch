<?php

namespace App\Settings;

/**
 * Единственный список настраиваемых параметров: из него берутся и правила
 * валидации, и переопределение конфига, и форма админки.
 */
class SettingsSchema
{
    /**
     * @return array<string, SettingDefinition>
     */
    public static function all(): array
    {
        $definitions = [
            new SettingDefinition('ai.enabled', 'bool', 'dealwatch.ai.enabled', 'ai', 'ИИ-разбор включён'),
            new SettingDefinition('ai.api_key', 'secret', 'services.openai.key', 'ai', 'OPENAI_API_KEY', 'Хранится в базе в зашифрованном виде и нигде не показывается целиком'),
            new SettingDefinition('ai.model_screen', 'string', 'dealwatch.ai.models.screen.name', 'ai', 'Модель для скрининга пачки', 'Дешёвая модель: прогоняет всю выборку и ранжирует её'),
            new SettingDefinition('ai.model_screen_input_price', 'float', 'dealwatch.ai.models.screen.input_price', 'ai', 'Цена входа, $/1M токенов', null, 0),
            new SettingDefinition('ai.model_screen_output_price', 'float', 'dealwatch.ai.models.screen.output_price', 'ai', 'Цена выхода, $/1M токенов', null, 0),
            new SettingDefinition('ai.model_deep', 'string', 'dealwatch.ai.models.deep.name', 'ai', 'Модель для разбора финалистов', 'Дорогая модель: детальный разбор топ-кандидатов'),
            new SettingDefinition('ai.model_deep_input_price', 'float', 'dealwatch.ai.models.deep.input_price', 'ai', 'Цена входа, $/1M токенов', null, 0),
            new SettingDefinition('ai.model_deep_output_price', 'float', 'dealwatch.ai.models.deep.output_price', 'ai', 'Цена выхода, $/1M токенов', null, 0),
            new SettingDefinition('ai.vision_enabled', 'bool', 'dealwatch.ai.vision.enabled', 'ai', 'Разбор фотографий включён', 'Дорогая операция: запускается вручную для конкретного объявления'),
            new SettingDefinition('ai.model_vision', 'string', 'dealwatch.ai.models.vision.name', 'ai', 'Модель для разбора фото', 'Должна уметь смотреть картинки'),
            new SettingDefinition('ai.model_vision_input_price', 'float', 'dealwatch.ai.models.vision.input_price', 'ai', 'Vision: цена входа, $/1M токенов', null, 0),
            new SettingDefinition('ai.model_vision_output_price', 'float', 'dealwatch.ai.models.vision.output_price', 'ai', 'Vision: цена выхода, $/1M токенов', null, 0),
            new SettingDefinition('ai.vision_max_images', 'int', 'dealwatch.ai.vision.max_images', 'ai', 'Фото на один разбор', 'Каждое фото добавляет к счёту', 1, 10),
            new SettingDefinition('ai.batch_size', 'int', 'dealwatch.ai.batch_size', 'ai', 'Объявлений в одной пачке', null, 1, 200),
            new SettingDefinition('ai.cache_hours', 'int', 'dealwatch.ai.cache_hours', 'ai', 'Кеш ответов, часов', 'Одинаковый вход не оплачивается дважды', 0, 720),
            new SettingDefinition('ai.daily_calls', 'int', 'dealwatch.ai.limits.daily_calls', 'ai', 'Лимит обращений в сутки', '0 — без лимита', 0),
            new SettingDefinition('ai.daily_cost_usd', 'float', 'dealwatch.ai.limits.daily_cost_usd', 'ai', 'Лимит расхода в сутки, $', '0 — без лимита', 0),

            new SettingDefinition('economics.prep_cost', 'int', 'dealwatch.economics.prep_cost', 'economics', 'Подготовка к продаже, MDL', null, 0),
            new SettingDefinition('economics.risk_reserve', 'int', 'dealwatch.economics.risk_reserve', 'economics', 'Резерв на риск, MDL', null, 0),
            new SettingDefinition('economics.min_profit', 'int', 'dealwatch.economics.min_profit', 'economics', 'Минимальная интересная маржа, MDL', 'От неё считается потолок покупки', 0),

            new SettingDefinition('collector.limit', 'int', 'dealwatch.collector.limit', 'collector', 'Объявлений за один сбор', null, 1, 200),
            new SettingDefinition('collector.enrich', 'bool', 'dealwatch.collector.enrich', 'collector', 'Открывать карточку объявления'),
            new SettingDefinition('collector.enrich_new_only', 'bool', 'dealwatch.collector.enrich_new_only', 'collector', 'Только новые и изменившиеся', 'Иначе каждые 3 минуты дёргаем 999.md по всему списку'),
            new SettingDefinition('collector.empty_runs_before_alert', 'int', 'dealwatch.collector.empty_runs_before_alert', 'collector', 'Пустых сборов до алерта', null, 1, 100),

            new SettingDefinition('telegram.bot_token', 'secret', 'services.telegram.bot_token', 'telegram', 'Токен бота'),
            new SettingDefinition('telegram.chat_id', 'string', 'services.telegram.chat_id', 'telegram', 'Chat ID для алертов'),
        ];

        return collect($definitions)->keyBy(fn (SettingDefinition $d) => $d->key)->all();
    }

    public static function find(string $key): ?SettingDefinition
    {
        return self::all()[$key] ?? null;
    }
}
