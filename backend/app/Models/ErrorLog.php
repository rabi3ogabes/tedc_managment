<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ErrorLog extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'device' => 'array', 'context' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime',
        'last_fix_at' => 'datetime', 'auto_fixed' => 'boolean', 'occurrences' => 'integer',
    ];
}
