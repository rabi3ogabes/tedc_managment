<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['announcement_id', 'user_id', 'status'])]
class AnnouncementRsvp extends Model
{
    use HasUuids;

    protected $casts = [];
}
