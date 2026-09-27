<?php

namespace App\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

/**
 * Resolves the authenticated platform user from a bearer JWT.
 */
class SupabaseUserResolver
{
    public function __construct(private readonly JwtVerifier $verifier) {}

    public function __invoke(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        try {
            $claims = $this->verifier->decode($token);
        } catch (Throwable) {
            return null;
        }

        $subject = (string) ($claims->sub ?? '');
        if ($subject === '') {
            return null;
        }

        $user = User::with('roles')->where('auth_id', $subject)->first()
            ?? User::with('roles')->whereKey($subject)->first();

        if (! $user && config('tedc.auth.auto_provision') && ! empty($claims->email)) {
            $user = $this->provision($subject, $claims);
        }

        return $user && $user->status === 'active' ? $user : null;
    }

    /**
     * Just-in-time provisioning for users created directly in Supabase Auth.
     * New accounts receive the least-privileged "employee" role.
     */
    private function provision(string $subject, object $claims): User
    {
        $user = User::firstOrCreate(
            ['email' => strtolower($claims->email)],
            ['name' => $claims->user_metadata->full_name ?? $claims->email, 'locale' => 'ar'],
        );
        $user->forceFill(['auth_id' => $subject])->save();

        if ($user->roles()->doesntExist() && ($role = Role::where('slug', Role::EMPLOYEE)->first())) {
            $user->roles()->attach($role);
        }

        return $user->load('roles');
    }
}
