<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=()',
        ]);
        // A response that names who may frame it (the LTI launch pages: only the registered platform) keeps that rule;
        // everything else may not be framed at all.
        if (! str_contains((string) $response->headers->get('Content-Security-Policy'), 'frame-ancestors')) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
