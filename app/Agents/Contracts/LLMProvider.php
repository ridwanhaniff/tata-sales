<?php

namespace App\Agents\Contracts;

use App\Agents\Values\ClassificationResult;
use App\Agents\Values\LLMResponse;

/**
 * Provider abstraction (§65) — semua agent bergantung pada interface ini,
 * bukan pada SDK provider tertentu. Ganti provider = ganti config/llm.php.
 */
interface LLMProvider
{
    public function generate(string $prompt, array $context = [], array $options = []): LLMResponse;

    public function classify(string $input, array $labels, array $context = []): ClassificationResult;

    public function extract(string $input, array $schema, array $context = []): array;
}
