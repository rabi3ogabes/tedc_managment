<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// In the production image the React app is built into public/app.html (see /Dockerfile):
// every non-API page returns it and the client-side router takes over.
$spa = function () {
    $shell = public_path('app.html');

    return is_file($shell)
        ? response()->file($shell, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-cache'])
        : response()->json(['name' => config('tedc.name'), 'api' => url('/api/v1'), 'docs' => 'See docs/API.md in the repository.']);
};

Route::get('/', $spa);

// Local-driver equivalent of a Supabase signed URL (development only).
Route::get('/files/{bucket}/{path}', function (string $bucket, string $path) {
    abort_unless(Storage::disk('local')->exists("{$bucket}/{$path}"), 404);

    // SVGs are served sandboxed so an uploaded or generated image can never run script.
    $headers = str_ends_with(strtolower($path), '.svg') ? ['Content-Security-Policy' => 'sandbox; default-src \'none\'; style-src \'unsafe-inline\''] : [];

    // A file response supports Range requests, which video seeking needs.
    return response()->file(Storage::disk('local')->path("{$bucket}/{$path}"), $headers);
})->where('path', '.*')->middleware('signed')->name('files.local');

Route::fallback(function (Request $request) use ($spa) {
    // API routes, non-GET requests and missing static files (e.g. an old /assets/*.js) stay 404.
    abort_if($request->is('api/*') || ! $request->isMethod('GET') || preg_match('/\.[a-z0-9]{1,5}$/i', $request->path()), 404);

    return $spa();
});
