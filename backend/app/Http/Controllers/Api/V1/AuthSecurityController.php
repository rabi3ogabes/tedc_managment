<?php

namespace App\Http\Controllers\Api\V1;

use App\Integrations\Identity\DirectoryLogin;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\User;
use App\Security\AuthSessions;
use App\Security\MfaService;
use App\Security\PasswordService;
use App\Security\PendingLogin;
use App\Security\SecurityPolicy;
use App\Security\Sso\SsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Everything after the password: the second factor, sessions, password changes, single sign-on and directory sign-in. */
class AuthSecurityController extends AuthController
{
    public function mfaSend(Request $request, PendingLogin $pending, MfaService $mfa): JsonResponse
    {
        $d = $request->validate(['mfa_token' => ['required', 'string'], 'method' => ['required', Rule::in(['email', 'sms'])]]);
        [$user] = $pending->resolve($d['mfa_token']);

        return response()->json(['data' => $mfa->sendOtp($user, $d['method'])]);
    }

    /** Completes a sign-in with the code (authenticator app, e-mail, SMS) or a recovery code. */
    public function mfaVerify(Request $request, PendingLogin $pending, MfaService $mfa): JsonResponse
    {
        $d = $request->validate(['mfa_token' => ['required', 'string'], 'method' => ['required', Rule::in(['totp', 'email', 'sms', 'recovery'])], 'code' => ['required', 'string', 'max:40'], 'remember_device' => ['sometimes', 'boolean'], 'device_label' => ['nullable', 'string', 'max:120']]);
        [$user, , $state] = $pending->resolve($d['mfa_token']);
        $mfa->verifyAny($user, $d['code'], $d['method']);
        $session = $pending->release($user, $state);
        $response = $this->complete($session)->getData(true);
        if (! empty($d['remember_device'])) {
            $response['device_token'] = $mfa->rememberDevice($user, $d['device_label'] ?? $request->userAgent());
        }

        return response()->json($response);
    }

    /** Starts enrolment during sign-in (the person has no second factor yet). */
    public function mfaSetupPending(Request $request, PendingLogin $pending, MfaService $mfa): JsonResponse
    {
        [$user] = $pending->resolve($request->validate(['mfa_token' => ['required', 'string']])['mfa_token']);

        return response()->json(['data' => $mfa->setup($user)]);
    }

    public function mfaConfirmPending(Request $request, PendingLogin $pending, MfaService $mfa): JsonResponse
    {
        $d = $request->validate(['mfa_token' => ['required', 'string'], 'code' => ['required', 'string', 'max:10']]);
        [$user, , $state] = $pending->resolve($d['mfa_token']);
        $codes = $mfa->confirm($user, $d['code']);
        $response = $this->complete($pending->release($user->refresh(), $state))->getData(true);

        return response()->json($response + ['recovery_codes' => $codes]);
    }

    // ---- the signed-in person's own security -------------------------------------------------------

    public function mfaStatus(Request $request, MfaService $mfa, SecurityPolicy $policy): JsonResponse
    {
        $u = $request->user();

        return response()->json(['data' => ['enabled' => (bool) $u->mfa_enabled, 'required' => $policy->mfaRequired($u), 'methods' => $mfa->methods($u), 'recovery_left' => count($u->mfa_recovery ?? [])]]);
    }

    public function mfaSetup(Request $request, MfaService $mfa): JsonResponse
    {
        return response()->json(['data' => $mfa->setup($request->user())]);
    }

    public function mfaConfirm(Request $request, MfaService $mfa): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:10']]);

        return response()->json(['data' => ['recovery_codes' => $mfa->confirm($request->user(), $d['code'])]]);
    }

    public function mfaDisable(Request $request, MfaService $mfa): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $mfa->disable($request->user(), $d['code']);

        return response()->json(['data' => ['enabled' => false]]);
    }

    public function mfaRecovery(Request $request, MfaService $mfa): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $mfa->verifyAny($request->user(), $d['code'], 'totp');

        return response()->json(['data' => ['recovery_codes' => $mfa->regenerateRecovery($request->user())]]);
    }

    /** Confirms the second factor again for a privileged action (valid for ten minutes). */
    public function stepUp(Request $request, MfaService $mfa): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:40'], 'method' => ['sometimes', Rule::in(['totp', 'email', 'sms', 'recovery'])]]);
        $mfa->verifyAny($request->user(), $d['code'], $d['method'] ?? 'totp');
        $session = $request->attributes->get('auth_session');
        $session?->forceFill(['stepped_up_at' => now()])->save();

        return response()->json(['data' => ['stepped_up' => true, 'tracked' => (bool) $session]]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $current = $request->attributes->get('auth_session')?->id;

        return response()->json(['data' => AuthSession::where('user_id', $request->user()->id)->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('last_seen_at')->get()
            ->map(fn (AuthSession $s) => $this->sessionRow($s) + ['current' => $s->id === $current])]);
    }

    public function endSession(Request $request, AuthSessions $sessions, string $sid): JsonResponse
    {
        $s = AuthSession::where('user_id', $request->user()->id)->whereKey($sid)->firstOrFail();
        $sessions->revoke($s, 'logout');

        return response()->json(null, 204);
    }

    public function logout(Request $request, AuthSessions $sessions, SsoService $sso): JsonResponse
    {
        $s = $request->attributes->get('auth_session');
        if ($s) {
            $sessions->revoke($s, 'logout');
        }

        return response()->json(['data' => ['signed_out' => true, 'sso_logout_url' => $s?->method === 'sso' ? $sso->logoutUrl() : null]]);
    }

    public function changePassword(Request $request, PasswordService $passwords, AuthSessions $sessions): JsonResponse
    {
        $d = $request->validate(['current_password' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:255', 'confirmed']]);
        $user = $request->user();
        if (! $this->auth->checkPassword($user, $d['current_password'])) {
            throw ValidationException::withMessages(['current_password' => __('auth.wrong_password')]);
        }
        $passwords->assert($d['password'], $user);
        $passwords->set($user, $d['password']);
        $ended = $sessions->revokeAll($user, 'password', null, $request->attributes->get('auth_session')?->id);
        AuditLog::create(['user_id' => $user->id, 'action' => 'password_changed', 'auditable_type' => User::class, 'auditable_id' => $user->id, 'new_values' => ['other_sessions_ended' => $ended], 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 250), 'url' => $request->fullUrl()]);

        return response()->json(['data' => ['changed' => true, 'other_sessions_ended' => $ended]]);
    }

    /** What the sign-in page needs to know: is single sign-on offered, may people use a directory password, what does the password policy say. */
    public function options(SsoService $sso, DirectoryLogin $ldap, SecurityPolicy $policy): JsonResponse
    {
        $p = $policy->all()['password'];

        return response()->json(['data' => ['sso' => $sso->enabled(), 'ldap' => $ldap->enabled(), 'password_policy' => ['min_length' => $p['min_length'], 'upper' => $p['upper'], 'lower' => $p['lower'], 'digit' => $p['digit'], 'symbol' => $p['symbol']]]]);
    }

    // ---- single sign-on and directory sign-in -------------------------------------------------------

    public function ssoStart(Request $request, SsoService $sso): JsonResponse
    {
        $d = $request->validate(['redirect' => ['nullable', 'string', 'max:300']]);

        return response()->json(['data' => $sso->start($d['redirect'] ?? null)]);
    }

    /** The identity provider sends the browser here; it is sent on to the app with a one-time code. */
    public function ssoCallback(Request $request, SsoService $sso)
    {
        $d = $request->validate(['code' => ['nullable', 'string'], 'state' => ['required', 'string'], 'profile' => ['nullable', 'string', 'max:4000']]);
        if ($request->filled('error')) {
            return redirect(rtrim((string) config('tedc.web_url'), '/').'/login?sso_error=1');
        }
        try {
            return redirect($sso->callback((string) ($d['code'] ?? ''), $d['state'], $d['profile'] ?? null));
        } catch (ValidationException) {
            return redirect(rtrim((string) config('tedc.web_url'), '/').'/login?sso_error=1');
        }
    }

    public function ssoExchange(Request $request, SsoService $sso): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:100']]);

        return $this->complete($sso->exchange($d['code']));
    }

    public function ldapLogin(Request $request, DirectoryLogin $ldap): JsonResponse
    {
        $d = $request->validate(['username' => ['required', 'string', 'max:200'], 'password' => ['required', 'string', 'max:255']]);

        return $this->complete($ldap->login($d['username'], $d['password']));
    }

    private function sessionRow(AuthSession $s): array
    {
        return ['id' => $s->id, 'method' => $s->method, 'ip' => $s->ip, 'device' => $s->user_agent, 'started_at' => $s->created_at?->toIso8601String(), 'last_seen_at' => $s->last_seen_at->toIso8601String(), 'expires_at' => $s->expires_at->toIso8601String()];
    }
}
