<?php

namespace App\Http\Middleware;

use App\Support\ActiveRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Decides which of the user's roles this request is made in (see ActiveRole). Runs before the permission checks. */
class ResolveActiveRole
{
    public function __construct(private readonly ActiveRole $active) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->active->clear();
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $assignments = $this->active->assignments($user);
        $requested = trim((string) $request->header('X-Active-Role', ''));
        if ($requested !== '') {
            $assignment = $assignments->firstWhere('id', $requested);
            if (! $assignment) {
                return response()->json(['message' => __('auth.role_not_held'), 'code' => 'role_not_held'], 403);
            }
        } else {
            $assignment = $this->active->defaultFor($user, $assignments);
        }
        $this->active->set($user, $assignment);

        return $next($request);
    }
}
