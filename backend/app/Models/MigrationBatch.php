<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['kind', 'filename', 'checksum', 'status', 'mapping', 'value_maps', 'defaults', 'total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows', 'created_rows', 'updated_rows', 'report', 'created_by', 'imported_at', 'rolled_back_at', 'expires_at'])]
class MigrationBatch extends Model
{
    use HasUuids;

    protected $casts = ['mapping' => 'array', 'value_maps' => 'array', 'defaults' => 'array', 'report' => 'array', 'imported_at' => 'datetime', 'rolled_back_at' => 'datetime', 'expires_at' => 'datetime'];

    public function rows(): HasMany
    {
        return $this->hasMany(MigrationRow::class, 'batch_id');
    }
}
