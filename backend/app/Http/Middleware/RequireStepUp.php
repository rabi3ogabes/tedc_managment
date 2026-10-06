<?php

namespace App\Http\Middleware;

use App\Security\AuthSessions;
use App\Security\SecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Privileged actions (security policy, identity settings, ending other people's sessions) ask people who use a second factor to
 * confirm it again: a code verified within the last ten minutes is enough. Sessions without tracking (older tokens) and people without a second factor pass.
 */
class RequireStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $session = $request->attributes->get('auth_session');
        if ($user && $session && app(SecurityPolicy::class)->mfaRequired($user) && $user->mfa_enabled && ! app(AuthSessions::class)->steppedUp($session)) {
            return response()->json(['message' => __('auth.step_up'), 'code' => 'step_up_required'], 403);
        }

        return $next($request);
    }
}
