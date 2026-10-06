<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role_slug', 'widgets'])]
class DashboardPreset extends Model
{
    use HasUuids;

    protected $casts = ['widgets' => 'array'];
}
