<?php

namespace Tests\Feature;

use App\Integrations\IntegrationManager;
use App\Integrations\Teams\TeamsService;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\IntegrationLog;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TeamsMeeting;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TEC-12: Teams town halls (Microsoft Graph solutions/virtualEvents/townhalls, v1.0). Creating, publishing and cancelling
 * a town hall needs the organiser's delegated permission (VirtualEvent.ReadWrite; Graph has no application permission
 * for it), so TEDC acts through the organiser account an administrator connected once; attendance comes from the
 * session's attendance reports and follows the same duration rules as meetings.
 */
class TeamsTownhallTest extends TestCase
{
    private const BASE = 'graph.microsoft.com/v1.0/solutions/virtualEvents/townhalls';

    /** The organiser's delegated access token the fake token endpoint hands out. */
    private string $delegated = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['tedc.integrations.backoff_ms' => 0]);
        Cache::flush();
    }

    private function teams(array $settings = []): void
    {
        app(IntegrationManager::class)->update('teams', ['driver' => 'graph', 'enabled' => true, 'settings' => $settings + [
            'tenant_id' => 'tenant-1', 'client_id' => 'cid', 'client_secret' => 'sec', 'organizer_upn' => 'tedc-events@moe.gov.qa',
            'townhalls' => true, 'organizer_refresh_token' => 'refresh-1', 'organizer_account' => 'tedc-events@moe.gov.qa',
        ]]);
    }

    /** An access token as Entra issues it (the claims TEDC reads; the signature is not TEDC's to check). */
    private function jwt(array $claims): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        return $b64('{"alg":"none","typ":"JWT"}').'.'.$b64(json_encode($claims)).'.sig';
    }

    /** Graph and the token endpoint: app tokens for client credentials, the organiser's token for the refresh grant. */
    private function graph(array $extra = []): void
    {
        $this->delegated = $this->jwt(['upn' => 'tedc-events@moe.gov.qa', 'scp' => 'VirtualEvent.ReadWrite OnlineMeetingArtifact.Read.All User.Read']);
        Http::fake($extra + [
            'login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => fn (Request $r) => Http::response($r['grant_type'] === 'refresh_token'
                ? ['access_token' => $this->delegated, 'refresh_token' => 'refresh-2', 'expires_in' => 3600]
                : ['access_token' => $this->jwt(['roles' => ['User.Read.All']]), 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/users/*' => Http::response(['id' => 'oid-looked-up']),
            self::BASE.'/*/publish' => Http::response('', 204),
            self::BASE.'/*/cancel' => Http::response('', 204),
            self::BASE.'/*/sessions' => Http::response(['value' => [['id' => 'S-1', 'joinWebUrl' => 'https://teams.microsoft.com/l/meetup-join/townhall-1']]]),
            self::BASE.'/*' => Http::response(['id' => 'TH-1@tenant-1', 'status' => 'draft'], 200),
            self::BASE => Http::response(['id' => 'TH-1@tenant-1', 'status' => 'draft'], 201),
        ]);
    }

    private function trainee($program, string $email, ?string $oid = null): Registration
    {
        $user = $this->makeUser(Role::EMPLOYEE, ['email' => $email]);
        if ($oid) {
            UserIdentity::create(['user_id' => $user->id, 'provider' => 'entra', 'subject' => $oid, 'upn' => $email]);
        }

        return Registration::create(['program_id' => $program->id, 'employee_id' => $this->makeEmployee([], $user)->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
    }

    private function townhallSession($program, array $attrs = [])
    {
        return $program->sessions()->create($attrs + ['title_ar' => 'لقاء مفتوح', 'title_en' => 'Town hall', 'starts_at' => now()->addDays(3)->setTime(9, 0), 'ends_at' => now()->addDays(3)->setTime(11, 0),
            'mode' => 'online', 'online_platform' => 'teams_townhall']);
    }

    private function isTownhallCreate(Request $r): bool
    {
        return $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/solutions/virtualEvents/townhalls');
    }

    public function test_a_town_hall_session_is_created_published_and_kept_in_step_with_the_schedule(): void
    {
        $this->teams();
        $this->graph();
        $program = $this->makeProgram();
        $this->trainee($program, 'amal@moe.gov.qa', 'oid-amal');
        $this->trainee($program, 'badr@moe.gov.qa');                       // no Microsoft identity yet: looked up by e-mail
        $session = $this->townhallSession($program);

        $m = TeamsMeeting::where('session_id', $session->id)->firstOrFail();
        $this->assertSame('townhall', $m->kind);
        $this->assertSame('TH-1@tenant-1', $m->meeting_id);
        $this->assertSame('S-1', $m->graph_session_id);
        $this->assertSame(2, $m->invited_count);
        $session->refresh();
        $this->assertSame('https://teams.microsoft.com/l/meetup-join/townhall-1', $session->online_url);
        $this->assertSame('teams_townhall', $session->online_platform);

        Http::assertSent(function (Request $r) use ($session) {
            if (! $this->isTownhallCreate($r)) {
                return false;
            }
            $ids = array_column($r['invitedAttendees'], 'id');
            sort($ids);

            return $r->hasHeader('Authorization', 'Bearer '.$this->delegated)       // the organiser's own (delegated) token
                && $r['displayName'] === 'لقاء مفتوح' && $r['audience'] === 'organization'
                && $r['startDateTime'] === ['dateTime' => $session->starts_at->copy()->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC']
                && $ids === ['oid-amal', 'oid-looked-up'] && $r['invitedAttendees'][0]['@odata.type'] === '#microsoft.graph.communicationsUserIdentity' && $r['invitedAttendees'][0]['tenantId'] === 'tenant-1';
        });
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/publish'));
        // The organiser's refresh token is rotated as Entra hands out new ones.
        $this->assertSame('refresh-2', app(IntegrationManager::class)->settings('teams')['organizer_refresh_token']);

        // A new time updates the same town hall; a cancelled session cancels it.
        $session->update(['starts_at' => $session->starts_at->copy()->addHour(), 'ends_at' => $session->ends_at->copy()->addHour()]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/townhalls/TH-1%40tenant-1')
            && $r['startDateTime']['dateTime'] === $session->fresh()->starts_at->copy()->utc()->format('Y-m-d\TH:i:s'));
        $this->assertSame(1, TeamsMeeting::count());
        $session->update(['status' => 'cancelled']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/townhalls/TH-1%40tenant-1/cancel'));
        $this->assertSame('cancelled', $m->fresh()->status);
    }

    public function test_a_session_that_leaves_teams_takes_its_event_down_and_one_that_joins_gets_one(): void
    {
        $this->teams();
        $this->graph(['graph.microsoft.com/v1.0/users/*/onlineMeetings' => Http::response(['id' => 'MEET-9', 'joinWebUrl' => 'https://teams.microsoft.com/l/meetup-join/m9'])]);
        $program = $this->makeProgram();
        $session = $this->townhallSession($program);
        $m = TeamsMeeting::where('session_id', $session->id)->firstOrFail();
        $this->assertSame('scheduled', $m->status);

        $session->update(['online_platform' => 'zoom', 'online_url' => 'https://zoom.example/j/1']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/townhalls/TH-1%40tenant-1/cancel'));
        $this->assertSame('cancelled', $m->fresh()->status);
        $this->assertSame('https://zoom.example/j/1', $session->fresh()->online_url, 'its own link stays');

        // Back to Teams, as a meeting this time: the town hall is replaced by a meeting on the same row.
        $session->update(['online_platform' => 'teams']);
        $again = TeamsMeeting::where('session_id', $session->id)->firstOrFail();
        $this->assertSame(['meeting', 'MEET-9', 'scheduled'], [$again->kind, $again->meeting_id, $again->status]);
        $this->assertSame(1, TeamsMeeting::where('session_id', $session->id)->count());
    }

    public function test_attendees_follow_the_registrations_before_the_event(): void
    {
        $this->teams();
        $this->graph();
        $program = $this->makeProgram();
        $this->trainee($program, 'amal@moe.gov.qa', 'oid-amal');
        $session = $this->townhallSession($program);
        $teams = app(TeamsService::class);

        $this->assertFalse($teams->syncAttendees($session), 'nothing changed, nothing is sent');
        $this->trainee($program, 'carim@moe.gov.qa', 'oid-carim');
        $this->assertTrue($teams->syncAttendees($session));
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && count($r['invitedAttendees'] ?? []) === 2);
        $this->assertSame(2, TeamsMeeting::where('session_id', $session->id)->value('invited_count'));
    }

    public function test_town_hall_attendance_follows_the_same_duration_rules_as_meetings(): void
    {
        $this->teams(['min_presence_percent' => 50]);
        $program = $this->makeProgram();
        $full = $this->trainee($program, 'full@moe.gov.qa', 'oid-full');
        $late = $this->trainee($program, 'late@moe.gov.qa', 'oid-late');
        $brief = $this->trainee($program, 'brief@moe.gov.qa', 'oid-brief');
        $manual = $this->trainee($program, 'manual@moe.gov.qa', 'oid-manual');
        $starts = now()->subHours(3)->startOfMinute();
        $iv = fn ($from, $to) => ['joinDateTime' => $starts->copy()->addMinutes($from)->utc()->format('Y-m-d\TH:i:s\Z'), 'leaveDateTime' => $starts->copy()->addMinutes($to)->utc()->format('Y-m-d\TH:i:s\Z'), 'durationInSeconds' => ($to - $from) * 60];
        $rec = fn (string $email, array $intervals, string $role = 'Attendee') => ['emailAddress' => $email, 'identity' => ['displayName' => $email], 'role' => $role, 'totalAttendanceInSeconds' => array_sum(array_column($intervals, 'durationInSeconds')), 'attendanceIntervals' => $intervals];
        $this->graph([
            self::BASE.'/*/sessions/S-1/attendanceReports' => Http::response(['value' => [['id' => 'R1']]]),
            self::BASE.'/*/sessions/S-1/attendanceReports/R1/attendanceRecords' => Http::response(['value' => [
                $rec('full@moe.gov.qa', [$iv(-15, 125)]), $rec('late@moe.gov.qa', [$iv(25, 120)]), $rec('brief@moe.gov.qa', [$iv(0, 20)]),
                $rec('manual@moe.gov.qa', [$iv(0, 120)]), $rec('tedc-events@moe.gov.qa', [$iv(0, 120)], 'Organizer'),
            ]]),
        ]);
        $session = $this->townhallSession($program, ['starts_at' => $starts, 'ends_at' => $starts->copy()->addMinutes(120)]);   // 120 minutes, over
        Attendance::create(['program_session_id' => $session->id, 'registration_id' => $manual->id, 'employee_id' => $manual->employee_id, 'status' => 'excused', 'method' => 'manual']);

        $this->assertSame(['matched' => 4, 'unmatched' => 1, 'applied' => 4], app(TeamsService::class)->syncAttendance($session));
        $row = fn (Registration $r) => Attendance::where('program_session_id', $session->id)->where('registration_id', $r->id)->first();
        $this->assertSame(['present', 120, 'teams'], [$row($full)->status, $row($full)->minutes_attended, $row($full)->method]);
        $this->assertSame(['late', 95], [$row($late)->status, $row($late)->minutes_attended]);
        $this->assertSame('absent', $row($brief)->status);
        $this->assertSame(['excused', 'manual'], [$row($manual)->status, $row($manual)->method], 'a trainer\'s mark is never overwritten');
        $this->assertSame('ended', TeamsMeeting::where('session_id', $session->id)->value('status'));
    }

    public function test_graph_errors_are_retried_and_logged_and_never_block_the_session(): void
    {
        $this->teams();
        $this->graph([self::BASE => Http::sequence()
            ->push(['error' => ['message' => 'Service unavailable']], 503)->push(['id' => 'TH-2@tenant-1', 'status' => 'draft'], 201)
            ->push(['error' => ['message' => 'Forbidden']], 403)->push(['error' => ['message' => 'Forbidden']], 403)]);
        $program = $this->makeProgram();
        $session = $this->townhallSession($program);

        $this->assertSame('TH-2@tenant-1', TeamsMeeting::where('session_id', $session->id)->value('meeting_id'), 'the second try worked');
        $this->assertSame(['error', 'ok'], IntegrationLog::where('integration_key', 'teams')->where('operation', 'create_townhall')->orderBy('created_at')->pluck('status')->all());

        $other = $this->townhallSession($program, ['title_ar' => 'لقاء آخر']);
        $this->assertNotNull($other->fresh(), 'the session is saved anyway');
        $this->assertSame(0, TeamsMeeting::where('session_id', $other->id)->count());
        $this->assertTrue(IntegrationLog::where('integration_key', 'teams')->where('operation', 'create_townhall')->where('status', 'error')->where('error', 'like', '%Forbidden%')->exists());
    }

    public function test_without_a_connected_organiser_no_town_hall_is_made_and_the_session_page_says_why(): void
    {
        $this->teams(['organizer_refresh_token' => null]);
        $this->graph();
        $program = $this->makeProgram();
        $session = $this->townhallSession($program);

        $this->assertSame(0, TeamsMeeting::count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'virtualEvents'));
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->getJson('/api/v1/admin/sessions/'.$session->id.'/teams')->assertOk()
            ->assertJsonPath('data.wants', false)->assertJsonPath('data.kind', 'townhall')->assertJsonPath('data.blocked', 'organizer_not_connected');
    }

    public function test_an_administrator_connects_the_organiser_account_once(): void
    {
        $this->teams(['organizer_refresh_token' => null, 'organizer_account' => null]);
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $url = $this->asUser($admin)->postJson('/api/v1/admin/teams/organizer')->assertOk()->json('data.url');
        $this->assertStringStartsWith('https://login.microsoftonline.com/tenant-1/oauth2/v2.0/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('cid', $q['client_id']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame('tedc-events@moe.gov.qa', $q['login_hint']);
        foreach (['VirtualEvent.ReadWrite', 'OnlineMeetingArtifact.Read.All', 'offline_access'] as $scope) {
            $this->assertStringContainsString($scope, $q['scope']);
        }
        $this->assertSame(url('/api/v1/integrations/teams/organizer/callback'), $q['redirect_uri']);

        Http::fake(['login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => fn (Request $r) => Http::response($r['grant_type'] === 'authorization_code' && $r['code'] === 'code-1' && $r['code_verifier'] !== ''
            ? ['access_token' => $this->jwt(['upn' => 'tedc-events@moe.gov.qa', 'scp' => 'VirtualEvent.ReadWrite OnlineMeetingArtifact.Read.All User.Read']), 'refresh_token' => 'refresh-new', 'expires_in' => 3600]
            : ['error' => 'invalid_grant'], $r['grant_type'] === 'authorization_code' ? 200 : 400)]);
        $this->withHeader('Authorization', '');
        $back = (string) $this->get('/api/v1/integrations/teams/organizer/callback?code=code-1&state='.$q['state'])->assertRedirect()->headers->get('Location');
        $this->assertStringEndsWith('/admin/integrations?teams_organizer=connected', $back);
        $s = app(IntegrationManager::class)->settings('teams');
        $this->assertSame('refresh-new', $s['organizer_refresh_token']);
        $this->assertSame('tedc-events@moe.gov.qa', $s['organizer_account']);
        $this->assertSame(1, AuditLog::where('action', 'teams_organizer_connected')->where('user_id', $admin->id)->count());
        $entry = AuditLog::where('action', 'teams_organizer_connected')->firstOrFail();
        $this->assertSame(['teams', 'tedc-events@moe.gov.qa'], [$entry->new_values['integration'], $entry->new_values['account']]);
        $this->assertArrayNotHasKey('organizer_refresh_token', app(IntegrationManager::class)->present('teams')['settings'], 'the token never reaches the browser');

        // The state works once.
        $this->assertStringEndsWith('teams_organizer=failed', (string) $this->get('/api/v1/integrations/teams/organizer/callback?code=code-1&state='.$q['state'])->headers->get('Location'));
        $this->assertSame(0, User::where('email', 'tedc-events@moe.gov.qa')->count(), 'connecting signs nobody in');
    }
}
