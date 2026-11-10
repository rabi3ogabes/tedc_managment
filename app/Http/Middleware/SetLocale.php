<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Arabic is the primary language of the platform. Clients opt into English
 * explicitly with `?lang=en` or the `X-Locale: en` header (web & mobile apps send
 * the user's chosen language). Browser Accept-Language is intentionally ignored so
 * that the default experience is always Arabic.
 */
class SetLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->query('lang') ?? $request->header('X-Locale');
        $locale = in_array($requested, self::SUPPORTED, true) ? $requested : config('app.locale', 'ar');

        App::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
