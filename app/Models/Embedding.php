<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A piece of content and its vector, made by one embedder (`model`, `dim`); vectors of different embedders are never compared. */
#[Fillable(['source_type', 'source_id', 'chunk_no', 'program_id', 'visibility', 'lang', 'title', 'route', 'content', 'vector', 'content_hash', 'updated_at', 'model', 'dim'])]
class Embedding extends Model
{
    use HasUuids;

    protected $table = 'embeddings';

    public $timestamps = false;

    protected $casts = ['vector' => 'array', 'updated_at' => 'datetime', 'dim' => 'integer'];

    /** The native pgvector column is written by the store, never read back as an attribute. */
    protected $hidden = ['vec'];
}
