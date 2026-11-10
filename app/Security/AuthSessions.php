<?php

namespace App\Security;

use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The server-side session registry. Every sign-in opens a session with an idle timeout and an absolute lifetime; each request refreshes it,
 * and a session that timed out, was ended or was terminated by an administrator no longer works — its tokens are refused, not just locked.
 */
class AuthSessions
{
    public function __construct(private readonly SecurityPolicy $policy) {}

    public function open(User $user, Request $request, string $method = 'password', ?string $externalId = null): AuthSession
    {
        $rule = $this->policy->all()['sessions'];
        if ($externalId && ($existing = AuthSession::where('external_id', $externalId)->first())) {
            return $existing;
        }

        return AuthSession::create([
            'external_id' => $externalId, 'user_id' => $user->id, 'method' => $method, 'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 250),
            'last_seen_at' => now(), 'expires_at' => now()->addHours($rule['absolute_hours']),
        ]);
    }

    /** Called on every authenticated request. Returns the live session, or null when it has ended (and ends it when it just timed out). */
    public function touch(string $sessionId, User $user, ?Request $request = null, ?string $external = null): ?AuthSession
    {
        $s = AuthSession::where('user_id', $user->id)->where(fn ($q) => $q->whereKey($sessionId)->orWhere('external_id', $external ?? $sessionId))->first();
        if (! $s) {
            // A provider session we have not seen (a sign-in through Supabase directly) is adopted.
            return $external ? $this->open($user, $request ?? request(), 'password', $external) : null;
        }
        if ($s->revoked_at) {
            return null;
        }
        $rule = $this->policy->all()['sessions'];
        if ($s->expires_at->isPast()) {
            $this->revoke($s, 'expired');

            return null;
        }
        if ($s->last_seen_at->lt(now()->subMinutes($rule['idle_minutes']))) {
            $this->revoke($s, 'idle');

            return null;
        }
        // Writing on every request would cost more than it is worth: refresh at most once a minute.
        if ($s->last_seen_at->lt(now()->subSeconds(60)) && Cache::add("authsess.{$s->id}", 1, 55)) {
            $s->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $s;
    }

    public function revoke(AuthSession $s, string $reason, ?User $by = null): void
    {
        if ($s->revoked_at) {
            return;
        }
        $s->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->saveQuietly();
        if ($reason === 'admin') {
            AuditLog::create(['user_id' => $by?->id, 'action' => 'session_terminated', 'auditable_type' => User::class, 'auditable_id' => $s->user_id, 'new_values' => ['session' => $s->id], 'ip_address' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 250), 'url' => request()->fullUrl()]);
        }
    }

    /** Ends every live session of a person (a password change, a leaver, an administrator's decision). */
    public function revokeAll(User $user, string $reason, ?User $by = null, ?string $except = null): int
    {
        $n = 0;
        AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->when($except, fn ($q) => $q->where('id', '!=', $except))->get()->each(function (AuthSession $s) use ($reason, $by, &$n) {
            $this->revoke($s, $reason, $by);
            $n++;
        });

        return $n;
    }

    public function steppedUp(?AuthSession $s): bool
    {
        return (bool) $s?->stepped_up_at?->gt(now()->subMinutes(10));
    }
}
