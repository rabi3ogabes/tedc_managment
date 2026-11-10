<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TrainingRoom;
use App\Services\AttendanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Settings → Attendance: the location check around the venue. */
class AttendanceSettingsController extends Controller
{
    public function __construct(private readonly AttendanceSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'geofence_enabled' => ['sometimes', 'boolean'],
            'radius_m' => ['sometimes', 'integer', 'min:20', 'max:5000'],
            'max_accuracy_m' => ['sometimes', 'integer', 'min:20', 'max:2000'],
            'checkin_window_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600'],
            'checkout_window_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600'],
            'manual_window_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600'],
            'absence_warning_percent' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'absence_breach_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'excuse_counts_as_attended' => ['sometimes', 'boolean'],
            'leave_notifies_trainee' => ['sometimes', 'boolean'],
        ]);
        $this->settings->update($data, $request->user());

        return response()->json(['data' => $this->payload()]);
    }

    /** Rooms without coordinates cannot be checked, so the dashboard points them out. */
    private function payload(): array
    {
        return [
            'settings' => $this->settings->all(),
            'rooms' => [
                'total' => TrainingRoom::count(),
                'located' => TrainingRoom::whereNotNull('latitude')->whereNotNull('longitude')->count(),
            ],
        ];
    }
}
