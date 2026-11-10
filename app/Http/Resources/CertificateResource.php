<?php

namespace App\Http\Resources;

use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Certificate */
class CertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'certificate_no' => $this->certificate_no,
            'verification_code' => $this->verification_code,
            'verification_url' => $this->verificationUrl(),
            'issued_at' => $this->issued_at->toIso8601String(),
            'hours' => $this->hours,
            'type' => $this->type,
            'hours_mode' => $this->hours_mode,
            'hours_total' => $this->hours_total,
            'hours_actual' => $this->hours_actual,
            'status' => $this->status,
            'revoked_reason' => $this->revoked_reason,
            'program' => $this->whenLoaded('program', fn () => ['id' => $this->program->id, 'code' => $this->program->code, 'title' => $this->program->translate('title')]),
            'employee' => $this->whenLoaded('employee', fn () => ['id' => $this->employee->id, 'employee_no' => $this->employee->employee_no, 'name' => $this->employee->user?->displayName()]),
            'is_sent' => $this->sent_at !== null,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'sent_count' => $this->sent_count,
            'sent_to' => $this->sent_to,
            'sent_by' => $this->whenLoaded('sender', fn () => $this->sender?->displayName()),
            'send_error' => $this->send_error,
            'download_url' => route('api.certificates.download', $this->id),
        ];
    }
}
