<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/** Validation of the remote-delivery settings and certificate templates a program carries. */
class RemoteProgramRules
{
    public const PLATFORMS = ['zoom', 'teams', 'meet', 'webex', 'other'];

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'remote' => ['nullable', 'array'],
            'remote.platform' => ['nullable', Rule::in(self::PLATFORMS)],
            'remote.join_url' => ['nullable', 'url:http,https', 'max:500'],
            'remote.passcode' => ['nullable', 'string', 'max:64'],
            'remote.join_opens_minutes' => ['nullable', 'integer', 'between:0,240'],
            'remote.instructions_ar' => ['nullable', 'string', 'max:1500'],
            'remote.instructions_en' => ['nullable', 'string', 'max:1500'],
            'remote.min_join_percent' => ['nullable', 'integer', 'between:0,100'],
            'certificate_template_id' => ['nullable', 'uuid', 'exists:certificate_templates,id'],
            'trainer_certificate_template_id' => ['nullable', 'uuid', 'exists:certificate_templates,id'],
        ];
    }

    /** @return array<string, mixed> */
    public static function sessionRules(string $prefix = ''): array
    {
        return [
            $prefix.'mode' => ['nullable', Rule::in(['in_person', 'online'])],
            $prefix.'online_url' => ['nullable', 'url:http,https', 'max:500'],
            $prefix.'online_platform' => ['nullable', Rule::in(self::PLATFORMS)],
            $prefix.'online_passcode' => ['nullable', 'string', 'max:64'],
            $prefix.'recording_url' => ['nullable', 'url:http,https', 'max:500'],
        ];
    }
}
