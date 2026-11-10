<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'statement', 'actor_key', 'verb', 'object_id', 'registration_id', 'lesson_id', 'voided', 'stored'])]
class XapiStatement extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'xapi_statements';

    protected $casts = ['statement' => 'array', 'voided' => 'boolean', 'stored' => 'datetime'];
}
