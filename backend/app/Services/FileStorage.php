<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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

        if ($this->usesSupabase()) {
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

        return Storage::disk('public')->url($path);
    }

    public function put(string $bucket, string $path, string $contents, string $mime): string
    {
        $bucketName = $this->bucket($bucket);

        if ($this->usesSupabase()) {
            $this->supabase()
                ->withHeaders(['Content-Type' => $mime, 'x-upsert' => 'true'])
                ->withBody($contents, $mime)
                ->post($this->endpoint("object/{$bucketName}/{$path}"))
                ->throw();
        } else {
            Storage::disk('local')->put("{$bucketName}/{$path}", $contents);
        }

        return $path;
    }

    public function get(string $bucket, string $path): string
    {
        $bucketName = $this->bucket($bucket);

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

        if ($this->usesSupabase()) {
            $signed = $this->supabase()
                ->post($this->endpoint("object/sign/{$bucketName}/{$path}"), ['expiresIn' => $ttl])
                ->throw()
                ->json('signedURL');

            return rtrim(config('tedc.supabase.url'), '/').'/storage/v1'.$signed;
        }

        return URL::temporarySignedRoute('files.local', now()->addSeconds($ttl), ['bucket' => $bucketName, 'path' => $path]);
    }

    private function bucket(string $key): string
    {
        return config("tedc.supabase.buckets.{$key}") ?? $key;
    }

    private function endpoint(string $path): string
    {
        return rtrim(config('tedc.supabase.url'), '/').'/storage/v1/'.$path;
    }

    private function supabase()
    {
        $key = config('tedc.supabase.service_role_key');

        return Http::withHeaders(['apikey' => $key, 'Authorization' => "Bearer {$key}"])->timeout(30);
    }
}
