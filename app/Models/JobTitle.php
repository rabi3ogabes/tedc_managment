<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name_en', 'name_ar', 'category'])]
class JobTitle extends Model
{
    use HasTranslations, HasUuids;
}
