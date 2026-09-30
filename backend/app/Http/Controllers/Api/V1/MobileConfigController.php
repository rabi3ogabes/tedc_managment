<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AttendanceSettings;
use App\Services\Push\PushSettings;
use App\Services\ThemeService;
use Illuminate\Http\JsonResponse;

/**
 * Runtime configuration for the mobile app: Firebase client options (public values) and brand colors.
 * Administrators change them from the dashboard, and installed apps pick them up without a new APK.
 */
class MobileConfigController extends Controller
{
    public function __invoke(PushSettings $push, ThemeService $theme, AttendanceSettings $attendance): JsonResponse
    {
        $t = $theme->get();

        return response()->json(['data' => [
            'push' => $push->forMobile(),
            'attendance' => ['geofence' => $attendance->all()['geofence_enabled']],
            'brand' => [
                'primary' => $t['colors']['primary'],
                'accent' => $t['colors']['accent'],
                'background' => $t['colors']['background'],
                'logo_ar' => $t['identity']['logo_ar'] ?? null,
                'logo_en' => $t['identity']['logo_en'] ?? null,
            ],
        ]]);
    }
}
