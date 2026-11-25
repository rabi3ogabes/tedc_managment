<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title_ar', 'title_en', 'body_ar', 'body_en', 'template_id', 'channels', 'audience', 'send_at', 'repeat', 'repeat_until', 'status', 'campaign_id', 'runs', 'last_error', 'created_by'])]
class ScheduledNotification extends Model
{
    use HasUuids;

    protected $casts = ['channels' => 'array', 'audience' => 'array', 'send_at' => 'datetime', 'repeat_until' => 'date', 'runs' => 'integer'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
