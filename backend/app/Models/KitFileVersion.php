<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['file_id', 'version', 'storage_path', 'snapshot', 'size', 'source', 'note', 'created_by'])]
class KitFileVersion extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $casts = ['snapshot' => 'array', 'created_at' => 'datetime'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(KitFile::class, 'file_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
