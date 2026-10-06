<?php

namespace App\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Security\Siem\SiemForwarder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** The events a security team wants to see: sign-ins and failures, lock-outs, second-factor failures, permission denials, exports of personal data. Each is kept and sent to the SIEM. */
class SecurityEvents
{
    public const TYPES = ['login_success', 'login_failed', 'account_locked', 'mfa_failed', 'permission_denied', 'personal_data_export', 'integration_failure', 'dsr_received', 'admin_action'];

    /** @param  array<string, mixed>  $meta  never passwords, tokens or message bodies */
    public static function record(string $type, ?User $user = null, string $outcome = 'ok', array $meta = [], ?Request $request = null): void
    {
        try {
            $request ??= request();
            $row = SecurityEvent::create(['type' => $type, 'user_id' => $user?->id, 'outcome' => $outcome, 'ip' => $request?->ip(), 'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 250) : null,
                'request_id' => $request?->attributes->get('request_id'), 'meta' => $meta ?: null, 'created_at' => now()]);
            app(SiemForwarder::class)->push('security', ['type' => $type, 'outcome' => $outcome, 'user_id' => $user?->id, 'ip' => $row->ip, 'request_id' => $row->request_id, 'meta' => $meta]);
        } catch (Throwable) {
            // a failure to record must never block the request
        }
    }

    /** Repeated denials of the same person on the same route in a minute are one event, not a flood. */
    public static function denied(?User $user, Request $request): void
    {
        $key = 'denied:'.($user?->id ?? $request->ip()).':'.sha1($request->method().$request->path());
        if (Cache::add($key, 1, 60)) {
            self::record('permission_denied', $user, 'denied', ['method' => $request->method(), 'path' => $request->path()], $request);
        }
    }
}
