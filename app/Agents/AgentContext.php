<?php

namespace App\Agents;

use App\Models\Tenant;

/**
 * Konteks satu percakapan agent. tenant_id di-bind dari session/context
 * server (§118) — tidak pernah berasal dari input yang bisa dipengaruhi LLM.
 */
class AgentContext
{
    /**
     * @param  array<string, mixed>  $meta  konteks percakapan (customer/product/lead snapshot)
     * @param  array<int, array{role: string, content: string}>  $history  riwayat pesan
     */
    public function __construct(
        public readonly string $message,
        public readonly ?Tenant $tenant = null,
        public readonly ?string $conversationId = null,
        public readonly ?string $leadId = null,
        public readonly array $meta = [],
        public readonly array $history = [],
    ) {}

    public function tenantId(): ?string
    {
        return $this->tenant?->id;
    }
}
