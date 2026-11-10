<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One punch received from a device. */
#[Fillable(['device_id', 'person_ref', 'punched_at', 'direction', 'raw', 'processed_at', 'outcome', 'attendance_id'])]
class DevicePunch extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['punched_at' => 'datetime', 'raw' => 'array', 'processed_at' => 'datetime'];
    }
}
