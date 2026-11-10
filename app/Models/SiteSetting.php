<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value', 'updated_by'])]
class SiteSetting extends Model
{
    use Auditable;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['value' => 'array'];

    /** Credentials stored in settings (e.g. the Firebase service account) never reach the audit log. */
    public function auditValues(array $values): array
    {
        if (isset($values['value'])) {
            $decoded = is_string($values['value']) ? json_decode($values['value'], true) : $values['value'];
            if (is_array($decoded) && array_key_exists('service_account', $decoded)) {
                $decoded['service_account'] = $decoded['service_account'] ? '[redacted]' : null;
                $values['value'] = $decoded;
            }
        }

        return $values;
    }
}
