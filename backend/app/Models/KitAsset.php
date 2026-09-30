<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An image used inside a kit deck (uploaded, imported from a PPTX, or AI generated). */
#[Fillable(['kit_id', 'file_id', 'name', 'mime', 'size', 'storage_path', 'prompt', 'source', 'width', 'height', 'meta', 'created_by'])]
class KitAsset extends Model
{
    use HasUuids;

    protected $casts = ['meta' => 'array'];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(TrainingKit::class, 'kit_id');
    }
}
