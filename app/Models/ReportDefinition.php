<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'category', 'title_ar', 'title_en', 'description_ar', 'description_en', 'dataset', 'columns', 'filters', 'group_by', 'sort', 'chart', 'options', 'visibility', 'roles', 'owner_id', 'is_system'])]
class ReportDefinition extends Model
{
    use HasUuids;

    protected $casts = ['columns' => 'array', 'filters' => 'array', 'group_by' => 'array', 'sort' => 'array', 'chart' => 'array', 'options' => 'array', 'roles' => 'array', 'is_system' => 'boolean'];
}
