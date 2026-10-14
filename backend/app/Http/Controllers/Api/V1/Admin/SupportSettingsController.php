<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\SupportSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings → Help & support: the contacts, the working hours and the service levels the help centre, the app and the e-mails show. */
class SupportSettingsController extends Controller
{
    public function __construct(private readonly SupportSettings $support) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->present()]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()\-]{5,28}$/'],
            'email' => ['nullable', 'email:rfc', 'max:120'],
            'saaed_url' => ['nullable', 'string', 'max:255', 'starts_with:https://', 'url'],
            'hours_ar' => ['nullable', 'string', 'max:120'],
            'hours_en' => ['nullable', 'string', 'max:120'],
            'sla' => ['nullable', 'array', 'size:'.count(SupportSettings::PRIORITIES)],
            'sla.*.priority' => ['required', Rule::in(SupportSettings::PRIORITIES), 'distinct'],
            'sla.*.response_ar' => ['required', 'string', 'max:40'], 'sla.*.response_en' => ['required', 'string', 'max:40'],
            'sla.*.resolution_ar' => ['required', 'string', 'max:40'], 'sla.*.resolution_en' => ['required', 'string', 'max:40'],
        ], [], (array) __('messages.support.fields'));   // the fields are named in the person's language in the messages
        foreach (['phone', 'email', 'saaed_url', 'hours_ar', 'hours_en'] as $k) {
            if (array_key_exists($k, $d)) {
                $d[$k] = trim((string) ($d[$k] ?? ''));   // a cleared field is saved as empty
            }
        }
        $this->support->update($d, $request->user(), $request->ip());

        return response()->json(['data' => $this->present()]);
    }

    /** @return array<string, mixed> */
    private function present(): array
    {
        return $this->support->all() + ['saved' => $this->support->saved()];
    }
}
