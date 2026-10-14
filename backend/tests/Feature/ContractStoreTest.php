<?php

namespace Tests\Feature;

use App\Integrations\Contracts\Contract;
use App\Integrations\Contracts\ContractStore;
use App\Integrations\Contracts\InvalidContract;
use App\Models\AuditLog;
use App\Models\IntegrationContractVersion;
use App\Models\Role;
use Tests\TestCase;

/**
 * Every Ministry system starts from the contract and mapping TEDC ships. An administrator can override either one in the integration
 * card (versioned, audited, with a diff and a rollback), so a different real specification is adapted without a deploy.
 */
class ContractStoreTest extends TestCase
{
    /** What ships for the employee list path: the system's own setting, or /employees. */
    private const SHIPPED_PATH = '{{ settings.employees_path|default:/employees|raw }}';

    private function store(): ContractStore
    {
        return app(ContractStore::class);
    }

    private function changed(string $path): array
    {
        $doc = $this->store()->active('hr')->document();
        $doc['operations']['fetch_employees']['path'] = $path;

        return $doc;
    }

    public function test_a_system_starts_from_the_documents_shipped_with_tedc(): void
    {
        $contract = $this->store()->active('hr');
        $this->assertSame('hr', $contract->system);
        $this->assertSame('GET', $contract->operation('fetch_employees')['method']);
        $this->assertSame('E-1001', $contract->map('employee', ['employee_no' => 'E-1001', 'ignored' => 'x'])['employee_no']);
        $this->assertSame(0, $this->store()->activeVersion('hr', 'contract'));
        $this->assertTrue($this->store()->has('hr'));
        $this->assertFalse($this->store()->has('entra'));
        $this->assertContains('hr', Contract::systems());
    }

    public function test_mawared_starts_from_the_hr_documents_but_is_its_own_system(): void
    {
        $mawared = $this->store()->active('mawared');
        $this->assertSame('mawared', $mawared->system);
        $this->assertSame('mawared', $mawared->document()['system']);
        $this->assertSame($this->store()->active('hr')->operations(), $mawared->operations());

        $doc = $this->store()->active('mawared')->document();
        $doc['operations']['fetch_employees']['path'] = '/api/v2/staff';
        $this->store()->save('mawared', 'contract', $doc, null);
        $this->assertSame('/api/v2/staff', $this->store()->active('mawared')->operation('fetch_employees')['path']);
        $this->assertSame(self::SHIPPED_PATH, $this->store()->active('hr')->operation('fetch_employees')['path'], 'the HR system is not changed by Mawared\'s override');
    }

    public function test_an_override_is_a_new_version_and_becomes_the_active_contract(): void
    {
        $v1 = $this->store()->save('hr', 'contract', $this->changed('/api/v1/staff'), null, 'Matches the sample from the Ministry');
        $this->assertSame(1, $v1->version);
        $this->assertSame('/api/v1/staff', $this->store()->active('hr')->operation('fetch_employees')['path']);
        $this->assertSame(1, $this->store()->activeVersion('hr', 'contract'));

        $v2 = $this->store()->save('hr', 'contract', $this->changed('/api/v2/staff'), null);
        $this->assertSame(2, $v2->version);
        $this->assertSame('/api/v2/staff', $this->store()->active('hr')->operation('fetch_employees')['path']);

        $this->assertSame(0, $this->store()->activeVersion('hr', 'mapping'), 'the mapping has its own versions');
    }

    public function test_an_invalid_override_is_refused_with_the_reasons_and_nothing_is_stored(): void
    {
        $doc = $this->changed('https://evil.example/steal');
        $doc['operations']['fetch_employees']['headers'] = ['Authorization' => 'Bearer x'];
        $doc['operations']['fetch_employees']['query'] = ['k' => '{{ settings.api_key }}'];
        try {
            $this->store()->save('hr', 'contract', $doc, null);
            $this->fail('an invalid contract was saved');
        } catch (InvalidContract $e) {
            $this->assertGreaterThanOrEqual(3, count($e->problems), implode('; ', $e->problems));
            $this->assertSame('invalid_contract', $e->errorCode);
        }
        $this->assertSame(0, IntegrationContractVersion::count());
        $this->assertSame(0, $this->store()->activeVersion('hr', 'contract'));
        $this->assertSame(self::SHIPPED_PATH, $this->store()->active('hr')->operation('fetch_employees')['path']);

        $this->expectException(InvalidContract::class);
        $this->store()->save('hr', 'mapping', ['entities' => ['employee' => ['fields' => ['employee_no' => '$..deep']]]], null);
    }

    public function test_a_contract_cannot_be_saved_for_another_system_or_an_unknown_one(): void
    {
        $doc = $this->changed('/x');
        $doc['system'] = 'licences';
        try {
            $this->store()->save('hr', 'contract', $doc, null);
            $this->fail('a contract of another system was saved');
        } catch (InvalidContract $e) {
            $this->assertNotSame([], $e->problems);
        }
        $this->expectException(InvalidContract::class);
        $this->store()->save('entra', 'contract', ['system' => 'entra', 'operations' => ['x' => ['method' => 'GET', 'path' => '/x']]], null);
    }

    public function test_a_rollback_makes_an_older_version_active_as_a_new_version(): void
    {
        $this->store()->save('hr', 'contract', $this->changed('/api/v1/staff'), null);
        $this->store()->save('hr', 'contract', $this->changed('/api/v2/staff'), null);

        $back = $this->store()->rollback('hr', 'contract', 1, null);
        $this->assertSame(3, $back->version, 'history is never rewritten');
        $this->assertSame(1, $back->restored_from);
        $this->assertSame('/api/v1/staff', $this->store()->active('hr')->operation('fetch_employees')['path']);

        $versions = $this->store()->versions('hr', 'contract');
        $this->assertSame([3, 2, 1, 0], array_column($versions, 'version'), 'newest first, the shipped documents last');
        $this->assertSame('bundled', end($versions)['source']);
        $this->assertSame('rollback', $versions[0]['source']);
    }

    public function test_it_can_go_back_to_the_documents_shipped_with_tedc(): void
    {
        $this->store()->save('hr', 'contract', $this->changed('/api/v2/staff'), null);
        $reset = $this->store()->reset('hr', 'contract', null);
        $this->assertSame('reset', $reset->source);
        $this->assertSame(self::SHIPPED_PATH, $this->store()->active('hr')->operation('fetch_employees')['path']);
        $this->store()->rollback('hr', 'contract', 1, null);
        $this->assertSame('/api/v2/staff', $this->store()->active('hr')->operation('fetch_employees')['path'], 'the override is still in the history');
        $this->store()->rollback('hr', 'contract', 0, null);
        $this->assertSame(self::SHIPPED_PATH, $this->store()->active('hr')->operation('fetch_employees')['path'], 'version 0 is the shipped document');
    }

    public function test_every_change_is_audited_with_who_and_which_system(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->store()->save('hr', 'contract', $this->changed('/api/v1/staff'), $admin->id, 'Matches the sample', '10.0.0.7');
        $this->store()->rollback('hr', 'contract', 0, $admin->id);
        $this->store()->reset('hr', 'mapping', $admin->id);

        $entries = AuditLog::where('user_id', $admin->id)->where('action', 'like', 'integration.contract.%')->orderBy('created_at')->get();
        $this->assertSame(['integration.contract.saved', 'integration.contract.rolled_back', 'integration.contract.reset'], $entries->pluck('action')->all());
        $this->assertSame('hr', $entries[0]->new_values['integration']);
        $this->assertSame(['contract', 1, 'Matches the sample'], [$entries[0]->new_values['kind'], $entries[0]->new_values['version'], $entries[0]->new_values['note']]);
        $this->assertSame('10.0.0.7', $entries[0]->ip_address);
        $this->assertSame('mapping', $entries[2]->new_values['kind']);
        $this->assertStringNotContainsString('Bearer', json_encode($entries->pluck('new_values')), 'the audit entry names the change, not the whole document');
    }

    public function test_the_difference_between_two_versions_names_the_path_that_changed(): void
    {
        $this->store()->save('hr', 'contract', $this->changed('/api/v1/staff'), null);
        $this->store()->save('hr', 'contract', $this->changed('/api/v2/staff'), null);

        $diff = $this->store()->diff('hr', 'contract', 1, 2);
        $this->assertSame([['path' => 'operations.fetch_employees.path', 'change' => 'changed', 'before' => '/api/v1/staff', 'after' => '/api/v2/staff']], $diff);
        $this->assertSame('/api/v2/staff', collect($this->store()->diff('hr', 'contract', 0, 2))->firstWhere('path', 'operations.fetch_employees.path')['after']);
    }

    public function test_an_unknown_version_or_kind_is_a_clear_error(): void
    {
        $this->expectException(InvalidContract::class);
        $this->store()->rollback('hr', 'contract', 9, null);
    }
}
