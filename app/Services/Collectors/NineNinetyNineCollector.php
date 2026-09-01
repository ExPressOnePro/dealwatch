<?php

namespace App\Services\Collectors;

use App\Models\SearchProfile;
use App\Services\CurrencyRateService;
use App\Services\ListingKindClassifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NineNinetyNineCollector
{
    private const GRAPHQL_URL = 'https://999.md/graphql';

    /** Mobile phones subcategory on 999.md */
    private const SUBCATEGORY_ID = 40;

    /** Значение поля «Тип предложения» = «Продам / Vând». */
    private const OFFER_TYPE_SELL = 776;

    /**
     * Приватный GraphQL 999.md. id полей (2 — цена, 8 — город, 13 — текст,
     * 14 — фото, 16 — телефоны, 590 — «Модель», 593 — состояние, 795 — автор,
     * 1078 — память, 2200 — АКБ) заданы площадкой и могут смениться без
     * предупреждения: тогда выдача просто станет пустой, за этим следит
     * CollectorHealthMonitor.
     */
    private const SEARCH_QUERY = <<<'GRAPHQL'
query SearchAds($input: Ads_SearchInput!) {
  searchAds(input: $input) {
    count
    ads {
      id
      title
      reseted(
        input: {
          format: "2006-01-02 15:04:05"
          locale: ru_RU
          timezone: "Europe/Chisinau"
          getDiff: false
        }
      )
      price: feature(id: 2) { value }
      city: feature(id: 8) { value }
      author: feature(id: 795) { value }
      condition: feature(id: 593) { value }
      offerType: feature(id: 1) { value }
      body: feature(id: 13) { value }
      phoneNumbers: feature(id: 16) { value }
      images: feature(id: 14) { value }
      storage: feature(id: 1078) { value }
      battery: feature(id: 2200) { value }
      siteModel: feature(id: 590) { value }
      owner {
        id
        login
        business { id plan }
      }
    }
  }
}
GRAPHQL;

    private const AUTHOR_PRIVATE = 18895;

    private const AUTHOR_SHOP = 37797;

    public function __construct(
        private readonly CurrencyRateService $currency,
        private readonly ListingKindClassifier $kinds,
    ) {}

    /**
     * Сбор по настроенному источнику: своя категория, ключевые слова и границы цены.
     *
     * @return list<array<string, mixed>>
     */
    public function collectForProfile(SearchProfile $profile, ?int $limit = null): array
    {
        $limit ??= max(1, (int) $profile->per_run);

        $byId = [];
        foreach ([null, 'CURRENCY_ADS_EUR', 'CURRENCY_ADS_USD'] as $currency) {
            foreach ($this->searchPage($limit, 0, $currency, $profile)['ads'] as $ad) {
                $id = (string) ($ad['id'] ?? '');
                if ($id !== '') {
                    $byId[$id] = $ad;
                }
            }
        }

        $results = [];
        foreach ($byId as $ad) {
            $mapped = $this->mapAd($ad);

            if (! $mapped) {
                continue;
            }

            if ($profile->isExcluded((string) $mapped['title'], $mapped['description'] ?? null)) {
                continue;
            }

            $price = $mapped['price_mdl'] ?? null;

            if ($profile->price_min && (! $price || $price < $profile->price_min)) {
                continue;
            }

            if ($profile->price_max && (! $price || $price > $profile->price_max)) {
                continue;
            }

            $mapped['search_profile_id'] = $profile->id;
            $results[] = $mapped;
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collect(?int $limit = null): array
    {
        $limit ??= (int) config('dealwatch.collector.limit', 40);

        $ads = $this->search($limit);
        // Also pull EUR/USD-priced listings explicitly (999 currency switch)
        $byId = [];
        foreach ($ads as $ad) {
            $byId[(string) ($ad['id'] ?? '')] = $ad;
        }
        foreach (['CURRENCY_ADS_EUR', 'CURRENCY_ADS_USD'] as $currency) {
            foreach ($this->search($limit, $currency) as $ad) {
                $id = (string) ($ad['id'] ?? '');
                if ($id !== '') {
                    $byId[$id] = $ad;
                }
            }
        }

        $results = [];
        foreach (array_values($byId) as $ad) {
            $mapped = $this->mapAd($ad);
            if ($mapped) {
                $results[] = $mapped;
            }
        }

        return $results;
    }

    /**
     * Перепись каталога источника: идём постранично, пока не наберём $depth
     * объявлений или каталог не кончится. Нужна, чтобы видеть, какие объявления
     * ещё висят, — по этому считается скорость продаж в нише.
     *
     * @param  callable(list<array<string, mixed>> $batch, int $seen, int $total): void  $onBatch
     * @return array{seen:int, total:int}
     */
    public function scanProfile(SearchProfile $profile, int $depth, callable $onBatch): array
    {
        $pageSize = 50;
        $skip = 0;
        $seen = 0;
        $total = null;

        while ($seen < $depth) {
            $page = $this->searchPage(min($pageSize, $depth - $seen), $skip, null, $profile);
            $total ??= $page['count'];
            $ads = $page['ads'];

            if ($ads === []) {
                break;
            }

            $batch = [];
            foreach ($ads as $ad) {
                $mapped = $this->mapAd($ad);

                if (! $mapped) {
                    continue;
                }

                if ($profile->isExcluded((string) $mapped['title'], $mapped['description'] ?? null)) {
                    continue;
                }

                unset($mapped['raw_data']);
                $mapped['search_profile_id'] = $profile->id;
                $batch[] = $mapped;
            }

            $seen += count($ads);
            $onBatch($batch, $seen, (int) $total);

            $skip += $pageSize;

            if ($skip >= (int) $total) {
                break;
            }

            usleep(250_000);
        }

        return ['seen' => $seen, 'total' => (int) ($total ?? 0)];
    }

    /**
     * Backfill active catalog pages until ads are older than $since.
     * Yields/returns mapped ads page-by-page via callback to avoid OOM.
     *
     * @param  callable(list<array<string, mixed>> $batch, int $fetched, int $total, ?string $oldestDate): void  $onBatch
     * @return array{fetched:int, total:int, oldest:?string}
     */
    public function collectSince(
        \DateTimeInterface $since,
        int $pageSize = 50,
        ?callable $onProgress = null,
        ?callable $onBatch = null,
    ): array {
        $pageSize = max(10, min(50, $pageSize));
        $skip = 0;
        $fetched = 0;
        $total = null;
        $oldestSeen = null;
        $emptyStreak = 0;

        while (true) {
            $page = $this->searchPage($pageSize, $skip);
            if ($total === null) {
                $total = $page['count'];
            }
            $ads = $page['ads'];
            if ($ads === []) {
                $emptyStreak++;
                if ($emptyStreak >= 3 || $skip >= (int) $total) {
                    break;
                }
                usleep(400_000);
                $skip += $pageSize;

                continue;
            }
            $emptyStreak = 0;

            $batch = [];
            $stop = false;
            foreach ($ads as $ad) {
                $mapped = $this->mapAd($ad);
                if (! $mapped) {
                    continue;
                }
                // Drop heavy nested payload after mapping essentials for bulk loads
                if ($onBatch) {
                    unset($mapped['raw_data']);
                }
                $published = $mapped['published_at'] ?? null;
                if ($published instanceof \DateTimeInterface) {
                    $oldestSeen = $published->format('Y-m-d H:i:s');
                    if ($published < $since) {
                        $stop = true;

                        continue;
                    }
                }
                $batch[] = $mapped;
            }

            $fetched += count($batch);

            if ($onBatch && $batch !== []) {
                $onBatch($batch, $fetched, (int) $total, $oldestSeen);
            }

            if ($onProgress) {
                $onProgress($fetched, (int) $total, $oldestSeen);
            }

            $skip += $pageSize;
            if ($stop || $skip >= (int) $total) {
                break;
            }

            usleep(120_000);
        }

        return [
            'fetched' => $fetched,
            'total' => (int) $total,
            'oldest' => $oldestSeen,
        ];
    }

    /**
     * @deprecated Prefer collectSince with onBatch for large loads
     *
     * @return list<array<string, mixed>>
     */
    public function collectSinceAll(\DateTimeInterface $since, int $pageSize = 50, ?callable $onProgress = null): array
    {
        $all = [];
        $this->collectSince($since, $pageSize, $onProgress, function (array $batch) use (&$all) {
            foreach ($batch as $item) {
                $all[$item['external_id']] = $item;
            }
        });

        return array_values($all);
    }

    /**
     * @return array{count:int, ads:list<array<string, mixed>>}
     */
    private function searchPage(int $limit, int $skip, ?string $currency = null, ?SearchProfile $profile = null): array
    {
        $input = [
            'sort' => 'SORT_ADS_DATE_DESC',
            'pagination' => [
                'skip' => $skip,
                'limit' => $limit,
            ],
        ];

        if ($profile?->subcategory_id) {
            $input['subCategoryId'] = (int) $profile->subcategory_id;
        } elseif ($profile?->category_id) {
            $input['categoryId'] = (int) $profile->category_id;
        } elseif (! $profile) {
            // Без источника работаем по историческому поведению — мобильные телефоны.
            $input['subCategoryId'] = self::SUBCATEGORY_ID;
        }

        if ($profile && filled($profile->query)) {
            $input['query'] = (string) $profile->query;
        }

        if ($currency) {
            $input['currency'] = $currency;
        }

        try {
            $response = Http::timeout(45)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Origin' => 'https://999.md',
                    'Referer' => 'https://999.md/ru/list/phone-and-communication/mobile-phones',
                ])
                ->post(self::GRAPHQL_URL, [
                    'query' => self::SEARCH_QUERY,
                    'variables' => ['input' => $input],
                ]);
        } catch (\Throwable $e) {
            // Сеть недоступна — для нас это такой же пустой прогон, как и смена схемы:
            // шедулер не должен падать, а CollectorHealthMonitor обязан это заметить.
            Log::warning('999 search request failed', ['error' => $e->getMessage(), 'skip' => $skip]);

            return ['count' => 0, 'ads' => []];
        }

        if (! $response->successful()) {
            Log::warning('999 backfill page failed', ['status' => $response->status(), 'skip' => $skip]);

            return ['count' => 0, 'ads' => []];
        }

        $json = $response->json();
        if (! empty($json['errors'])) {
            Log::warning('999 backfill GraphQL errors', ['errors' => $json['errors'], 'skip' => $skip]);

            return ['count' => 0, 'ads' => []];
        }

        return [
            'count' => (int) ($json['data']['searchAds']['count'] ?? 0),
            'ads' => $json['data']['searchAds']['ads'] ?? [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(int $limit, ?string $currency = null): array
    {
        $page = $this->searchPage($limit, 0, $currency);
        $ads = $page['ads'];

        if ($ads === [] && ! $currency) {
            return $this->searchWithoutFilter($limit);
        }

        return $ads;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchWithoutFilter(int $limit): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post(self::GRAPHQL_URL, [
                    'query' => self::SEARCH_QUERY,
                    'variables' => [
                        'input' => [
                            'subCategoryId' => self::SUBCATEGORY_ID,
                            'sort' => 'SORT_ADS_DATE_DESC',
                            'pagination' => ['skip' => 0, 'limit' => $limit],
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('999 fallback search failed: '.$e->getMessage());

            return [];
        }

        $ads = $response->json('data.searchAds.ads') ?? [];

        return array_values(array_filter($ads, function (array $ad) {
            $offer = data_get($ad, 'offerType.value.value')
                ?? data_get($ad, 'offerType.value');

            return (int) $offer === self::OFFER_TYPE_SELL;
        }));
    }

    /**
     * @param  array<string, mixed>  $ad
     * @return array<string, mixed>|null
     */
    private function mapAd(array $ad): ?array
    {
        $id = (string) ($ad['id'] ?? '');
        $title = trim((string) ($ad['title'] ?? ''));
        if ($id === '' || $title === '') {
            return null;
        }

        $priced = $this->extractPrice($ad);
        $description = $this->extractText(data_get($ad, 'body.value'));
        $location = $this->extractCity(data_get($ad, 'city.value'));
        $condition = $this->extractOption(data_get($ad, 'condition.value'));
        $phone = $this->extractPhone(data_get($ad, 'phoneNumbers.value'));
        $images = $this->extractImages(data_get($ad, 'images.value'));
        $storage = $this->extractStorage(data_get($ad, 'storage.value'));
        $battery = $this->extractBattery(data_get($ad, 'battery.value'));
        $siteModel = $this->extractOption(data_get($ad, 'siteModel.value'));

        $isBusiness = (bool) data_get($ad, 'owner.business.id');
        $author = data_get($ad, 'author.value');
        $authorOption = is_array($author) ? (int) ($author['value'] ?? 0) : 0;
        if ($authorOption === self::AUTHOR_SHOP) {
            $isBusiness = true;
        } elseif ($authorOption === self::AUTHOR_PRIVATE) {
            $isBusiness = false;
        } elseif (is_array($author) && isset($author['translated'])) {
            $sellerHint = mb_strtolower((string) $author['translated']);
            if (str_contains($sellerHint, 'магазин') || str_contains($sellerHint, 'magazin') || str_contains($sellerHint, 'business')) {
                $isBusiness = true;
            }
            if (str_contains($sellerHint, 'част') || str_contains($sellerHint, 'privat') || str_contains($sellerHint, 'fizic')) {
                $isBusiness = false;
            }
        }

        $offer = data_get($ad, 'offerType.value.value') ?? data_get($ad, 'offerType.value');
        if ($offer !== null && (int) $offer !== self::OFFER_TYPE_SELL) {
            // keep non-sell out of market DB
            return null;
        }

        $currency = $priced['currency'] ?? 'MDL';
        $priceOriginal = $priced['amount'] ?? null;
        $priceMdl = $priceOriginal !== null
            ? $this->currency->toMdl($priceOriginal, $currency)
            : null;

        $listingKind = $this->kinds->classify($title, $description);

        $publishedAt = now();
        if (! empty($ad['reseted']) && is_string($ad['reseted'])) {
            try {
                $publishedAt = Carbon::createFromFormat(
                    'Y-m-d H:i:s',
                    $ad['reseted'],
                    'Europe/Chisinau'
                ) ?: Carbon::parse($ad['reseted'], 'Europe/Chisinau');
            } catch (\Throwable) {
                try {
                    $publishedAt = Carbon::parse($ad['reseted'], 'Europe/Chisinau');
                } catch (\Throwable) {
                    $publishedAt = now();
                }
            }
        }

        return [
            'platform' => '999',
            'external_id' => $id,
            'url' => "https://999.md/ru/{$id}",
            'title' => $title,
            'site_model' => $siteModel,
            'description' => $description,
            'price_original' => $priceOriginal,
            'price_mdl' => $priceMdl,
            'currency' => $currency,
            'seller_name' => data_get($ad, 'owner.login'),
            'seller_phone' => $phone,
            'seller_type' => $isBusiness ? 'shop' : 'private',
            'listing_kind' => $listingKind,
            'location' => $location,
            'images' => $images,
            'published_at' => $publishedAt,
            'condition' => $condition,
            'storage_gb' => $storage,
            'battery_health' => $battery,
            'raw_data' => $ad,
        ];
    }

    /**
     * Authoritative ad payload: HTML JSON-LD (description, region) + GraphQL extras.
     *
     * @return array<string, mixed>|null
     */
    public function fetchDetail(string $id): ?array
    {
        $id = preg_replace('/\D+/', '', $id);
        if ($id === '') {
            return null;
        }

        $htmlDetail = $this->fetchDetailFromHtml($id);

        $graphDetail = null;
        try {
            $response = Http::timeout(25)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                    'Accept' => 'application/json',
                ])
                ->post(self::GRAPHQL_URL, [
                    'query' => 'query($input: Ad_AdIDsInput) { getAdsByIds(input: $input) { id title price: feature(id: 2) { value } city: feature(id: 8) { value } author: feature(id: 795) { value } condition: feature(id: 593) { value } body: feature(id: 13) { value } phoneNumbers: feature(id: 16) { value } images: feature(id: 14) { value } storage: feature(id: 1078) { value } battery: feature(id: 2200) { value } siteModel: feature(id: 590) { value } owner { id login business { id plan } } } }',
                    'variables' => [
                        'input' => ['ids' => [(int) $id]],
                    ],
                ]);

            $ad = $response->json('data.getAdsByIds.0');
            if (is_array($ad)) {
                $graphDetail = $this->mapAd($ad);
            }
        } catch (\Throwable) {
            // HTML JSON-LD is enough for description/region.
        }

        if (! $htmlDetail && ! $graphDetail) {
            return null;
        }

        return $this->mergeDetailSources($id, $graphDetail, $htmlDetail);
    }

    /**
     * @param  array<string, mixed>|null  $graph
     * @param  array<string, mixed>|null  $html
     * @return array<string, mixed>
     */
    private function mergeDetailSources(string $id, ?array $graph, ?array $html): array
    {
        $base = $graph ?? $html ?? [];

        return array_merge($base, [
            'platform' => '999',
            'external_id' => $id,
            'url' => "https://999.md/ru/{$id}",
            'title' => $html['title'] ?? $graph['title'] ?? null,
            'description' => $html['description'] ?? $graph['description'] ?? null,
            'location' => $html['location'] ?? $graph['location'] ?? null,
            'price_original' => $html['price_original'] ?? $graph['price_original'] ?? null,
            'price_mdl' => $html['price_mdl'] ?? $graph['price_mdl'] ?? null,
            'currency' => $html['currency'] ?? $graph['currency'] ?? 'MDL',
            'seller_name' => $graph['seller_name'] ?? null,
            'seller_phone' => $graph['seller_phone'] ?? null,
            'seller_type' => $graph['seller_type'] ?? null,
            'images' => $graph['images'] ?? $html['images'] ?? null,
            'published_at' => $graph['published_at'] ?? now(),
            'raw_data' => array_merge(
                (array) ($graph['raw_data'] ?? []),
                ['html_detail' => $html['raw_data'] ?? null]
            ),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDetailFromHtml(string $id): ?array
    {
        $url = "https://999.md/ru/{$id}";

        try {
            $response = Http::timeout(25)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('999 detail page failed for '.$id.': '.$e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();
        $jsonLd = $this->parseProductJsonLd($html);

        $title = $jsonLd['title'] ?? null;
        if (! $title && preg_match('/property="og:title"\s+content="([^"]+)"/u', $html, $m)) {
            $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (! $title) {
            return null;
        }

        $description = $jsonLd['description'] ?? null;
        if (! $description && preg_match('/property="og:description"\s+content="([^"]+)"/u', $html, $m)) {
            $description = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($description) {
            $description = trim(str_replace(['\\n', '\n'], "\n", $description));
        }

        $location = $jsonLd['location'] ?? null;

        $price = $jsonLd['price_original'] ?? null;
        $currency = $jsonLd['currency'] ?? 'MDL';

        if ($price === null) {
            if (preg_match('/(\d[\d\s.,]{1,})\s*(€|EUR|euro)/iu', $html, $m)) {
                $price = (int) round((float) str_replace([' ', ','], ['', '.'], $m[1]));
                $currency = 'EUR';
            } elseif (preg_match('/(\d[\d\s.,]{1,})\s*(\$|USD)/iu', $html, $m)) {
                $price = (int) round((float) str_replace([' ', ','], ['', '.'], $m[1]));
                $currency = 'USD';
            } elseif (preg_match('/(\d[\d\s]{2,})\s*(?:MDL|лей|lei)/iu', $html, $m)) {
                $price = (int) preg_replace('/\s+/', '', $m[1]);
            }
        }

        $priceMdl = $price !== null
            ? $this->currency->toMdl($price, $currency)
            : null;

        return [
            'platform' => '999',
            'external_id' => $id,
            'url' => $url,
            'title' => $title,
            'description' => $description,
            'location' => $location,
            'price_original' => $price,
            'price_mdl' => $priceMdl,
            'currency' => $currency,
            'published_at' => now(),
            'images' => $jsonLd['images'] ?? null,
            'raw_data' => ['source' => 'html_jsonld', 'jsonld' => $jsonLd['raw'] ?? null],
        ];
    }

    /**
     * @return array{title:?string,description:?string,location:?string,price_original:?int,currency:string,images:?list<string>,raw:?array}|null
     */
    private function parseProductJsonLd(string $html): ?array
    {
        if (! preg_match_all('/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $chunk) {
            $data = json_decode(html_entity_decode(trim($chunk), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (! is_array($data)) {
                continue;
            }

            $product = ($data['@type'] ?? null) === 'Product' ? $data : null;
            if (! $product && isset($data[0])) {
                foreach ($data as $node) {
                    if (is_array($node) && ($node['@type'] ?? null) === 'Product') {
                        $product = $node;
                        break;
                    }
                }
            }

            if (! $product) {
                continue;
            }

            $description = isset($product['description']) ? trim((string) $product['description']) : null;
            $location = isset($product['displayLocation']) ? trim((string) $product['displayLocation']) : null;

            $price = null;
            $currency = 'MDL';
            if (isset($product['offers']['price'])) {
                $price = (int) round((float) $product['offers']['price']);
                $currency = (string) ($product['offers']['priceCurrency'] ?? 'MDL');
            }

            $images = null;
            if (isset($product['image'])) {
                $images = is_array($product['image']) ? array_values($product['image']) : [$product['image']];
            }

            return [
                'title' => isset($product['name']) ? trim((string) $product['name']) : null,
                'description' => $description,
                'location' => $location,
                'price_original' => $price,
                'currency' => $currency,
                'images' => $images,
                'raw' => $product,
            ];
        }

        return null;
    }

    /**
     * @return array{amount:?int,currency:string}
     */
    private function extractPrice(array $ad): array
    {
        $value = data_get($ad, 'price.value');
        $amount = null;
        $unit = null;

        if (is_array($value)) {
            if (isset($value['value']) && is_numeric($value['value'])) {
                $amount = (int) round((float) $value['value']);
            }
            $unit = $value['unit'] ?? $value['measurement'] ?? null;
        } elseif (is_numeric($value)) {
            $amount = (int) round((float) $value);
        }

        $currency = $this->currency->normalizeUnit(is_string($unit) ? $unit : null);

        return [
            'amount' => $amount,
            'currency' => $currency,
        ];
    }

    private function extractText(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return strip_tags($value);
        }
        if (is_array($value)) {
            if (isset($value['translated'])) {
                return (string) $value['translated'];
            }
            if (isset($value['value']) && is_string($value['value'])) {
                return strip_tags($value['value']);
            }
        }

        return null;
    }

    private function extractOption(mixed $value): ?string
    {
        if (is_array($value)) {
            return $value['translated'] ?? (isset($value['value']) && is_scalar($value['value']) ? (string) $value['value'] : null);
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function extractCity(mixed $value): ?string
    {
        if (is_array($value)) {
            return $value['translated']
                ?? data_get($value, 'title.translated')
                ?? data_get($value, 'value.translated')
                ?? (is_string($value['value'] ?? null) ? $value['value'] : null)
                ?? $value['name']
                ?? null;
        }

        return is_string($value) ? $value : null;
    }

    private function extractPhone(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/\+?\d[\d\s\-]{7,}/', $value, $m)) {
            return preg_replace('/[^\d+]/', '', $m[0]);
        }

        if (is_array($value)) {
            $flat = json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
            if (preg_match('/(\+?373[\d\s\-]{7,}|\b0\d{7,9}\b)/', $flat, $m)) {
                return preg_replace('/[^\d+]/', '', $m[1]);
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function extractImages(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $urls = [];
        // Без JSON_UNESCAPED_SLASHES ссылки выглядят как https:\/\/… и regex их не находит.
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        if (preg_match_all('#https?://[^"\']+\.(?:jpg|jpeg|png|webp)#i', $json, $m)) {
            $urls = array_values(array_unique($m[0]));
        }

        return $urls ?: null;
    }

    private function extractStorage(mixed $value): ?int
    {
        $text = is_array($value)
            ? (string) ($value['translated'] ?? $value['value'] ?? json_encode($value))
            : (string) $value;

        if (preg_match('/\b(64|128|256|512|1024)\b/', $text, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function extractBattery(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $n = (int) $value;

            return ($n >= 70 && $n <= 100) ? $n : null;
        }

        $text = is_array($value)
            ? (string) ($value['translated'] ?? $value['value'] ?? '')
            : (string) $value;

        if (preg_match('/(\d{2,3})\s*%/', $text, $m)) {
            $n = (int) $m[1];

            return ($n >= 70 && $n <= 100) ? $n : null;
        }

        return null;
    }
}
