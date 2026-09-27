<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Railway's / any load balancer's TLS proxy: trust X-Forwarded-* so URLs are https.
        $middleware->trustProxies(at: '*');
        $middleware->api(prepend: [SetLocale::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['permission' => EnsurePermission::class]);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(fn (AuthenticationException $e, Request $request) => response()->json(['message' => __('auth.unauthenticated')], 401));
        // PostgreSQL rejects malformed UUIDs (SQLSTATE 22P02): a bad id in a URL is "not found", not a server error.
        $exceptions->render(fn (QueryException $e, Request $request) => ($e->errorInfo[0] ?? null) === '22P02'
            ? response()->json(['message' => __('messages.not_found')], 404)
            : null);
    })->create();
