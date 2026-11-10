<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'hidden'])]
class GamificationProfile extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    protected $casts = ['hidden' => 'boolean'];
}
