<?php

namespace Tests\Feature;

use App\Integrations\IntegrationManager;
use App\Integrations\Teams\FakeGraph;
use App\Integrations\Teams\TeamsService;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Attendance;
use App\Models\IntegrationLog;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TeamsAttendanceRecord;
use App\Models\TeamsMeeting;
use App\Models\TeamsTeam;
use App\Models\TrainingGroup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamsIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['tedc.integrations.backoff_ms' => 0]);
        Cache::flush();
    }

    private function teams(array $settings = [], string $driver = 'graph'): void
    {
        app(IntegrationManager::class)->update('teams', ['driver' => $driver, 'enabled' => true, 'settings' => $settings + ['tenant_id' => 'tenant-1', 'client_id' => 'cid', 'client_secret' => 'sec', 'organizer_upn' => 'tedc-bot@moe.gov.qa', 'auto_meetings' => true, 'lobby' => 'organization']]);
    }

    private function graph(array $extra = []): void
    {
        Http::fake($extra + [
            'login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token', 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/users/*/onlineMeetings' => Http::response(['id' => 'MEET-1', 'joinWebUrl' => 'https://teams.microsoft.com/l/meetup-join/abc']),
        ]);
    }

    private function onlineSession(array $attrs = [])
    {
        $program = $this->makeProgram();

        return [$program, $program->sessions()->create($attrs + ['title_ar' => 'جلسة', 'title_en' => 'Online session', 'starts_at' => now()->addDays(2)->setTime(10, 0), 'ends_at' => now()->addDays(2)->setTime(12, 0), 'mode' => 'online'])];
    }

    public function test_an_online_session_gets_a_teams_meeting_that_follows_the_schedule(): void
    {
        $this->teams(['lobby' => 'everyone']);
        $this->graph();
        [, $session] = $this->onlineSession();

        $m = TeamsMeeting::where('session_id', $session->id)->first();
        $this->assertNotNull($m);
        $this->assertSame('MEET-1', $m->meeting_id);
        $this->assertSame('tedc-bot@moe.gov.qa', $m->organizer_upn);
        $session->refresh();
        $this->assertSame('https://teams.microsoft.com/l/meetup-join/abc', $session->online_url);   // the join link the trainees receive
        $this->assertSame('teams', $session->online_platform);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/users/tedc-bot%40moe.gov.qa/onlineMeetings') && $r->method() === 'POST'
            && $r['lobbyBypassSettings']['scope'] === 'everyone' && $r['subject'] === 'جلسة' && $r->hasHeader('Authorization', 'Bearer graph-token')
            && $r['startDateTime'] === $session->starts_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'));

        // A changed time updates the same meeting; a cancelled session cancels it.
        Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'graph-token']), 'graph.microsoft.com/v1.0/users/*/onlineMeetings/MEET-1' => Http::response('', 200)]);
        $session->update(['starts_at' => $session->starts_at->copy()->addHour(), 'ends_at' => $session->ends_at->copy()->addHour()]);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), 'onlineMeetings/MEET-1') && $r['startDateTime'] === $session->fresh()->starts_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(1, TeamsMeeting::count());

        $session->update(['status' => 'cancelled']);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'onlineMeetings/MEET-1'));
        $this->assertSame('cancelled', $m->fresh()->status);
    }

    public function test_nothing_is_called_when_teams_is_off_or_the_session_is_not_online(): void
    {
        Http::fake();
        [, $session] = $this->onlineSession();                                   // Teams is not set up
        $this->assertSame(0, TeamsMeeting::count());
        $this->teams();
        $this->graph();
        [, $inPerson] = $this->onlineSession(['mode' => 'in_person']);
        $this->assertSame(0, TeamsMeeting::where('session_id', $inPerson->id)->count());
        [, $zoom] = $this->onlineSession(['online_platform' => 'zoom']);          // another platform keeps its own link
        $this->assertSame(0, TeamsMeeting::where('session_id', $zoom->id)->count());
        $this->assertNotNull($session);
    }

    public function test_a_failing_graph_never_blocks_saving_the_session_and_is_logged(): void
    {
        $this->teams();
        Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 't']), 'graph.microsoft.com/*' => Http::response(['error' => ['message' => 'Insufficient privileges']], 403)]);
        [, $session] = $this->onlineSession();
        $this->assertNotNull($session->fresh());                                 // saved
        $this->assertSame(0, TeamsMeeting::count());
        $this->assertStringContainsString('Insufficient privileges', (string) IntegrationLog::where('integration_key', 'teams')->where('status', 'error')->first()->error);
    }

    public function test_attendance_is_computed_from_time_in_the_call(): void
    {
        $this->teams(['min_presence_percent' => 50]);
        $this->graph();
        [$program, $session] = $this->onlineSession(['starts_at' => now()->subHours(3), 'ends_at' => now()->subHour()]);     // already over: a 120-minute session
        $mk = fn (string $email) => Registration::create(['program_id' => $program->id, 'employee_id' => $this->makeEmployee([], $this->makeUser(Role::EMPLOYEE, ['email' => $email]))->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $full = $mk('full@moe.gov.qa');
        $late = $mk('late@moe.gov.qa');
        $brief = $mk('brief@moe.gov.qa');
        $gone = $mk('gone@moe.gov.qa');
        $manual = $mk('manual@moe.gov.qa');
        Attendance::create(['program_session_id' => $session->id, 'registration_id' => $manual->id, 'employee_id' => $manual->employee_id, 'status' => 'excused', 'method' => 'manual']);

        $start = $session->starts_at;
        $iv = fn ($from, $to) => ['joinDateTime' => $start->copy()->addMinutes($from)->utc()->format('Y-m-d\TH:i:s\Z'), 'leaveDateTime' => $start->copy()->addMinutes($to)->utc()->format('Y-m-d\TH:i:s\Z'), 'durationInSeconds' => ($to - $from) * 60];
        $rec = fn (string $email, array $intervals, string $role = 'Attendee') => ['emailAddress' => $email, 'identity' => ['displayName' => $email], 'role' => $role, 'totalAttendanceInSeconds' => array_sum(array_column($intervals, 'durationInSeconds')), 'attendanceIntervals' => $intervals];
        Http::fake([
            'graph.microsoft.com/v1.0/users/*/onlineMeetings/MEET-1/attendanceReports' => Http::response(['value' => [['id' => 'R1']]]),
            'graph.microsoft.com/v1.0/users/*/onlineMeetings/MEET-1/attendanceReports/R1/attendanceRecords' => Http::response(['value' => [
                $rec('full@moe.gov.qa', [$iv(-10, 130)]),                                   // joined early, stayed late: counts the 120 scheduled minutes
                $rec('late@moe.gov.qa', [$iv(30, 50), $iv(60, 120)]),                       // left and came back: 20 + 60 = 80 minutes, joined 30 minutes late
                $rec('brief@moe.gov.qa', [$iv(0, 30)]),                                     // 30 of 120 = 25 %: not enough
                $rec('organiser@moe.gov.qa', [$iv(0, 120)], 'Organizer'),                   // not a trainee
                $rec('manual@moe.gov.qa', [$iv(0, 120)]),
            ]]),
        ]);

        $result = app(TeamsService::class)->syncAttendance($session);
        $this->assertSame(['matched' => 4, 'unmatched' => 1, 'applied' => 4], $result);
        $row = fn (Registration $r) => Attendance::where('program_session_id', $session->id)->where('registration_id', $r->id)->first();
        $this->assertSame('present', $row($full)->status);
        $this->assertSame(120, $row($full)->minutes_attended);
        $this->assertSame('teams', $row($full)->method);
        $this->assertSame('late', $row($late)->status);
        $this->assertSame(80, $row($late)->minutes_attended);
        $this->assertSame(2, $row($late)->join_count);
        $this->assertSame('absent', $row($brief)->status);                                  // under the threshold
        $this->assertSame(30, $row($brief)->minutes_attended);                            // 25 % of the session
        $this->assertSame('absent', $row($gone)->status);                                   // never appeared
        $this->assertSame('excused', $row($manual)->status);                                // a trainer's mark is not overwritten
        $this->assertSame('manual', $row($manual)->method);

        // The percentage feeds the registration (and from there absence rules and passing).
        $this->assertEquals(100.0, (float) $full->fresh()->attendance_percent);
        $this->assertEquals(66.67, (float) $late->fresh()->attendance_percent);
        $this->assertEquals(0.0, (float) $brief->fresh()->attendance_percent);
        $this->assertSame(5, TeamsAttendanceRecord::where('session_id', $session->id)->count());
        $this->assertNotNull(TeamsMeeting::first()->attendance_synced_at);

        // Running it again replaces, never doubles.
        app(TeamsService::class)->syncAttendance($session);
        $this->assertSame(5, TeamsAttendanceRecord::where('session_id', $session->id)->count());
        $this->assertSame(5, Attendance::where('program_session_id', $session->id)->count());
    }

    public function test_the_minute_job_creates_meetings_and_pulls_attendance_once_sessions_end(): void
    {
        $this->teams(['min_presence_percent' => 50], 'fake');
        [$program, $future] = $this->onlineSession();
        TeamsMeeting::query()->delete();
        $future->forceFill(['online_url' => null])->saveQuietly();
        $this->artisan('tedc:teams-sync')->assertSuccessful();
        $this->assertNotNull(TeamsMeeting::where('session_id', $future->id)->first());

        [$program2, $over] = $this->onlineSession(['starts_at' => now()->subHours(3), 'ends_at' => now()->subHours(1)]);
        $meeting = TeamsMeeting::where('session_id', $over->id)->first();
        $this->assertNotNull($meeting);
        $reg = Registration::create(['program_id' => $program2->id, 'employee_id' => $this->makeEmployee([], $this->makeUser(Role::EMPLOYEE, ['email' => 'a@moe.gov.qa']))->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        app(IntegrationManager::class)->update('teams', ['settings' => ['fake_attendance' => ['*' => [['email' => 'a@moe.gov.qa', 'name' => 'A', 'role' => 'Attendee', 'total_seconds' => 6000, 'intervals' => []]]]]]);
        $this->artisan('tedc:teams-sync')->assertSuccessful();
        $this->assertSame('present', Attendance::where('registration_id', $reg->id)->value('status'));            // 100 minutes of 120
        $this->assertSame('ended', $meeting->fresh()->status);
        $this->assertNotNull($program);
    }

    public function test_a_group_team_follows_registrations_and_keeps_the_staff(): void
    {
        $this->teams(['create_teams' => true], 'fake');
        $admin = $this->makeUser(Role::TRAINING_HEAD);
        $program = $this->makeProgram();
        $group = TrainingGroup::where('program_id', $program->id)->first() ?? TrainingGroup::create(['program_id' => $program->id, 'code' => 'G1', 'title_ar' => 'م', 'title_en' => 'G', 'sequence' => 1, 'delivery_mode' => 'online', 'start_date' => now()->addDay(), 'end_date' => now()->addDays(5), 'capacity' => 20, 'status' => 'planned']);
        $mk = fn (string $email) => Registration::create(['program_id' => $program->id, 'training_group_id' => $group->id, 'employee_id' => $this->makeEmployee([], $this->makeUser(Role::EMPLOYEE, ['email' => $email]))->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $a = $mk('a@moe.gov.qa');
        $b = $mk('b@moe.gov.qa');

        $r = $this->asUser($admin)->postJson("/api/v1/admin/groups/{$group->id}/teams")->assertOk();
        $this->assertSame(['added' => 2, 'removed' => 0], $r->json('data.members'));
        $team = TeamsTeam::where('group_id', $group->id)->first();
        $graph = new FakeGraph;
        $this->assertEqualsCanonicalizing(['tedc-bot@moe.gov.qa', 'a@moe.gov.qa', 'b@moe.gov.qa'], array_values($graph->members($team->team_id)));

        $b->update(['status' => Registration::STATUS_CANCELLED]);
        $c = $mk('c@moe.gov.qa');
        $r = $this->asUser($admin)->postJson("/api/v1/admin/groups/{$group->id}/teams")->assertOk();
        $this->assertSame(['added' => 1, 'removed' => 1], $r->json('data.members'));
        $this->assertEqualsCanonicalizing(['tedc-bot@moe.gov.qa', 'a@moe.gov.qa', 'c@moe.gov.qa'], array_values($graph->members($team->team_id)));     // the service owner stays
        $this->assertSame(1, TeamsTeam::count());                                                                                                      // one team per group
        $this->asUser($admin)->getJson("/api/v1/admin/groups/{$group->id}/teams")->assertOk()->assertJsonPath('data.team.team_id', $team->team_id);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson("/api/v1/admin/groups/{$group->id}/teams")->assertForbidden();
        $this->assertNotNull($a);
        $this->assertNotNull($c);
    }

    public function test_forms_quizzes_are_linked_and_their_exported_results_become_attempts(): void
    {
        $admin = $this->makeUser(Role::TRAINING_HEAD);
        $program = $this->makeProgram();
        $assessment = Assessment::create(['program_id' => $program->id, 'kind' => 'quiz', 'title_ar' => 'اختبار', 'title_en' => 'Quiz', 'delivery' => 'online', 'pass_percent' => 60, 'status' => 'draft']);
        $reg = fn (string $email) => Registration::create(['program_id' => $program->id, 'employee_id' => $this->makeEmployee([], $this->makeUser(Role::EMPLOYEE, ['email' => $email]))->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $a = $reg('a@moe.gov.qa');
        $reg('b@moe.gov.qa');

        $this->asUser($admin)->putJson("/api/v1/admin/assessments/{$assessment->id}/forms", ['forms_url' => 'https://evil.example/forms'])->assertStatus(422);
        $this->asUser($admin)->putJson("/api/v1/admin/assessments/{$assessment->id}/forms", ['forms_url' => 'https://forms.office.com/r/abc123'])->assertOk()->assertJsonPath('data.forms_url', 'https://forms.office.com/r/abc123');

        $csv = "\xEF\xBB\xBFEmail,Total points,Out of\na@moe.gov.qa,8,10\nb@moe.gov.qa,\"5,5\",10\nstranger@x.com,9,10\nbad@moe.gov.qa,n/a,10\n";
        $r = $this->asUser($admin)->post("/api/v1/admin/assessments/{$assessment->id}/forms/import", ['file' => UploadedFile::fake()->createWithContent('results.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(['imported' => 2, 'unmatched' => 2], $r->json('data'));
        $attempt = AssessmentAttempt::where('registration_id', $a->id)->first();
        $this->assertEquals(80.0, (float) $attempt->score_percent);
        $this->assertTrue((bool) $attempt->passed);
        $this->assertSame('forms', $attempt->delivery);
        $this->assertFalse((bool) AssessmentAttempt::where('score_percent', 55)->value('passed'));      // 5.5 of 10 is under 60
        // Importing again makes the next attempt, not a duplicate of the first.
        $this->asUser($admin)->post("/api/v1/admin/assessments/{$assessment->id}/forms/import", ['file' => UploadedFile::fake()->createWithContent('results.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(2, (int) AssessmentAttempt::where('registration_id', $a->id)->max('attempt_no'));
    }
}
