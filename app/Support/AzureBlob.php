<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

/**
 * Azure Blob Storage over its REST API, with private containers only. Browsers and other services get short-lived SAS links: signed with the account
 * key when one is configured (development, Azurite), otherwise user-delegation SAS built from the workload's managed identity (production — no keys anywhere).
 * Server-side reads and writes use a SAS of their own with the narrowest permission, or the managed identity's bearer token.
 */
class AzureBlob
{
    private string $account;

    private ?string $key;

    private string $base;

    private string $version;

    public function __construct()
    {
        $c = config('tedc.azure');
        $this->account = (string) ($c['account'] ?? '');
        $this->key = $c['key'] ?: null;
        $this->version = (string) ($c['api_version'] ?? '2022-11-02');
        $this->base = rtrim((string) ($c['endpoint'] ?: "https://{$this->account}.blob.core.windows.net"), '/');
    }

    public function url(string $container, string $blob = ''): string
    {
        return $this->base.'/'.$container.($blob !== '' ? '/'.implode('/', array_map('rawurlencode', explode('/', $blob))) : '');
    }

    // ---- SAS --------------------------------------------------------------------------------------------

    /** A link to one blob valid for $ttl seconds with the given permissions (r read, w write, c create, d delete). */
    public function sasUrl(string $container, string $blob, string $permissions, int $ttl, array $headers = []): string
    {
        return $this->url($container, $blob).'?'.$this->sasQuery($container, $blob, $permissions, $ttl, $headers);
    }

    public function sasQuery(string $container, string $blob, string $permissions, int $ttl, array $headers = []): string
    {
        $expiry = gmdate('Y-m-d\TH:i:s\Z', time() + $ttl);
        $protocol = str_starts_with($this->base, 'https') ? 'https' : 'https,http';
        $resource = "/blob/{$this->account}/{$container}/{$blob}";
        $rs = [$headers['rscc'] ?? '', $headers['rscd'] ?? '', $headers['rsce'] ?? '', $headers['rscl'] ?? '', $headers['rsct'] ?? ''];
        $q = ['sv' => $this->version, 'spr' => $protocol, 'se' => $expiry, 'sr' => 'b', 'sp' => $permissions];

        if ($this->key) {
            $toSign = implode("\n", [$permissions, '', $expiry, $resource, '', '', $protocol, $this->version, 'b', '', '', ...$rs]);
            $q['sig'] = base64_encode(hash_hmac('sha256', $toSign, (string) base64_decode($this->key, true), true));
        } else {
            $k = $this->delegationKey();
            $toSign = implode("\n", [$permissions, '', $expiry, $resource, $k['oid'], $k['tid'], $k['start'], $k['expiry'], $k['service'], $k['version'], '', '', '', '', $protocol, $this->version, 'b', '', '', ...$rs]);
            $q += ['skoid' => $k['oid'], 'sktid' => $k['tid'], 'skt' => $k['start'], 'ske' => $k['expiry'], 'sks' => $k['service'], 'skv' => $k['version']];
            $q['sig'] = base64_encode(hash_hmac('sha256', $toSign, (string) base64_decode($k['value'], true), true));
        }
        foreach (array_filter(['rscc' => $rs[0], 'rscd' => $rs[1], 'rsce' => $rs[2], 'rscl' => $rs[3], 'rsct' => $rs[4]]) as $name => $value) {
            $q[$name] = $value;
        }

        return http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{oid: string, tid: string, start: string, expiry: string, service: string, version: string, value: string} */
    private function delegationKey(): array
    {
        return Cache::remember('azure.delegation-key', 3000, function () {
            $start = gmdate('Y-m-d\TH:i:s\Z', time() - 300);
            $expiry = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
            $xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?><KeyInfo><Start>{$start}</Start><Expiry>{$expiry}</Expiry></KeyInfo>";
            $res = $this->bearer()->withBody($xml, 'application/xml')->post($this->base.'/?restype=service&comp=userdelegationkey')->throw()->body();
            $x = new SimpleXMLElement($res);

            return ['oid' => (string) $x->SignedOid, 'tid' => (string) $x->SignedTid, 'start' => (string) $x->SignedStart, 'expiry' => (string) $x->SignedExpiry, 'service' => (string) $x->SignedService, 'version' => (string) $x->SignedVersion, 'value' => (string) $x->Value];
        });
    }

    private function bearer(): PendingRequest
    {
        return Http::timeout(60)->withHeaders(['Authorization' => 'Bearer '.$this->token(), 'x-ms-version' => $this->version]);
    }

    /** An access token for storage from the workload's managed identity (Container Apps / App Service / IMDS). */
    private function token(): string
    {
        return Cache::remember('azure.storage-token', 3000, function () {
            $client = config('tedc.azure.client_id');
            $endpoint = getenv('IDENTITY_ENDPOINT');
            $res = $endpoint
                ? Http::withHeaders(['X-IDENTITY-HEADER' => (string) getenv('IDENTITY_HEADER')])->get($endpoint, array_filter(['resource' => 'https://storage.azure.com/', 'api-version' => '2019-08-01', 'client_id' => $client]))
                : Http::withHeaders(['Metadata' => 'true'])->get('http://169.254.169.254/metadata/identity/oauth2/token', array_filter(['api-version' => '2018-02-01', 'resource' => 'https://storage.azure.com/', 'client_id' => $client]));
            $token = $res->throw()->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('No storage token from the managed identity.');
            }

            return $token;
        });
    }

    // ---- operations --------------------------------------------------------------------------------------

    /** The request for a server-side operation: a narrow SAS when keys are used, the bearer token otherwise. */
    private function request(string $container, string $blob, string $permissions): array
    {
        if ($this->key) {
            return [Http::timeout(120)->withHeaders(['x-ms-version' => $this->version]), $this->sasUrl($container, $blob, $permissions, 300)];
        }

        return [$this->bearer(), $this->url($container, $blob)];
    }

    public function put(string $container, string $blob, string $contents, string $mime): void
    {
        [$http, $url] = $this->request($container, $blob, 'cw');
        $http->withHeaders(['x-ms-blob-type' => 'BlockBlob', 'x-ms-blob-content-md5' => base64_encode(md5($contents, true))])->withBody($contents, $mime)->put($url)->throw();
    }

    public function get(string $container, string $blob): string
    {
        [$http, $url] = $this->request($container, $blob, 'r');
        $r = $http->get($url);
        if ($r->status() === 404) {
            throw new RuntimeException('File not found.');
        }

        return $r->throw()->body();
    }

    public function delete(string $container, string $blob): void
    {
        [$http, $url] = $this->request($container, $blob, 'd');
        $r = $http->delete($url);
        if ($r->status() !== 404) {
            $r->throw();
        }
    }

    public function exists(string $container, string $blob): bool
    {
        [$http, $url] = $this->request($container, $blob, 'r');

        return $http->head($url)->successful();
    }

    /** MD5 the service holds for a blob (set at upload), base64. */
    public function md5(string $container, string $blob): ?string
    {
        [$http, $url] = $this->request($container, $blob, 'r');

        return $http->head($url)->header('Content-MD5') ?: null;
    }

    /** Copies inside the account without pulling the bytes through the application. */
    public function copy(string $fromContainer, string $from, string $toContainer, string $to): void
    {
        [$http, $url] = $this->request($toContainer, $to, 'cw');
        $source = $this->sasUrl($fromContainer, $from, 'r', 300);
        $http->withHeaders(['x-ms-copy-source' => $source])->withBody('', 'application/octet-stream')->put($url)->throw();
    }

    /** Creates a container (development and tests with the account key; production containers come from the infrastructure code). */
    public function ensureContainer(string $container): void
    {
        if (! $this->key) {
            return;
        }
        $date = gmdate('D, d M Y H:i:s').' GMT';
        $url = $this->url($container).'?restype=container';
        $path = (string) parse_url($url, PHP_URL_PATH);
        // VERB, Content-Encoding, Content-Language, Content-Length (empty when zero), Content-MD5, Content-Type, Date, If-* (4), Range: the real service signs the Content-Type that is sent.
        $toSign = implode("\n", ['PUT', '', '', '', '', 'application/octet-stream', '', '', '', '', '', ''])."\nx-ms-date:{$date}\nx-ms-version:{$this->version}\n/{$this->account}{$path}\nrestype:container";
        $sig = base64_encode(hash_hmac('sha256', $toSign, (string) base64_decode($this->key, true), true));
        $r = Http::timeout(30)->withHeaders(['x-ms-date' => $date, 'x-ms-version' => $this->version, 'Authorization' => "SharedKey {$this->account}:{$sig}"])->withBody('', 'application/octet-stream')->put($url);
        if ($r->status() !== 409) {   // 409: the container exists already
            $r->throw();
        }
    }
}
