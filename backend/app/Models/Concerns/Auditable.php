<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Records create / update / delete events for the model in `audit_logs`.
 * Sensitive attributes (passwords, secrets, encrypted identifiers) are never persisted.
 */
trait Auditable
{
    protected static array $auditExcluded = ['password', 'remember_token', 'qr_secret', 'national_id', 'updated_at', 'created_at'];

    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => static::writeAudit($model, 'created', [], $model->getAttributes()));
        static::updated(function (Model $model) {
            $changes = $model->getChanges();
            if (empty(array_diff(array_keys($changes), static::$auditExcluded))) {
                return;
            }
            static::writeAudit($model, 'updated', array_intersect_key($model->getOriginal(), $changes), $changes);
        });
        static::deleted(fn (Model $model) => static::writeAudit($model, 'deleted', $model->getOriginal(), []));
    }

    protected static function writeAudit(Model $model, string $action, array $old, array $new): void
    {
        $strip = fn (array $values) => array_diff_key($values, array_flip(static::$auditExcluded));

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $strip($old) ?: null,
            'new_values' => $strip($new) ?: null,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
            'url' => substr((string) Request::fullUrl(), 0, 255),
        ]);
    }
}
