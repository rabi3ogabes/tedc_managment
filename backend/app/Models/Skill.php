<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name_en', 'name_ar', 'category'])]
class Skill extends Model
{
    use HasTranslations, HasUuids;

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class)->withPivot('target_level');
    }
}
