<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['lesson_id', 'version', 'snapshot', 'note', 'is_archived', 'created_by'])]
class CourseLessonVersion extends Model
{
    use HasUuids;

    protected $casts = ['snapshot' => 'array', 'is_archived' => 'boolean'];
}
