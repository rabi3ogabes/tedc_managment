<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One saved version of a system's contract or mapping. No document: the one shipped with TEDC. */
#[Fillable(['integration_key', 'kind', 'version', 'document', 'source', 'restored_from', 'note', 'created_by'])]
class IntegrationContractVersion extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $casts = ['document' => 'array', 'version' => 'integer', 'restored_from' => 'integer'];
}
