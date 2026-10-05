<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['standard', 'title', 'version', 'storage_root', 'manifest', 'entry_points', 'size', 'uploaded_by', 'status', 'error'])]
class ContentPackage extends Model
{
    use HasUuids;

    protected $casts = ['manifest' => 'array', 'entry_points' => 'array', 'size' => 'integer'];
}
