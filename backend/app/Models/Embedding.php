<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['source_type', 'source_id', 'chunk_no', 'program_id', 'visibility', 'lang', 'title', 'route', 'content', 'vector', 'content_hash', 'updated_at'])]
class Embedding extends Model
{
    use HasUuids;

    protected $table = 'embeddings';

    public $timestamps = false;

    protected $casts = ['vector' => 'array', 'updated_at' => 'datetime'];
}
