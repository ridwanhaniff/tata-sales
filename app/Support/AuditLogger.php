<?php

namespace App\Support;

use App\Models\AuditLog;

class AuditLogger
{
    /**
     * Catat aksi kritikal ke audit_logs (action konvensi: promo.updated,
     * lead.reassigned, dst).
     */
    public static function log(
        string $action,
        string $entityType,
        ?string $entityId = null,
        array $before = [],
        array $after = [],
        string $actorType = 'user'
    ): AuditLog {
        return AuditLog::create([
            'actor_id' => auth()->id() ?: null,
            'actor_type' => $actorType,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_data' => $before !== [] ? $before : null,
            'after_data' => $after !== [] ? $after : null,
            'ip_address' => request() ? request()->ip() : null,
        ]);
    }
}
