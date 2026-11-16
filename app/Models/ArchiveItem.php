<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'subject_id', 'sijil_ref', 'status', 'attempts', 'error', 'archived_at'])]
class ArchiveItem extends Model
{
    use HasUuids;

    protected $casts = ['archived_at' => 'datetime'];
}
