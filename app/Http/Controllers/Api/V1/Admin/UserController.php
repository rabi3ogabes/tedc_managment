<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Users, roles & permissions (RBAC administration) and the audit trail.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(User::with('roles:id,slug,name_ar,name_en')
            ->when($request->query('role'), fn ($q, $r) => $q->whereHas('roles', fn ($w) => $w->where('slug', $r)))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->whereLike('name', "%{$t}%")->orWhereLike('name_ar', "%{$t}%")->orWhereLike('email', "%{$t}%")))
            ->orderBy('name')
            ->paginate($this->perPage($request, 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'name_ar' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:10'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['exists:roles,slug'],
        ]);
        $this->guardSuperAdmin($data['roles']);

        $user = User::create(collect($data)->except('roles')->all() + ['email' => strtolower($data['email'])]);
        $user->roles()->sync(Role::whereIn('slug', $data['roles'])->pluck('id'));

        return response()->json(['data' => $user->load('roles')], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'name_ar' => ['nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['exists:roles,slug'],
        ]);

        if (isset($data['roles'])) {
            $this->guardSuperAdmin($data['roles']);
            $user->roles()->sync(Role::whereIn('slug', $data['roles'])->pluck('id'));
            AuditLog::create([
                'user_id' => $this->user()->id,
                'action' => 'roles_changed',
                'auditable_type' => $user->getMorphClass(),
                'auditable_id' => $user->id,
                'new_values' => ['roles' => $data['roles']],
                'ip_address' => $request->ip(),
            ]);
        }
        $user->update(collect($data)->except('roles')->all());

        return response()->json(['data' => $user->load('roles')]);
    }

    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => Role::with('permissions:id,slug')->withCount('users')->orderByDesc('level')->get(),
            'permissions' => Permission::orderBy('group')->orderBy('slug')->get()->groupBy('group'),
        ]);
    }

    /** Role names for pickers; unlike roles() it carries no permission lists, so it can be offered to more people. */
    public function roleOptions(): JsonResponse
    {
        return response()->json(['data' => Role::orderByDesc('level')->get(['id', 'slug', 'name_ar', 'name_en', 'level'])]);
    }

    public function updateRolePermissions(Request $request, Role $role): JsonResponse
    {
        abort_if($role->slug === Role::SUPER_ADMIN, 422, 'Super admin permissions are implicit.');

        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['exists:permissions,slug']]);
        $role->permissions()->sync(Permission::whereIn('slug', $data['permissions'])->pluck('id'));

        AuditLog::create([
            'user_id' => $this->user()->id,
            'action' => 'permissions_changed',
            'auditable_type' => $role->getMorphClass(),
            'auditable_id' => $role->id,
            'new_values' => ['permissions' => $data['permissions']],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['data' => $role->load('permissions')]);
    }

    public function audit(Request $request): JsonResponse
    {
        return response()->json(AuditLog::with('user:id,name,name_ar,email')
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', $a))
            ->when($request->query('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->query('type'), fn ($q, $t) => $q->whereLike('auditable_type', "%{$t}%"))
            ->latest('created_at')
            ->paginate($this->perPage($request, 50)));
    }

    /**
     * Only super admins can grant the super admin role.
     */
    private function guardSuperAdmin(array $roles): void
    {
        abort_if(in_array(Role::SUPER_ADMIN, $roles, true) && ! $this->user()->isSuperAdmin(), 403, __('auth.forbidden'));
    }
}
