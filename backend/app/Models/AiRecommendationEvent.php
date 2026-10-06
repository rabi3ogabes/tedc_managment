<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'item_type', 'item_id', 'event', 'variant', 'score', 'reasons', 'note', 'created_at'])]
class AiRecommendationEvent extends Model
{
    use HasUuids;

    protected $table = 'ai_recommendation_events';

    public $timestamps = false;

    protected $casts = ['reasons' => 'array', 'created_at' => 'datetime'];
}
