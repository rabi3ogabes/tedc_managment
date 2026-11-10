<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A fingerprint / badge device that sends punches. */
#[Fillable(['name', 'vendor', 'serial', 'location_room_id', 'api_config', 'last_sync_at', 'status', 'last_error'])]
class AttendanceDevice extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['api_config' => 'encrypted:array', 'last_sync_at' => 'datetime'];
    }
}
