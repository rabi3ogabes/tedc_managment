<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class EmployeeSkill extends Pivot
{
    use HasUuids;

    protected $table = 'employee_skills';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['verified_at' => 'datetime', 'level' => 'integer'];
}
