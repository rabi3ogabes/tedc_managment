<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'hash', 'created_at'])]
class PasswordHistory extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'password_history';
}
