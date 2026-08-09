<?php

namespace App\Agents\Values;

class ToolCall
{
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
    ) {}
}
