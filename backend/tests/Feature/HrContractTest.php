<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractException;
use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\Contracts\ContractStore;
use App\Integrations\IntegrationManager;
use App\Integrations\IntegrationUnavailable;
use App\Integrations\Ministry\HrSync;
use App\Models\Employee;
use App\Models\IntegrationLog;
use App\Models\Role;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * HR and Mawared on the contract kit: the shipped contract and mapping drive a sync from the recorded answer, an administrator's
 * override adapts a different real specification without a deploy, errors come out as clear messages, and a failing system is paused
 * by the circuit breaker and picked up again when it recovers.
 */
class HrContractTest extends TestCase
{
    private const BASE = 'https://hr.test/api';

    protected function setUp(): void
    {
        parent::setUp();
        config(['tedc.integrations.backoff_ms' => 0]);
    }

    private function hr(string $key = 'hr', array $settings = []): void
    {
        app(IntegrationManager::class)->update($key, ['driver' => 'http', 'enabled' => true, 'settings' => $settings + ['base_url' => self::BASE, 'api_key' => 'HR-KEY-123']]);
    }

    private function school(): void
    {
        $this->makeSchool(['code' => 'S-001']);
    }

    public function test_the_shipped_contract_drives_a_full_sync_from_the_recorded_answer(): void
    {
        $this->school();
        $this->hr();
        ContractFixtures::fake('hr', self::BASE);

        $result = app(HrSync::class)->run('hr');

        $this->assertSame(['created' => 2, 'updated' => 0, 'deactivated' => 0, 'skipped' => 1, 'errors' => 0], $result);
        $sara = Employee::where('employee_no', 'E-1001')->firstOrFail();
        $this->assertSame('Master', $sara->qualification);
        $this->assertSame('2016-09-01', $sara->hire_date->toDateString());
        $this->assertSame('سارة الكواري', $sara->user->name_ar);
        $this->assertSame($sara->id, Employee::where('employee_no', 'E-1002')->firstOrFail()->supervisor_id);
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/employees' && $r->hasHeader('Authorization', 'Bearer HR-KEY-123'));
    }

    public function test_an_override_of_the_contract_changes_what_is_asked_without_a_deploy(): void
    {
        $this->school();
        $this->hr();
        $store = app(ContractStore::class);
        $doc = $store->active('hr')->document();
        $doc['operations']['fetch_employees']['path'] = '/v2/staff/export';
        $doc['operations']['fetch_employees']['query'] = ['changed_after?' => '{{ since }}', 'format' => 'json'];
        $store->save('hr', 'contract', $doc, null, 'Matches the HR interface document');
        Http::fake([self::BASE.'/v2/staff/export*' => Http::response(['data' => []])]);

        app(HrSync::class)->run('hr');
        app(HrSync::class)->run('hr');

        $urls = collect(Http::recorded())->map(fn ($p) => (string) $p[0]->url())->all();
        $this->assertSame(self::BASE.'/v2/staff/export?format=json', $urls[0]);
        $this->assertStringStartsWith(self::BASE.'/v2/staff/export?changed_after=', $urls[1]);
    }

    public function test_an_override_of_the_mapping_adapts_a_different_field_naming(): void
    {
        $this->school();
        $this->hr();
        $store = app(ContractStore::class);
        $mapping = $store->active('hr')->mappingDocument();
        $mapping['entities']['employee']['fields'] = [
            'employee_no' => '$.staff.id', 'name' => '$.staff.name.en', 'name_ar' => '$.staff.name.ar', 'email' => '$.contact.email',
            'status' => ['path' => '$.state', 'transform' => ['table:state'], 'default' => 'active'],
            'hire_date' => ['path' => '$.joined', 'transform' => ['date:d/m/Y>Y-m-d']], 'school_code' => '$.site',
        ];
        $mapping['entities']['employee']['tables'] = ['state' => ['A' => 'active', 'T' => 'left']];
        $store->save('hr', 'mapping', $mapping, null);
        Http::fake([self::BASE.'/employees' => Http::response(['data' => [
            ['staff' => ['id' => 'X-9', 'name' => ['en' => 'Omar Yousef', 'ar' => 'عمر يوسف']], 'contact' => ['email' => 'omar.yousef@example.test'], 'state' => 'A', 'joined' => '03/02/2019', 'site' => 'S-001'],
        ]])]);

        $this->assertSame(1, app(HrSync::class)->run('hr')['created']);
        $employee = Employee::where('employee_no', 'X-9')->firstOrFail();
        $this->assertSame('2019-02-03', $employee->hire_date->toDateString());
        $this->assertSame('Omar Yousef', $employee->user->name);
        $this->assertNotNull($employee->school_id);
    }

    public function test_mawared_uses_the_same_contract_with_its_own_settings_and_its_own_override(): void
    {
        $this->school();
        $this->hr('mawared', ['base_url' => 'https://mawared.test/gov']);
        ContractFixtures::fake('hr', 'https://mawared.test/gov');

        $this->assertSame(2, app(HrSync::class)->run('mawared')['created']);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://mawared.test/gov/employees'));
        $this->assertSame('mawared', app(ContractStore::class)->active('mawared')->system);
    }

    public function test_a_refused_key_is_a_clear_message_that_never_holds_the_key(): void
    {
        $this->hr();
        ContractFixtures::fake('hr', self::BASE, ['fetch_employees' => 'unauthorised']);

        try {
            app(HrSync::class)->run('hr');
            $this->fail('the refusal was swallowed');
        } catch (ContractException $e) {
            $this->assertSame(['unauthorised', 401, false], [$e->errorCode, $e->status, $e->retryable]);
        }
        $log = IntegrationLog::where('integration_key', 'hr')->where('status', 'error')->latest('created_at')->firstOrFail();
        $this->assertStringContainsString('refused the credentials', $log->error);
        $this->assertStringNotContainsString('HR-KEY-123', json_encode($log->toArray()));
        Http::assertSentCount(1);   // a refusal is not tried again: the answer will not change
    }

    public function test_the_circuit_opens_after_five_failures_and_the_next_run_after_it_recovers_works(): void
    {
        $this->school();
        $this->hr();
        ContractFixtures::fake('hr', self::BASE, ['fetch_employees' => 'server_error']);
        for ($i = 0; $i < 5; $i++) {
            try {
                app(HrSync::class)->run('hr');
                $this->fail('the failure was swallowed');
            } catch (ContractException $e) {
                $this->assertSame('upstream_error', $e->errorCode);
            }
        }
        $sentWhenOpened = count(Http::recorded());

        try {
            app(HrSync::class)->run('hr');
            $this->fail('the open circuit let a call through');
        } catch (IntegrationUnavailable $e) {
            $this->assertSame('circuit_open', $e->reason);
        }
        $this->assertCount($sentWhenOpened, Http::recorded(), 'a paused system is not called');
        $this->assertTrue(app(IntegrationManager::class)->present('hr')['circuit_open']);

        Carbon::setTestNow(now()->addMinutes(6));
        Http::swap(new HttpFactory);   // the system is back: a fresh stand-in
        ContractFixtures::fake('hr', self::BASE);
        $this->assertSame(2, app(HrSync::class)->run('hr')['created']);
        $this->assertFalse(app(IntegrationManager::class)->present('hr')['circuit_open']);
        $this->assertSame('ok', app(IntegrationManager::class)->get('hr')->health);
        Carbon::setTestNow();
    }

    public function test_the_connection_check_uses_the_contracts_own_way_of_signing_in(): void
    {
        $this->hr('hr', ['auth_header' => 'X-Hr-Key', 'health_path' => '/status']);
        $store = app(ContractStore::class);
        $doc = $store->active('hr')->document();
        $doc['auth'] = ['type' => 'api_key', 'in' => 'header', 'name' => 'X-Api-Key', 'name_setting' => 'auth_header', 'setting' => 'api_key'];
        $store->save('hr', 'contract', $doc, null);
        Http::fake([self::BASE.'/status' => Http::sequence()->push('ok', 200)->push('no', 503)->push('no', 401)]);

        $this->assertSame('ok', app(IntegrationManager::class)->check('hr')['health']);
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/status' && $r->hasHeader('X-Hr-Key', 'HR-KEY-123') && ! $r->hasHeader('Authorization'));
        $this->assertSame('down', app(IntegrationManager::class)->check('hr')['health']);
        $this->assertSame('degraded', app(IntegrationManager::class)->check('hr')['health']);
    }

    public function test_the_training_mode_still_works_without_any_contract_call(): void
    {
        Http::fake();
        app(IntegrationManager::class)->update('hr', ['driver' => 'fake', 'enabled' => true, 'settings' => ['fake_employees' => [['employee_no' => 'T-1', 'name' => 'Trainer', 'email' => 'trainer@example.test']]]]);
        $this->assertSame(1, app(HrSync::class)->run('hr')['created']);
        Http::assertNothingSent();
        $this->assertInstanceOf(Role::class, Role::first());
    }
}
