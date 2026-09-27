<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['school_id', 'code', 'name_en', 'name_ar'])]
class Department extends Model
{
    use HasTranslations, HasUuids;

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
