<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['session_id', 'email', 'display_name', 'role', 'total_seconds', 'intervals', 'employee_id', 'registration_id', 'minutes', 'percent', 'applied'])]
class TeamsAttendanceRecord extends Model
{
    use HasUuids;

    protected $casts = ['intervals' => 'array', 'applied' => 'boolean', 'percent' => 'float'];
}
