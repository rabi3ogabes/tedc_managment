<?php

namespace Tests\Feature;

use App\Integrations\IntegrationManager;
use App\Integrations\Ministry\HrSync;
use App\Integrations\Ministry\LicenceSync;
use App\Integrations\Ministry\NsisSync;
use App\Integrations\Ministry\QnedsPublisher;
use App\Integrations\Ministry\SaeedTickets;
use App\Integrations\Ministry\SijilArchive;
use App\Integrations\WebhookSignature;
use App\Models\AppNotification;
use App\Models\ArchiveItem;
use App\Models\CareerPath;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\ProfessionalLicence;
use App\Models\Registration;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MinistryIntegrationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['tedc.integrations.backoff_ms' => 0]);
    }

    private function sys(string $key, array $settings = [], string $driver = 'fake'): void
    {
        app(IntegrationManager::class)->update($key, ['driver' => $driver, 'enabled' => true, 'settings' => $settings]);
    }

    private function fakeRows(string $key, string $field, array $rows): void
    {
        $i = app(IntegrationManager::class)->get($key);
        $i->putSettings(array_merge($i->settings(), [$field => $rows]));
        $i->save();
    }

    public function test_hr_sync_creates_updates_and_deactivates_with_hr_winning_master_data(): void
    {
        $school = $this->makeSchool(['code' => 'SCH-9']);
        $title = JobTitle::first();
        $manager = $this->makeEmployee(['employee_no' => 'M-1']);
        $existing = $this->makeEmployee(['employee_no' => 'E-1', 'qualification' => 'bachelor'], $this->makeUser(Role::EMPLOYEE, ['email' => 'e1@moe.gov.qa', 'name' => 'Old Name']));
        $leaver = $this->makeEmployee(['employee_no' => 'E-2'], $this->makeUser(Role::EMPLOYEE, ['email' => 'e2@moe.gov.qa']));
        Registration::create(['program_id' => $this->makeProgram()->id, 'employee_id' => $leaver->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_COMPLETED]);
        $tokenOfLeaver = $this->postJson('/api/v1/auth/login', ['email' => 'e2@moe.gov.qa', 'password' => 'Secret#12345'])->assertOk()->json('access_token');

        $this->sys('hr');
        $this->fakeRows('hr', 'fake_employees', [
            ['employee_no' => 'E-1', 'name' => 'New Name', 'email' => 'e1@moe.gov.qa', 'job_title_code' => $title->code, 'school_code' => 'SCH-9', 'supervisor_employee_no' => 'M-1', 'qualification' => 'master', 'hire_date' => '2020-01-15'],
            ['employee_no' => 'E-2', 'status' => 'left'],
            ['employee_no' => 'E-3', 'name' => 'Brand New', 'email' => 'e3@moe.gov.qa', 'school_code' => 'SCH-9', 'job_title_code' => $title->code, 'gender' => 'female'],
            ['employee_no' => 'E-4', 'status' => 'left'],                                // a leaver we never had
            ['name' => 'no number'],                                                       // unusable row
            ['employee_no' => 'E-5', 'name' => 'No school yet', 'email' => 'e5@moe.gov.qa', 'school_code' => 'NOPE'],   // an unknown school code is left blank, the person is still created
        ]);
        $r = app(HrSync::class)->run('hr');
        $this->assertSame(['created' => 2, 'updated' => 1, 'deactivated' => 1, 'skipped' => 2, 'errors' => 0], $r);
        $this->assertNull(Employee::where('employee_no', 'E-5')->first()->school_id);

        $e1 = $existing->fresh();
        $this->assertSame('master', $e1->qualification);                                  // HR wins
        $this->assertSame($school->id, $e1->school_id);
        $this->assertSame($manager->id, $e1->supervisor_id);
        $this->assertSame('New Name', $e1->user->name);
        $this->assertSame('female', Employee::where('employee_no', 'E-3')->first()->gender);
        $this->assertSame('inactive', $leaver->user->fresh()->status);                     // cannot sign in
        $this->assertSame('left', $leaver->fresh()->status);
        $this->assertSame(1, Registration::where('employee_id', $leaver->id)->count());    // the training record stays
        $this->withHeader('Authorization', 'Bearer '.$tokenOfLeaver)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertNotNull(app(IntegrationManager::class)->get('hr')->last_sync_at);

        // When TEDC wins, HR only fills blanks.
        $this->sys('hr', ['conflict_policy' => 'tedc_wins']);
        $this->fakeRows('hr', 'fake_employees', [['employee_no' => 'E-1', 'name' => 'Third Name', 'qualification' => 'phd', 'nationality' => 'QA']]);
        app(HrSync::class)->run('hr');
        $this->assertSame('master', $e1->fresh()->qualification);
        $this->assertSame('QA', $e1->fresh()->nationality);
        $this->assertSame('New Name', $e1->fresh()->user->name);

        // A leaver who returns is reactivated.
        $this->sys('hr', ['conflict_policy' => 'hr_wins']);
        $this->fakeRows('hr', 'fake_employees', [['employee_no' => 'E-2', 'status' => 'active', 'email' => 'e2@moe.gov.qa']]);
        app(HrSync::class)->run('hr');
        $this->assertSame('active', $leaver->user->fresh()->status);
    }

    public function test_hr_sync_asks_only_for_what_changed_since_the_last_run_and_mawared_takes_precedence(): void
    {
        $this->sys('mawared', ['base_url' => 'https://mawared.test', 'api_key' => 'k', 'employees_path' => '/employees'], 'http');
        $this->sys('hr', ['base_url' => 'https://hr.test']);
        $this->assertSame('mawared', app(HrSync::class)->active());
        Http::fake(['mawared.test/*' => Http::response(['data' => []])]);
        app(HrSync::class)->run('mawared');
        app(HrSync::class)->run('mawared');
        $sent = Http::recorded();
        $this->assertArrayNotHasKey('since', [] + (array) parse_url((string) $sent[0][0]->url(), PHP_URL_QUERY));
        $this->assertStringNotContainsString('since=', (string) $sent[0][0]->url());                // the first run is a full sync
        $this->assertStringContainsString('since=', (string) $sent[1][0]->url());                    // the second only asks for changes
        $this->assertTrue($sent[0][0]->hasHeader('Authorization', 'Bearer k'));
    }

    public function test_hr_changes_arrive_by_signed_webhook_once(): void
    {
        $this->sys('hr', ['inbound_secret' => 'hr-secret']);
        $e = $this->makeEmployee(['employee_no' => 'E-1', 'qualification' => 'bachelor'], $this->makeUser(Role::EMPLOYEE, ['email' => 'e1@moe.gov.qa']));
        $post = function (array $body, string $secret = 'hr-secret') {
            $raw = json_encode($body);
            $ts = (string) time();

            return $this->call('POST', '/api/v1/integrations/hr/inbound', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TEDC_TIMESTAMP' => $ts, 'HTTP_X_TEDC_SIGNATURE' => 'sha256='.WebhookSignature::sign($secret, $ts, $raw)], $raw);
        };
        $post(['id' => 'm1', 'type' => 'employee.updated', 'employee' => ['employee_no' => 'E-1', 'qualification' => 'master']], 'wrong')->assertStatus(401);
        $post(['id' => 'm1', 'type' => 'employee.updated', 'employee' => ['employee_no' => 'E-1', 'qualification' => 'master']])->assertOk()->assertJsonPath('data.status', 'processed');
        $this->assertSame('master', $e->fresh()->qualification);
        $e->update(['qualification' => 'diploma']);
        $post(['id' => 'm1', 'type' => 'employee.updated', 'employee' => ['employee_no' => 'E-1', 'qualification' => 'master']])->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->assertSame('diploma', $e->fresh()->qualification);                          // the repeat changed nothing
        $post(['id' => 'm2', 'type' => 'employee.left', 'employee' => ['employee_no' => 'E-1']])->assertOk();
        $this->assertSame('inactive', $e->user->fresh()->status);
        $post(['id' => 'm3', 'type' => 'something.else'])->assertOk()->assertJsonPath('data.status', 'ignored');
    }

    public function test_licences_are_read_and_completions_are_pushed(): void
    {
        $employee = $this->makeEmployee(['employee_no' => 'E-1']);
        $path = CareerPath::create(['type' => 'licence', 'title_ar' => 'رخصة المعلم', 'title_en' => 'Teacher licence', 'is_active' => true, 'job_title_ids' => []]);
        $this->sys('licences', ['base_url' => 'https://lic.test'], 'http');
        $cert = Certificate::create(['certificate_no' => 'C-1', 'verification_code' => 'VCODE1', 'registration_id' => Registration::create(['program_id' => ($p = $this->makeProgram(['code' => 'PRG-1']))->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => 'completed'])->id, 'employee_id' => $employee->id, 'program_id' => $p->id, 'issued_at' => now(), 'hours' => 12, 'status' => 'valid']);
        Http::fake(['lic.test/licences*' => Http::response(['data' => [
            ['employee_no' => 'E-1', 'path_title' => 'Teacher licence', 'level_no' => 2, 'licence_no' => 'L-77', 'issued_at' => '2025-01-01', 'expires_at' => '2028-01-01', 'status' => 'active'],
            ['employee_no' => 'NOBODY', 'path_title' => 'Teacher licence', 'licence_no' => 'L-78'],
            ['employee_no' => 'E-1', 'path_title' => 'Unknown path', 'licence_no' => 'L-79'],
        ]]), 'lic.test/completions' => Http::response(['accepted' => 1])]);

        $r = app(LicenceSync::class)->run();
        $this->assertSame(['created' => 1, 'updated' => 0, 'unmatched' => 2, 'pushed' => 1], $r);
        $l = ProfessionalLicence::where('licence_no', 'L-77')->first();
        $this->assertSame(2, $l->level_no);
        $this->assertSame('ministry', $l->source);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/completions') && $req['items'][0]['employee_no'] === 'E-1' && $req['items'][0]['program_code'] === 'PRG-1' && $req['items'][0]['hours'] === 12.0);

        // The same certificate is not pushed twice; the next run only sends what is new.
        $this->assertSame(0, app(LicenceSync::class)->pushCompletions());
        $this->assertSame('updated', app(LicenceSync::class)->apply(['employee_no' => 'E-1', 'path_id' => $path->id, 'licence_no' => 'L-77', 'level_no' => 3]));
        $this->assertSame(3, $l->fresh()->level_no);
    }

    public function test_nsis_updates_grades_and_subjects_and_qneds_publishes_only_aggregates(): void
    {
        $school = $this->makeSchool(['code' => 'S-1', 'moe_no' => 'MOE-1']);
        $e = $this->makeEmployee(['employee_no' => 'EMP-777', 'school_id' => $school->id]);
        $this->sys('nsis');
        $this->fakeRows('nsis', 'fake_teachers', [['employee_no' => 'EMP-777', 'grades' => [4, 5], 'subjects' => ['Math']], ['employee_no' => 'X', 'grades' => [1]]]);
        $this->assertSame(['updated' => 1, 'unmatched' => 1], app(NsisSync::class)->run());
        $this->assertSame(['4', '5'], $e->fresh()->grades_taught);
        $this->assertSame(['Math'], $e->fresh()->subjects);

        $p = $this->makeProgram();
        $reg = Registration::create(['program_id' => $p->id, 'employee_id' => $e->id, 'source' => 'center_nomination', 'status' => 'completed']);
        Certificate::create(['certificate_no' => 'C-2', 'verification_code' => 'VCODE2', 'registration_id' => $reg->id, 'employee_id' => $e->id, 'program_id' => $p->id, 'issued_at' => now(), 'hours' => 6, 'status' => 'valid']);
        $this->sys('qneds', ['base_url' => 'https://qneds.test'], 'http');
        Http::fake(['qneds.test/*' => Http::response(['accepted' => true])]);
        $this->assertSame(['schools' => 1], app(QnedsPublisher::class)->publish());
        Http::assertSent(function ($req) {
            $item = $req['items'][0];

            return $item['school_code'] === 'MOE-1' && $item['trained_staff'] === 1 && $item['completed_programs'] === 1 && $item['training_hours'] === 6.0 && ! str_contains(json_encode($req->data()), 'EMP-777');   // no person in it
        });
    }

    public function test_problem_reports_reach_saaed_and_come_back_with_their_status(): void
    {
        Storage::fake('local');
        $user = $this->makeUser(Role::EMPLOYEE);
        $make = fn (array $extra = []) => $this->asUser($user)->post('/api/v1/me/tickets', $extra + ['category' => 'bug', 'subject' => 'Cannot save', 'description' => 'The save button does nothing', 'page_url' => 'https://app.test/portal/tasks', 'context' => ['platform' => 'web', 'viewport' => '1280x720']], ['Accept' => 'application/json']);

        // Saaed is off: the ticket waits with the administrators.
        $t = $make()->assertCreated();
        $this->assertSame('open', $t->json('data.status'));
        $this->assertNull($t->json('data.ticket_no'));

        // Saaed is down: it queues, and the minute job sends it when Saaed is back.
        $this->sys('saaed', ['base_url' => 'https://saaed.test', 'inbound_secret' => 'sa-secret', 'category_map' => ['bug' => 'TECH']], 'http');
        Http::fake(['saaed.test/tickets' => Http::sequence()->push('down', 503)->push('down', 503)->push(['ticket_no' => 'SA-1001', 'status' => 'open'])]);
        $q = $make(['screenshot' => UploadedFile::fake()->image('shot.png', 200, 100)])->assertCreated();
        $this->assertSame('queued', $q->json('data.status'));
        $id = $q->json('data.id');
        $this->artisan('tedc:tickets-sync')->assertSuccessful();
        $ticket = SupportTicket::find($id);
        $this->assertSame('SA-1001', $ticket->saaed_ticket_no);
        $this->assertSame('open', $ticket->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/tickets') && ($r['category'] ?? null) === 'TECH' && ($r['reporter']['email'] ?? null) === $user->email && ($r['page_url'] ?? null) === 'https://app.test/portal/tasks'
            && ! empty($r['screenshot']['content_base64']) && ($r['context']['platform'] ?? null) === 'web' && ($r['context']['role'] ?? null) === 'employee');
        $this->assertSame(1, AppNotification::where('user_id', $user->id)->where('type', 'ticket.created')->count());

        // Saaed reports progress by signed webhook; the person is told.
        $post = function (array $body) {
            $raw = json_encode($body);
            $ts = (string) time();

            return $this->call('POST', '/api/v1/integrations/saaed/inbound', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TEDC_TIMESTAMP' => $ts, 'HTTP_X_TEDC_SIGNATURE' => 'sha256='.WebhookSignature::sign('sa-secret', $ts, $raw)], $raw);
        };
        $post(['id' => 'u1', 'type' => 'ticket.updated', 'ticket_no' => 'SA-1001', 'status' => 'in_progress'])->assertOk();
        $post(['id' => 'u2', 'type' => 'ticket.updated', 'ticket_no' => 'SA-1001', 'status' => 'resolved', 'comment' => 'Fixed in the next release'])->assertOk();
        $post(['id' => 'u3', 'type' => 'ticket.updated', 'ticket_no' => 'NOT-OURS', 'status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'ignored');
        $this->assertSame('resolved', $ticket->fresh()->status);
        $this->assertSame(2, AppNotification::where('user_id', $user->id)->where('type', 'ticket.updated')->count());

        $this->asUser($user)->getJson('/api/v1/me/tickets')->assertOk()->assertJsonCount(2, 'data');
        $this->asUser($user)->getJson('/api/v1/admin/tickets')->assertForbidden();
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->getJson('/api/v1/admin/tickets?status=resolved')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($user)->postJson('/api/v1/me/tickets', ['category' => 'nonsense', 'subject' => 'x', 'description' => 'y'])->assertStatus(422);
        $this->assertNotNull(app(SaeedTickets::class));
    }

    public function test_certificates_are_queued_for_the_sijil_archive_and_sent_with_retries(): void
    {
        $employee = $this->makeEmployee(['employee_no' => 'E-1']);
        $program = $this->makeProgram(['code' => 'PRG-9']);
        $make = fn (string $no) => Certificate::create(['certificate_no' => $no, 'verification_code' => 'V'.$no.'ZZ', 'registration_id' => Registration::create(['program_id' => $program->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'center_nomination', 'status' => 'completed'])->id, 'employee_id' => $employee->id, 'program_id' => $program->id, 'issued_at' => now(), 'hours' => 8, 'status' => 'valid']);

        $before = $make('C-100');                                                           // issued before Sijil was switched on
        $this->assertSame(0, ArchiveItem::count());
        $this->sys('sijil', ['base_url' => 'https://sijil.test'], 'http');
        $after = $make('C-101');
        $this->assertSame(1, ArchiveItem::where('subject_id', $after->id)->count());          // queued the moment it is issued

        Http::fake(['sijil.test/archive' => Http::sequence()->push('busy', 503)->push('busy', 503)->push(['reference' => 'SJ-1'])->push(['reference' => 'SJ-2'])]);
        $r = app(SijilArchive::class)->run();                                                // the old certificate is picked up too; the first send fails and retries
        $this->assertSame(1, $r['archived']);
        $this->assertSame(2, ArchiveItem::count());
        Http::assertSent(fn ($req) => str_contains($req->url(), '/archive') && $req['metadata']['type'] === 'certificate' && $req['metadata']['employee_no'] === 'E-1' && $req['metadata']['program_code'] === 'PRG-9'
            && $req['file']['mime'] === 'application/pdf' && str_starts_with(base64_decode($req['file']['content_base64']), '%PDF'));
        $this->assertSame(1, ArchiveItem::where('status', 'archived')->count());
        $this->assertSame(1, ArchiveItem::where('status', 'pending')->count());               // waits for the next run
        $this->assertSame(1, app(SijilArchive::class)->run()['archived']);
        $this->assertSame(2, ArchiveItem::where('status', 'archived')->count());
        $this->assertNotNull($before);
        $this->assertSame(0, app(SijilArchive::class)->run()['archived']);                    // nothing left, nothing sent twice
    }

    public function test_the_admin_can_sync_and_check_each_system_and_sees_its_state(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->sys('hr');
        $this->fakeRows('hr', 'fake_employees', []);
        $this->asUser($admin)->postJson('/api/v1/admin/integrations/hr/sync')->assertOk()->assertJsonPath('data.errors', 0)->assertJsonPath('integration.health', 'ok');
        $this->asUser($admin)->postJson('/api/v1/admin/integrations/saaed/sync')->assertStatus(422);       // nothing to sync
        $this->asUser($admin)->postJson('/api/v1/admin/integrations/hr/check')->assertOk()->assertJsonPath('data.health', 'ok');
        $this->asUser($admin)->getJson('/api/v1/admin/integrations/hr/logs')->assertOk();
        $this->artisan('tedc:ministry-sync', ['--all' => true])->assertSuccessful();
        $this->artisan('tedc:integrations-health')->assertSuccessful();
        $this->artisan('tedc:tickets-sync')->assertSuccessful();
        $this->assertInstanceOf(User::class, $admin);
    }
}
