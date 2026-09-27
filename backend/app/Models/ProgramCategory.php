<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name_en', 'name_ar', 'icon', 'color'])]
class ProgramCategory extends Model
{
    use HasTranslations, HasUuids;

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class, 'category_id');
    }
}
