<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Custom roles: create from scratch or clone a role, edit, delete. System roles stay as shipped (their permissions are edited in the matrix). */
class RoleAdminController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:120'], 'name_en' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'],
            'slug' => ['nullable', 'alpha_dash', 'max:60', 'unique:roles,slug'],
            'permissions' => ['sometimes', 'array'], 'permissions.*' => ['exists:permissions,slug'],
            'clone_from' => ['nullable', 'uuid', 'exists:roles,id'],
            'scope_levels' => ['sometimes', 'nullable', 'array', 'min:1'], 'scope_levels.*' => [Rule::in(RoleUser::SCOPES)],
            'landing_route' => ['nullable', 'string', 'max:120'], 'level' => ['sometimes', 'integer', 'between:1,99'],
        ]);

        $source = isset($data['clone_from']) ? Role::with('permissions:id,slug')->findOrFail($data['clone_from']) : null;
        if ($source?->slug === Role::SUPER_ADMIN) {
            throw new BusinessRuleException(__('messages.roles.no_clone_super'), 'no_clone_super');
        }
        $slug = $data['slug'] ?? $this->uniqueSlug($data['name_en']);
        $permissions = $data['permissions'] ?? $source?->permissions->pluck('slug')->all() ?? [];

        $role = Role::create([
            'slug' => $slug, 'name_ar' => $data['name_ar'], 'name_en' => $data['name_en'], 'description' => $data['description'] ?? null,
            'level' => $data['level'] ?? $source?->level ?? 20, 'is_system' => false,
            'scope_levels' => $data['scope_levels'] ?? $source?->scope_levels, 'landing_route' => $data['landing_route'] ?? $source?->landing_route,
        ]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id'));
        $this->audit('role_created', $role, $request, ['slug' => $slug, 'cloned_from' => $source?->slug, 'permissions' => $permissions]);

        return response()->json(['data' => $role->load('permissions:id,slug')], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->customOnly($role);
        $data = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:120'], 'name_en' => ['sometimes', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'],
            'scope_levels' => ['sometimes', 'nullable', 'array', 'min:1'], 'scope_levels.*' => [Rule::in(RoleUser::SCOPES)],
            'landing_route' => ['nullable', 'string', 'max:120'], 'level' => ['sometimes', 'integer', 'between:1,99'],
        ]);
        $before = $role->only(array_keys($data));
        $role->update($data);
        $this->audit('role_updated', $role, $request, ['before' => $before, 'after' => $data]);

        return response()->json(['data' => $role->fresh('permissions:id,slug')]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->customOnly($role);
        if ($role->users()->exists()) {
            throw new BusinessRuleException(__('messages.roles.in_use'), 'role_in_use');
        }
        $this->audit('role_deleted', $role, $request, ['slug' => $role->slug]);
        $role->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function customOnly(Role $role): void
    {
        if ($role->is_system) {
            throw new BusinessRuleException(__('messages.roles.system_protected'), 'system_role');
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = 'custom_'.(Str::slug($name, '_') ?: 'role');
        $slug = $base;
        for ($i = 2; Role::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'_'.$i;
        }

        return $slug;
    }

    /** @param  array<string, mixed>  $values */
    private function audit(string $action, Role $role, Request $request, array $values): void
    {
        AuditLog::create(['user_id' => $this->user()->id, 'action' => $action, 'auditable_type' => Role::class, 'auditable_id' => $role->id, 'new_values' => $values, 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255), 'url' => $request->fullUrl()]);
    }
}
