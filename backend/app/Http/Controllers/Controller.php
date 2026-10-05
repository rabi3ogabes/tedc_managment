<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function user(): User
    {
        /** @var User */
        return request()->user();
    }

    protected function isCenterStaff(?User $user = null): bool
    {
        return ($user ?? $this->user())->hasRole(...Role::CENTER_STAFF);
    }

    /** What the active role may see (Ministry, school group, school or department). */
    protected function scope(): AccessScope
    {
        return AccessScope::current($this->user());
    }

    protected function perPage(Request $request, int $default = 20): int
    {
        return min(100, max(1, (int) $request->query('per_page', $default)));
    }
}
