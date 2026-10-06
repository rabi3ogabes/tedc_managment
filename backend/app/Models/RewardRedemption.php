<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['reward_id', 'user_id', 'points', 'code', 'status'])]
class RewardRedemption extends Model
{
    use HasUuids;
}
