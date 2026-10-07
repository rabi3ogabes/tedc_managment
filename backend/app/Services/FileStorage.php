<?php

namespace App\Services;

use App\Support\AzureBlob;
use App\Support\Supabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * File storage abstraction over Supabase Storage (production) and the local private disk (development).
 *
 * All buckets are private: files are only reachable through short-lived signed URLs that
 * the API hands out after an authorization check (file access control).
 */
class FileStorage
{
    public function usesSupabase(): bool
    {
        return config('tedc.storage_driver') === 'supabase';
    }

    public function usesAzure(): bool
    {
        return config('tedc.storage_driver') === 'azure';
    }

    private function azure(): AzureBlob
    {
        return app(AzureBlob::class);
    }

    public function upload(UploadedFile $file, string $bucket, string $directory): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension;

        return $this->put($bucket, $path, (string) file_get_contents($file->getRealPath()), $file->getMimeType() ?? 'application/octet-stream');
    }

    /**
     * Stores a publicly readable asset (program covers, trainer photos, news images).
     */
    public function uploadPublic(UploadedFile $file, string $directory): string
    {
        $path = trim($directory, '/').'/'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'jpg');

        if ($this->usesSupabase() || $this->usesAzure()) {
            return $this->put('public', $path, (string) file_get_contents($file->getRealPath()), $file->getMimeType() ?? 'image/jpeg');
        }

        Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
    }

    public static function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (Str::startsWith($path, ['http://', 'https://', '/'])) {
            return $path;
        }
        if (config('tedc.storage_driver') === 'supabase') {
            return rtrim(config('tedc.supabase.url'), '/').'/storage/v1/object/public/'.config('tedc.supabase.buckets.public').'/'.$path;
        }
        if (config('tedc.storage_driver') === 'azure') {
            // Public assets sit in a private container too: a day-long read link, renewed when it is half used.
            $c = (string) (config('tedc.supabase.buckets.public') ?? 'public');

            return Cache::remember('azure.public.'.sha1($path), 43200, fn () => app(AzureBlob::class)->sasUrl($c, $path, 'r', 86400));
        }

        return Storage::disk('public')->url($path);
    }

    public function put(string $bucket, string $path, string $contents, string $mime): string
    {
        $bucketName = $this->bucket($bucket);

        if ($this->usesAzure()) {
            $this->azure()->put($bucketName, $path, $contents, $mime);

            return $path;
        }

        if ($this->usesSupabase()) {
            $send = fn () => $this->supabase()
                ->withHeaders(['Content-Type' => $mime, 'x-upsert' => 'true'])
                ->withBody($contents, $mime)
                ->post($this->endpoint("object/{$bucketName}/{$path}"));
            $response = $send();
            // A bucket that was never created (a new release adds buckets): create it once, then write again.
            if ($response->failed() && str_contains(strtolower((string) $response->body()), 'bucket not found') && $this->createSupabaseBucket($bucketName, $bucket === 'public')) {
                $response = $send();
            }
            $response->throw();
        } else {
            Storage::disk('local')->put("{$bucketName}/{$path}", $contents);
        }

        return $path;
    }

    public function get(string $bucket, string $path): string
    {
        $bucketName = $this->bucket($bucket);

        if ($this->usesAzure()) {
            return $this->azure()->get($bucketName, $path);
        }

        if ($this->usesSupabase()) {
            return $this->supabase()->get($this->endpoint("object/{$bucketName}/{$path}"))->throw()->body();
        }

        $contents = Storage::disk('local')->get("{$bucketName}/{$path}");
        if ($contents === null) {
            throw new RuntimeException('File not found.');
        }

        return $contents;
    }

    public function delete(string $bucket, string $path): void
    {
        $bucketName = $this->bucket($bucket);

        if ($this->usesAzure()) {
            $this->azure()->delete($bucketName, $path);

            return;
        }

        if ($this->usesSupabase()) {
            $this->supabase()->delete($this->endpoint("object/{$bucketName}"), ['prefixes' => [$path]]);

            return;
        }

        Storage::disk('local')->delete("{$bucketName}/{$path}");
    }

    /**
     * Short-lived URL for a private object. Call only after authorizing the requester.
     */
    public function temporaryUrl(string $bucket, string $path, ?int $ttl = null): string
    {
        $ttl ??= config('tedc.supabase.signed_url_ttl');
        $bucketName = $this->bucket($bucket);

        if ($this->usesAzure()) {
            return $this->azure()->sasUrl($bucketName, $path, 'r', (int) $ttl);
        }

        if ($this->usesSupabase()) {
            $signed = $this->supabase()
                ->post($this->endpoint("object/sign/{$bucketName}/{$path}"), ['expiresIn' => $ttl])
                ->throw()
                ->json('signedURL');

            return rtrim(config('tedc.supabase.url'), '/').'/storage/v1'.$signed;
        }

        return URL::temporarySignedRoute('files.local', now()->addSeconds($ttl), ['bucket' => $bucketName, 'path' => $path]);
    }

    /**
     * A one-time place to upload a big file to straight from the browser (the API itself cannot take large bodies
     * on serverless hosting). Supabase: a signed upload URL; local driver: a signed route of this API.
     *
     * @return array{url: string, method: 'PUT', mode: 'form'|'raw', headers: array<string, string>}
     */
    public function signedUpload(string $bucket, string $path, string $mime): array
    {
        $bucketName = $this->bucket($bucket);

        if ($this->usesAzure()) {
            return ['url' => $this->azure()->sasUrl($bucketName, $path, 'cw', 1800), 'method' => 'PUT', 'mode' => 'raw', 'headers' => ['x-ms-blob-type' => 'BlockBlob', 'Content-Type' => $mime]];
        }

        if ($this->usesSupabase()) {
            $res = $this->supabase()->withHeaders(['x-upsert' => 'true'])->post($this->endpoint("object/upload/sign/{$bucketName}/{$path}"))->throw()->json();

            return ['url' => rtrim(config('tedc.supabase.url'), '/').'/storage/v1'.$res['url'], 'method' => 'PUT', 'mode' => 'form', 'headers' => ['x-upsert' => 'true']];
        }

        $signed = URL::temporarySignedRoute('uploads.local', now()->addMinutes(30), ['bucket' => $bucketName, 'path' => $path]);
        $parts = parse_url($signed);

        return ['url' => $parts['path'].'?'.$parts['query'], 'method' => 'PUT', 'mode' => 'raw', 'headers' => ['Content-Type' => $mime]];
    }

    /** Stores the body of a signed local upload (development). */
    public function putStream(string $bucket, string $path, $stream): void
    {
        $target = Storage::disk('local')->path("{$bucket}/{$path}");
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        $out = fopen($target, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
    }

    /** Copies an object between buckets without pulling it through the application (videos can be large). */
    public function copy(string $fromBucket, string $from, string $toBucket, string $to): void
    {
        $source = $this->bucket($fromBucket);
        $target = $this->bucket($toBucket);

        if ($this->usesAzure()) {
            $this->azure()->copy($source, $from, $target, $to);

            return;
        }

        if ($this->usesSupabase()) {
            $this->supabase()->post($this->endpoint('object/copy'), ['bucketId' => $source, 'sourceKey' => $from, 'destinationBucket' => $target, 'destinationKey' => $to])->throw();

            return;
        }

        $dest = Storage::disk('local')->path("{$target}/{$to}");
        if (! is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }
        copy(Storage::disk('local')->path("{$source}/{$from}"), $dest);
    }

    public function exists(string $bucket, string $path): bool
    {
        $bucketName = $this->bucket($bucket);

        if ($this->usesAzure()) {
            return $this->azure()->exists($bucketName, $path);
        }

        if ($this->usesSupabase()) {
            return $this->supabase()->head($this->endpoint("object/info/{$bucketName}/{$path}"))->successful()
                || $this->supabase()->get($this->endpoint("object/info/{$bucketName}/{$path}"))->successful();
        }

        return Storage::disk('local')->exists("{$bucketName}/{$path}");
    }

    private function bucket(string $key): string
    {
        return config("tedc.supabase.buckets.{$key}") ?? $key;
    }

    private function endpoint(string $path): string
    {
        return rtrim(config('tedc.supabase.url'), '/').'/storage/v1/'.$path;
    }

    /** Creates a storage bucket with the service key: private, except the one for public assets. Returns whether it exists afterwards. */
    private function createSupabaseBucket(string $name, bool $public): bool
    {
        $r = $this->supabase()->post($this->endpoint('bucket'), ['id' => $name, 'name' => $name, 'public' => $public]);

        return $r->successful() || str_contains(strtolower((string) $r->body()), 'already exists');
    }

    private function supabase()
    {
        return Supabase::admin();
    }
}
