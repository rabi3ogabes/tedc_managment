<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\Contracts\ContractStore;
use App\Integrations\IntegrationManager;
use App\Integrations\Ministry\SijilArchive;
use App\Models\ArchiveItem;
use App\Models\Certificate;
use App\Models\Registration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\ChecksTheCircuit;
use Tests\TestCase;

/** Sijil on the contract kit: a certificate is archived once with its metadata, the reference is kept, and an archive that is down only delays it. */
class SijilContractTest extends TestCase
{
    use ChecksTheCircuit;

    private const BASE = 'https://sijil.test/api';

    protected function setUp(): void
    {
        parent::setUp();
        config(['tedc.integrations.backoff_ms' => 0]);
        app(IntegrationManager::class)->update('sijil', ['driver' => 'http', 'enabled' => true, 'settings' => ['base_url' => self::BASE, 'api_key' => 'SJ-KEY-1']]);
    }

    private function certificate(string $no = 'C-2026-000001'): Certificate
    {
        $employee = $this->makeEmployee(['employee_no' => 'E-1001']);
        $program = $this->makeProgram(['code' => 'PRG-100']);
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => 'completed']);

        return Certificate::create(['certificate_no' => $no, 'verification_code' => 'V'.substr($no, -6), 'registration_id' => $registration->id, 'employee_id' => $employee->id, 'program_id' => $program->id, 'issued_at' => now(), 'hours' => 8, 'status' => 'valid']);
    }

    public function test_a_certificate_is_archived_with_its_metadata_and_the_reference_is_kept(): void
    {
        ContractFixtures::fake('sijil', self::BASE);
        $certificate = $this->certificate();

        $this->assertSame(['archived' => 1, 'failed' => 0], app(SijilArchive::class)->run());

        $item = ArchiveItem::where('subject_id', $certificate->id)->firstOrFail();
        $this->assertSame(['archived', 'SIJIL-0001'], [$item->status, $item->sijil_ref]);
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/archive' && $r->hasHeader('Authorization', 'Bearer SJ-KEY-1') && $r['metadata']['certificate_no'] === 'C-2026-000001'
            && $r['metadata']['program_code'] === 'PRG-100' && $r['metadata']['hours'] === 8.0 && $r['file']['mime'] === 'application/pdf' && str_starts_with(base64_decode($r['file']['content_base64']), '%PDF'));
    }

    public function test_every_attempt_for_one_certificate_carries_the_same_idempotency_key(): void
    {
        Http::fake([self::BASE.'/archive' => Http::sequence()->push('busy', 503)->push('busy', 503)->push(['reference' => 'SJ-9'], 201)]);
        $certificate = $this->certificate();

        app(SijilArchive::class)->run();   // the send and its retry fail
        app(SijilArchive::class)->run();   // the next run archives it

        $keys = collect(Http::recorded())->map(fn ($p) => $p[0]->header('Idempotency-Key')[0] ?? null)->unique();
        $this->assertSame(['tedc-cert-'.$certificate->certificate_no], $keys->all(), 'Sijil can tell that the file was already received');
        $this->assertSame('SJ-9', ArchiveItem::where('subject_id', $certificate->id)->value('sijil_ref'));
    }

    public function test_a_file_the_archive_refuses_is_a_clear_reason_on_the_item(): void
    {
        ContractFixtures::fake('sijil', self::BASE, ['archive_certificate' => 'too_large']);
        $certificate = $this->certificate();

        app(SijilArchive::class)->run();

        $item = ArchiveItem::where('subject_id', $certificate->id)->firstOrFail();
        $this->assertSame('pending', $item->status);
        $this->assertStringContainsString('larger than 5 MB', (string) $item->error);
        $this->assertStringNotContainsString('SJ-KEY-1', (string) $item->error);
        Http::assertSentCount(1);   // a rejection is not asked again within the run
    }

    public function test_an_override_of_the_address_and_the_reference_field_adapts_without_a_deploy(): void
    {
        $store = app(ContractStore::class);
        $doc = $store->active('sijil')->document();
        $doc['operations']['archive_certificate']['path'] = '/v2/documents';
        $doc['operations']['archive_certificate']['response']['values']['reference'] = ['$.document.archive_id'];
        $store->save('sijil', 'contract', $doc, null);
        Http::fake([self::BASE.'/v2/documents' => Http::response(['document' => ['archive_id' => 'ARC-55']], 201)]);
        $certificate = $this->certificate();

        app(SijilArchive::class)->run();

        $this->assertSame('ARC-55', ArchiveItem::where('subject_id', $certificate->id)->value('sijil_ref'));
    }

    public function test_the_circuit_opens_after_five_failures_and_recovers(): void
    {
        $this->certificate('C-2026-000001');
        $this->assertTheCircuitOpensAndRecovers('sijil', 'sijil', self::BASE, 'archive_certificate', function (int $run) {
            if ($run === 6) {
                ArchiveItem::query()->update(['status' => 'pending', 'attempts' => 0]);   // the items that waited are tried again
            }

            return app(SijilArchive::class)->run();
        });
    }
}
