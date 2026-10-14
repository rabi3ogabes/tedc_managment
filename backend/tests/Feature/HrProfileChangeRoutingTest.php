<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\IntegrationManager;
use App\Integrations\WebhookSignature;
use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CAR-05: a change to something HR masters (name, job title, qualification, experience dates…) is not written in TEDC. Its approval sends
 * a profile change to HR through the contract (queued, retried), HR's answer comes back by signed webhook, the employee sees where the
 * request stands, and what HR masters is read-only in TEDC while HR wins.
 */
class HrProfileChangeRoutingTest extends TestCase
{
    private const BASE = 'https://hr.test/api';

    private const SECRET = 'hr-inbound-secret';

    private User $admin;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tedc.integrations.backoff_ms' => 0]);
        $this->admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->employee = $this->makeEmployee(['employee_no' => 'E-1001', 'qualification' => 'bachelor', 'experience_years' => 8], $this->makeUser(Role::EMPLOYEE, ['email' => 'sara.kuwari@example.test']));
    }

    private function hr(array $settings = [], string $driver = 'http'): void
    {
        app(IntegrationManager::class)->update('hr', ['driver' => $driver, 'enabled' => true, 'settings' => $settings + ['base_url' => self::BASE, 'api_key' => 'HR-KEY-123', 'inbound_secret' => self::SECRET]]);
    }

    /** The employee asks for a change; an administrator approves it. */
    private function approved(string $field = 'qualification', string $value = 'master'): ProfileChangeRequest
    {
        $id = $this->asUser($this->employee->user)->postJson('/api/v1/me/account/requests', ['field' => $field, 'requested_value' => $value, 'note' => 'I finished my degree'])->assertCreated()->json('data.id');
        $this->asUser($this->admin)->postJson("/api/v1/admin/profile-requests/{$id}/approve", ['apply' => true, 'note' => 'Certificate seen'])->assertOk();

        return ProfileChangeRequest::findOrFail($id);
    }

    private function webhook(array $body, string $secret = self::SECRET)
    {
        $raw = json_encode($body);
        $ts = (string) time();

        return $this->call('POST', '/api/v1/integrations/hr/inbound', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TEDC_TIMESTAMP' => $ts, 'HTTP_X_TEDC_SIGNATURE' => 'sha256='.WebhookSignature::sign($secret, $ts, $raw)], $raw);
    }

    public function test_the_approval_of_a_change_hr_masters_goes_to_hr_and_is_not_written_in_tedc(): void
    {
        $this->hr();
        ContractFixtures::fake('hr', self::BASE);

        $request = $this->approved();

        $this->assertSame(['approved', false, 'sent', 'HR-REQ-77', 'hr'], [$request->status, $request->applied, $request->hr_status, $request->hr_reference, $request->hr_system]);
        $this->assertSame('bachelor', $this->employee->fresh()->qualification, 'HR masters it: TEDC writes nothing until HR has accepted');
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/profile-changes' && $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer HR-KEY-123')
            && $r->hasHeader('Idempotency-Key', 'tedc-pc-'.$request->id) && $r['reference'] === $request->id && $r['employee_no'] === 'E-1001'
            && $r['changes'][0]['field'] === 'qualification' && $r['changes'][0]['from'] === 'bachelor' && $r['changes'][0]['to'] === 'master' && $r['note'] === 'I finished my degree');

        $mine = $this->asUser($this->employee->user)->getJson('/api/v1/me/account/requests')->assertOk()->json('data.0');
        $this->assertSame(['sent', 'HR-REQ-77', true], [$mine['hr_status'], $mine['hr_reference'], $mine['hr_mastered']]);
        $this->assertSame(1, AppNotification::where('user_id', $this->employee->user_id)->where('type', 'profile.request_approved')->count());
    }

    public function test_hr_accepts_by_signed_webhook_and_the_change_appears_and_the_employee_is_told(): void
    {
        $this->hr();
        ContractFixtures::fake('hr', self::BASE);
        $request = $this->approved();

        $this->webhook(['id' => 'r1', 'type' => 'profile_change.result', 'reference' => $request->id, 'status' => 'accepted', 'hr_reference' => 'HR-REQ-77', 'employee' => ['employee_no' => 'E-1001', 'qualification' => 'master']])
            ->assertOk()->assertJsonPath('data.status', 'processed');

        $request->refresh();
        $this->assertSame(['accepted', true], [$request->hr_status, $request->applied]);
        $this->assertNotNull($request->hr_resolved_at);
        $this->assertSame('master', $this->employee->fresh()->qualification, 'HR sent the record it now holds: TEDC shows it at once');
        $this->assertSame(1, AppNotification::where('user_id', $this->employee->user_id)->where('type', 'profile.hr_accepted')->count());
        $this->assertSame('accepted', $this->asUser($this->employee->user)->getJson('/api/v1/me/account/requests')->json('data.0.hr_status'));
    }

    public function test_hr_refusing_closes_the_request_with_its_reason_and_the_employee_is_told(): void
    {
        $this->hr();
        ContractFixtures::fake('hr', self::BASE);
        $request = $this->approved();

        $this->webhook(['id' => 'r2', 'type' => 'profile_change.result', 'reference' => $request->id, 'status' => 'rejected', 'reason' => 'The degree is not yet in the file'])->assertOk();

        $request->refresh();
        $this->assertSame(['rejected', 'rejected', 'The degree is not yet in the file'], [$request->status, $request->hr_status, $request->hr_reason]);
        $this->assertStringContainsString('The degree is not yet in the file', (string) $request->review_note);
        $this->assertSame('bachelor', $this->employee->fresh()->qualification);
        $note = AppNotification::where('user_id', $this->employee->user_id)->where('type', 'profile.hr_rejected')->firstOrFail();
        $this->assertStringContainsString('The degree is not yet in the file', json_encode($note->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_an_unsigned_a_repeated_and_a_stale_result_change_nothing(): void
    {
        $this->hr();
        ContractFixtures::fake('hr', self::BASE);
        $request = $this->approved();
        $accept = ['id' => 'r3', 'type' => 'profile_change.result', 'reference' => $request->id, 'status' => 'accepted'];

        $this->webhook($accept, 'wrong-secret')->assertUnauthorized();
        $this->assertSame('sent', $request->fresh()->hr_status);
        $this->postJson('/api/v1/integrations/hr/inbound', $accept)->assertUnauthorized();   // no signature at all

        $this->webhook($accept)->assertOk()->assertJsonPath('data.status', 'processed');
        $this->webhook($accept)->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->webhook(['id' => 'r4', 'type' => 'profile_change.result', 'reference' => $request->id, 'status' => 'rejected', 'reason' => 'late'])->assertOk()->assertJsonPath('data.status', 'ignored');
        $this->assertSame(['accepted', 'approved'], [$request->fresh()->hr_status, $request->fresh()->status], 'a finished request is not turned around by a later message');
        $this->webhook(['id' => 'r5', 'type' => 'profile_change.result', 'reference' => '00000000-0000-4000-8000-000000000000', 'status' => 'accepted'])->assertOk()->assertJsonPath('data.status', 'ignored');
        $this->assertSame(1, AppNotification::where('user_id', $this->employee->user_id)->where('type', 'profile.hr_accepted')->count(), 'the employee is told once');
    }

    public function test_when_hr_is_down_the_change_waits_is_retried_and_then_the_administrators_are_told(): void
    {
        $this->hr();
        ContractFixtures::fake('hr', self::BASE, ['send_profile_change' => 'server_error']);

        $request = $this->approved();
        $this->assertSame(['queued', 1], [$request->hr_status, $request->hr_attempts]);
        $this->assertTrue($request->hr_next_attempt_at->isFuture());
        $this->assertStringContainsString('503', (string) $request->hr_last_error);
        $this->assertSame('queued', $this->asUser($this->employee->user)->getJson('/api/v1/me/account/requests')->json('data.0.hr_status'));

        $this->artisan('tedc:tickets-sync')->assertSuccessful();
        $this->assertSame(1, $request->fresh()->hr_attempts, 'not before its retry time');

        foreach (range(2, 6) as $attempt) {
            ProfileChangeRequest::whereKey($request->id)->update(['hr_next_attempt_at' => now()->subMinute()]);
            $this->artisan('tedc:tickets-sync')->assertSuccessful();
            $this->assertSame($attempt, $request->fresh()->hr_attempts);
        }
        $this->assertSame('failed', $request->fresh()->hr_status);
        $this->assertSame(1, AppNotification::where('user_id', $this->admin->id)->where('type', 'profile.hr_failed')->count());

        // HR is back (after the pause the circuit breaker took): an administrator sends it again.
        $this->travel(6)->minutes();
        Http::swap(new HttpFactory);
        ContractFixtures::fake('hr', self::BASE);
        $this->asUser($this->admin)->postJson("/api/v1/admin/profile-requests/{$request->id}/resend")->assertOk()->assertJsonPath('data.hr_status', 'sent');
        $this->assertSame('HR-REQ-77', $request->fresh()->hr_reference);
        $this->asUser($this->employee->user)->postJson("/api/v1/admin/profile-requests/{$request->id}/resend")->assertForbidden();
    }

    public function test_what_hr_masters_is_read_only_in_tedc_with_an_explanation_while_hr_wins(): void
    {
        $this->hr();
        $mine = $this->asUser($this->employee->user)->getJson('/api/v1/me/account')->assertOk()->json('data.sections');
        $fields = collect($mine)->pluck('fields')->flatten(1)->keyBy('key');
        $this->assertTrue($fields['qualification']['hr_mastered']);
        $this->assertStringContainsString('HR', $fields['qualification']['hr_note']);
        $this->assertFalse($fields['phone']['hr_mastered']);
        $this->assertNull($fields['phone']['hr_note']);

        // The employee file cannot be edited around HR for what HR masters…
        $this->asUser($this->admin)->putJson("/api/v1/admin/employees/{$this->employee->id}", ['qualification' => 'phd'])->assertStatus(422)->assertJsonPath('fields.0', 'qualification');
        $this->assertSame('bachelor', $this->employee->fresh()->qualification);
        // …but the same value, and what HR does not master, can.
        $this->asUser($this->admin)->putJson("/api/v1/admin/employees/{$this->employee->id}", ['qualification' => 'bachelor', 'gender' => 'female'])->assertOk();
        $this->assertSame('female', $this->employee->fresh()->gender);
    }

    public function test_when_tedc_wins_or_hr_is_not_connected_the_usual_flow_applies(): void
    {
        Http::fake();
        // HR is not connected.
        $request = $this->approved();
        $this->assertSame([null, true], [$request->hr_status, $request->applied]);
        $this->assertSame('master', $this->employee->fresh()->qualification);

        // HR is connected but TEDC wins: the field is TEDC's.
        $this->hr(['conflict_policy' => 'tedc_wins']);
        $second = $this->approved('experience_years', '10');
        $this->assertNull($second->hr_status);
        $this->asUser($this->admin)->putJson("/api/v1/admin/employees/{$this->employee->id}", ['qualification' => 'phd'])->assertOk();
        $account = collect($this->asUser($this->employee->user)->getJson('/api/v1/me/account')->json('data.sections'))->pluck('fields')->flatten(1)->keyBy('key');
        $this->assertFalse($account['qualification']['hr_mastered']);
        Http::assertNothingSent();
    }

    public function test_a_field_hr_does_not_master_follows_the_usual_flow_even_while_hr_wins(): void
    {
        $this->hr();
        Http::fake();

        $request = $this->approved('phone', '+974 5555 0000');

        $this->assertSame([null, true], [$request->hr_status, $request->applied]);
        $this->assertSame('+974 5555 0000', $this->employee->user->fresh()->phone);
        Http::assertNothingSent();
    }

    public function test_the_switch_and_the_list_of_what_hr_masters_are_settings(): void
    {
        $this->hr(['route_profile_changes' => false]);
        Http::fake();
        $this->assertNull($this->approved()->hr_status, 'routing switched off: the usual flow');

        $this->hr(['route_profile_changes' => true, 'hr_mastered_fields' => ['experience_years']]);
        $this->assertNull($this->approved('hire_date', '2015-09-01')->hr_status, 'not in the list: the usual flow');
        ContractFixtures::fake('hr', self::BASE);
        $this->assertSame('sent', $this->approved('experience_years', '9')->hr_status);
    }

    public function test_in_training_mode_hr_is_simulated_without_any_call(): void
    {
        $this->hr(driver: 'fake');
        Http::fake();

        $request = $this->approved();

        $this->assertSame('sent', $request->hr_status);
        $this->assertStringStartsWith('FAKE-', (string) $request->hr_reference);
        Http::assertNothingSent();
        $this->webhook(['id' => 'f1', 'type' => 'profile_change.result', 'reference' => $request->id, 'status' => 'accepted'])->assertOk()->assertJsonPath('data.status', 'processed');
        $this->assertSame('accepted', $request->fresh()->hr_status);
    }

    public function test_the_reconciliation_report_lists_where_tedc_and_hr_differ(): void
    {
        $this->hr();
        $this->employee->update(['qualification' => 'bachelor', 'hire_date' => '2016-09-01']);
        $this->employee->user->update(['name' => 'Sara Kuwari', 'name_ar' => 'سارة الكواري']);
        $this->makeEmployee(['employee_no' => 'E-9999'], $this->makeUser(Role::EMPLOYEE, ['name' => 'Only Here']));   // HR does not have this person
        ContractFixtures::fake('hr', self::BASE);   // HR has E-1001 (Master), E-1002 (not in TEDC) and E-0900 (left, not in TEDC)

        $report = $this->asUser($this->admin)->getJson('/api/v1/admin/integrations/hr/reconciliation')->assertOk()->json('data');

        $this->assertSame(3, $report['hr_total']);
        $this->assertSame(['E-1002'], array_column($report['only_in_hr'], 'employee_no'));
        $this->assertSame(['E-9999'], array_column($report['only_in_tedc'], 'employee_no'));
        $different = collect($report['different'])->keyBy('employee_no');
        $this->assertSame('qualification', collect($different['E-1001']['fields'])->firstWhere('field', 'qualification')['field']);
        $this->assertSame(['bachelor', 'Master'], [collect($different['E-1001']['fields'])->firstWhere('field', 'qualification')['tedc'], collect($different['E-1001']['fields'])->firstWhere('field', 'qualification')['hr']]);
        $this->assertNull(collect($different['E-1001']['fields'])->firstWhere('field', 'hire_date'), 'the same date is not a difference');
        $this->assertSame(['matching' => 0, 'different' => 1, 'only_in_hr' => 1, 'only_in_tedc' => 1], collect($report['summary'])->only(['matching', 'different', 'only_in_hr', 'only_in_tedc'])->all());

        $csv = $this->asUser($this->admin)->get('/api/v1/admin/integrations/hr/reconciliation?format=csv')->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->streamedContent(), 'Excel opens Arabic text correctly only with the byte-order mark');
        $this->assertStringContainsString('E-1001', $csv->streamedContent());

        $this->asUser($this->employee->user)->getJson('/api/v1/admin/integrations/hr/reconciliation')->assertForbidden();
        $this->asUser($this->admin)->getJson('/api/v1/admin/integrations/licences/reconciliation')->assertNotFound();
    }

    public function test_the_reconciliation_never_shows_the_national_id(): void
    {
        $this->hr(driver: 'fake');
        $this->employee->update(['national_id' => '29012345678']);
        app(IntegrationManager::class)->update('hr', ['settings' => ['fake_employees' => [['employee_no' => 'E-1001', 'national_id' => '29000000000', 'status' => 'active']]]]);

        $response = $this->asUser($this->admin)->getJson('/api/v1/admin/integrations/hr/reconciliation')->assertOk();

        $this->assertStringNotContainsString('29012345678', $response->getContent());
        $this->assertStringNotContainsString('29000000000', $response->getContent());
        $field = collect($response->json('data.different.0.fields'))->firstWhere('field', 'national_id');
        $this->assertSame([null, null], [$field['tedc'], $field['hr']], 'only the fact that they differ');
    }
}
