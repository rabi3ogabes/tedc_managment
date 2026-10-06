<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['article_id', 'version', 'snapshot', 'created_by'])]
class HelpArticleVersion extends Model
{
    use HasUuids;

    protected $casts = ['snapshot' => 'array'];
}
