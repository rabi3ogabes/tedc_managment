<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One push fan-out: how many devices were targeted, reached, failed or pruned. */
#[Fillable(['type', 'title', 'recipients', 'devices', 'delivered', 'failed', 'pruned', 'error', 'triggered_by'])]
class PushLog extends Model
{
    use HasUuids;
}
