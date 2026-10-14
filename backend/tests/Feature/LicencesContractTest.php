<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractException;
use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\Contracts\ContractStore;
use App\Integrations\IntegrationManager;
use App\Integrations\Ministry\LicenceSync;
use App\Models\CareerPath;
use App\Models\Certificate;
use App\Models\ProfessionalLicence;
use App\Models\Registration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\ChecksTheCircuit;
use Tests\TestCase;

/** The professional licences system on the contract kit: licences read from the recorded answer, certificates reported, errors clear. */
class LicencesContractTest extends TestCase
{
    use ChecksTheCircuit;

    private const BASE = 'https://lic.test/api';

    protected function setUp(): void
    {
        parent::setUp();
        config(['tedc.integrations.backoff_ms' => 0]);
        app(IntegrationManager::class)->update('licences', ['driver' => 'http', 'enabled' => true, 'settings' => ['base_url' => self::BASE, 'api_key' => 'LIC-KEY-1']]);
        CareerPath::create(['type' => 'licence', 'title_ar' => 'رخصة المعلم', 'title_en' => 'Teacher licence', 'is_active' => true, 'job_title_ids' => []]);
    }

    private function certificate(string $employeeNo = 'E-1001', string $no = 'C-2026-000001'): Certificate
    {
        $employee = $this->makeEmployee(['employee_no' => $employeeNo]);
        $program = $this->makeProgram(['code' => 'PRG-100']);
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => 'completed']);

        return Certificate::create(['certificate_no' => $no, 'verification_code' => 'V'.substr($no, -6), 'registration_id' => $registration->id, 'employee_id' => $employee->id, 'program_id' => $program->id, 'issued_at' => now(), 'hours' => 12.5, 'status' => 'valid']);
    }

    public function test_the_shipped_contract_reads_the_recorded_licences_and_reports_the_certificates(): void
    {
        $this->certificate('E-1001');
        $this->makeEmployee(['employee_no' => 'E-1002']);
        ContractFixtures::fake('licences', self::BASE);

        $result = app(LicenceSync::class)->run();

        $this->assertSame(['created' => 2, 'updated' => 0, 'unmatched' => 0, 'pushed' => 1], $result);
        $licence = ProfessionalLicence::where('licence_no', 'LIC-2024-001')->firstOrFail();
        $this->assertSame([2, 'ministry', 'active'], [$licence->level_no, $licence->source, $licence->status]);
        $this->assertSame('expired', ProfessionalLicence::where('licence_no', 'LIC-2023-114')->value('status'));
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/completions' && $r->hasHeader('Authorization', 'Bearer LIC-KEY-1')
            && $r['items'][0] === ['employee_no' => 'E-1001', 'program_code' => 'PRG-100', 'certificate_no' => 'C-2026-000001', 'hours' => 12.5, 'issued_at' => now()->toDateString()]);
    }

    public function test_an_override_adapts_the_address_and_the_field_naming_without_a_deploy(): void
    {
        $this->makeEmployee(['employee_no' => 'E-77']);
        $store = app(ContractStore::class);
        $doc = $store->active('licences')->document();
        $doc['operations']['fetch_licences']['path'] = '/v3/professional-licences';
        $store->save('licences', 'contract', $doc, null);
        $mapping = $store->active('licences')->mappingDocument();
        $mapping['entities']['licence']['fields'] = ['employee_no' => '$.holder.no', 'path_title' => '$.category', 'licence_no' => '$.number', 'level_no' => '$.grade', 'status' => ['path' => '$.state', 'transform' => ['lower']]];
        $store->save('licences', 'mapping', $mapping, null);
        Http::fake([self::BASE.'/v3/professional-licences' => Http::response(['data' => [['holder' => ['no' => 'E-77'], 'category' => 'Teacher licence', 'number' => 'N-5', 'grade' => 3, 'state' => 'ACTIVE']]])]);

        $this->assertSame(1, app(LicenceSync::class)->run()['created']);
        $licence = ProfessionalLicence::where('licence_no', 'N-5')->firstOrFail();
        $this->assertSame([3, 'active'], [$licence->level_no, $licence->status]);
    }

    public function test_a_refusal_is_a_clear_error_and_the_certificates_wait_for_the_next_run(): void
    {
        $this->certificate();
        ContractFixtures::fake('licences', self::BASE, ['fetch_licences' => 'unauthorised']);

        try {
            app(LicenceSync::class)->run();
            $this->fail('the refusal was swallowed');
        } catch (ContractException $e) {
            $this->assertSame('unauthorised', $e->errorCode);
        }
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/completions'));
        $this->assertSame(0, ProfessionalLicence::count());
    }

    public function test_the_circuit_opens_after_five_failures_and_recovers(): void
    {
        $this->assertTheCircuitOpensAndRecovers('licences', 'licences', self::BASE, 'fetch_licences', fn () => app(LicenceSync::class)->run());
    }
}
