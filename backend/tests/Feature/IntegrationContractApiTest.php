<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\IntegrationManager;
use App\Models\AuditLog;
use App\Models\IntegrationChecklistItem;
use App\Models\Role;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The Contract, Test cases and Go-live checklist tabs of an integration card: view, override (versioned and audited), diff, rollback, preview, run, tick. */
class IntegrationContractApiTest extends TestCase
{
    private const URL = '/api/v1/admin/integrations';

    private function admin()
    {
        return $this->makeUser(Role::CENTER_ADMIN);
    }

    private function hr(array $settings = []): void
    {
        config(['tedc.integrations.backoff_ms' => 0]);
        app(IntegrationManager::class)->update('hr', ['driver' => 'http', 'enabled' => true, 'settings' => $settings + ['base_url' => 'https://hr.test/api', 'api_key' => 'HR-KEY-123']]);
    }

    public function test_the_card_shows_the_contract_the_mapping_the_versions_the_test_cases_and_the_checklist(): void
    {
        $admin = $this->admin();
        $this->hr();

        $r = $this->asUser($admin)->getJson(self::URL.'/hr/contract')->assertOk();

        $this->assertSame('hr', $r->json('data.contract.system'));
        $this->assertSame('GET', $r->json('data.contract.operations.fetch_employees.method'));
        $this->assertNotEmpty($r->json('data.mapping.entities.employee.fields'));
        $this->assertSame([0], array_column($r->json('data.versions.contract'), 'version'));
        $this->assertSame('bearer', $r->json('data.auth_type'));
        $this->assertContains('fetch_employees.ok', $r->json('data.fixtures'));
        $this->assertSame('fetch_employees', $r->json('data.tests.0.operation'));
        $this->assertSame('access', $r->json('data.checklist.0.id'));
        $this->assertFalse($r->json('data.checklist.0.done'));
        $this->assertContains('api_key', $r->json('data.secret_settings'));
        $this->assertStringNotContainsString('HR-KEY-123', $r->getContent(), 'a secret never comes back');

        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson(self::URL.'/hr/contract')->assertForbidden();
        $this->asUser($admin)->getJson(self::URL.'/entra/contract')->assertNotFound();
        $this->asUser($admin)->getJson(self::URL.'/nope/contract')->assertNotFound();
    }

    public function test_an_override_is_saved_as_a_version_audited_and_used(): void
    {
        $admin = $this->admin();
        $doc = $this->asUser($admin)->getJson(self::URL.'/hr/contract')->json('data.contract');
        $doc['operations']['fetch_employees']['path'] = '/v2/staff';

        $r = $this->asUser($admin)->putJson(self::URL.'/hr/contract', ['kind' => 'contract', 'document' => $doc, 'note' => 'Matches the HR document of October'])->assertOk();

        $this->assertSame(1, $r->json('saved.version'));
        $this->assertSame('/v2/staff', $r->json('data.contract.operations.fetch_employees.path'));
        $this->assertSame([1, 0], array_column($r->json('data.versions.contract'), 'version'));
        $this->assertSame('Matches the HR document of October', $r->json('data.versions.contract.0.note'));
        $this->assertTrue($r->json('data.versions.contract.0.active'));
        $audit = AuditLog::where('action', 'integration.contract.saved')->where('user_id', $admin->id)->firstOrFail();
        $this->assertSame(['hr', 'contract', 1], [$audit->new_values['integration'], $audit->new_values['kind'], $audit->new_values['version']]);
    }

    public function test_an_invalid_override_is_refused_with_every_reason(): void
    {
        $admin = $this->admin();
        $doc = $this->asUser($admin)->getJson(self::URL.'/hr/contract')->json('data.contract');
        $doc['operations']['fetch_employees']['path'] = 'https://evil.example/x';
        $doc['operations']['fetch_employees']['query'] = ['k' => '{{ settings.api_key }}'];

        $r = $this->asUser($admin)->putJson(self::URL.'/hr/contract', ['kind' => 'contract', 'document' => $doc])->assertStatus(422);

        $this->assertGreaterThanOrEqual(2, count($r->json('problems')));
        $this->assertStringContainsString('path', implode(' ', $r->json('problems')));
        $this->assertSame(0, AuditLog::where('action', 'integration.contract.saved')->count());
        $this->asUser($admin)->putJson(self::URL.'/hr/contract', ['kind' => 'something', 'document' => $doc])->assertStatus(422);
        $this->asUser($admin)->putJson(self::URL.'/hr/contract', ['kind' => 'contract'])->assertStatus(422);
    }

    public function test_the_difference_a_rollback_and_a_reset(): void
    {
        $admin = $this->admin();
        $doc = $this->asUser($admin)->getJson(self::URL.'/hr/contract')->json('data.contract');
        foreach (['/v1/staff', '/v2/staff'] as $path) {
            $doc['operations']['fetch_employees']['path'] = $path;
            $this->asUser($admin)->putJson(self::URL.'/hr/contract', ['kind' => 'contract', 'document' => $doc])->assertOk();
        }

        $diff = $this->asUser($admin)->getJson(self::URL.'/hr/contract/diff?kind=contract&from=1&to=2')->assertOk()->json('data');
        $this->assertSame([['path' => 'operations.fetch_employees.path', 'change' => 'changed', 'before' => '/v1/staff', 'after' => '/v2/staff']], $diff);

        $back = $this->asUser($admin)->postJson(self::URL.'/hr/contract/rollback', ['kind' => 'contract', 'version' => 1])->assertOk();
        $this->assertSame(3, $back->json('saved.version'));
        $this->assertSame('/v1/staff', $back->json('data.contract.operations.fetch_employees.path'));

        $reset = $this->asUser($admin)->postJson(self::URL.'/hr/contract/reset', ['kind' => 'contract'])->assertOk();
        $this->assertSame(4, $reset->json('saved.version'));
        $this->assertStringContainsString('employees_path', $reset->json('data.contract.operations.fetch_employees.path'));
        $this->assertSame(['integration.contract.saved', 'integration.contract.saved', 'integration.contract.rolled_back', 'integration.contract.reset'], AuditLog::where('action', 'like', 'integration.contract.%')->orderBy('created_at')->orderBy('id')->pluck('action')->all());

        $this->asUser($admin)->postJson(self::URL.'/hr/contract/rollback', ['kind' => 'contract', 'version' => 99])->assertStatus(422);
        $this->asUser($admin)->getJson(self::URL.'/hr/contract/diff?kind=contract&from=1&to=99')->assertStatus(422);
    }

    public function test_the_mapping_can_be_tried_on_a_recorded_answer_before_it_is_saved(): void
    {
        $admin = $this->admin();
        $mapping = ['entities' => ['employee' => ['fields' => ['employee_no' => '$.employee_no', 'email' => ['path' => '$.email', 'transform' => ['upper']], 'grade' => ['path' => '$.grade_level', 'transform' => ['lower']]]]]];

        $r = $this->asUser($admin)->postJson(self::URL.'/hr/contract/preview', ['entity' => 'employee', 'fixture' => 'fetch_employees.ok', 'mapping' => $mapping])->assertOk();

        $this->assertSame([], $r->json('problems'));
        $this->assertSame(3, $r->json('total'));
        $this->assertSame(['employee_no' => 'E-1001', 'email' => 'SARA.KUWARI@EXAMPLE.TEST', 'grade' => 'g5'], $r->json('records.0.mapped'));
        $this->assertSame('E-1001', $r->json('records.0.source.employee_no'));
        $this->assertSame(0, AuditLog::where('action', 'like', 'integration.contract.%')->count(), 'a preview saves nothing');

        $bad = $this->asUser($admin)->postJson(self::URL.'/hr/contract/preview', ['entity' => 'employee', 'fixture' => 'fetch_employees.ok', 'mapping' => ['entities' => ['employee' => ['fields' => ['x' => '$..deep']]]]])->assertOk();
        $this->assertNotSame([], $bad->json('problems'));
        $this->asUser($admin)->postJson(self::URL.'/hr/contract/preview', ['entity' => 'employee', 'fixture' => '../../.env'])->assertStatus(422);
        $this->asUser($admin)->postJson(self::URL.'/hr/contract/preview', ['entity' => 'nobody', 'fixture' => 'fetch_employees.ok'])->assertStatus(422);
    }

    public function test_test_cases_run_against_the_recorded_answers_without_touching_the_system(): void
    {
        Http::fake();
        $admin = $this->admin();
        $this->hr();

        $all = $this->asUser($admin)->postJson(self::URL.'/hr/test-case', ['mode' => 'contract'])->assertOk();
        $this->assertTrue($all->json('data.ok'), $all->getContent());
        $this->assertSame($all->json('data.total'), $all->json('data.passed'));

        $one = $this->asUser($admin)->postJson(self::URL.'/hr/test-case', ['mode' => 'request', 'case' => 1])->assertOk();
        $this->assertSame(1, $one->json('data.total'));
        $this->assertSame('•••', $one->json('data.cases.0.request.headers.Authorization'));
        $this->assertStringNotContainsString('HR-KEY-123', $one->getContent());

        $this->asUser($admin)->postJson(self::URL.'/hr/test-case', ['mode' => 'contract', 'case' => 99])->assertStatus(422);
        $this->asUser($admin)->postJson(self::URL.'/hr/test-case', ['mode' => 'telepathy'])->assertStatus(422);
        $this->asUser($admin)->postJson(self::URL.'/hr/test-case', ['mode' => 'live'])->assertStatus(422);   // live has its own address
        Http::assertNothingSent();
        $this->assertSame(['contract', 'request'], AuditLog::where('action', 'integration.test_case.run')->orderBy('created_at')->get()->map(fn ($a) => $a->new_values['mode'])->sort()->values()->all());
    }

    public function test_a_live_run_needs_the_system_to_be_switched_on_and_reports_counts_not_people(): void
    {
        $admin = $this->admin();
        $this->asUser($admin)->postJson(self::URL.'/hr/test-case/live', ['case' => 0])->assertStatus(422);   // switched off

        $this->hr();
        ContractFixtures::fake('hr', 'https://hr.test/api');
        $r = $this->asUser($admin)->postJson(self::URL.'/hr/test-case/live', ['case' => 0])->assertOk();

        $this->assertTrue($r->json('data.ok'), $r->getContent());
        $this->assertSame(3, $r->json('data.cases.0.response.records'));
        $this->assertStringNotContainsString('sara.kuwari@example.test', $r->getContent());
        $this->assertStringNotContainsString('HR-KEY-123', $r->getContent());
        $this->assertSame('live', AuditLog::where('action', 'integration.test_case.run')->latest('created_at')->first()->new_values['mode']);

        app(IntegrationManager::class)->update('hr', ['driver' => 'fake']);
        $this->asUser($admin)->postJson(self::URL.'/hr/test-case/live', ['case' => 0])->assertStatus(422);   // the training mode reaches nothing
    }

    public function test_the_go_live_checklist_records_who_ticked_what_and_when(): void
    {
        $admin = $this->admin();
        $r = $this->asUser($admin)->putJson(self::URL.'/hr/checklist/credentials', ['done' => true, 'note' => 'Received by e-mail on 14 Oct'])->assertOk();

        $item = collect($r->json('data'))->firstWhere('id', 'credentials');
        $this->assertTrue($item['done']);
        $this->assertSame($admin->displayName(), $item['done_by']);
        $this->assertNotNull($item['done_at']);
        $this->assertSame('Received by e-mail on 14 Oct', $item['note']);
        $this->assertNotEmpty($item['ar']);
        $this->assertNotEmpty($item['en']);
        $this->assertSame(1, IntegrationChecklistItem::where('integration_key', 'hr')->count());
        $this->assertSame('credentials', AuditLog::where('action', 'integration.checklist.updated')->firstOrFail()->new_values['item']);

        $again = $this->asUser($admin)->getJson(self::URL.'/hr/checklist')->assertOk();
        $this->assertSame(1, collect($again->json('data'))->where('done', true)->count());

        $undone = $this->asUser($admin)->putJson(self::URL.'/hr/checklist/credentials', ['done' => false])->assertOk();
        $this->assertFalse(collect($undone->json('data'))->firstWhere('id', 'credentials')['done']);
        $this->assertNull(collect($undone->json('data'))->firstWhere('id', 'credentials')['done_by']);

        $this->asUser($admin)->putJson(self::URL.'/hr/checklist/not_a_step', ['done' => true])->assertNotFound();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->putJson(self::URL.'/hr/checklist/credentials', ['done' => true])->assertForbidden();
    }

    public function test_mawared_has_its_own_versions_and_its_own_checklist(): void
    {
        $admin = $this->admin();
        $doc = $this->asUser($admin)->getJson(self::URL.'/mawared/contract')->json('data.contract');
        $this->assertSame('mawared', $doc['system']);
        $doc['operations']['fetch_employees']['path'] = '/gov/staff';
        $this->asUser($admin)->putJson(self::URL.'/mawared/contract', ['kind' => 'contract', 'document' => $doc])->assertOk();
        $this->asUser($admin)->putJson(self::URL.'/mawared/checklist/access', ['done' => true])->assertOk();

        $this->assertSame([0], array_column($this->asUser($admin)->getJson(self::URL.'/hr/contract')->json('data.versions.contract'), 'version'));
        $this->assertFalse(collect($this->asUser($admin)->getJson(self::URL.'/hr/checklist')->json('data'))->firstWhere('id', 'access')['done']);
    }
}
