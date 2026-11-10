<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

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

    /** Hook to redact model-specific sensitive values before they are logged. */
    public function auditValues(array $values): array
    {
        return $values;
    }

    protected static function writeAudit(Model $model, string $action, array $old, array $new): void
    {
        $strip = fn (array $values) => $model->auditValues(array_diff_key($values, array_flip(static::$auditExcluded)));

        // audit_logs.auditable_id is a UUID column; models keyed by a string (e.g. site settings) keep their
        // key in the logged values instead, which PostgreSQL would otherwise reject.
        $key = $model->getKey();
        $uuidKey = is_string($key) && Str::isUuid($key);
        $newValues = $strip($new);
        if (! $uuidKey && $key !== null) {
            $newValues = [$model->getKeyName() => $key] + $newValues;
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $uuidKey ? $key : null,
            'old_values' => $strip($old) ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
            'url' => substr((string) Request::fullUrl(), 0, 255),
        ]);
    }
}
