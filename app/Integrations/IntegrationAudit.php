<?php

namespace App\Integrations;

use App\Models\AuditLog;
use App\Models\Integration;

/**
 * The audit entry for a change to a connected system. An integration is keyed by name, not by id, so the entry carries the key
 * in its values ("integration") and the screens find the history of one system by it.
 */
class IntegrationAudit
{
    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>|null  $old
     */
    public static function record(string $key, string $action, ?string $userId, array $new = [], ?array $old = null, ?string $ip = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => $userId, 'action' => $action, 'auditable_type' => Integration::class, 'auditable_id' => null,
            'old_values' => $old === null ? null : ['integration' => $key] + $old, 'new_values' => ['integration' => $key] + $new, 'ip_address' => $ip,
        ]);
    }
}
