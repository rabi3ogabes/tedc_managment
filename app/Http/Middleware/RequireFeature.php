<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('feature:payments') — the route answers 403 `feature_disabled` while the flag is off. */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! Features::enabled($feature)) {
            return response()->json(['message' => __('messages.features.disabled'), 'code' => 'feature_disabled', 'details' => ['feature' => $feature]], 403);
        }

        return $next($request);
    }
}
