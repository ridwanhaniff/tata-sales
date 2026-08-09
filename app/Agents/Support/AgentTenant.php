<?php

namespace App\Agents\Support;

use App\Agents\AgentContext;

class AgentTenant
{
    /**
     * tenant_id selalu dari context server (session/request), tidak pernah
     * dari parameter yang bisa dipengaruhi LLM/user (§118).
     */
    public static function resolve(AgentContext $context): ?string
    {
        if ($context->tenantId() !== null) {
            return $context->tenantId();
        }

        if (app()->bound('currentTenant')) {
            return app('currentTenant')->id;
        }

        return null;
    }
}
