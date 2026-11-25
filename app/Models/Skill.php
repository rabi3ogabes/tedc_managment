<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name_en', 'name_ar', 'category', 'domain_id', 'framework_version', 'descriptors', 'licence_relevant', 'is_active'])]
class Skill extends Model
{
    use HasTranslations, HasUuids;

    protected function casts(): array
    {
        return ['descriptors' => 'array', 'licence_relevant' => 'boolean', 'is_active' => 'boolean'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(CompetencyDomain::class, 'domain_id');
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class)->withPivot('target_level');
    }
}
