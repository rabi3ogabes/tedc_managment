<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractException;
use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\IntegrationManager;
use App\Integrations\Ministry\QnedsPublisher;
use App\Models\Certificate;
use App\Models\Registration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\ChecksTheCircuit;
use Tests\TestCase;

/** QNEDS on the contract kit: aggregated indicators per school, with no person in them and one idempotency key per publication. */
class QnedsContractTest extends TestCase
{
    use ChecksTheCircuit;

    private const BASE = 'https://qneds.test/api';

    protected function setUp(): void
    {
        parent::setUp();
        config(['tedc.integrations.backoff_ms' => 0]);
        app(IntegrationManager::class)->update('qneds', ['driver' => 'http', 'enabled' => true, 'settings' => ['base_url' => self::BASE, 'api_key' => 'QN-KEY-1']]);
        $school = $this->makeSchool(['code' => 'S-1', 'moe_no' => 'MOE-1']);
        $employee = $this->makeEmployee(['employee_no' => 'EMP-777', 'school_id' => $school->id]);
        $program = $this->makeProgram();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => 'completed']);
        Certificate::create(['certificate_no' => 'C-2', 'verification_code' => 'VCODE2', 'registration_id' => $registration->id, 'employee_id' => $employee->id, 'program_id' => $program->id, 'issued_at' => now(), 'hours' => 6, 'status' => 'valid']);
    }

    public function test_the_shipped_contract_publishes_the_indicators_of_the_year(): void
    {
        ContractFixtures::fake('qneds', self::BASE);

        $this->assertSame(['schools' => 1], app(QnedsPublisher::class)->publish((int) now()->year));

        Http::assertSent(function (Request $r) {
            $item = $r['items'][0];

            return $r->url() === self::BASE.'/indicators' && $r->hasHeader('Authorization', 'Bearer QN-KEY-1') && $r['year'] === (int) now()->year
                && $item === ['school_code' => 'MOE-1', 'year' => (int) now()->year, 'trained_staff' => 1, 'completed_programs' => 1, 'training_hours' => 6.0]
                && ! str_contains(json_encode($r->data()), 'EMP-777');
        });
    }

    public function test_a_retry_of_the_same_publication_carries_the_same_idempotency_key(): void
    {
        Http::fake([self::BASE.'/indicators' => Http::sequence()->push('busy', 503)->push(['accepted' => 1], 200)]);

        app(QnedsPublisher::class)->publish();

        $keys = collect(Http::recorded())->map(fn ($p) => $p[0]->header('Idempotency-Key')[0] ?? null);
        $this->assertCount(2, $keys);
        $this->assertNotNull($keys[0]);
        $this->assertSame($keys[0], $keys[1], 'the system can tell the retry from a second publication');
    }

    public function test_the_systems_rejection_is_a_clear_error(): void
    {
        ContractFixtures::fake('qneds', self::BASE, ['publish_indicators' => 'rejected']);
        try {
            app(QnedsPublisher::class)->publish();
            $this->fail('the rejection was swallowed');
        } catch (ContractException $e) {
            $this->assertSame(['rejected', 422, false], [$e->errorCode, $e->status, $e->retryable]);
            $this->assertStringContainsString('Unknown school code', $e->getMessage());
        }
    }

    public function test_the_circuit_opens_after_five_failures_and_recovers(): void
    {
        $this->assertTheCircuitOpensAndRecovers('qneds', 'qneds', self::BASE, 'publish_indicators', fn () => app(QnedsPublisher::class)->publish());
    }
}
