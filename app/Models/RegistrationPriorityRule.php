<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Ordered criteria that rank candidates and the waiting list. */
#[Fillable(['scope', 'scope_id', 'criteria', 'weights', 'is_active'])]
class RegistrationPriorityRule extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['criteria' => 'array', 'weights' => 'array', 'is_active' => 'boolean'];
    }
}
