<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\MfaDevice;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\User;
use App\Security\AuthSessions;
use App\Security\LoginGuard;
use App\Security\SecurityPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Settings → Security (password policy, lockout, MFA enforcement, session lifetimes), active sessions, and unlocking or resetting a person's sign-in. */
class SecurityPolicyController extends Controller
{
    public function __construct(private readonly SecurityPolicy $policy) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->policy->all(), 'meta' => ['roles' => Role::orderByDesc('level')->get(['slug', 'name_ar', 'name_en'])]]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'password' => ['sometimes', 'array'], 'password.min_length' => ['integer', 'between:8,64'], 'password.history' => ['integer', 'between:0,24'], 'password.expiry_days' => ['integer', 'between:0,730'],
            'password.upper' => ['boolean'], 'password.lower' => ['boolean'], 'password.digit' => ['boolean'], 'password.symbol' => ['boolean'], 'password.breached_check' => ['boolean'],
            'lockout' => ['sometimes', 'array'], 'lockout.max_attempts' => ['integer', 'between:3,20'], 'lockout.minutes' => ['integer', 'between:1,1440'],
            'mfa' => ['sometimes', 'array'], 'mfa.enforce_roles' => ['array'], 'mfa.enforce_roles.*' => ['string'], 'mfa.methods' => ['array'], 'mfa.methods.*' => ['in:totp,email,sms'], 'mfa.remember_days' => ['integer', 'between:0,90'],
            'sessions' => ['sometimes', 'array'], 'sessions.idle_minutes' => ['integer', 'between:5,1440'], 'sessions.absolute_hours' => ['integer', 'between:1,168'],
        ]);
        $before = SiteSetting::find(SecurityPolicy::KEY)?->value ?? SecurityPolicy::defaults();
        $after = $this->policy->update($d, $this->user());
        AuditLog::create(['user_id' => $this->user()->id, 'action' => 'security_policy_changed', 'auditable_type' => SiteSetting::class, 'auditable_id' => SecurityPolicy::KEY, 'old_values' => $before, 'new_values' => $after, 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 250), 'url' => $request->fullUrl()]);

        return response()->json(['data' => $after]);
    }

    /** Everyone who is signed in right now. */
    public function sessions(Request $request): JsonResponse
    {
        $q = AuthSession::with('user:id,name,name_ar,email')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->when($request->query('user_id'), fn ($b, $v) => $b->where('user_id', $v))
            ->when($request->query('q'), function ($b, $v) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower((string) $v)).'%';
                $b->whereIn('user_id', User::whereRaw('lower(email) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like])->select('id'));
            });

        return response()->json($q->orderByDesc('last_seen_at')->paginate($this->perPage($request)));
    }

    public function terminate(AuthSession $authSession, AuthSessions $sessions): JsonResponse
    {
        $sessions->revoke($authSession, 'admin', $this->user());

        return response()->json(['data' => ['ended' => true]]);
    }

    public function terminateAll(User $user, AuthSessions $sessions): JsonResponse
    {
        return response()->json(['data' => ['ended' => $sessions->revokeAll($user, 'admin', $this->user())]]);
    }

    public function unlock(Request $request, User $user, LoginGuard $guard): JsonResponse
    {
        $guard->unlock($user, $this->user(), $request);

        return response()->json(['data' => ['unlocked' => true]]);
    }

    /** Removes a person's second factor (lost phone) so they can enrol again; audited. */
    public function resetMfa(Request $request, User $user, AuthSessions $sessions): JsonResponse
    {
        $user->forceFill(['mfa_enabled' => false, 'mfa_secret' => null, 'mfa_recovery' => null, 'mfa_confirmed_at' => null])->saveQuietly();
        MfaDevice::where('user_id', $user->id)->delete();
        $sessions->revokeAll($user, 'admin', $this->user());
        AuditLog::create(['user_id' => $this->user()->id, 'action' => 'mfa_reset', 'auditable_type' => User::class, 'auditable_id' => $user->id, 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 250), 'url' => $request->fullUrl()]);

        return response()->json(['data' => ['reset' => true]]);
    }
}
