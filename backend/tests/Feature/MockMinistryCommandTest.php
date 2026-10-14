<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** `php artisan tedc:mock-ministry {system}`: the local mock of a Ministry system for UAT and demos. */
class MockMinistryCommandTest extends TestCase
{
    public function test_it_lists_what_the_mock_answers(): void
    {
        $this->artisan('tedc:mock-ministry', ['system' => 'hr', '--routes' => true])
            ->expectsOutputToContain('/employees   (fetch_employees)')
            ->expectsOutputToContain('send_profile_change')
            ->assertSuccessful();
    }

    public function test_mawared_is_served_from_the_hr_documents_and_a_setting_can_move_an_address(): void
    {
        $this->artisan('tedc:mock-ministry', ['system' => 'mawared', '--routes' => true])->expectsOutputToContain('/employees')->assertSuccessful();
        $this->artisan('tedc:mock-ministry', ['system' => 'ministry_site', '--routes' => true, '--setting' => ['publish_path=/api/tedc-feed']])
            ->expectsOutputToContain('/api/tedc-feed   (publish_item)')->assertSuccessful();
    }

    public function test_an_unknown_system_is_refused_with_the_ones_it_knows(): void
    {
        $this->artisan('tedc:mock-ministry', ['system' => 'telepathy', '--routes' => true])->expectsOutputToContain('hr')->assertFailed();
    }

    public function test_it_never_runs_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('tedc:mock-ministry', ['system' => 'hr', '--routes' => true])->expectsOutputToContain('production')->assertFailed();
    }

    public function test_the_served_mock_answers_like_the_system_would(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", base_path('scripts/mock-ministry-router.php')], base_path(), [
            'TEDC_MOCK_SYSTEM' => 'hr', 'TEDC_MOCK_DIR' => resource_path('contracts/hr'), 'TEDC_MOCK_SCENARIO' => 'ok', 'TEDC_MOCK_KEY' => 'UAT-KEY', 'TEDC_MOCK_SETTINGS' => '{}',
        ]);
        $server->start();
        try {
            for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
                usleep(100_000);
            }
            $base = "http://127.0.0.1:{$port}";

            $ok = Http::withToken('UAT-KEY')->get("{$base}/employees");
            $this->assertSame(200, $ok->status());
            $this->assertSame('E-1001', $ok->json('data.0.employee_no'));
            $this->assertSame(401, Http::withToken('wrong')->get("{$base}/employees")->status());
            $this->assertSame(404, Http::withToken('UAT-KEY')->get("{$base}/nothing")->status());
            $this->assertSame(500, Http::withToken('UAT-KEY')->withHeaders(['X-Mock-Scenario' => 'server_error'])->get("{$base}/employees")->status());
            $created = Http::withToken('UAT-KEY')->post("{$base}/profile-changes", ['reference' => 'REQ-1', 'employee_no' => 'E-1001', 'changes' => [['field' => 'x']]]);
            $this->assertSame([201, 'HR-REQ-77'], [$created->status(), $created->json('reference')]);
        } finally {
            $server->stop(1);
        }
    }
}
