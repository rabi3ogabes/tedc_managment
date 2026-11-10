<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role', 'resource_types', 'target_types', 'allow_reshare', 'allow_download', 'watermark'])]
class SharingPolicy extends Model
{
    use HasUuids;

    protected $casts = ['resource_types' => 'array', 'target_types' => 'array', 'allow_reshare' => 'boolean', 'allow_download' => 'boolean', 'watermark' => 'boolean'];
}
