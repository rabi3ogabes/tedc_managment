<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\User;
use App\Support\ScopeLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A user's roles, each granted at a scope (Ministry, school group, school or department) and optionally until a date. */
class UserRoleController extends Controller
{
    /** Finds people to grant something to (program staff screen): name, e-mail and roles, ten at a time. */
    public function lookup(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($q) < 2, 422, __('validation.min.string', ['attribute' => 'q', 'min' => 2]));
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

        return response()->json(['data' => User::with('roles:id,slug,name_ar,name_en')->where('status', 'active')
            ->where(fn ($w) => $w->whereLike('name', $like)->orWhereLike('name_ar', $like)->orWhereLike('email', $like))
            ->orderBy('name')->limit(10)->get()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->displayName(), 'email' => $u->email, 'roles' => $u->roles->map(fn ($r) => app()->getLocale() === 'ar' ? $r->name_ar : $r->name_en)->all()])]);
    }

    public function index(User $user): JsonResponse
    {
        return response()->json(['data' => RoleUser::with('role')->where('user_id', $user->id)->get()->map(fn (RoleUser $g) => $this->present($g))->values()]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'uuid', 'exists:roles,id'],
            'scope_type' => ['required', Rule::in(RoleUser::SCOPES)],
            'scope_id' => ['nullable', 'uuid'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $role = Role::findOrFail($data['role_id']);
        abort_if($role->slug === Role::SUPER_ADMIN && ! $this->user()->isSuperAdmin(), 403, __('auth.forbidden'));

        $allowed = $role->scope_levels ?? RoleUser::SCOPES;
        if (! in_array($data['scope_type'], $allowed, true)) {
            throw new BusinessRuleException(__('messages.roles.scope_not_allowed'), 'scope_not_allowed');
        }
        $scopeId = $data['scope_type'] === 'ministry' ? null : ($data['scope_id'] ?? null);
        if ($data['scope_type'] !== 'ministry') {
            $exists = $scopeId && match ($data['scope_type']) {
                'school' => School::whereKey($scopeId)->exists(),
                'school_group' => SchoolGroup::whereKey($scopeId)->exists(),
                'department' => Department::whereKey($scopeId)->exists(),
            };
            if (! $exists) {
                throw new BusinessRuleException(__('messages.roles.scope_required'), 'scope_required');
            }
        }
        $duplicate = RoleUser::where('user_id', $user->id)->where('role_id', $role->id)->where('scope_type', $data['scope_type'])->where('scope_id', $scopeId)->exists();
        if ($duplicate) {
            throw new BusinessRuleException(__('messages.roles.already_granted'), 'already_granted');
        }

        $grant = RoleUser::create([
            'user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $data['scope_type'], 'scope_id' => $scopeId,
            'granted_by' => $this->user()->id, 'granted_at' => now(), 'expires_at' => $data['expires_at'] ?? null,
        ]);
        $this->audit('role_granted', $user, $request, ['role' => $role->slug, 'scope_type' => $data['scope_type'], 'scope_id' => $scopeId, 'expires_at' => $data['expires_at'] ?? null]);

        return response()->json(['data' => $this->present($grant->load('role'))], 201);
    }

    public function destroy(Request $request, User $user, RoleUser $roleUser): JsonResponse
    {
        abort_unless($roleUser->user_id === $user->id, 404);
        $role = $roleUser->role;
        abort_if($role->slug === Role::SUPER_ADMIN && ! $this->user()->isSuperAdmin(), 403, __('auth.forbidden'));
        if (RoleUser::where('user_id', $user->id)->count() <= 1) {
            throw new BusinessRuleException(__('messages.roles.last_role'), 'last_role');
        }

        $roleUser->delete();
        $this->audit('role_revoked', $user, $request, ['role' => $role->slug, 'scope_type' => $roleUser->scope_type, 'scope_id' => $roleUser->scope_id]);

        return response()->json(['data' => ['revoked' => true]]);
    }

    /**
     * The rights of one person in one of their roles: what is added for them on top of the role, and what is taken from
     * them out of it. The role itself is not touched, so nobody else who holds it is affected. What is sent is tidied:
     * a right the role already gives is not "added", and only a right the role gives can be "taken".
     */
    public function permissions(Request $request, User $user, RoleUser $roleUser): JsonResponse
    {
        abort_unless($roleUser->user_id === $user->id, 404);
        $role = $roleUser->role()->with('permissions:id,slug')->firstOrFail();
        // The system administrator's rights are implicit and complete: there is nothing to add to them or take from them.
        abort_if($role->slug === Role::SUPER_ADMIN, 422, __('messages.roles.super_admin_fixed'));

        $known = Permission::pluck('slug')->all();
        $data = $request->validate([
            'granted' => ['present', 'array'], 'granted.*' => ['string', Rule::in($known)],
            'revoked' => ['present', 'array'], 'revoked.*' => ['string', Rule::in($known)],
        ]);
        $own = $role->permissions->pluck('slug');
        $granted = collect($data['granted'])->unique()->diff($own)->sort()->values();
        $revoked = collect($data['revoked'])->unique()->intersect($own)->sort()->values();
        $before = ['granted' => $roleUser->granted_permissions ?? [], 'revoked' => $roleUser->revoked_permissions ?? []];

        $roleUser->update(['granted_permissions' => $granted->all() ?: null, 'revoked_permissions' => $revoked->all() ?: null]);
        AuditLog::create([
            'user_id' => $this->user()->id, 'action' => 'user_permissions_changed', 'auditable_type' => User::class, 'auditable_id' => $user->id,
            'old_values' => $before, 'new_values' => ['for' => $user->email, 'role' => $role->slug, 'granted' => $granted->all(), 'revoked' => $revoked->all()],
            'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255), 'url' => $request->fullUrl(),
        ]);

        return response()->json(['data' => $this->present($roleUser->fresh()->load('role'))]);
    }

    /** @return array<string, mixed> */
    private function present(RoleUser $g): array
    {
        $label = ScopeLabel::for($g);

        return [
            'id' => $g->id, 'role_id' => $g->role_id, 'slug' => $g->role->slug, 'name_ar' => $g->role->name_ar, 'name_en' => $g->role->name_en,
            'scope_type' => $g->scope_type, 'scope_id' => $g->scope_id, 'scope_label_ar' => $label['ar'], 'scope_label_en' => $label['en'],
            'granted_at' => $g->granted_at?->toIso8601String(), 'expires_at' => $g->expires_at?->toIso8601String(), 'expired' => ! $g->isActive(),
            // Rights of this person only, on top of the role's (added) and out of the role's (taken).
            'granted_permissions' => $g->granted_permissions ?? [], 'revoked_permissions' => $g->revoked_permissions ?? [],
        ];
    }

    /** @param  array<string, mixed>  $values */
    private function audit(string $action, User $user, Request $request, array $values): void
    {
        AuditLog::create(['user_id' => $this->user()->id, 'action' => $action, 'auditable_type' => User::class, 'auditable_id' => $user->id, 'new_values' => $values + ['for' => $user->email], 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255), 'url' => $request->fullUrl()]);
    }
}
