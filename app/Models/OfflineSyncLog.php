<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['idempotency_key', 'user_id', 'result'])]
class OfflineSyncLog extends Model
{
    use HasUuids;

    protected $table = 'offline_sync_log';

    protected $casts = ['result' => 'array'];
}
