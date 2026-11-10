<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\AuthService;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\AuditLog;
use App\Models\RoleUser;
use App\Models\User;
use App\Security\AuthSessions;
use App\Security\LoginGuard;
use App\Security\MfaService;
use App\Security\PasswordService;
use App\Security\PendingLogin;
use App\Security\SecurityPolicy;
use App\Security\Sso\SsoService;
use App\Services\Channels\ChannelSettings;
use App\Services\Channels\EmailSender;
use App\Support\ActiveRole;
use App\Support\ScopeLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(protected readonly AuthService $auth) {}

    public function login(Request $request, LoginGuard $guard, SecurityPolicy $policy, MfaService $mfa, PendingLogin $pending, SsoService $sso, AuthSessions $sessions): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_token' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::with('roles')->where('email', strtolower($data['email']))->first();
        $guard->assertOpen($user);   // a locked account says so before the password is even tried
        try {
            $session = $this->auth->login($data['email'], $data['password']);
        } catch (ValidationException $e) {
            $guard->failed($user, $request);

            throw $e;
        }
        $guard->succeeded($session['user']);

        // With single sign-on in force, local passwords are for external users and break-glass administrators only.
        if (! $sso->localLoginAllowed($session['user'])) {
            if (! empty($session['session_id'])) {
                $sessions->revokeAll($session['user'], 'logout');
            }

            throw ValidationException::withMessages(['email' => __('auth.sso_domain')])->status(403);
        }
        if ($policy->mfaRequired($session['user']) && ! $mfa->trustedDevice($session['user'], $data['device_token'] ?? null)) {
            return response()->json(['mfa_required' => true, 'mfa_token' => $pending->hold($session['user'], $session), 'methods' => $mfa->methods($session['user']), 'enrolled' => (bool) $session['user']->mfa_enabled]);
        }

        return $this->complete($session);
    }

    /** A signed-in session is real activity: it clears any idle lock. */
    protected function complete(array $session): JsonResponse
    {
        $session['user']->forceFill(['last_active_at' => now(), 'locked_at' => null])->saveQuietly();

        return $this->session($session);
    }

    /** Sets the first password from the activation link e-mailed after an external registration was approved. */
    public function activate(Request $request): JsonResponse
    {
        return $this->applyToken($request, 'activation_invalid', __('messages.external.activation_invalid'), 'activated');
    }

    /**
     * Sends a password-reset link. The answer is the same whether or not the e-mail belongs to an account, so nothing can be
     * learnt about who has one. Accounts that must sign in through the Ministry's single sign-on get no link.
     */
    public function forgot(Request $request, EmailSender $mail, ChannelSettings $channels, SsoService $sso): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email', 'max:255']]);
        if (! $channels->ready('email')) {
            return response()->json(['message' => __('auth.reset_mail_off'), 'code' => 'mail_not_configured'], 503);
        }
        $user = User::with('roles')->where('email', strtolower($d['email']))->where('status', 'active')->first();
        if ($user && $sso->localLoginAllowed($user)) {
            $locale = $request->header('X-Locale') === 'en' ? 'en' : 'ar';
            $name = $locale === 'en' ? $user->name : ($user->name_ar ?: $user->name);
            $token = Password::broker()->createToken($user);
            $link = rtrim((string) config('tedc.web_url'), '/').'/reset?email='.urlencode($user->email).'&token='.$token;
            $minutes = (int) config('auth.passwords.users.expire', 60);
            $mail->send($user->email, 'إعادة تعيين كلمة المرور · Reset your password',
                "مرحباً {$name}،\nطُلبت إعادة تعيين كلمة مرور حسابك في بوابة التدريب. الرابط صالح لمدة {$minutes} دقيقة:\n{$link}\n\nإن لم تطلب ذلك فتجاهل هذه الرسالة؛ كلمة مرورك لم تتغير.\n\n"
                ."Hello {$name},\nA password reset was requested for your training-portal account. The link is valid for {$minutes} minutes:\n{$link}\n\nIf you did not ask for this, ignore this message; your password has not changed.", $locale);
            AuditLog::create([
                'user_id' => $user->id, 'action' => 'password_reset_requested', 'auditable_type' => User::class, 'auditable_id' => $user->id,
                'old_values' => null, 'new_values' => ['email' => $user->email],
                'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255), 'url' => $request->fullUrl(),
            ]);
        }

        return response()->json(['data' => ['sent' => true]]);
    }

    /** Sets a new password from the reset link; every other session of the account is ended. */
    public function reset(Request $request): JsonResponse
    {
        return $this->applyToken($request, 'reset_invalid', __('auth.reset_invalid'), 'reset');
    }

    /** Activation and reset share one mechanism: the password broker's token, the password policy, and a fresh start for the account. */
    private function applyToken(Request $request, string $code, string $message, string $flag): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string'], 'password' => ['required', 'string', 'confirmed']]);
        $d['email'] = strtolower($d['email']);
        app(PasswordService::class)->assert($d['password'], User::where('email', $d['email'])->first());
        $status = Password::reset($d, function (User $user, string $password) use ($request, $flag) {
            app(PasswordService::class)->set($user, $password);
            $user->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'locked_at' => null])->saveQuietly();
            if ($flag === 'reset') {
                app(AuthSessions::class)->revokeAll($user, 'password_reset');
            }
            AuditLog::create([
                'user_id' => $user->id, 'action' => $flag === 'reset' ? 'password_reset' : 'account_activated', 'auditable_type' => User::class, 'auditable_id' => $user->id,
                'old_values' => null, 'new_values' => ['email' => $user->email],
                'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255), 'url' => $request->fullUrl(),
            ]);
        });
        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => $message, 'code' => $code], 422);
        }

        return response()->json(['data' => [$flag => true]]);
    }

    /** Locks the dashboard immediately (idle timeout reached in the browser). */
    public function lock(Request $request): JsonResponse
    {
        $request->user()->forceFill(['locked_at' => now()])->saveQuietly();

        return response()->json(['data' => ['locked' => true]]);
    }

    /** Unlocks after the password is confirmed again. */
    public function unlock(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);

        if (! $this->auth->checkPassword($request->user(), $data['password'])) {
            throw ValidationException::withMessages(['password' => __('auth.wrong_password')]);
        }
        $request->user()->forceFill(['locked_at' => null, 'last_active_at' => now()])->saveQuietly();

        return response()->json(['data' => ['locked' => false]]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string']]);

        return $this->session($this->auth->refresh($data['refresh_token']));
    }

    /** Switches the role the user works in (and remembers it). */
    public function switchRole(Request $request, ActiveRole $active): JsonResponse
    {
        $data = $request->validate(['role_user_id' => ['required', 'uuid']]);
        $user = $request->user();
        $assignment = $active->assignments($user)->firstWhere('id', $data['role_user_id']);
        abort_unless($assignment, 403, __('auth.role_not_held'));

        $before = $user->active_role_user_id;
        $user->forceFill(['active_role_user_id' => $assignment->id])->saveQuietly();
        $active->set($user, $assignment);
        AuditLog::create([
            'user_id' => $user->id, 'action' => 'role_switched', 'auditable_type' => User::class, 'auditable_id' => $user->id,
            'old_values' => ['role_user_id' => $before], 'new_values' => ['role_user_id' => $assignment->id, 'role' => $assignment->role->slug, 'scope' => $assignment->scope_type],
            'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255), 'url' => $request->fullUrl(),
        ]);

        return response()->json(['data' => $this->profile($user->refresh())]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profile($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        // Personal data is read-only: corrections go through change requests (My account). Only the language is a preference.
        $data = $request->validate(['locale' => ['sometimes', 'in:ar,en']]);

        $request->user()->update($data);

        return response()->json(['data' => $this->profile($request->user()->refresh())]);
    }

    protected function session(array $session): JsonResponse
    {
        return response()->json([
            'password_expired' => app(PasswordService::class)->expired($session['user']),
            'access_token' => $session['access_token'],
            'refresh_token' => $session['refresh_token'],
            'token_type' => 'bearer',
            'expires_in' => $session['expires_in'],
            'user' => $this->profile($session['user']),
        ]);
    }

    public function profile(User $user): array
    {
        $user->loadMissing(['employee.school', 'employee.jobTitle', 'employee.department', 'trainer']);
        $context = app(ActiveRole::class);
        $assignments = $context->assignments($user);
        // Signing in happens before any request is made in a role: the remembered (or highest) one is the active role.
        $active = $context->isSet($user) ? $context->for($user) : $context->defaultFor($user, $assignments);
        if (! $context->isSet($user)) {
            $context->set($user, $active);
        }
        $ar = app()->getLocale() === 'ar';
        $roles = $assignments->map(function (RoleUser $a) use ($active, $ar) {
            $scope = ScopeLabel::for($a);

            return [
                'id' => $a->id, 'slug' => $a->role->slug, 'name' => $ar ? $a->role->name_ar : $a->role->name_en, 'name_ar' => $a->role->name_ar, 'name_en' => $a->role->name_en,
                'scope_type' => $a->scope_type, 'scope_id' => $a->scope_id, 'scope_label_ar' => $scope['ar'], 'scope_label_en' => $scope['en'],
                'landing_route' => $a->role->landing_route ?: ($a->role->level >= 40 ? '/admin' : '/portal'), 'expires_at' => $a->expires_at?->toIso8601String(), 'active' => $active?->id === $a->id,
            ];
        })->values();

        return [
            'id' => $user->id,
            'name' => $user->displayName(),
            'name_en' => $user->name,
            'name_ar' => $user->name_ar,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'roles' => $roles,
            'active_role' => $roles->firstWhere('active', true),
            'permissions' => $user->isSuperAdmin() ? ['*'] : $user->permissionSlugs(),
            'employee' => $user->employee ? new EmployeeResource($user->employee) : null,
            'trainer_id' => $user->trainer?->id,
        ];
    }
}
