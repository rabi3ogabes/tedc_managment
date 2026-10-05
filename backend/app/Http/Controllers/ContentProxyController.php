<?php

namespace App\Http\Controllers;

use App\Models\ContentPackage;
use App\Services\Content\Mime;
use App\Services\Content\PackageToken;
use App\Services\FileStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Serves package files from the app's own origin (SCORM needs the player and the content on one origin) with a signed, short-lived token. */
class ContentProxyController extends Controller
{
    public function show(Request $request, FileStorage $storage, string $token, string $package, string $path = ''): Response
    {
        abort_unless(PackageToken::read($token, $package), 403);
        $path = ltrim(str_replace('\\', '/', rawurldecode($path)), '/');
        abort_if(preg_match('#(^|/)\.\.(/|$)#', $path) || str_contains($path, "\0"), 404);
        $pkg = ContentPackage::where('status', 'ready')->findOrFail($package);
        $path = $path === '' ? ($pkg->entry_points[0]['href'] ?? 'index.html') : $path;

        try {
            $bytes = $storage->get('packages', "{$pkg->storage_root}/{$path}");
        } catch (Throwable) {
            abort(404);
        }
        $headers = ['Content-Type' => Mime::of($path), 'X-Content-Type-Options' => 'nosniff', 'Accept-Ranges' => 'bytes', 'Cache-Control' => 'private, max-age=600',
            'Content-Security-Policy' => "frame-ancestors 'self'; object-src 'none'; base-uri 'self'", 'Referrer-Policy' => 'no-referrer'];

        $total = strlen($bytes);
        if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) $request->header('Range'), $m) && ($m[1] !== '' || $m[2] !== '')) {
            $start = $m[1] === '' ? max(0, $total - (int) $m[2]) : (int) $m[1];
            $end = $m[2] === '' || $m[1] === '' ? $total - 1 : min((int) $m[2], $total - 1);
            if ($start > $end || $start >= $total) {
                return response('', 416, ['Content-Range' => "bytes */{$total}"]);
            }

            return response(substr($bytes, $start, $end - $start + 1), 206, $headers + ['Content-Range' => "bytes {$start}-{$end}/{$total}"]);
        }

        return response($bytes, 200, $headers);
    }
}
