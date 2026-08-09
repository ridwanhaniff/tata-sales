<?php

namespace App\Agents\Contracts;

use App\Agents\AgentContext;
use App\Agents\Tools\Contracts\Tool;

interface AgentInterface
{
    public function name(): string;

    /**
     * @return Tool[]
     */
    public function tools(): array;

    public function handle(AgentContext $context): array;
}
