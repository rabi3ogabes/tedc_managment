<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'program_id', 'log', 'created_by'])]
class ContentImport extends Model
{
    use HasUuids;

    protected $casts = ['log' => 'array'];
}
