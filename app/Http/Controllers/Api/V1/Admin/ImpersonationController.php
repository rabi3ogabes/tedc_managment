<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Auth\JwtVerifier;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Sign in as": the system administrator opens another user's account to check or fix it. The session lasts one
 * hour, cannot be refreshed, and both the start and the end are written to the audit log.
 */
class ImpersonationController extends Controller
{
    private const TTL = 3600;

    public function start(Request $request, User $user, JwtVerifier $jwt, AuthController $auth): JsonResponse
    {
        $admin = $request->user();
        abort_unless($admin->isSuperAdmin(), 403, __('auth.forbidden'));

        if ($user->id === $admin->id) {
            throw new BusinessRuleException(__('messages.impersonation.self'), 'impersonation_self');
        }
        if ($user->isSuperAdmin()) {
            throw new BusinessRuleException(__('messages.impersonation.admin'), 'impersonation_admin');
        }
        if ($user->status !== 'active') {
            throw new BusinessRuleException(__('messages.impersonation.inactive'), 'impersonation_inactive');
        }

        $this->audit($admin, $user, 'impersonation_started', $request);

        return response()->json([
            'access_token' => $jwt->issue(['sub' => $user->id, 'email' => $user->email, 'typ' => 'access', 'imp' => $admin->id], self::TTL),
            'refresh_token' => '',
            'token_type' => 'bearer',
            'expires_in' => self::TTL,
            'user' => $auth->profile($user),
            'impersonator' => ['id' => $admin->id, 'name' => $admin->displayName()],
        ]);
    }

    /** Records the return to the administrator's own account (the client discards the borrowed token). */
    public function stop(Request $request, User $user): JsonResponse
    {
        $this->audit($request->user(), $user, 'impersonation_ended', $request);

        return response()->json(['message' => 'ok']);
    }

    private function audit(User $admin, User $target, string $action, Request $request): void
    {
        AuditLog::create([
            'user_id' => $admin->id, 'action' => $action, 'auditable_type' => User::class, 'auditable_id' => $target->id,
            'new_values' => ['admin' => $admin->email, 'as' => $target->email], 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255), 'url' => $request->fullUrl(),
        ]);
    }
}
