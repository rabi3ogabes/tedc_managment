<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_id', 'user_id', 'status'])]
class SpaceEventRsvp extends Model
{
    use HasUuids;
}
