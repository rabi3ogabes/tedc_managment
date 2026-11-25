<?php

namespace App\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Locks an account after too many wrong passwords for a while; an administrator can unlock it at once. */
class LoginGuard
{
    public function __construct(private readonly SecurityPolicy $policy, private readonly NotificationService $notifications) {}

    /** @throws ValidationException when the account is locked */
    public function assertOpen(?User $user): void
    {
        if ($user && $user->locked_until?->isFuture()) {
            throw ValidationException::withMessages(['email' => __('auth.locked', ['minutes' => (int) ceil(now()->diffInSeconds($user->locked_until, true) / 60)])])->status(423);
        }
    }

    public function failed(?User $user, Request $request): void
    {
        SecurityEvents::record('login_failed', $user, 'failed', ['email_hash' => sha1(strtolower((string) $request->input('email', '')))], $request);
        if (! $user) {
            return;
        }
        $rule = $this->policy->all()['lockout'];
        $n = $user->failed_attempts + 1;
        if ($n >= $rule['max_attempts']) {
            $user->forceFill(['failed_attempts' => 0, 'locked_until' => now()->addMinutes($rule['minutes'])])->saveQuietly();
            SecurityEvents::record('account_locked', $user, 'locked', ['minutes' => $rule['minutes']], $request);
            AuditLog::create(['user_id' => $user->id, 'action' => 'account_locked', 'auditable_type' => User::class, 'auditable_id' => $user->id, 'new_values' => ['minutes' => $rule['minutes']], 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 250), 'url' => $request->fullUrl()]);
            $this->notifications->send($user, 'security.locked', ['ar' => 'تم قفل حسابك مؤقتًا', 'en' => 'Your account is locked for a while'], ['ar' => 'بسبب محاولات دخول خاطئة متكررة. يمكنك المحاولة بعد '.$rule['minutes'].' دقيقة أو التواصل مع المسؤول.', 'en' => "Too many wrong passwords. Try again in {$rule['minutes']} minutes or ask an administrator."], raw: true);
        } else {
            $user->forceFill(['failed_attempts' => $n])->saveQuietly();
        }
    }

    public function succeeded(User $user): void
    {
        SecurityEvents::record('login_success', $user);
        if ($user->failed_attempts || $user->locked_until) {
            $user->forceFill(['failed_attempts' => 0, 'locked_until' => null])->saveQuietly();
        }
    }

    public function unlock(User $user, ?User $by, Request $request): void
    {
        $user->forceFill(['failed_attempts' => 0, 'locked_until' => null])->saveQuietly();
        AuditLog::create(['user_id' => $by?->id, 'action' => 'account_unlocked', 'auditable_type' => User::class, 'auditable_id' => $user->id, 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 250), 'url' => $request->fullUrl()]);
    }
}
