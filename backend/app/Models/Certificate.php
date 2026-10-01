<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['certificate_no', 'verification_code', 'registration_id', 'employee_id', 'program_id', 'issued_at', 'hours', 'file_path', 'status', 'revoked_reason', 'issued_by', 'meta', 'sent_at', 'sent_count', 'sent_to', 'sent_by', 'send_error', 'available_notified_at', 'template_id'])]
class Certificate extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['issued_at' => 'datetime', 'sent_at' => 'datetime', 'available_notified_at' => 'datetime', 'meta' => 'array', 'hours' => 'float'];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function verificationUrl(): string
    {
        return rtrim(config('tedc.web_url'), '/').'/verify/'.$this->verification_code;
    }
}
