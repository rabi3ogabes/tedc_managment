<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'title', 'parameters', 'payload', 'status', 'file_path', 'generated_by'])]
class Report extends Model
{
    use HasUuids;

    protected $casts = ['parameters' => 'array', 'payload' => 'array'];
}
