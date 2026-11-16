<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'title_ar', 'title_en', 'description_ar', 'description_en', 'job_title_ids', 'is_active'])]
class CareerPath extends Model
{
    use Auditable, HasTranslations, HasUuids;

    protected $casts = ['job_title_ids' => 'array', 'is_active' => 'boolean'];

    public function levels(): HasMany
    {
        return $this->hasMany(CareerPathLevel::class, 'path_id')->orderBy('level_no');
    }
}
