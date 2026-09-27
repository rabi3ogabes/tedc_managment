<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name_en', 'name_ar', 'title_en', 'title_ar', 'email', 'phone', 'bio_en', 'bio_ar', 'specializations', 'photo_path', 'is_external', 'organization', 'rating', 'status'])]
class Trainer extends Model
{
    use Auditable, HasTranslations, HasUuids;

    protected $casts = ['specializations' => 'array', 'is_external' => 'boolean', 'rating' => 'float'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class)->withPivot('role');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ProgramSession::class);
    }
}
