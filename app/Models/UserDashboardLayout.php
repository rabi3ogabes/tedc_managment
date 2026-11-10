<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'role_slug', 'layout'])]
class UserDashboardLayout extends Model
{
    use HasUuids;

    protected $casts = ['layout' => 'array'];
}
