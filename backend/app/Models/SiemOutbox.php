<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'payload', 'attempts', 'last_error', 'sent_at', 'created_at'])]
class SiemOutbox extends Model
{
    use HasUuids;

    protected $table = 'siem_outbox';

    public $timestamps = false;

    protected $casts = ['payload' => 'array', 'sent_at' => 'datetime', 'created_at' => 'datetime'];
}
