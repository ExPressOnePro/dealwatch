<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Экономика перепродажи
    |--------------------------------------------------------------------------
    |
    | Единственный источник правды для формулы прибыли:
    | profit = ожидаемая продажа − цена покупки − prep_cost − risk_reserve.
    | min_profit — маржа, ради которой вообще стоит браться за сделку;
    | от неё считается потолок покупки (max_buy_for_profit).
    |
    */

    'economics' => [
        'prep_cost' => (int) env('DEALWATCH_PREP_COST', 300),
        'risk_reserve' => (int) env('DEALWATCH_RISK_RESERVE', 300),
        'min_profit' => (int) env('DEALWATCH_MIN_PROFIT', 1500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deal Score
    |--------------------------------------------------------------------------
    |
    | weights — доли компонентов в итоговом score (сумма ожидается ≈ 1.0,
    | результат в любом случае обрезается в диапазон 0..100).
    | verdict — пороги вердиктов; floor — жёсткая отсечка «это не сделка».
    |
    */

    'score' => [
        'weights' => [
            'discount' => 0.30,
            'profit' => 0.20,
            'liquidity' => 0.12,
            'freshness' => 0.12,
            'private_seller' => 0.08,
            'parse_confidence' => 0.05,
            'condition' => 0.08,
            'valuation_confidence' => 0.05,
        ],

        'verdict' => [
            'buy' => [
                'score' => 80,
                'profit' => 1500,
                'discount' => 12.0,
            ],
            'check' => [
                'score' => 60,
                'profit' => 800,
            ],
            'floor' => [
                'profit' => 800,
                'discount' => 8.0,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Рыночные ориентиры
    |--------------------------------------------------------------------------
    |
    | Из чего строится market_prices при пересчёте по собственному корпусу.
    |
    */

    'market' => [
        'buy_max_ratio' => (float) env('DEALWATCH_BUY_MAX_RATIO', 0.82),
        'buy_min_ratio' => (float) env('DEALWATCH_BUY_MIN_RATIO', 0.70),
        'min_samples' => (int) env('DEALWATCH_MARKET_MIN_SAMPLES', 5),
        'evidence_cache_minutes' => (int) env('DEALWATCH_EVIDENCE_CACHE_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Продавцы
    |--------------------------------------------------------------------------
    */

    'sellers' => [
        'reseller_threshold' => (int) env('DEALWATCH_RESELLER_THRESHOLD', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Сбор объявлений
    |--------------------------------------------------------------------------
    |
    | enrich_new_only — открывать карточку объявления только для новых
    | и изменившихся записей (иначе каждый прогон шедулера бьёт по площадке
    | двумя лишними запросами на каждое уже известное объявление).
    | empty_runs_before_alert — сколько подряд пустых сборов терпим,
    | прежде чем считать интеграцию сломанной и звать в Telegram.
    |
    */

    'collector' => [
        'limit' => (int) env('DEALWATCH_COLLECT_LIMIT', 40),
        'enrich' => (bool) env('DEALWATCH_COLLECT_ENRICH', true),
        'enrich_new_only' => (bool) env('DEALWATCH_COLLECT_ENRICH_NEW_ONLY', true),
        'empty_runs_before_alert' => (int) env('DEALWATCH_EMPTY_RUNS_BEFORE_ALERT', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | ИИ-разбор (OpenAI)
    |--------------------------------------------------------------------------
    |
    | Двухуровневая схема: дешёвая модель (screen) прогоняет пачку объявлений
    | и отсеивает мусор, дорогая (deep) детально разбирает финалистов.
    | Имена моделей и цены заданы через .env: у разных аккаунтов открыт
    | разный набор, а прайс меняется чаще, чем код. Цены — доллары за 1M
    | токенов, они нужны только для учёта расходов в таблице ai_requests.
    |
    */

    'ai' => [
        'enabled' => filter_var(env('DEALWATCH_AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 120),
        'retries' => (int) env('OPENAI_RETRIES', 2),

        'models' => [
            'screen' => [
                'name' => env('OPENAI_MODEL_SCREEN', 'gpt-5-mini'),
                'input_price' => (float) env('OPENAI_MODEL_SCREEN_INPUT_PRICE', 0.25),
                'output_price' => (float) env('OPENAI_MODEL_SCREEN_OUTPUT_PRICE', 2.00),
            ],
            'deep' => [
                'name' => env('OPENAI_MODEL_DEEP', 'gpt-5'),
                'input_price' => (float) env('OPENAI_MODEL_DEEP_INPUT_PRICE', 1.25),
                'output_price' => (float) env('OPENAI_MODEL_DEEP_OUTPUT_PRICE', 10.00),
            ],
            // Разбор фотографий: включается вручную для конкретного объявления —
            // картинки съедают заметно больше токенов, чем текст.
            'vision' => [
                'name' => env('OPENAI_MODEL_VISION', 'gpt-5'),
                'input_price' => (float) env('OPENAI_MODEL_VISION_INPUT_PRICE', 1.25),
                'output_price' => (float) env('OPENAI_MODEL_VISION_OUTPUT_PRICE', 10.00),
            ],
        ],

        'vision' => [
            'enabled' => filter_var(env('DEALWATCH_AI_VISION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'max_images' => (int) env('DEALWATCH_AI_VISION_MAX_IMAGES', 4),
            'detail' => env('DEALWATCH_AI_VISION_DETAIL', 'auto'),
        ],

        // Сколько символов описания отдаём модели при разборе одного объявления.
        'deep_text_limit' => (int) env('DEALWATCH_AI_DEEP_TEXT_LIMIT', 4000),

        // Сколько объявлений уходит в одну пачку скрининга.
        'batch_size' => (int) env('DEALWATCH_AI_BATCH_SIZE', 40),

        // Одинаковый вход не оплачивается дважды.
        'cache_hours' => (int) env('DEALWATCH_AI_CACHE_HOURS', 24),

        // Предохранители: превышение — отказ с понятной ошибкой, а не тихий счёт.
        'limits' => [
            'daily_calls' => (int) env('DEALWATCH_AI_DAILY_CALLS', 200),
            'daily_cost_usd' => (float) env('DEALWATCH_AI_DAILY_COST_USD', 5.0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Протухшие объявления
    |--------------------------------------------------------------------------
    |
    | 999.md показывает дату последнего обновления объявления. Если продавец
    | давно его не трогал, велик шанс, что товар уже продан или объявление
    | неактуально — звонить по такому в первую очередь не стоит.
    |
    */

    'staleness' => [
        'suspect_days' => (int) env('DEALWATCH_STALE_SUSPECT_DAYS', 21),
        'dead_days' => (int) env('DEALWATCH_STALE_DEAD_DAYS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Товары вне справочника моделей
    |--------------------------------------------------------------------------
    |
    | Для телефонов рынок берётся из market_prices. Для остальных источников
    | (велосипеды, ноутбуки, инструмент…) рынком считается медиана цен по самим
    | объявлениям источника: чем уже настроен источник, тем точнее ориентир.
    |
    */

    'generic' => [
        'min_samples' => (int) env('DEALWATCH_GENERIC_MIN_SAMPLES', 5),
        // Насколько ниже медианы обычно уходит реальная сделка после торга.
        'negotiation_percent' => (float) env('DEALWATCH_GENERIC_NEGOTIATION', 7.0),
        // Отсечка выбросов относительно медианы перед расчётом квартилей.
        'outlier_low_ratio' => (float) env('DEALWATCH_GENERIC_OUTLIER_LOW', 0.4),
        'outlier_high_ratio' => (float) env('DEALWATCH_GENERIC_OUTLIER_HIGH', 2.5),
        'stats_cache_minutes' => (int) env('DEALWATCH_GENERIC_STATS_CACHE_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Архив объявлений
    |--------------------------------------------------------------------------
    |
    | Объявления снимают с площадки сразу после продажи, поэтому мы храним
    | снимки: текст и цену — для всех, тяжёлые медиа (фото и копию страницы) —
    | только для «своих» объявлений (избранное, сделка, отслеживание) или
    | по кнопке. Иначе корпус в десятки тысяч объявлений съест десятки гигабайт.
    |
    */

    'archive' => [
        'enabled' => filter_var(env('DEALWATCH_ARCHIVE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'media_for_saved_only' => filter_var(env('DEALWATCH_ARCHIVE_MEDIA_SAVED_ONLY', true), FILTER_VALIDATE_BOOLEAN),
        'max_images' => (int) env('DEALWATCH_ARCHIVE_MAX_IMAGES', 6),
        'max_image_bytes' => (int) env('DEALWATCH_ARCHIVE_MAX_IMAGE_BYTES', 3_000_000),
        'keep_html' => filter_var(env('DEALWATCH_ARCHIVE_KEEP_HTML', true), FILTER_VALIDATE_BOOLEAN),
        'disk' => env('DEALWATCH_ARCHIVE_DISK', 'local'),
        // Через сколько часов без встречи в выдаче проверяем, жива ли страница.
        'gone_check_after_hours' => (int) env('DEALWATCH_GONE_CHECK_AFTER_HOURS', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Витринная статистика
    |--------------------------------------------------------------------------
    |
    | Счётчики ленты и сводка по корпусу считаются по всей базе, поэтому
    | держатся в кеше. Действия пользователя (скрыть, купить, избранное)
    | сбрасывают его сразу, фоновые сборы — по истечении TTL.
    | 0 — считать каждый раз (так гоняются тесты).
    |
    */

    'stats' => [
        'cache_seconds' => (int) env('DEALWATCH_STATS_CACHE_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Доступ
    |--------------------------------------------------------------------------
    |
    | Лента сделок общая для всех аккаунтов, поэтому самостоятельная
    | регистрация по умолчанию закрыта: пользователей заводит сид/тинкер.
    |
    */

    'registration_enabled' => filter_var(
        env('DEALWATCH_ALLOW_REGISTRATION', false),
        FILTER_VALIDATE_BOOLEAN
    ),

];
