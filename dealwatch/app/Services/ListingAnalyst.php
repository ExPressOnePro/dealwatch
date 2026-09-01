<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\MarketPrice;

class ListingAnalyst
{
    /**
     * Analyze listing title/description/price (RU+RO) and build SMS + risk briefing.
     *
     * @return array{
     *     comment: string,
     *     flags: list<string>,
     *     is_bait: bool,
     *     risk_level: 'none'|'low'|'medium'|'high',
     *     notes: list<string>,
     *     report: array{
     *         known: list<string>,
     *         ask: list<string>,
     *         risks: list<array{label: string, probability: int, detail: string}>,
     *         sms: string,
     *         battery_from_text: ?int
     *     }
     * }
     */
    public function analyze(Listing $listing, ?MarketPrice $market = null): array
    {
        $notes = [];
        $flags = [];
        $known = [];
        $ask = [];
        $risks = [];

        $title = (string) ($listing->title ?? '');
        $description = (string) ($listing->description ?? '');
        $text = mb_strtolower(trim($title."\n".$description));

        $priceMdl = (int) ($listing->price_mdl ?? $listing->price ?? 0);
        $priceOriginal = (int) ($listing->price_original ?? $listing->price ?? 0);
        $currency = strtoupper((string) ($listing->currency ?: 'MDL'));

        $this->detectBaitPrice($priceMdl, $priceOriginal, $currency, $market, $notes, $flags);
        $this->detectTextSignals($text, $notes, $flags);
        $condition = $this->extractCondition($text, $listing, $known, $ask, $notes, $flags, $risks);

        $flags = array_values(array_unique($flags));
        $isBait = in_array('bait_price', $flags, true)
            || in_array('negotiable', $flags, true)
            || in_array('clickbait_text', $flags, true);

        $defectHeavy = in_array('no_face_id', $flags, true)
            || in_array('wireless_broken', $flags, true)
            || in_array('parts_or_lock', $flags, true)
            || (in_array('replaced_parts', $flags, true) && in_array('no_face_id', $flags, true));

        $riskLevel = match (true) {
            in_array('bait_price', $flags, true)
                || in_array('negotiable', $flags, true)
                || in_array('clickbait_text', $flags, true) => 'high',
            $defectHeavy
                || in_array('deposit', $flags, true)
                || in_array('exchange', $flags, true)
                || in_array('incomplete', $flags, true) => 'medium',
            in_array('replaced_parts', $flags, true)
                || in_array('low_battery', $flags, true)
                || $flags !== [] => 'low',
            default => 'none',
        };

        // Default asks if description empty / thin
        if ($ask === [] && ! $this->hasRichDescription($text)) {
            $ask = [
                'Аккумулятор сколько %?',
                'Face ID / отпечаток работает?',
                'iCloud чистый, без блокировок?',
                'Экран/корпус родные? Был ли ремонт?',
                'Цена окончательная — сколько?',
                'Где и когда удобно посмотреть?',
            ];
        } else {
            // Always confirm authenticity + meeting if not bait
            if (! in_array('icloud_ok', $flags, true) && ! $this->mentionsIcloud($text)) {
                $ask[] = 'iCloud чистый, без блокировок? Можете выйти из аккаунта при встрече?';
            }
            if (! $this->mentionsFinalPrice($text) && ! in_array('negotiable', $flags, true)) {
                $ask[] = 'Цена в объявлении окончательная?';
            }
            $ask[] = 'Где и когда удобно посмотреть / проверить?';
            $ask = array_values(array_unique($ask));
        }

        if ($notes === []) {
            $seller = $listing->seller_type === 'shop' ? 'магазин' : ($listing->seller_type === 'private' ? 'частник' : 'продавец неясен');
            $notes[] = 'Явных кликбейт-флагов в цене нет ('.$seller.').';
        }

        if ($risks !== []) {
            usort($risks, fn ($a, $b) => $b['probability'] <=> $a['probability']);
        }

        $brief = $this->buildBrief($listing, $notes, $known, $flags, $riskLevel, $isBait);
        $sms = $this->buildSms($listing, $priceOriginal, $currency, $priceMdl, $known, $ask, $condition);

        return [
            'comment' => $brief['headline'],
            'flags' => $flags,
            'is_bait' => $isBait,
            'risk_level' => $riskLevel,
            'notes' => $notes,
            'report' => [
                'known' => $known,
                'ask' => $ask,
                'risks' => $risks,
                'sms' => $sms,
                'battery_from_text' => $condition['battery'] ?? null,
                'brief' => $brief,
                'notes' => $notes,
            ],
        ];
    }

    /**
     * @param  list<string>  $notes
     * @param  list<string>  $known
     * @param  list<string>  $flags
     * @return array{
     *     headline: string,
     *     model: array{label: string, title: string, confidence: ?int, storage_gb: ?int},
     *     price_alerts: list<string>,
     *     text_alerts: list<string>,
     *     seller_facts: list<string>,
     *     general: list<string>,
     *     risk_level: string
     * }
     */
    private function buildBrief(
        Listing $listing,
        array $notes,
        array $known,
        array $flags,
        string $riskLevel,
        bool $isBait,
    ): array {
        $priceAlerts = [];
        $textAlerts = [];
        $general = [];

        foreach ($notes as $note) {
            if (preg_match('/^Цена\s/u', $note) || str_contains($note, 'кликбейт') || str_contains($note, 'buy_min') || str_contains($note, 'market mid')) {
                $priceAlerts[] = $note;
            } elseif (preg_match('/^(В тексте|Нет текста|Похоже на обмен|Упоминается|В описании цена)/u', $note)) {
                $textAlerts[] = $note;
            } else {
                $general[] = $note;
            }
        }

        $confidence = $listing->parse_confidence !== null
            ? (int) round((float) $listing->parse_confidence * 100)
            : null;

        $modelLabel = trim($listing->displayName());
        if ($modelLabel === '' || $modelLabel === $listing->title) {
            $modelLabel = (string) $listing->title;
        }

        $headline = match (true) {
            $isBait => 'Подозрительная цена — не ориентируйся на цифру в объявлении',
            $riskLevel === 'high' => 'Высокий риск — проверь цену и текст перед звонком',
            $riskLevel === 'medium' => 'Есть замечания по состоянию или тексту — только после проверки',
            $known !== [] => 'Модель распознана, часть фактов уже в описании продавца',
            default => 'Модель распознана, явных красных флагов нет',
        };

        return [
            'headline' => $headline,
            'model' => [
                'label' => $modelLabel,
                'title' => (string) ($listing->title ?? ''),
                'site_model' => $listing->site_model,
                'source' => $listing->model_source,
                'confidence' => $confidence,
                'storage_gb' => $listing->storage_gb,
            ],
            'price_alerts' => $priceAlerts,
            'text_alerts' => $textAlerts,
            'seller_facts' => $known,
            'general' => $general,
            'risk_level' => $riskLevel,
            'flags' => $flags,
        ];
    }

    /**
     * @param  list<string>  $known
     * @param  list<string>  $ask
     * @param  list<string>  $notes
     * @param  list<string>  $flags
     * @param  list<array{label: string, probability: int, detail: string}>  $risks
     * @return array{battery: ?int, storage: ?int}
     */
    private function extractCondition(
        string $text,
        Listing $listing,
        array &$known,
        array &$ask,
        array &$notes,
        array &$flags,
        array &$risks,
    ): array {
        $battery = $listing->battery_health;
        $storage = $listing->storage_gb;

        if (preg_match('/(?:батаре[яи]|батарея|аккумулятор|battery|baterie|capacitate\s+baterie)[^\d%]{0,20}(\d{2,3})\s*%/u', $text, $m)
            || preg_match('/(\d{2,3})\s*%\s*(?:батаре|battery|bater|акб|health)/u', $text, $m)
            || preg_match('/baterie\s*(?:de\s*)?(\d{2,3})\s*%/u', $text, $m)) {
            $battery = (int) $m[1];
            if ($battery >= 50 && $battery <= 100) {
                $known[] = "АКБ {$battery}%";
            }
        } elseif ($battery) {
            $known[] = "АКБ {$battery}% (из карточки)";
        } else {
            $ask[] = 'Аккумулятор сколько % (в настройках Battery Health)?';
        }

        if ($battery && $battery < 85) {
            $flags[] = 'low_battery';
            $risks[] = [
                'label' => 'Скоро нужна замена АКБ',
                'probability' => $battery < 80 ? 75 : 55,
                'detail' => "Заявлено {$battery}% — покупатель перепродажи часто требует скидку или замену (~800–1500 MDL).",
            ];
        }

        if (preg_match('/(\d{2,4})\s*(?:гб|gb|Go)\b/u', $text, $m)) {
            $storage = (int) $m[1];
            if (in_array($storage, [64, 128, 256, 512, 1024], true)) {
                $known[] = "{$storage} ГБ";
            }
        }

        $replaced = [];
        if (preg_match('/экран\s+замен|замен[её]н\s+экран|display\s+(?:schimb|replaced)|ecran\s+schimb|changed\s+screen|screen\s+replac/u', $text)
            || (preg_match('/экран/u', $text) && preg_match('/замен/u', $text))) {
            $replaced[] = 'экран';
        }
        if (preg_match('/аккумулятор\s+замен|замен[её]н\s+аккумулятор|battery\s+replac|baterie\s+schimb|schimbat[ăa]?\s+bater/u', $text)
            || (preg_match('/аккумулятор|baterie|battery/u', $text) && preg_match('/замен|schimbat/u', $text) && ! preg_match('/батаре[яи]\s+\d/u', $text))) {
            // "экран заменён, аккумулятор и камера" — list of replaced parts
            if (preg_match('/замен[её]н[аы]?\s*[,:]?\s*([^.\n]+)/u', $text, $chunk)
                || preg_match('/schimbat[ăa]?\s*[:\-]?\s*([^.\n]+)/u', $text, $chunk)) {
                $chunkText = $chunk[1] ?? '';
                if (preg_match('/аккумулятор|baterie|battery/u', $chunkText)) {
                    $replaced[] = 'аккумулятор';
                }
                if (preg_match('/камер|camera/u', $chunkText)) {
                    $replaced[] = 'камера';
                }
                if (preg_match('/экран|ecran|display|screen/u', $chunkText)) {
                    $replaced[] = 'экран';
                }
            }
        }
        // Pattern like: "экран заменён, аккумулятор и камера (всё работает)"
        if (preg_match('/замен[её]н[аы]?\s*,?\s*аккумулятор/u', $text) || preg_match('/замен[её]н[аы]?.{0,40}аккумулятор/u', $text)) {
            $replaced[] = 'аккумулятор';
        }
        if (preg_match('/замен[её]н[аы]?.{0,60}камер/u', $text) || preg_match('/камер[аы].{0,20}замен/u', $text)) {
            $replaced[] = 'камера';
        }
        if (preg_match('/schimbat.{0,40}(ecran|display|baterie|camera)/u', $text)) {
            if (preg_match('/ecran|display/u', $text)) {
                $replaced[] = 'экран';
            }
            if (preg_match('/baterie/u', $text)) {
                $replaced[] = 'аккумулятор';
            }
            if (preg_match('/camera/u', $text)) {
                $replaced[] = 'камера';
            }
        }

        $replaced = array_values(array_unique($replaced));
        if ($replaced !== []) {
            $flags[] = 'replaced_parts';
            $known[] = 'заменены: '.implode(', ', $replaced);
            $risks[] = [
                'label' => 'Неоригинальные запчасти → ниже перепродажа',
                'probability' => 70,
                'detail' => 'После замены экрана/камеры/АКБ частный рынок обычно −10…25% к mid; True Tone/Face ID часто страдают.',
            ];
            $ask[] = 'Запчасти оригинальные Apple/сервис или китай? Есть чек/гарантия на ремонт?';
        } else {
            $ask[] = 'Экран и корпус родные? Был ли ремонт?';
        }

        // Face ID
        if (preg_match('/face\s*id\s*(нет|не\s+работа|сломан|absent|nu\s+func|nu\s+merge|doesn.?t\s+work|broken)|нет\s+face\s*id|fără\s+face\s*id|fara\s+face\s*id|without\s+face\s*id/u', $text)) {
            $flags[] = 'no_face_id';
            $known[] = 'Face ID нет / не работает';
            $risks[] = [
                'label' => 'Без Face ID сильно режется цена продажи',
                'probability' => 85,
                'detail' => 'Перепродажа iPhone без Face ID часто −20…40% или уходит в «на запчасти». Вероятность долгого срока продажи высокая.',
            ];
            $ask[] = 'Почему нет Face ID — после ремонта экрана/камеры? True Tone есть?';
        } elseif (preg_match('/face\s*id\s*(работа|ok|funcț|merge|works)|face\s*id\s*—?\s*да/u', $text)) {
            $flags[] = 'face_id_ok';
            $known[] = 'Face ID работает (по тексту)';
        } else {
            $ask[] = 'Face ID / Touch ID работает?';
        }

        // NFC / Bluetooth / WiFi broken
        $brokenRadios = [];
        if (preg_match('/не\s+работа(?:ет|ют)\s+[^.\n]{0,40}nfc|nfc\s*(не\s+работа|nu\s+func|broken|defect)/u', $text)
            || (preg_match('/\bnfc\b/u', $text) && preg_match('/не\s+работа|nu\s+func|nu\s+merge/u', $text))) {
            $brokenRadios[] = 'NFC';
        }
        if (preg_match('/не\s+работа(?:ет|ют)\s+[^.\n]{0,40}bluetooth|bluetooth\s*(не\s+работа|nu\s+func|broken)/u', $text)
            || (preg_match('/bluetooth/u', $text) && preg_match('/не\s+работа|nu\s+func|nu\s+merge/u', $text))) {
            $brokenRadios[] = 'Bluetooth';
        }
        if (preg_match('/не\s+работа(?:ет|ют)\s+[^.\n]{0,40}wi-?fi|wi-?fi\s*(не\s+работа|nu\s+func)/u', $text)) {
            $brokenRadios[] = 'Wi‑Fi';
        }
        // "не работают NFC, Bluetooth"
        if (preg_match('/не\s+работают\s+([^.\n]+)/u', $text, $m)) {
            $chunk = $m[1];
            if (str_contains($chunk, 'nfc')) {
                $brokenRadios[] = 'NFC';
            }
            if (str_contains($chunk, 'bluetooth')) {
                $brokenRadios[] = 'Bluetooth';
            }
            if (preg_match('/wi-?fi/u', $chunk)) {
                $brokenRadios[] = 'Wi‑Fi';
            }
        }
        if (preg_match('/nu\s+(?:funcționează|merg)\s+([^.\n]+)/u', $text, $m)) {
            $chunk = $m[1];
            if (str_contains($chunk, 'nfc')) {
                $brokenRadios[] = 'NFC';
            }
            if (str_contains($chunk, 'bluetooth')) {
                $brokenRadios[] = 'Bluetooth';
            }
        }

        $brokenRadios = array_values(array_unique($brokenRadios));
        if ($brokenRadios !== []) {
            $flags[] = 'wireless_broken';
            $known[] = 'не работают: '.implode(', ', $brokenRadios);
            $risks[] = [
                'label' => 'Скрытый ущерб платы / дорогой ремонт',
                'probability' => 65,
                'detail' => implode(', ', $brokenRadios).' часто чинятся сервисом дорого; «сделают в сервисе» не гарантирует бюджет. Риск, что проявятся ещё дефекты.',
            ];
            if (preg_match('/в\s+сервисе\s+сделают|la\s+service|repară\s+la\s+service|починят/u', $text)) {
                $known[] = 'продавец обещает ремонт в сервисе';
                $risks[] = [
                    'label' => 'Обещание «починят в сервисе» не сбудется / не окупится',
                    'probability' => 55,
                    'detail' => 'До встречи зафиксируйте: кто платит ремонт и входит ли это в цену.',
                ];
                $ask[] = 'Ремонт NFC/Bluetooth уже оценён? Сколько в сервисе и кто оплачивает?';
            }
        }

        if (preg_match('/есть\s+чехол|чехол\s+и\s+стекло|husa|husă|toc\s+și|with\s+case|стекло\s+на\s+н/u', $text)) {
            $known[] = 'есть чехол/стекло';
        }

        if (preg_match('/в\s+остальном\s+вс[её]\s+работа|restul\s+funcț|everything\s+else\s+works/u', $text)) {
            $known[] = 'остальное по словам продавца работает';
            $risks[] = [
                'label' => 'Недосказанные дефекты при живом осмотре',
                'probability' => 40,
                'detail' => 'Формула «в остальном всё ок» при уже перечисленных поломках часто скрывает царапины, микрофон, зарядку, True Tone.',
            ];
            $ask[] = 'True Tone, зарядка, микрофоны, динамик, камеры — всё штатно? Можете на видео показать Face ID/настройки?';
        }

        return [
            'battery' => $battery && $battery >= 50 && $battery <= 100 ? $battery : null,
            'storage' => $storage,
        ];
    }

    /**
     * @param  list<string>  $known
     * @param  list<string>  $ask
     * @param  array{battery: ?int, storage: ?int}  $condition
     */
    private function buildSms(
        Listing $listing,
        int $priceOriginal,
        string $currency,
        int $priceMdl,
        array $known,
        array $ask,
        array $condition,
    ): string {
        $name = trim($listing->displayName());
        $priceLabel = $priceOriginal > 0
            ? number_format($priceOriginal, 0, '.', ' ').' '.$currency
            : ($priceMdl > 0 ? number_format($priceMdl, 0, '.', ' ').' MDL' : '—');

        $lines = [
            "Здравствуйте! Интересует {$name} (в объявлении {$priceLabel}).",
        ];

        if ($known !== []) {
            $lines[] = 'По описанию понял: '.implode('; ', array_slice($known, 0, 6)).'.';
        }

        $lines[] = 'Перед встречей уточните, пожалуйста:';
        $n = 1;
        foreach (array_slice($ask, 0, 6) as $q) {
            $lines[] = $n.') '.$q;
            $n++;
        }
        $lines[] = 'Спасибо!';

        return implode("\n", $lines);
    }

    private function hasRichDescription(string $text): bool
    {
        return mb_strlen($text) >= 40;
    }

    private function mentionsIcloud(string $text): bool
    {
        return (bool) preg_match('/icloud|айклауд|apple\s*id|fără\s+cont|clean\s+account/u', $text);
    }

    private function mentionsFinalPrice(string $text): bool
    {
        return (bool) preg_match('/цена\s+окончательн|ultimul\s+pre[tț]|final\s+price|торг\s+умест|pre[tț]\s+fix/u', $text);
    }

    /**
     * @param  list<string>  $notes
     * @param  list<string>  $flags
     */
    private function detectBaitPrice(
        int $priceMdl,
        int $priceOriginal,
        string $currency,
        ?MarketPrice $market,
        array &$notes,
        array &$flags,
    ): void {
        $amounts = array_values(array_unique(array_filter([$priceOriginal, $priceMdl], fn ($v) => $v > 0)));

        foreach ($amounts as $amount) {
            $amountCurrency = $amount === $priceOriginal ? $currency : 'MDL';
            if ($this->isClassicBaitNumber($amount, $amountCurrency)) {
                $flags[] = 'bait_price';
                $notes[] = sprintf(
                    'Цена %s %s — типичный кликбейт (1/111/123…), не реальная покупка',
                    number_format($amount, 0, '.', ' '),
                    $amountCurrency
                );

                return;
            }
        }

        if ($priceMdl > 0 && $priceMdl < 300) {
            $flags[] = 'bait_price';
            $notes[] = sprintf(
                'Цена %s MDL слишком низкая для телефона — почти наверняка не финальная',
                number_format($priceMdl, 0, '.', ' ')
            );

            return;
        }

        if ($market && $priceMdl > 0) {
            $buyMin = (int) $market->buy_min;
            $mid = $market->marketMid();
            if ($buyMin > 0 && $priceMdl < (int) round($buyMin * 0.35)) {
                $flags[] = 'bait_price';
                $notes[] = sprintf(
                    'Цена %s MDL < 35%% от buy_min (%s) — подозрительный дамп / приманка',
                    number_format($priceMdl, 0, '.', ' '),
                    number_format($buyMin, 0, '.', ' ')
                );

                return;
            }
            if ($mid > 0 && $priceMdl < (int) round($mid * 0.25)) {
                $flags[] = 'bait_price';
                $notes[] = sprintf(
                    'Цена %s MDL < 25%% от market mid (%s) — нереалистично дёшево',
                    number_format($priceMdl, 0, '.', ' '),
                    number_format($mid, 0, '.', ' ')
                );
            }
        }
    }

    private function isClassicBaitNumber(int $amount, string $currency = 'MDL'): bool
    {
        $currency = strtoupper($currency);

        if (in_array($currency, ['EUR', 'USD'], true)) {
            if (in_array($amount, [1, 2, 3, 5, 10, 11, 12, 20, 50, 99, 100, 111, 123, 222], true)) {
                return true;
            }

            return preg_match('/^(\d)\1{1,2}$/', (string) $amount) === 1 && $amount < 300;
        }

        if (in_array($amount, [1, 2, 3, 5, 10, 11, 12, 20, 50, 99, 100, 111, 123, 200, 222, 333, 555, 666, 777, 888, 999, 1111, 1234, 2222, 11111], true)) {
            return true;
        }

        if (preg_match('/^(\d)\1+$/', (string) $amount) === 1 && $amount < 20000) {
            return true;
        }

        return in_array((string) $amount, ['123', '1234', '12345', '123456'], true);
    }

    /**
     * @param  list<string>  $notes
     * @param  list<string>  $flags
     */
    private function detectTextSignals(string $text, array &$notes, array &$flags): void
    {
        if ($text === '') {
            $flags[] = 'incomplete';
            $notes[] = 'Нет текста объявления — сложно проверить реальность цены';

            return;
        }

        $patterns = [
            'negotiable' => [
                '/договорн/u',
                '/по\s+договор[её]нност/u',
                '/цена\s+договор/u',
                '/negociabil/u',
                '/pre[tț]\s+negociabil/u',
                '/price\s+negotiable/u',
                '/call\s+for\s+price/u',
                '/узнаете\s+по\s+телефон/u',
                '/цен[ау]\s+уточн/u',
                '/цена\s+при\s+встреч/u',
            ],
            'clickbait_text' => [
                '/не\s+смотр(и|ите)\s+на\s+цен/u',
                '/цена\s+для\s+привлечен/u',
                '/цена\s+в\s+объявлен/u',
                '/цена\s+не\s+актуальн/u',
                '/цена\s+указана\s+для/u',
                '/для\s+поднят(ия)\s+в\s+поиск/u',
                '/чтобы\s+подня(ть|лось)/u',
            ],
            'deposit' => [
                '/задат[ока]/u',
                '/\bavans\b/u',
                '/deposit/u',
                '/предоплат/u',
                '/бронь/u',
            ],
            'exchange' => [
                '/только\s+обмен/u',
                '/doar\s+schimb/u',
                '/exchange\s+only/u',
                '/обмен\s+на\s+/u',
                '/schimb\s+cu\s+/u',
            ],
        ];

        $labels = [
            'negotiable' => 'В тексте цена договорная / по звонку — цифра не основание для BUY',
            'clickbait_text' => 'В описании цена для просмотров / неактуальна',
            'deposit' => 'Упоминается задаток/предоплата/бронь — риск схемы',
            'exchange' => 'Похоже на обмен, а не чистую продажу',
        ];

        foreach ($patterns as $flag => $regexes) {
            foreach ($regexes as $regex) {
                if (preg_match($regex, $text)) {
                    $flags[] = $flag;
                    $notes[] = $labels[$flag];
                    break;
                }
            }
        }

        if (preg_match('/icloud\s*(lock|лок)|блокир|не\s+включа|на\s+запчаст|для\s+разбор|pentru\s+piese|for\s+parts/u', $text)) {
            $flags[] = 'parts_or_lock';
            $notes[] = 'В тексте: блокировка / не включается / на запчасти';
        }
    }
}
