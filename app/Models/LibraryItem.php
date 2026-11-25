<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'title_ar', 'title_en', 'description_ar', 'description_en', 'authors', 'publisher', 'isbn', 'year', 'language', 'subjects', 'skill_ids', 'cover_path', 'file_path', 'file_mime', 'url', 'source', 'external_id', 'rights', 'audience', 'status', 'search_text', 'views', 'downloads', 'created_by'])]
class LibraryItem extends Model
{
    use HasUuids;

    protected $casts = ['authors' => 'array', 'subjects' => 'array', 'skill_ids' => 'array', 'rights' => 'array', 'audience' => 'array'];
}
