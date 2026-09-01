<?php

namespace App\Services;

class PhoneNormalizer
{
    /** @var array<string, array{brand:string,model:string,aliases:list<string>,specificity:int}> */
    private array $catalog = [
        'iphone_12_pro_max' => ['brand' => 'Apple', 'model' => 'iPhone 12 Pro Max', 'specificity' => 30, 'aliases' => ['iphone 12 pro max', 'айфон 12 про макс', '12 pro max', '12promax']],
        'iphone_12_pro' => ['brand' => 'Apple', 'model' => 'iPhone 12 Pro', 'specificity' => 20, 'aliases' => ['iphone 12 pro', 'айфон 12 про', '12 pro', '12pro']],
        'iphone_12_mini' => ['brand' => 'Apple', 'model' => 'iPhone 12 Mini', 'specificity' => 20, 'aliases' => ['iphone 12 mini', 'айфон 12 мини', '12 mini']],
        'iphone_12' => ['brand' => 'Apple', 'model' => 'iPhone 12', 'specificity' => 10, 'aliases' => ['iphone 12', 'айфон 12', 'iphone12']],
        'iphone_13_pro_max' => ['brand' => 'Apple', 'model' => 'iPhone 13 Pro Max', 'specificity' => 30, 'aliases' => ['iphone 13 pro max', 'айфон 13 про макс', '13 pro max', '13promax', '13 pm']],
        'iphone_13_pro' => ['brand' => 'Apple', 'model' => 'iPhone 13 Pro', 'specificity' => 20, 'aliases' => ['iphone 13 pro', 'айфон 13 про', '13 pro', '13pro']],
        'iphone_13_mini' => ['brand' => 'Apple', 'model' => 'iPhone 13 Mini', 'specificity' => 20, 'aliases' => ['iphone 13 mini', 'айфон 13 мини', '13 mini']],
        'iphone_13' => ['brand' => 'Apple', 'model' => 'iPhone 13', 'specificity' => 10, 'aliases' => ['iphone 13', 'айфон 13', 'iphone13']],
        'iphone_14_pro_max' => ['brand' => 'Apple', 'model' => 'iPhone 14 Pro Max', 'specificity' => 30, 'aliases' => ['iphone 14 pro max', 'айфон 14 про макс', '14 pro max', '14promax', '14 pm']],
        'iphone_14_pro' => ['brand' => 'Apple', 'model' => 'iPhone 14 Pro', 'specificity' => 20, 'aliases' => ['iphone 14 pro', 'айфон 14 про', '14 pro', '14pro']],
        'iphone_14_plus' => ['brand' => 'Apple', 'model' => 'iPhone 14 Plus', 'specificity' => 20, 'aliases' => ['iphone 14 plus', 'айфон 14 плюс', '14 plus', '14+']],
        'iphone_14' => ['brand' => 'Apple', 'model' => 'iPhone 14', 'specificity' => 10, 'aliases' => ['iphone 14', 'айфон 14', 'iphone14']],
        'iphone_15_pro_max' => ['brand' => 'Apple', 'model' => 'iPhone 15 Pro Max', 'specificity' => 30, 'aliases' => ['iphone 15 pro max', 'айфон 15 про макс', '15 pro max', '15promax', '15 pm']],
        'iphone_15_pro' => ['brand' => 'Apple', 'model' => 'iPhone 15 Pro', 'specificity' => 20, 'aliases' => ['iphone 15 pro', 'айфон 15 про', '15 pro', '15pro']],
        'iphone_15_plus' => ['brand' => 'Apple', 'model' => 'iPhone 15 Plus', 'specificity' => 20, 'aliases' => ['iphone 15 plus', 'айфон 15 плюс', '15 plus', '15+']],
        'iphone_15' => ['brand' => 'Apple', 'model' => 'iPhone 15', 'specificity' => 10, 'aliases' => ['iphone 15', 'айфон 15', 'iphone15']],
        'iphone_16_pro_max' => ['brand' => 'Apple', 'model' => 'iPhone 16 Pro Max', 'specificity' => 30, 'aliases' => ['iphone 16 pro max', 'айфон 16 про макс', '16 pro max', '16promax']],
        'iphone_16_pro' => ['brand' => 'Apple', 'model' => 'iPhone 16 Pro', 'specificity' => 20, 'aliases' => ['iphone 16 pro', 'айфон 16 про', '16 pro', '16pro']],
        'iphone_16_plus' => ['brand' => 'Apple', 'model' => 'iPhone 16 Plus', 'specificity' => 20, 'aliases' => ['iphone 16 plus', 'айфон 16 плюс', '16 plus', '16+']],
        'iphone_16e' => ['brand' => 'Apple', 'model' => 'iPhone 16e', 'specificity' => 15, 'aliases' => ['iphone 16e', 'айфон 16e', '16e']],
        'iphone_16' => ['brand' => 'Apple', 'model' => 'iPhone 16', 'specificity' => 10, 'aliases' => ['iphone 16', 'айфон 16', 'iphone16']],
        'iphone_17_pro_max' => ['brand' => 'Apple', 'model' => 'iPhone 17 Pro Max', 'specificity' => 30, 'aliases' => ['iphone 17 pro max', 'айфон 17 про макс', '17 pro max', '17promax']],
        'iphone_17_pro' => ['brand' => 'Apple', 'model' => 'iPhone 17 Pro', 'specificity' => 20, 'aliases' => ['iphone 17 pro', 'айфон 17 про', '17 pro', '17pro']],
        'iphone_17' => ['brand' => 'Apple', 'model' => 'iPhone 17', 'specificity' => 10, 'aliases' => ['iphone 17', 'айфон 17', 'iphone17']],
        's22_ultra' => ['brand' => 'Samsung', 'model' => 'S22 Ultra', 'specificity' => 30, 'aliases' => ['s22 ultra', 'galaxy s22 ultra', 'samsung s22 ultra', 'самсунг s22 ultra']],
        's23_ultra' => ['brand' => 'Samsung', 'model' => 'S23 Ultra', 'specificity' => 30, 'aliases' => ['s23 ultra', 'galaxy s23 ultra', 'samsung s23 ultra', 'самсунг s23 ultra']],
        's24_ultra' => ['brand' => 'Samsung', 'model' => 'S24 Ultra', 'specificity' => 30, 'aliases' => ['s24 ultra', 'galaxy s24 ultra', 'samsung s24 ultra', 'самсунг s24 ultra']],
        's24' => ['brand' => 'Samsung', 'model' => 'S24', 'specificity' => 10, 'aliases' => ['galaxy s24', 'samsung s24', 'самсунг s24']],
        'galaxy_a15' => ['brand' => 'Samsung', 'model' => 'Galaxy A15', 'specificity' => 20, 'aliases' => ['galaxy a15', 'samsung a15', 'самсунг a15']],
        'galaxy_fold_5' => ['brand' => 'Samsung', 'model' => 'Galaxy Fold 5', 'specificity' => 30, 'aliases' => ['galaxy fold 5', 'fold 5', 'z fold 5', 'zfold5']],
        'galaxy_fold_6' => ['brand' => 'Samsung', 'model' => 'Galaxy Fold 6', 'specificity' => 30, 'aliases' => ['galaxy fold 6', 'fold 6', 'z fold 6', 'zfold6']],
        'pixel_8_pro' => ['brand' => 'Google', 'model' => 'Pixel 8 Pro', 'specificity' => 30, 'aliases' => ['pixel 8 pro', 'google pixel 8 pro']],
        'xiaomi_13_pro' => ['brand' => 'Xiaomi', 'model' => '13 Pro', 'specificity' => 20, 'aliases' => ['xiaomi 13 pro', 'mi 13 pro']],
        'poco_f7_pro' => ['brand' => 'Xiaomi', 'model' => 'Poco F7 Pro', 'specificity' => 30, 'aliases' => ['poco f7 pro', 'f7 pro']],
        'redmi_note_14' => ['brand' => 'Xiaomi', 'model' => 'Redmi Note 14', 'specificity' => 20, 'aliases' => ['redmi note 14', 'note 14 5g']],
        'redmi_note_13_pro_plus' => ['brand' => 'Xiaomi', 'model' => 'Redmi Note 13 Pro+', 'specificity' => 30, 'aliases' => ['redmi note 13 pro+', 'note 13 pro+', 'redmi note 13 pro plus']],
    ];

    /**
     * @return array{
     *     brand:?string,
     *     model:?string,
     *     storage_gb:?int,
     *     battery_health:?int,
     *     confidence:float,
     *     multi_model:bool,
     *     model_source:?string
     * }
     */
    public function parse(string $title, ?string $description = null, ?string $siteModel = null): array
    {
        $siteModel = trim((string) $siteModel);
        $titleNorm = $this->normalize($title);
        $descNorm = $this->normalize((string) $description);
        $siteNorm = $siteModel !== '' ? $this->normalize($siteModel) : '';

        $multi = $this->isMultiModelCatalog($titleNorm);
        $best = null;
        $bestScore = -1;
        $bestAliasLen = 0;
        $modelSource = null;

        // 999.md field «Модель» — primary when seller filled it in.
        if ($siteNorm !== '') {
            [$best, $bestScore, $bestAliasLen] = $this->bestMatch($siteNorm);
            if ($best) {
                $modelSource = 'site';
            }

            if ($best && str_contains($best['model'], 'Pro') && ! str_contains($best['model'], 'Max')
                && preg_match('/pro\s*max|про\s*макс/u', $siteNorm)) {
                $upgrade = $this->proMaxUpgrade($best['model']);
                if ($upgrade) {
                    $best = $upgrade;
                    $bestScore = max($bestScore, 30);
                }
            }
        }

        // Title only when «Модель на сайте» is empty.
        if (! $best && $siteNorm === '') {
            if (! $multi) {
                [$best, $bestScore, $bestAliasLen] = $this->bestMatch($titleNorm);
                if ($best) {
                    $modelSource = 'title';
                }
            }

            if (! $best && ! $multi && ! $this->looksLikeOtherDevice($titleNorm)
                && ! $this->titleNamesIphoneGeneration($titleNorm)) {
                [$best, $bestScore, $bestAliasLen] = $this->bestMatch($descNorm);
                if ($best && ! $this->isMultiModelCatalog($descNorm)) {
                    $modelSource = 'description';
                } else {
                    $best = null;
                    $bestScore = -1;
                    $bestAliasLen = 0;
                }
            }

            if ($best && str_contains($best['model'], 'Pro') && ! str_contains($best['model'], 'Max')
                && preg_match('/pro\s*max|про\s*макс/u', $titleNorm)) {
                $upgrade = $this->proMaxUpgrade($best['model']);
                if ($upgrade) {
                    $best = $upgrade;
                    $bestScore = max($bestScore, 30);
                }
            }
        }

        $storage = $this->extractStorage($titleNorm) ?? $this->extractStorage($descNorm);
        if (! $storage && $siteNorm !== '') {
            $storage = $this->extractStorage($siteNorm);
        }
        $battery = $this->extractBattery($titleNorm) ?? $this->extractBattery($descNorm);

        $confidence = 0.0;
        if ($best) {
            $confidence = match ($modelSource) {
                'site' => 0.88,
                'title' => 0.60 + min(0.30, $bestAliasLen / 40) + ($bestScore >= 30 ? 0.05 : 0),
                'description' => 0.45 + min(0.20, $bestAliasLen / 50),
                default => 0.55,
            };
            if ($storage) {
                $confidence += 0.05;
            }
        }

        return [
            'brand' => $best['brand'] ?? null,
            'model' => $best['model'] ?? null,
            'storage_gb' => $storage,
            'battery_health' => $battery,
            'confidence' => round(min(1, $confidence), 2),
            'multi_model' => $multi && $siteNorm === '',
            'model_source' => $modelSource,
        ];
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['ё', 'промакс', 'про-макс', 'promax'], ['е', 'pro max', 'pro max', 'pro max'], $text);
        // "14Pro" / "15ProMax" → spaced tokens
        $text = preg_replace('/(\d{1,2})\s*(pro)\s*(max)?/u', '$1 $2 $3', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array{0:?array{brand:string,model:string,specificity:int,aliases:list<string>},1:int,2:int}
     */
    private function bestMatch(string $text): array
    {
        $best = null;
        $bestScore = -1;
        $bestAliasLen = 0;

        foreach ($this->catalog as $item) {
            foreach ($item['aliases'] as $alias) {
                if (! $this->aliasMatches($text, $alias)) {
                    continue;
                }

                $len = mb_strlen($alias);
                $score = ($item['specificity'] * 100) + $len;

                // Longer / more specific wins. Equal → keep first (catalog is Pro Max before Pro before base).
                if ($score > $bestScore || ($score === $bestScore && $len > $bestAliasLen)) {
                    // Guard: base "iphone 14" must not win over "iphone 14 pro" if pro is present for same gen.
                    if ($best && $this->isWeakerVariant($item, $best, $text)) {
                        continue;
                    }
                    $best = $item;
                    $bestScore = $score;
                    $bestAliasLen = $len;
                }
            }
        }

        // If we matched a base/pro but text has pro max for that generation, upgrade handled later.
        // If we matched "14 pro" because alias hit inside "14 pro max", prefer pro max:
        if ($best && str_contains($best['model'], 'Pro') && ! str_contains($best['model'], 'Max')
            && preg_match('/pro\s*max|про\s*макс/u', $text)) {
            $upgrade = $this->proMaxUpgrade($best['model']);
            if ($upgrade) {
                $best = $upgrade;
                $bestScore = max($bestScore, $upgrade['specificity'] * 100);
            }
        }

        return [$best, $bestScore, $bestAliasLen];
    }

    private function aliasMatches(string $text, string $alias): bool
    {
        $alias = mb_strtolower(trim($alias));
        if ($alias === '') {
            return false;
        }

        // Compact forms without spaces (14promax, iphone13)
        if (! str_contains($alias, ' ')) {
            return (bool) preg_match('/(?<![a-z0-9])'.preg_quote($alias, '/').'(?![a-z0-9])/u', $text);
        }

        $parts = preg_split('/\s+/u', $alias) ?: [];
        $pattern = '(?<![a-z0-9])'.implode('\s*', array_map(fn ($p) => preg_quote($p, '/'), $parts)).'(?![a-z0-9])';

        return (bool) preg_match('/'.$pattern.'/u', $text);
    }

    /**
     * @param  array{brand:string,model:string,specificity:int}  $candidate
     * @param  array{brand:string,model:string,specificity:int}  $current
     */
    private function isWeakerVariant(array $candidate, array $current, string $text): bool
    {
        if ($candidate['brand'] !== $current['brand']) {
            return false;
        }

        // Prefer more specific within same family when both matched.
        return $candidate['specificity'] < $current['specificity'];
    }

    /**
     * @return array{brand:string,model:string,specificity:int,aliases:list<string>}|null
     */
    private function proMaxUpgrade(string $model): ?array
    {
        $map = [
            'iPhone 12 Pro' => 'iphone_12_pro_max',
            'iPhone 13 Pro' => 'iphone_13_pro_max',
            'iPhone 14 Pro' => 'iphone_14_pro_max',
            'iPhone 15 Pro' => 'iphone_15_pro_max',
            'iPhone 16 Pro' => 'iphone_16_pro_max',
            'iPhone 17 Pro' => 'iphone_17_pro_max',
        ];

        $key = $map[$model] ?? null;

        return $key ? $this->catalog[$key] : null;
    }

    /**
     * Shop catalog titles listing many gens at once ("14Pro.15Pro.17Pro Max...").
     */
    private function isMultiModelCatalog(string $text): bool
    {
        $keys = [];

        if (preg_match_all('/(?:iphone|айфон)\s*(\d{1,2})\s*(pro\s*max|promax|pro|plus|mini|e)?/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $keys[$m[1].'|'.($m[2] ?? '')] = true;
            }
        }

        // Compact catalogs: "14Pro.15Pro.17Pro Max" without repeating "iphone"
        if (preg_match_all('/(?<![a-z0-9])(\d{1,2})\s*(pro\s*max|promax|pro|plus)(?![a-z0-9])/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $gen = (int) $m[1];
                if ($gen >= 11 && $gen <= 17) {
                    $keys[$m[1].'|'.$m[2]] = true;
                }
            }
        }

        // Bare gens in promo lists: "17e 17 16 16e 15"
        if (preg_match('/iphone|айфон/u', $text)
            && preg_match_all('/(?<![a-z0-9])(1[1-7])e?(?![0-9])/u', $text, $gens)) {
            $uniqueGens = array_unique($gens[1]);
            if (count($uniqueGens) >= 3) {
                return true;
            }
        }

        // "Vând iPhone 11 / schimb pe 12" — two gens + exchange = ambiguous for market.
        if (preg_match('/schimb|обмен|trade/u', $text)) {
            $gens = [];
            if (preg_match_all('/(?:iphone|айфон)\s*(1[1-7])/u', $text, $m)) {
                foreach ($m[1] as $g) {
                    $gens[$g] = true;
                }
            }
            if (preg_match_all('/(?:schimb|обмен|trade)[^\d]{0,12}(?:pe|на|on)?[^\d]{0,8}(1[1-7])/u', $text, $m)) {
                foreach ($m[1] as $g) {
                    $gens[$g] = true;
                }
            }
            if (count($gens) >= 2) {
                return true;
            }
        }

        return count($keys) >= 3;
    }

    /** Title mentions an iPhone generation even if we don't catalog it (7, 8, 11…). */
    private function titleNamesIphoneGeneration(string $title): bool
    {
        return (bool) preg_match('/(?:iphone|айфон)\s*\d{1,2}/u', $title);
    }

    private function looksLikeOtherDevice(string $title): bool
    {
        return (bool) preg_match(
            '/\b(samsung|galaxy|fold|flip|xiaomi|redmi|poco|pixel|huawei|honor|oppo|realme|motorola|nokia|sony|oneplus)\b/u',
            $title
        ) && ! preg_match('/\b(iphone|айфон)\b/u', $title);
    }

    private function extractStorage(string $text): ?int
    {
        if (preg_match('/\b(64|128|256|512|1024|1)\s*(tb|тб|gb|гб)\b/u', $text, $m)) {
            $n = (int) $m[1];
            if (str_contains($m[2], 't') || str_contains($m[2], 'т')) {
                return 1024;
            }

            return $n === 1 ? 1024 : $n;
        }

        return null;
    }

    private function extractBattery(string $text): ?int
    {
        if (preg_match('/(?:battery|батар|bh|health|ёмкост|емкост)[^\d]{0,12}(\d{2,3})\s*%/u', $text, $m)
            || preg_match('/\b(\d{2,3})\s*%/u', $text, $m)) {
            $val = (int) $m[1];
            if ($val >= 70 && $val <= 100) {
                return $val;
            }
        }

        return null;
    }
}
