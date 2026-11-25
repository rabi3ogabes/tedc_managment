<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\ActiveRole;
use App\Support\Features;
use App\Support\ScopeLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a person sees and may do, role by role: their position, each role they hold with its rights and where it lands,
 * and whether the administrator may open the account. Read only: nothing here touches the person's session.
 *
 * A person works in one role at a time (ActiveRole), so the rights are listed per role, not added together.
 */
class UserAccessController extends Controller
{
    public function show(Request $request, User $user, ActiveRole $context): JsonResponse
    {
        $user->loadMissing(['employee.school', 'employee.jobTitle', 'employee.department', 'trainer']);
        $grants = $context->assignments($user);
        $default = $context->defaultFor($user, $grants);
        $employee = $user->employee;
        $named = fn ($model) => $model ? ['ar' => $model->name_ar, 'en' => $model->name_en ?: $model->name_ar] : null;

        return response()->json(['data' => [
            'user' => [
                'id' => $user->id, 'name' => $user->displayName(), 'name_ar' => $user->name_ar, 'name_en' => $user->name, 'email' => $user->email,
                'status' => $user->status, 'last_login_at' => $user->last_login_at,
            ],
            'position' => [
                'job_title' => $named($employee?->jobTitle), 'school' => $named($employee?->school), 'department' => $named($employee?->department),
                'has_trainee_profile' => $employee !== null, 'is_trainer' => $user->trainer !== null,
            ],
            'roles' => $grants->map(function (RoleUser $grant) use ($default) {
                $role = $grant->role;
                $scope = ScopeLabel::for($grant);

                return [
                    'id' => $grant->id, 'slug' => $role->slug, 'name_ar' => $role->name_ar, 'name_en' => $role->name_en,
                    'scope_type' => $grant->scope_type, 'scope_label_ar' => $scope['ar'], 'scope_label_en' => $scope['en'],
                    'landing_route' => $role->landing_route ?: ($role->level >= 40 ? '/admin' : '/portal'),
                    'expires_at' => $grant->expires_at?->toIso8601String(),
                    // The role the person starts in when they sign in.
                    'starts_in' => $default?->id === $grant->id,
                    // What the person may do in this role: the role's rights with what was added for them and without what was taken from them.
                    'permissions' => $role->slug === Role::SUPER_ADMIN ? ['*'] : $grant->effectivePermissions()->sort()->values(),
                    'granted' => $grant->granted_permissions ?? [], 'revoked' => $grant->revoked_permissions ?? [],
                    'hidden_pages' => array_values($grant->hidden_pages ?? []), 'denied_actions' => (object) ($grant->denied_actions ?? []),
                ];
            })->values(),
            'sign_in' => $this->signIn($request->user(), $user),
        ]]);
    }

    /**
     * Whether this administrator may open the account, and if not, why: the same rules as ImpersonationController,
     * told before the button is pressed.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    private function signIn(User $admin, User $user): array
    {
        $reason = match (true) {
            ! $admin->isSuperAdmin() => 'not_system_admin',
            $user->id === $admin->id => 'self',
            $user->isSuperAdmin() => 'admin',
            $user->status !== 'active' => 'inactive',
            ! Features::enabled('impersonation') => 'feature_off',
            default => null,
        };

        return ['allowed' => $reason === null, 'reason' => $reason];
    }
}
