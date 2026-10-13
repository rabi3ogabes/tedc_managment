<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use package for taking an assessment in the app without a connection (TYP-12). The frozen questions, with
 * their answers, stay on the server; the device gets them without answers and a signature over the token.
 */
#[Fillable(['assessment_id', 'registration_id', 'device_id', 'issued_at', 'expires_at', 'nonce', 'question_ids', 'questions', 'seed', 'used_at', 'attempt_id'])]
#[Hidden(['questions'])]
class OfflineExamToken extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'expires_at' => 'datetime', 'used_at' => 'datetime', 'question_ids' => 'array', 'questions' => 'array', 'seed' => 'integer'];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
