<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the CDN (Vercel's edge network) serve anonymous public GET responses: visitors get them in a few
 * milliseconds while the API refreshes them in the background (stale-while-revalidate). Responses vary by
 * the `lang` query parameter, which the web and mobile apps always send. Signed-in or personalised requests
 * are never cached.
 */
class EdgeCache
{
    public function handle(Request $request, Closure $next, int $seconds = 60): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || $request->bearerToken() || $response->getStatusCode() !== 200 || $response->headers->has('Set-Cookie')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'public, max-age='.min(30, $seconds).', stale-while-revalidate=300');
        // Edge-only directives (Vercel and other CDNs), longer than the browser's.
        $edge = "public, s-maxage={$seconds}, stale-while-revalidate=86400";
        $response->headers->set('CDN-Cache-Control', $edge);
        $response->headers->set('Vercel-CDN-Cache-Control', $edge);
        $response->headers->set('Vary', 'X-Locale, Accept-Encoding');

        return $response;
    }
}
