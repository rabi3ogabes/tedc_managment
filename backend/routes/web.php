<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', fn () => response()->json([
    'name' => config('tedc.name'),
    'api' => url('/api/v1'),
    'docs' => 'See docs/API.md in the repository.',
]));

// Local-driver equivalent of a Supabase signed URL (development only).
Route::get('/files/{bucket}/{path}', function (string $bucket, string $path) {
    abort_unless(Storage::disk('local')->exists("{$bucket}/{$path}"), 404);

    return Storage::disk('local')->response("{$bucket}/{$path}");
})->where('path', '.*')->middleware('signed')->name('files.local');
