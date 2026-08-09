<?php

namespace App\Agents\Values;

class ClassificationResult
{
    public function __construct(
        public readonly string $label,
        public readonly float $confidence,
        public readonly array $raw = [],
    ) {}
}
