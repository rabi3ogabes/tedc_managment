<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * "throttle:N,M" limits requests to the route it is on. Laravel's middleware keys a numeric limit by the person (or the
 * address) alone, so all throttled routes shared one counter and a busy page — presence pings, lesson heartbeats — made
 * unrelated low-limit routes answer "too many attempts". Named limiters are unchanged.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $scope = $route ? ($route->getName() ?: $route->getDomain().'|'.implode(',', $route->methods()).'|'.$route->uri()) : '';

        return sha1(parent::resolveRequestSignature($request).'|'.$scope);
    }
}
