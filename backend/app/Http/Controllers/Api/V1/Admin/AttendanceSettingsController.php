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
