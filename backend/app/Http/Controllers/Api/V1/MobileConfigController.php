<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AttendanceSettings;
use App\Services\Push\PushSettings;
use App\Services\ThemeService;
use App\Support\DemoGuard;
use App\Support\Features;
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
            // The sign-in screens list the demo accounts only where demo mode is on (never on a live system by default).
            'demo_accounts' => DemoGuard::allowed() && Features::enabled('test_accounts'),
            'attendance' => ['geofence' => $attendance->all()['geofence_enabled']],
            'brand' => [
                'primary' => $t['colors']['primary'],
                'accent' => $t['colors']['accent'],
                'background' => $t['colors']['background'],
                'logo_ar' => $t['identity']['logo_ar'] ?? null,
                'logo_en' => $t['identity']['logo_en'] ?? null,
            ],
            // The loading screen and the occasion in force, so the app opens the way the website does.
            'typography' => [
                'arabic_family' => $t['typography']['arabic_family'] ?? null,
                'latin_family' => $t['typography']['latin_family'] ?? null,
                'arabic_font_url' => $t['typography']['arabic_font_url'] ?? null,
                'latin_font_url' => $t['typography']['latin_font_url'] ?? null,
            ],
            'loading' => $t['loading'] ?? null,
            'occasion' => $t['active_occasion'] ?? null,
        ]]);
    }
}
