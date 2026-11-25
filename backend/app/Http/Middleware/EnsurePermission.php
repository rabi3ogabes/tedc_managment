<?php

namespace App\Http\Middleware;

use App\Security\SecurityEvents;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('permission:programs.manage|programs.view')  (any of the listed permissions)
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        foreach (explode('|', $permissions) as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        SecurityEvents::denied($user, $request);
        // The person may read this page but was not given this action on it: say which, instead of a bare refusal.
        if ($action = $user->limitedOn(explode('|', $permissions))) {
            return response()->json(['message' => __('auth.action_denied.'.$action), 'code' => 'action_denied', 'action' => $action], 403);
        }

        abort(403, __('auth.forbidden'));
    }
}
