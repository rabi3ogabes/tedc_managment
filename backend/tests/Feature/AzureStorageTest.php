<?php

namespace Tests\Feature;

use App\Services\FileStorage;
use App\Support\AzureBlob;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Azure Blob driver. Most tests run against a fake HTTP layer; the ones marked @group azurite talk to a real emulator
 * (CI starts Azurite and sets AZURITE_ENDPOINT) and prove that the SAS signatures and the REST calls are accepted by the storage service itself.
 */
class AzureStorageTest extends TestCase
{
    private const AZURITE_KEY = 'Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==';

    private function useAzure(?string $endpoint = null): void
    {
        config(['tedc.storage_driver' => 'azure', 'tedc.azure.account' => 'devstoreaccount1', 'tedc.azure.key' => self::AZURITE_KEY, 'tedc.azure.endpoint' => $endpoint ?? 'http://azure.test/devstoreaccount1']);
    }

    public function test_blobs_are_written_read_checked_and_deleted_through_short_lived_links(): void
    {
        $this->useAzure();
        Http::fake(['azure.test/*' => Http::sequence()->push('', 201)->push('hello', 200)->push('', 200)->push('', 202)]);
        $s = app(FileStorage::class);
        $this->assertSame('a/b c.txt', $s->put('documents', 'a/b c.txt', 'hello', 'text/plain'));
        $this->assertSame('hello', $s->get('documents', 'a/b c.txt'));
        $this->assertTrue($s->exists('documents', 'a/b c.txt'));
        $s->delete('documents', 'a/b c.txt');

        $reqs = Http::recorded();
        [$put] = $reqs[0];
        $this->assertSame('PUT', $put->method());
        $this->assertStringContainsString('/devstoreaccount1/documents/a/b%20c.txt?', $put->url());
        $this->assertSame('BlockBlob', $put->header('x-ms-blob-type')[0]);
        $this->assertSame(base64_encode(md5('hello', true)), $put->header('x-ms-blob-content-md5')[0]);
        parse_str((string) parse_url($put->url(), PHP_URL_QUERY), $q);
        $this->assertSame('cw', $q['sp']);                                                  // write-only link for a write
        $this->assertSame('b', $q['sr']);
        $this->assertNotEmpty($q['sig']);
        parse_str((string) parse_url($reqs[3][0]->url(), PHP_URL_QUERY), $d);
        $this->assertSame('d', $d['sp']);                                                   // delete-only link for a delete
    }

    public function test_the_browser_gets_a_read_link_and_an_upload_link_with_the_right_permissions_and_expiry(): void
    {
        $this->useAzure('https://acct.blob.core.windows.net');
        $s = app(FileStorage::class);
        $url = $s->temporaryUrl('documents', 'x/y.pdf', 120);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertStringStartsWith('https://acct.blob.core.windows.net/documents/x/y.pdf?', $url);
        $this->assertSame('r', $q['sp']);
        $this->assertSame('https', $q['spr']);
        $this->assertEqualsWithDelta(time() + 120, strtotime($q['se']), 3);
        $up = $s->signedUpload('documents', 'big/video.mp4', 'video/mp4');
        parse_str((string) parse_url($up['url'], PHP_URL_QUERY), $u);
        $this->assertSame('PUT', $up['method']);
        $this->assertSame('cw', $u['sp']);
        $this->assertSame('BlockBlob', $up['headers']['x-ms-blob-type']);
        $this->assertSame('video/mp4', $up['headers']['Content-Type']);
    }

    public function test_the_signature_is_an_hmac_over_the_fields_in_the_documented_order(): void
    {
        $this->useAzure('https://acct.blob.core.windows.net');
        $q = [];
        parse_str(app(AzureBlob::class)->sasQuery('c1', 'dir/f.txt', 'r', 300, ['rscd' => 'attachment']), $q);
        $toSign = implode("\n", ['r', '', $q['se'], '/blob/devstoreaccount1/c1/dir/f.txt', '', '', 'https', '2022-11-02', 'b', '', '', '', 'attachment', '', '', '']);
        $this->assertSame(base64_encode(hash_hmac('sha256', $toSign, base64_decode(self::AZURITE_KEY), true)), $q['sig']);
        $this->assertSame('attachment', $q['rscd']);
    }

    public function test_a_missing_blob_is_reported_like_a_missing_local_file(): void
    {
        $this->useAzure();
        Http::fake(['azure.test/*' => Http::response('', 404)]);
        $this->expectExceptionMessage('File not found.');
        app(FileStorage::class)->get('documents', 'nope.txt');
    }

    public function test_the_production_path_uses_the_managed_identity_and_never_a_key(): void
    {
        config(['tedc.storage_driver' => 'azure', 'tedc.azure.account' => 'acct', 'tedc.azure.key' => null, 'tedc.azure.endpoint' => null]);
        putenv('IDENTITY_ENDPOINT=http://identity.test/token');
        putenv('IDENTITY_HEADER=secret-header');
        Http::fake([
            'identity.test/*' => Http::response(['access_token' => 'AAD-TOKEN']),
            'acct.blob.core.windows.net/*' => Http::response('', 201),
        ]);
        app(FileStorage::class)->put('documents', 'f.txt', 'x', 'text/plain');
        $recorded = Http::recorded();
        $this->assertSame('secret-header', $recorded[0][0]->header('X-IDENTITY-HEADER')[0]);
        $this->assertSame('Bearer AAD-TOKEN', $recorded[1][0]->header('Authorization')[0]);
        $this->assertStringNotContainsString('sig=', $recorded[1][0]->url());                // no SAS, no key: the token alone
        putenv('IDENTITY_ENDPOINT');
        putenv('IDENTITY_HEADER');
    }

    /** @group azurite */
    public function test_against_the_emulator_every_operation_and_every_link_is_accepted(): void
    {
        $endpoint = getenv('AZURITE_ENDPOINT');
        if (! $endpoint) {
            $this->markTestSkipped('Set AZURITE_ENDPOINT (http://127.0.0.1:10000/devstoreaccount1) to run against Azurite.');
        }
        $this->useAzure($endpoint);
        $blob = app(AzureBlob::class);
        $blob->ensureContainer('documents');
        $s = app(FileStorage::class);
        $path = 'tests/'.uniqid().'/hello world.txt';

        $s->put('documents', $path, 'hello azure', 'text/plain');
        $this->assertTrue($s->exists('documents', $path));
        $this->assertSame('hello azure', $s->get('documents', $path));
        $this->assertSame(base64_encode(md5('hello azure', true)), $blob->md5('documents', $path));

        $this->assertSame('hello azure', Http::get($s->temporaryUrl('documents', $path, 60))->body());          // the read link works for anyone holding it
        $up = $s->signedUpload('documents', 'tests/uploaded.bin', 'application/octet-stream');
        $this->assertTrue(Http::withHeaders($up['headers'])->withBody('browser bytes', 'application/octet-stream')->put($up['url'])->successful());
        $this->assertSame('browser bytes', $s->get('documents', 'tests/uploaded.bin'));
        $this->assertFalse(Http::get($up['url'])->successful());                                                // an upload link is not a read link

        $s->copy('documents', $path, 'documents', 'tests/copy.txt');
        $this->assertSame('hello azure', $s->get('documents', 'tests/copy.txt'));
        $s->delete('documents', $path);
        $this->assertFalse($s->exists('documents', $path));
        $this->assertFalse(Http::get(str_replace('sig=', 'sig=x', $s->temporaryUrl('documents', 'tests/copy.txt', 60)))->successful());   // a tampered link is refused
    }
}
