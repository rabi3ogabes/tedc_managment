<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SiteSetting;
use App\Security\AppKeyRotator;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/** Moving everything that is locked with APP_KEY to a new key (docs/KEY-ROTATION.md). */
class AppKeyRotationTest extends TestCase
{
    private string $newKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->newKey = 'base64:'.base64_encode(random_bytes(32));
    }

    private function newEncrypter(): Encrypter
    {
        return new Encrypter(base64_decode(substr($this->newKey, 7)), 'AES-256-CBC');
    }

    /** A person with a locked national ID and MFA secret, plus a settings document with locked values at different depths. */
    private function seedLockedData(): array
    {
        $user = $this->makeUser();
        $user->forceFill(['mfa_secret' => Crypt::encryptString('MFA-SECRET-1')])->saveQuietly();
        $employee = Employee::create(['user_id' => $user->id, 'employee_no' => 'E-ROT', 'national_id' => '29163400002', 'national_id_hash' => Employee::nationalIdHash('29163400002'), 'status' => 'active']);
        SiteSetting::updateOrCreate(['key' => 'rotation_probe'], ['value' => [
            'plain' => 'visible', 'count' => 3, 'empty' => [],
            'api_key' => Crypt::encryptString('API-KEY-1'),
            'nested' => ['list' => [Crypt::encryptString('LIST-1'), 'also plain']],
        ]]);

        return [$user, $employee];
    }

    private function rawColumn(string $table, string $column, string $key, string $id): string
    {
        return (string) DB::table($table)->where($key, $id)->value($column);
    }

    public function test_a_dry_run_counts_and_changes_nothing(): void
    {
        [$user, $employee] = $this->seedLockedData();
        $before = [$this->rawColumn('users', 'mfa_secret', 'id', $user->id), $this->rawColumn('employees', 'national_id', 'id', $employee->id), $this->rawColumn('employees', 'national_id_hash', 'id', $employee->id)];

        $report = (new AppKeyRotator($this->newKey, 'dry'))->handle();

        $this->assertTrue($report['finished']);
        $this->assertGreaterThanOrEqual(4, $report['rotated']);   // mfa secret, national id, api key, list item
        $this->assertFalse($report['complete']);
        $this->assertSame($before, [$this->rawColumn('users', 'mfa_secret', 'id', $user->id), $this->rawColumn('employees', 'national_id', 'id', $employee->id), $this->rawColumn('employees', 'national_id_hash', 'id', $employee->id)]);
    }

    public function test_a_run_relocks_every_value_and_recomputes_the_fingerprints(): void
    {
        [$user, $employee] = $this->seedLockedData();

        $report = (new AppKeyRotator($this->newKey, 'run'))->handle();

        $this->assertTrue($report['finished']);
        $this->assertSame(0, $report['unreadable']);
        $this->assertGreaterThanOrEqual(4, $report['rotated']);

        $new = $this->newEncrypter();
        $this->assertSame('MFA-SECRET-1', $new->decryptString($this->rawColumn('users', 'mfa_secret', 'id', $user->id)));
        $this->assertSame('29163400002', $new->decryptString($this->rawColumn('employees', 'national_id', 'id', $employee->id)));
        $this->assertSame(Employee::nationalIdHash('29163400002', $this->newKey), $this->rawColumn('employees', 'national_id_hash', 'id', $employee->id));
        $this->assertNotSame(Employee::nationalIdHash('29163400002'), $this->rawColumn('employees', 'national_id_hash', 'id', $employee->id), 'the fingerprint follows the new key');

        // The settings document keeps its shape: plain values, numbers and empty lists untouched; locked values re-locked at every depth.
        $value = SiteSetting::find('rotation_probe')->value;
        $this->assertSame('visible', $value['plain']);
        $this->assertSame(3, $value['count']);
        $this->assertSame([], $value['empty']);
        $this->assertSame('also plain', $value['nested']['list'][1]);
        $this->assertSame('API-KEY-1', $new->decryptString($value['api_key']));
        $this->assertSame('LIST-1', $new->decryptString($value['nested']['list'][0]));

        // The old key no longer opens anything it moved.
        $this->expectException(DecryptException::class);
        Crypt::decryptString($this->rawColumn('employees', 'national_id', 'id', $employee->id));
    }

    public function test_running_twice_is_safe_and_the_second_pass_changes_nothing(): void
    {
        $this->seedLockedData();
        (new AppKeyRotator($this->newKey, 'run'))->handle();

        $again = (new AppKeyRotator($this->newKey, 'run'))->handle();

        $this->assertSame(0, $again['rotated']);
        $this->assertGreaterThanOrEqual(4, $again['already_new']);
        $this->assertSame(0, $again['unreadable']);
        $this->assertTrue($again['complete']);
    }

    public function test_verify_with_the_new_key_as_app_key_confirms_everything_opens(): void
    {
        $this->seedLockedData();
        (new AppKeyRotator($this->newKey, 'run'))->handle();

        // Switch the platform to the new key, as the hosting would.
        config(['app.key' => $this->newKey]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $verify = (new AppKeyRotator($this->newKey, 'verify'))->handle();

        $this->assertTrue($verify['complete'], json_encode($verify));
        $this->assertSame(0, $verify['rotated']);
        $this->assertSame(0, $verify['unreadable']);
    }

    public function test_a_value_neither_key_opens_is_left_alone_and_reported(): void
    {
        $this->seedLockedData();
        $stranger = (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString('from another key');
        SiteSetting::updateOrCreate(['key' => 'stranger'], ['value' => ['secret' => $stranger]]);

        $report = (new AppKeyRotator($this->newKey, 'run'))->handle();

        $this->assertSame(1, $report['unreadable']);
        $this->assertFalse($report['complete']);
        $this->assertSame($stranger, SiteSetting::find('stranger')->value['secret'], 'it is never rewritten or dropped');
    }

    public function test_the_new_key_must_be_valid_and_different(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AppKeyRotator((string) config('app.key'), 'run');
    }

    public function test_the_rotation_endpoint_only_exists_while_a_new_key_is_set(): void
    {
        config(['tedc.cron_secret' => 'right-secret', 'tedc.rotation.new_app_key' => null]);
        $this->postJson('/api/v1/system/rotate-key', ['mode' => 'dry'], ['Authorization' => 'Bearer right-secret'])->assertNotFound();

        config(['tedc.rotation.new_app_key' => $this->newKey]);
        $this->postJson('/api/v1/system/rotate-key', ['mode' => 'dry'], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->postJson('/api/v1/system/rotate-key', ['mode' => 'run'], ['Authorization' => 'Bearer right-secret'])->assertStatus(422);   // writing needs the confirmation word
    }

    public function test_the_endpoint_runs_and_never_returns_a_key_or_a_value(): void
    {
        $this->seedLockedData();
        config(['tedc.cron_secret' => 'right-secret', 'tedc.rotation.new_app_key' => $this->newKey]);
        $headers = ['Authorization' => 'Bearer right-secret'];

        $dry = $this->postJson('/api/v1/system/rotate-key', ['mode' => 'dry'], $headers)->assertOk()->assertJsonPath('data.finished', true);
        $this->assertGreaterThanOrEqual(4, $dry->json('data.rotated'));

        $run = $this->postJson('/api/v1/system/rotate-key', ['mode' => 'run', 'confirm' => 'ROTATE-APP-KEY'], $headers)->assertOk();
        $this->assertSame(0, $run->json('data.unreadable'));
        foreach ([$dry->getContent(), $run->getContent()] as $body) {
            $this->assertStringNotContainsString(substr($this->newKey, 7), $body);
            $this->assertStringNotContainsString('29163400002', $body);
            $this->assertStringNotContainsString('API-KEY-1', $body);
        }
    }

    public function test_the_command_reports_and_refuses_a_bad_key(): void
    {
        $this->seedLockedData();
        $this->artisan('tedc:rotate-app-key', ['--mode' => 'dry', '--new-key' => $this->newKey])->assertFailed();   // dry finds work to do: not complete
        $this->artisan('tedc:rotate-app-key', ['--mode' => 'run', '--new-key' => $this->newKey, '--yes' => true])->assertSuccessful();
        $this->artisan('tedc:rotate-app-key', ['--mode' => 'dry', '--new-key' => $this->newKey])->assertSuccessful();   // nothing left to move
        $this->artisan('tedc:rotate-app-key', ['--mode' => 'run', '--new-key' => (string) config('app.key'), '--yes' => true])->assertFailed();
    }
}
