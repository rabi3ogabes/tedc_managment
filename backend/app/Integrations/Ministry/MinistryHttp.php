<?php

namespace App\Integrations\Ministry;

use App\Support\Supabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** The one way TEDC calls a Ministry system over HTTP: bearer key, TLS verified, timeout, and a clear error when it answers badly. */
class MinistryHttp
{
    /** @param  array<string, mixed>  $s */
    public function __construct(private readonly array $s) {}

    private function http(): PendingRequest
    {
        $base = rtrim((string) ($this->s['base_url'] ?? ''), '/');
        if ($base === '') {
            throw new RuntimeException('The system address is not set.');
        }

        return Http::baseUrl($base)->withOptions(['verify' => Supabase::caBundle()])->timeout(20)->acceptJson()->when(filled($this->s['api_key'] ?? null), fn ($h) => $h->withToken((string) $this->s['api_key']));
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        $r = $this->http()->get($path, $query);
        if (! $r->successful()) {
            throw new RuntimeException('HTTP '.$r->status().' from '.$path);
        }

        return (array) $r->json();
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $body = []): array
    {
        $r = $this->http()->post($path, $body);
        if (! $r->successful()) {
            throw new RuntimeException('HTTP '.$r->status().' from '.$path.': '.mb_substr(strip_tags((string) $r->body()), 0, 120));
        }

        return (array) $r->json();
    }
}
