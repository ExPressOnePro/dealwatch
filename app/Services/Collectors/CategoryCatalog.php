<?php

namespace App\Services\Collectors;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Дерево категорий 999.md — чтобы источники настраивались выбором из списка,
 * а не вводом числового id наугад. Названия площадка отдаёт по-румынски.
 */
class CategoryCatalog
{
    private const CACHE_KEY = 'dealwatch:999:categories';

    private const QUERY = <<<'GRAPHQL'
query CategoryTree($input: GetCategoryTreeRequestInput!) {
  categoryTree(input: $input) {
    categories {
      id
      title { translated }
      categories { id title { translated } }
    }
  }
}
GRAPHQL;

    /**
     * @return list<array{id: int, label: string, children: list<array{id: int, label: string}>}>
     */
    public function tree(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addDay(), fn () => $this->fetch());
    }

    /**
     * @return list<array{id: int, label: string, children: list<array{id: int, label: string}>}>
     */
    private function fetch(): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                    'Origin' => 'https://999.md',
                ])
                ->post('https://999.md/graphql', [
                    'query' => self::QUERY,
                    'variables' => ['input' => ['id' => 0]],
                ]);
        } catch (\Throwable $e) {
            Log::warning('999 category tree failed: '.$e->getMessage());

            return [];
        }

        if (! $response->successful()) {
            Log::warning('999 category tree failed', ['status' => $response->status()]);

            return [];
        }

        return collect($response->json('data.categoryTree.categories') ?? [])
            ->map(fn (array $section) => [
                'id' => (int) $section['id'],
                'label' => (string) data_get($section, 'title.translated', $section['id']),
                'children' => collect($section['categories'] ?? [])
                    ->map(fn (array $child) => [
                        'id' => (int) $child['id'],
                        'label' => (string) data_get($child, 'title.translated', $child['id']),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
