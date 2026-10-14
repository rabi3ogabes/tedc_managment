<?php

namespace Tests\Feature;

use App\Integrations\Contracts\Contract;
use App\Integrations\Contracts\ContractException;
use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\Contracts\ContractStore;
use App\Integrations\Contracts\ContractTestRunner;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Run test case": every operation's request is checked against what the contract says, every recorded answer must map to the TEDC
 * records the case expects, and every error must come out as the clear message of the contract's error model. A live run reaches the
 * real system for read-only operations and reports counts and field gaps, never the people in the answer.
 */
class ContractTestRunnerTest extends TestCase
{
    private function runner(string $system, ?Contract $contract = null): ContractTestRunner
    {
        $store = app(ContractStore::class);

        return new ContractTestRunner($contract ?? $store->active($system), $system, $store->secretSettings($system));
    }

    public function test_every_shipped_test_case_of_every_system_passes_against_its_recorded_answers(): void
    {
        foreach (Contract::systems() as $system) {
            $summary = $this->runner($system)->runAll('contract', ['base_url' => 'https://sys.test/api']);
            $failed = collect($summary['cases'])->where('ok', false)->map(fn ($c) => $c['name'].': '.collect($c['checks'])->where('ok', false)->pluck('detail')->implode(' | '))->all();
            $this->assertSame([], $failed, $system);
            $this->assertSame(count(Contract::bundled($system)->tests()), $summary['total'], $system);
            $this->assertSame($summary['total'], $summary['passed']);
        }
    }

    public function test_a_mapping_that_does_not_fit_the_answer_fails_the_case_and_names_the_field(): void
    {
        $store = app(ContractStore::class);
        $mapping = $store->active('hr')->mappingDocument();
        $mapping['entities']['employee']['fields']['employee_no'] = '$.staff.number';
        $result = $this->runner('hr', $store->active('hr')->withDocuments(null, $mapping))->run($store->active('hr')->tests()[0], 'contract', ['base_url' => 'https://sys.test/api']);

        $this->assertFalse($result['ok']);
        $failed = collect($result['checks'])->where('ok', false)->pluck('check')->all();
        $this->assertContains('response.mapped', $failed);
        $this->assertStringContainsString('employee_no', collect($result['checks'])->firstWhere('check', 'response.mapped')['detail']);
    }

    public function test_a_request_that_is_not_what_the_case_expects_fails(): void
    {
        $store = app(ContractStore::class);
        $doc = $store->active('hr')->document();
        $doc['operations']['fetch_employees']['path'] = '/api/v2/staff';
        $result = $this->runner('hr', $store->active('hr')->withDocuments($doc, null))->run($store->active('hr')->tests()[0], 'contract', ['base_url' => 'https://sys.test/api']);

        $this->assertFalse($result['ok']);
        $check = collect($result['checks'])->firstWhere('check', 'request.expected');
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('/api/v2/staff', $check['detail']);
        $this->assertStringContainsString('/employees', $check['detail']);
    }

    public function test_an_error_the_case_does_not_expect_fails_and_one_it_expects_passes(): void
    {
        $contract = app(ContractStore::class)->active('hr');
        $unexpected = $contract->tests()[0];
        $unexpected['fixture'] = 'fetch_employees.unauthorised';
        $result = $this->runner('hr')->run($unexpected, 'contract', ['base_url' => 'https://sys.test/api']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unauthorised', collect($result['checks'])->firstWhere('check', 'response.error')['detail']);

        $expected = $contract->tests()[2];   // the key is refused
        $this->assertTrue($this->runner('hr')->run($expected, 'contract', ['base_url' => 'https://sys.test/api'])['ok']);
    }

    public function test_the_request_mode_shows_what_would_be_sent_with_no_secret_and_sends_nothing(): void
    {
        Http::fake();
        $result = $this->runner('hr')->run(app(ContractStore::class)->active('hr')->tests()[1], 'request', ['base_url' => 'https://hr.moe.example/api', 'api_key' => 'TOP-SECRET-KEY']);

        $this->assertTrue($result['ok']);
        $this->assertSame('https://hr.moe.example/api/employees?since=2026-10-01T00%3A00%3A00%2B03%3A00', $result['request']['url']);
        $this->assertSame('•••', $result['request']['headers']['Authorization']);
        $this->assertStringNotContainsString('TOP-SECRET-KEY', json_encode($result));
        $this->assertNull($result['response'], 'no answer is shown without a fixture or a live call');
        Http::assertNothingSent();
    }

    public function test_a_preview_never_asks_a_token_service_for_a_token(): void
    {
        Http::fake();
        $store = app(ContractStore::class);
        $doc = $store->active('hr')->document();
        $doc['auth'] = ['type' => 'oauth2', 'token_url_setting' => 'token_url', 'client_id_setting' => 'client_id', 'client_secret_setting' => 'client_secret'];
        $contract = $store->active('hr')->withDocuments($doc, null);
        $result = $this->runner('hr', $contract)->run($contract->tests()[0], 'request', ['base_url' => 'https://hr.moe.example']);

        $this->assertTrue($result['ok'], json_encode($result['checks']));
        $this->assertSame('•••', $result['request']['headers']['Authorization']);
        Http::assertNothingSent();
    }

    public function test_a_live_run_is_for_read_only_operations_and_reports_counts_and_gaps_not_people(): void
    {
        ContractFixtures::fake('hr', 'https://hr.moe.example/api');
        $settings = ['base_url' => 'https://hr.moe.example/api', 'api_key' => 'KEY-1'];
        $case = app(ContractStore::class)->active('hr')->tests()[0];
        $result = $this->runner('hr')->run($case, 'live', $settings);

        $this->assertTrue($result['ok'], json_encode($result['checks']));
        $this->assertSame(3, $result['response']['records']);
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach (['sara.kuwari@example.test', 'سارة الكواري', 'E-1001'] as $person) {
            $this->assertStringNotContainsString($person, $json, 'a live answer holds real people: only counts and gaps are returned');
        }
        $gap = collect($result['checks'])->firstWhere('check', 'response.gaps');
        $this->assertNotNull($gap);
        $this->assertStringContainsString('national_id', $gap['detail'], 'a mapped field that no record carries is reported');
    }

    public function test_a_live_run_refuses_an_operation_that_changes_something(): void
    {
        Http::fake();
        $case = collect(app(ContractStore::class)->active('hr')->tests())->firstWhere('operation', 'send_profile_change');
        $result = $this->runner('hr')->run($case, 'live', ['base_url' => 'https://hr.moe.example/api', 'api_key' => 'KEY-1']);

        $this->assertFalse($result['ok']);
        $this->assertSame('live.safe', $result['checks'][0]['check']);
        Http::assertNothingSent();
    }

    public function test_a_live_run_reports_the_clear_message_when_the_system_fails(): void
    {
        ContractFixtures::fake('hr', 'https://hr.moe.example/api', ['fetch_employees' => 'unauthorised']);
        $result = $this->runner('hr')->run(app(ContractStore::class)->active('hr')->tests()[0], 'live', ['base_url' => 'https://hr.moe.example/api', 'api_key' => 'KEY-1']);

        $this->assertFalse($result['ok']);
        $check = collect($result['checks'])->firstWhere('check', 'response.live');
        $this->assertStringContainsString('unauthorised', $check['detail']);
        $this->assertStringNotContainsString('KEY-1', json_encode($result));
    }

    public function test_a_sample_pasted_into_the_case_is_read_like_a_recorded_answer(): void
    {
        $case = ['name' => 'Their sample', 'operation' => 'fetch_employees', 'response' => ['status' => 200, 'body' => ['data' => [['employee_no' => 'Z-1', 'email' => 'z@example.test']]]], 'expect' => ['records' => 1, 'mapped' => ['0' => ['employee_no' => 'Z-1']]]];
        $this->assertTrue($this->runner('hr')->run($case, 'contract', [])['ok']);

        $case['response'] = ['status' => 404, 'body' => ['message' => 'No such list']];
        $case['expect'] = ['error' => ['code' => 'not_found']];
        $this->assertTrue($this->runner('hr')->run($case, 'contract', [])['ok']);
    }

    public function test_a_live_run_of_everything_leaves_out_the_operations_that_change_something(): void
    {
        ContractFixtures::fake('hr', 'https://hr.moe.example/api');
        $summary = $this->runner('hr')->runAll('live', ['base_url' => 'https://hr.moe.example/api', 'api_key' => 'KEY-1'], onlySafe: true);

        $this->assertSame(4, $summary['total'], 'the four cases of the employee list; the two profile change cases are left out');
        $this->assertTrue($summary['ok'], json_encode($summary));
        $this->assertNotContains('send_profile_change', array_column($summary['cases'], 'operation'));
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        $this->expectException(ContractException::class);
        $this->runner('hr')->run(app(ContractStore::class)->active('hr')->tests()[0], 'whatever', []);
    }
}
