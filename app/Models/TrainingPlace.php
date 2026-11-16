<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A training venue (campus) that holds buildings and rooms. */
#[Fillable(['name_ar', 'name_en', 'address', 'map_url', 'website', 'latitude', 'longitude', 'capacity_limit'])]
class TrainingPlace extends Model
{
    use Auditable, HasUuids;

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class, 'place_id');
    }
}
