<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What one of the people who run a session wrote about it: one remark per person per session. */
#[Fillable(['program_session_id', 'user_id', 'body'])]
class SessionRemark extends Model
{
    use HasUuids;

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProgramSession::class, 'program_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
