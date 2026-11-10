<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Every request carries an id from the first hop to the logs and back to the caller, so one problem can be followed through the gateway, the API and the SIEM. */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $given = (string) $request->header('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $given) ? $given : (string) Str::uuid();
        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
