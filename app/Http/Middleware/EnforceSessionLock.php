<?php

namespace App\Http\Middleware;

use App\Services\PresenceService;
use App\Services\SecuritySettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle lock of the administration team: after N minutes without real activity (reported by the dashboard with
 * its heartbeats) the dashboard API answers 423 until the user confirms their password. Being enforced on the
 * server, an unattended browser or a copied token cannot keep using the dashboard.
 */
class EnforceSessionLock
{
    public function __construct(private readonly SecuritySettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $this->settings->lockEnabled() && PresenceService::isStaff($user)) {
            if (! $user->locked_at) {
                $active = $user->last_active_at ?? now();
                if ($active->lt(now()->subSeconds($this->settings->lockSeconds()))) {
                    $user->forceFill(['locked_at' => now()])->saveQuietly();
                }
            }
            if ($user->locked_at) {
                return response()->json(['message' => __('auth.session_locked'), 'code' => 'session_locked'], 423);
            }
        }

        return $next($request);
    }
}
