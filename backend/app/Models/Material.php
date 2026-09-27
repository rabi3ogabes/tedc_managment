<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['program_id', 'program_session_id', 'title_en', 'title_ar', 'type', 'storage_path', 'url', 'mime', 'size', 'visibility', 'uploaded_by'])]
class Material extends Model
{
    use HasTranslations, HasUuids;

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProgramSession::class, 'program_session_id');
    }
}
