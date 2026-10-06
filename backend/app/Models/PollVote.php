<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['poll_id', 'user_id', 'option_ids'])]
class PollVote extends Model
{
    use HasUuids;

    protected $casts = ['option_ids' => 'array'];
}
