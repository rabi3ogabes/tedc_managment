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
            'status' => $this->status,
            'revoked_reason' => $this->revoked_reason,
            'program' => $this->whenLoaded('program', fn () => ['id' => $this->program->id, 'code' => $this->program->code, 'title' => $this->program->translate('title')]),
            'employee' => $this->whenLoaded('employee', fn () => ['id' => $this->employee->id, 'employee_no' => $this->employee->employee_no, 'name' => $this->employee->user?->displayName()]),
            'download_url' => route('api.certificates.download', $this->id),
        ];
    }
}
