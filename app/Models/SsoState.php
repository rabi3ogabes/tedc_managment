<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['state', 'nonce', 'verifier', 'redirect_after', 'kind', 'session', 'expires_at'])]
class SsoState extends Model
{
    use HasUuids;

    protected $casts = ['session' => 'array', 'expires_at' => 'datetime'];
}
