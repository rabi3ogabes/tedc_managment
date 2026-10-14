<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A SAML assertion already used: the same assertion never signs anyone in twice. */
#[Fillable(['assertion_id', 'expires_at'])]
class SsoReplay extends Model
{
    use HasUuids;

    protected $casts = ['expires_at' => 'datetime'];
}
