<?php

namespace App\Agents\Tools\Contracts;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /**
     * JSON Schema parameter tool (OpenAI-style: type/properties/required).
     */
    public function parameters(): array;

    /**
     * Eksekusi tool. Implementasi hanya boleh memakai whitelist argumen —
     * apapun kunci argument lain dari LLM/user diabaikan.
     */
    public function execute(array $arguments): array;
}
