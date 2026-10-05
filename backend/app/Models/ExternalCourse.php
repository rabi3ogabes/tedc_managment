<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['provider', 'external_id', 'title', 'url', 'hours', 'meta', 'program_id', 'synced_at'])]
class ExternalCourse extends Model
{
    use HasUuids;

    protected $casts = ['meta' => 'array', 'synced_at' => 'datetime'];
}
