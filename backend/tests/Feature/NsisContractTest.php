<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractException;
use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\IntegrationManager;
use App\Integrations\Ministry\NsisSync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\ChecksTheCircuit;
use Tests\TestCase;

/** NSIS on the contract kit: the grades and subjects each teacher teaches, and nothing about students. */
class NsisContractTest extends TestCase
{
    use ChecksTheCircuit;

    private const BASE = 'https://nsis.test/api';

    protected function setUp(): void
    {
        parent::setUp();
        config(['tedc.integrations.backoff_ms' => 0]);
        app(IntegrationManager::class)->update('nsis', ['driver' => 'http', 'enabled' => true, 'settings' => ['base_url' => self::BASE, 'api_key' => 'NSIS-KEY-1']]);
    }

    public function test_the_shipped_contract_maps_the_recorded_assignments_onto_the_teachers(): void
    {
        $sara = $this->makeEmployee(['employee_no' => 'E-1001']);
        $khalid = $this->makeEmployee(['employee_no' => 'E-1002']);
        ContractFixtures::fake('nsis', self::BASE);

        $this->assertSame(['updated' => 2, 'unmatched' => 0], app(NsisSync::class)->run());

        $this->assertSame(['4', '5'], $sara->fresh()->grades_taught);
        $this->assertSame(['Arabic'], $sara->fresh()->subjects);
        $this->assertSame(['9', '10', '11'], $khalid->fresh()->grades_taught);
        $this->assertSame(['Mathematics', 'Physics'], $khalid->fresh()->subjects);
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/teachers' && $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer NSIS-KEY-1'));
    }

    public function test_an_unknown_teacher_is_counted_and_nothing_else_about_the_answer_is_kept(): void
    {
        $this->makeEmployee(['employee_no' => 'E-1001']);
        ContractFixtures::fake('nsis', self::BASE);

        $this->assertSame(['updated' => 1, 'unmatched' => 1], app(NsisSync::class)->run());
    }

    public function test_a_refusal_and_a_failure_are_clear_errors(): void
    {
        ContractFixtures::fake('nsis', self::BASE, ['fetch_teacher_assignments' => 'unauthorised']);
        try {
            app(NsisSync::class)->run();
            $this->fail('the refusal was swallowed');
        } catch (ContractException $e) {
            $this->assertSame(['unauthorised', false], [$e->errorCode, $e->retryable]);
        }
    }

    public function test_the_circuit_opens_after_five_failures_and_recovers(): void
    {
        $this->makeEmployee(['employee_no' => 'E-1001']);
        $this->assertTheCircuitOpensAndRecovers('nsis', 'nsis', self::BASE, 'fetch_teacher_assignments', fn () => app(NsisSync::class)->run());
    }
}
