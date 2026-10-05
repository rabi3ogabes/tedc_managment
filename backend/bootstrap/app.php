<?php

use App\Http\Middleware\ChooseNotifyChannels;
use App\Http\Middleware\CompactJson;
use App\Http\Middleware\EdgeCache;
use App\Http\Middleware\EnforceSessionLock;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\RequireFeature;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Services\ErrorLogService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Liveness probe for load balancers: plain JSON outside the web middleware group
        // (no session, cookies or views), so it answers wherever PHP runs.
        then: fn () => Route::get('/up', fn () => response()->json(['status' => 'up']))->name('health'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Railway's / any load balancer's TLS proxy: trust X-Forwarded-* so URLs are https.
        $middleware->trustProxies(at: '*');
        $middleware->api(prepend: [CompactJson::class, SetLocale::class, ChooseNotifyChannels::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['permission' => EnsurePermission::class, 'edge.cache' => EdgeCache::class, 'unlocked' => EnforceSessionLock::class, 'feature' => RequireFeature::class]);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every server exception worth a look goes to the error log (grouped, scrubbed and, when a remedy is known, fixed).
        $exceptions->report(function (Throwable $e) {
            app(ErrorLogService::class)->recordThrowable($e);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(fn (AuthenticationException $e, Request $request) => response()->json(['message' => __('auth.unauthenticated')], 401));
        // "No query results for model [App\Models\X]" names an internal class: say only that it was not found.
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $e->getPrevious() instanceof ModelNotFoundException
            ? response()->json(['message' => __('messages.not_found')], 404)
            : null);
        // PostgreSQL rejects malformed UUIDs (SQLSTATE 22P02): a bad id in a URL is "not found", not a server error.
        $exceptions->render(fn (QueryException $e, Request $request) => ($e->errorInfo[0] ?? null) === '22P02'
            ? response()->json(['message' => __('messages.not_found')], 404)
            : null);
    })->create();
