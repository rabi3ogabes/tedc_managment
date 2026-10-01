<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\RoomScreenSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings → Room screen: the template of the screens at the classroom doors. */
class RoomScreenSettingsController extends Controller
{
    public function __construct(private readonly RoomScreenSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'layout' => ['sometimes', Rule::in(RoomScreenSettings::LAYOUTS)], 'theme' => ['sometimes', Rule::in(RoomScreenSettings::THEMES)],
            'background' => ['nullable', 'string', 'max:7'], 'accent' => ['nullable', 'string', 'max:7'],
            'show_logo' => ['sometimes', 'boolean'], 'show_center_name' => ['sometimes', 'boolean'], 'show_clock' => ['sometimes', 'boolean'], 'show_trainer' => ['sometimes', 'boolean'],
            'show_trainees' => ['sometimes', 'boolean'], 'show_school' => ['sometimes', 'boolean'], 'show_progress' => ['sometimes', 'boolean'], 'show_attendance_ring' => ['sometimes', 'boolean'],
            'footer_ar' => ['nullable', 'string', 'max:200'], 'footer_en' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json(['data' => $this->settings->update($data, $request->user())]);
    }

    public function reset(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settings->update(RoomScreenSettings::defaults(), $request->user())]);
    }
}
