<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['group_id', 'team_id', 'channel_id', 'drive_id', 'folder_item_id', 'web_url', 'members_synced_at', 'last_error'])]
class TeamsTeam extends Model
{
    use HasUuids;

    protected $casts = ['members_synced_at' => 'datetime'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class, 'group_id');
    }
}
