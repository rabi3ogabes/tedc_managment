<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A building of a training place. */
#[Fillable(['place_id', 'name_ar', 'name_en', 'capacity_limit'])]
class Building extends Model
{
    use Auditable, HasUuids;

    public function place(): BelongsTo
    {
        return $this->belongsTo(TrainingPlace::class);
    }
}
