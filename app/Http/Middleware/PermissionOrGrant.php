<?php

namespace App\Http\Middleware;

use App\Services\ProgramGrantService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('can_or_grant:attendance.manage,attendance.mark') — passes for people whose role has the permission, and for
 * people who hold the program grant on at least one program. The controller then checks the specific program.
 */
class PermissionOrGrant
{
    public function __construct(private readonly ProgramGrantService $grants) {}

    public function handle(Request $request, Closure $next, string $permission, string $ability): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        foreach (explode('|', $permission) as $slug) {
            if ($user->hasPermission($slug)) {
                return $next($request);
            }
        }
        abort_unless($this->grants->holdsAny($user, $ability), 403, __('auth.forbidden'));

        return $next($request);
    }
}
