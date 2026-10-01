<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The thank-you certificate of a trainer for a program whose hours they completed. */
#[Fillable(['certificate_no', 'verification_code', 'trainer_id', 'program_id', 'issued_at', 'hours', 'file_path', 'status', 'revoked_reason', 'meta'])]
class TrainerCertificate extends Model
{
    use HasUuids;

    protected $casts = ['issued_at' => 'datetime', 'meta' => 'array', 'hours' => 'float'];

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function verificationUrl(): string
    {
        return rtrim(config('tedc.web_url'), '/').'/verify/'.$this->verification_code;
    }
}
