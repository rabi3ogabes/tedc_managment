<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name_en', 'name_ar', 'building', 'capacity', 'facilities', 'latitude', 'longitude'])]
class TrainingRoom extends Model
{
    use HasTranslations, HasUuids;

    protected $casts = ['facilities' => 'array'];
}
