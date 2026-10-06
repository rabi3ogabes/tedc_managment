<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'title_ar', 'title_en', 'body_ar', 'body_en', 'roles', 'module', 'related_routes', 'video_url', 'video_asset', 'screenshots', 'sort_order', 'status', 'version', 'updated_by'])]
class HelpArticle extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['roles' => 'array', 'related_routes' => 'array', 'screenshots' => 'array'];

    public function versions(): HasMany
    {
        return $this->hasMany(HelpArticleVersion::class, 'article_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(HelpFeedback::class, 'article_id');
    }
}
