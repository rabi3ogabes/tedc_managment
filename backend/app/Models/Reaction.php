<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['target_type', 'target_id', 'user_id', 'type'])]
class Reaction extends Model
{
    use HasUuids;
}
