<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['article_id', 'user_id', 'helpful', 'comment', 'article_version'])]
class HelpFeedback extends Model
{
    use HasUuids;

    protected $table = 'help_feedback';

    protected $casts = ['helpful' => 'boolean'];
}
