<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'activity_id', 'agent_key', 'registration', 'doc_id', 'content', 'content_type'])]
class XapiDocument extends Model
{
    use HasUuids;

    protected $casts = [];
}
