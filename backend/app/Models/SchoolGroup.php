<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A set of schools (a directorate, cluster or stage) that a role can be granted over. */
#[Fillable(['code', 'name_ar', 'name_en', 'type', 'description'])]
class SchoolGroup extends Model
{
    use Auditable, HasUuids;

    public const TYPES = ['directorate', 'cluster', 'stage', 'custom'];

    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'school_group_school');
    }
}
