<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['registration_id', 'lesson_id', 'au_id', 'token_hash', 'state', 'expires_at'])]
class Cmi5Session extends Model
{
    use HasUuids;

    protected $casts = ['state' => 'array', 'expires_at' => 'datetime'];
}
