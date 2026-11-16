<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Throwable;

#[Fillable(['batch_id', 'row_no', 'data', 'mapped', 'row_key', 'status', 'errors', 'action', 'target_id', 'before', 'created_ids'])]
class MigrationRow extends Model
{
    use HasUuids;

    protected $casts = ['mapped' => 'array', 'errors' => 'array', 'before' => 'array', 'created_ids' => 'array'];

    /** The source values, decrypted. @return array<string, mixed> */
    public function source(): array
    {
        try {
            return json_decode(Crypt::decryptString($this->data), true) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @param  array<string, mixed>  $row */
    public static function seal(array $row): string
    {
        return Crypt::encryptString(json_encode($row, JSON_UNESCAPED_UNICODE));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MigrationBatch::class, 'batch_id');
    }
}
