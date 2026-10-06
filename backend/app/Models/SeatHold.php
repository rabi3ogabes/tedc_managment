<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['group_id', 'kind', 'owner_id', 'quantity', 'expires_at'])]
class SeatHold extends Model
{
    use HasUuids;

    protected $table = 'seat_holds';

    protected $casts = ['expires_at' => 'datetime'];
}
