<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kit_id', 'user_id', 'type', 'prompt', 'params', 'result', 'provider', 'status', 'error'])]
class KitGeneration extends Model
{
    use HasUuids;

    protected $casts = ['params' => 'array', 'result' => 'array'];
}
