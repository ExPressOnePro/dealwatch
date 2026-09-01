<?php

namespace App\Services\Ai;

/**
 * Разобранный ответ модели плюс всё, что нужно для учёта расходов.
 */
readonly class AiResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public string $model,
        public string $tier,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public float $costUsd = 0.0,
        public bool $cached = false,
    ) {}
}
