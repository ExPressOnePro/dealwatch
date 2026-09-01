# DealWatch MVP

Система поиска недооценённых телефонов на **999.md** + Deal Score + мгновенные алерты в Telegram.

> Цель MVP: ответить на вопрос *«Какое объявление прямо сейчас стоит звонка?»*

## Стек

- Laravel 12 + Inertia + React
- SQLite (для старта)
- Telegram Bot API
- Scheduler: сбор каждые 3 минуты

## Быстрый старт

```bash
cd dealwatch
cp .env.example .env   # если нужно
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Логин:

- email: `dealer@dealwatch.test`
- password: `password`

Открой: http://127.0.0.1:8000/deals

## Telegram

В `.env`:

```env
TELEGRAM_BOT_TOKEN=...
TELEGRAM_CHAT_ID=...
```

Алерты уходят на сделки с verdict `buy` / `check` при первом появлении объявления.

## Команды

```bash
# демо-данные (уже в db:seed)
php artisan db:seed --class=DemoListingSeeder

# сбор с 999.md
php artisan deals:collect-999

# пересчёт score
php artisan deals:recalculate

# scheduler (в отдельном терминале)
php artisan schedule:work
```

## Как пользоваться

1. Смотри ленту сделок на `/deals` (score, дисконт, потенциал прибыли).
2. **Открыть** / **Позвонить** — сразу к продавцу.
3. Или вставь URL объявления 999 в поле «Оценить URL».
4. Статусы: `Купил` / `Скрыть` — чтобы не терять фокус.

## Deal Score (упрощённо)

- 35% дисконт к рынку
- 20% потенциальная прибыль
- 15% ликвидность модели
- 15% свежесть объявления
- 10% частник vs магазин
- 5% уверенность парсера модели

Жёсткие правила:

- прибыль &lt; 800 MDL или дисконт &lt; 10% → ignore
- цена выше `buy_max` по модели → ignore
- buy: score ≥ 80, прибыль ≥ 1500, дисконт ≥ 15%

## Важно про парсинг 999

999 отдаёт каталог через JS. Коллектор:

1. тянет HTML списка и пытается вытащить ID;
2. открывает карточку объявления (`/ru/{id}`) и читает og-meta / цену / телефон;
3. если список пустой — используй **импорт URL** или демо-сид.

Для продакшена следующий шаг: стабильный источник ID (RSS/API/браузерный worker), затем Facebook/Telegram.

## Структура

```
Collectors (999) → ListingPipeline → PhoneNormalizer
                                 → DealScoreEngine
                                 → TelegramNotifier
                                 → /deals dashboard
```
