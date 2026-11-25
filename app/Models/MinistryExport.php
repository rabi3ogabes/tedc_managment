<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['announcement_id', 'status', 'attempts', 'last_error', 'next_attempt_at', 'sent_at'])]
class MinistryExport extends Model
{
    use HasUuids;

    protected $casts = ['next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];

    public function announcementRow(): BelongsTo
    {
        return $this->belongsTo(Announcement::class, 'announcement_id');
    }
}
