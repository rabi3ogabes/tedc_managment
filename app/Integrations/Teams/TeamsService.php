<?php

namespace App\Integrations\Teams;

use App\Integrations\IntegrationManager;
use App\Integrations\IntegrationUnavailable;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Attendance;
use App\Models\Material;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TeamsAttendanceRecord;
use App\Models\TeamsMeeting;
use App\Models\TeamsTeam;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\AttendanceService;
use App\Services\FileStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Microsoft Teams for training: a meeting for every online session, or a town hall for large audiences (both updated or
 * cancelled when the schedule changes; a town hall's invitations follow the registrations), attendance computed from how
 * long each person was in the call, a Team per training group with membership kept in line with registrations, files in
 * its channel, and Office 365 Forms quizzes as assessment sources.
 */
class TeamsService
{
    private const ONLINE_MODES = ['online', 'hybrid', 'blended'];

    public function __construct(private readonly IntegrationManager $hub, private readonly AttendanceService $attendance, private readonly FileStorage $storage) {}

    public function ready(): bool
    {
        return $this->hub->isReady('teams');
    }

    private function api(array $s): GraphApi
    {
        // The organiser's refresh token is replaced whenever Entra hands out a new one.
        return $this->hub->get('teams')->driver === 'fake' ? new FakeGraph($s)
            : new HttpGraph($s, fn (string $token) => $this->hub->update('teams', ['settings' => ['organizer_refresh_token' => $token]]));
    }

    /** Graph for the activity feed, the permission checklist and the like (the settings as they are now). */
    public function graph(): GraphApi
    {
        return $this->api($this->hub->settings('teams'));
    }

    /** A Teams town hall for sessions set to "Teams town hall", a meeting otherwise. */
    public function kind(ProgramSession $session): string
    {
        return $session->online_platform === 'teams_townhall' ? 'townhall' : 'meeting';
    }

    /** What stands in the way of the session's Teams event, or null: not_configured, townhalls_off, organizer_not_connected. */
    public function blocked(ProgramSession $session): ?string
    {
        if (! $this->ready()) {
            return 'not_configured';
        }
        if ($this->kind($session) === 'townhall') {
            $s = $this->hub->settings('teams');
            if (empty($s['townhalls'])) {
                return 'townhalls_off';
            }
            if ($this->hub->get('teams')->driver !== 'fake' && blank($s['organizer_refresh_token'] ?? null)) {
                return 'organizer_not_connected';
            }
        }

        return null;
    }

    /** The session is still the kind that belongs on Teams (online, with Teams or no platform chosen); false once it moves to another platform or in person. */
    public function staysOnTeams(ProgramSession $session): bool
    {
        return in_array($session->mode, self::ONLINE_MODES, true) && in_array($session->online_platform, [null, 'teams', 'teams_townhall'], true);
    }

    /** True when the session should have a Teams meeting or town hall. */
    public function wants(ProgramSession $session): bool
    {
        if (! in_array($session->mode, self::ONLINE_MODES, true) || $session->status === 'cancelled' || $this->blocked($session) !== null) {
            return false;
        }
        if ($this->kind($session) === 'townhall') {
            return true;
        }
        $s = $this->hub->settings('teams');

        return ($s['auto_meetings'] ?? true) && ($session->online_platform === null || $session->online_platform === 'teams');
    }

    // ---- meetings ------------------------------------------------------------------------------------

    /** Creates the meeting or town hall if there is none, otherwise brings it in line with the session. */
    public function ensureMeeting(ProgramSession $session): ?TeamsMeeting
    {
        if (! $this->wants($session)) {
            return null;
        }
        $existing = TeamsMeeting::where('session_id', $session->id)->first();
        // The session moved between a meeting and a town hall: the old event is cancelled first.
        if ($existing && $existing->status !== 'cancelled' && $existing->kind !== $this->kind($session)) {
            $this->cancelEvent($session, $existing);
            $existing->refresh();
        }
        if ($this->kind($session) === 'townhall') {
            return $this->ensureTownhall($session, $existing);
        }
        $subject = $session->title_ar ?: $session->title_en ?: 'TEDC';

        return $this->hub->call('teams', $existing ? 'update_meeting' : 'create_meeting', function (array $s) use ($session, $existing, $subject) {
            $organizer = $this->organizer($session, $s);
            $api = $this->api($s);
            if ($existing && $existing->status !== 'cancelled') {
                $api->updateMeeting($existing->organizer_upn ?: $organizer, $existing->meeting_id, $subject, $session->starts_at, $session->ends_at);

                return $existing;
            }
            $m = $api->createMeeting($organizer, $subject, $session->starts_at, $session->ends_at, (string) ($s['lobby'] ?? 'organization'));
            $meeting = TeamsMeeting::updateOrCreate(['session_id' => $session->id], ['kind' => 'meeting', 'meeting_id' => $m['id'], 'graph_session_id' => null, 'join_url' => $m['join_url'], 'organizer_upn' => $organizer, 'status' => 'scheduled',
                'lobby' => $s['lobby'] ?? 'organization', 'attendees_hash' => null, 'invited_count' => null, 'attendance_attempts' => 0, 'attendance_synced_at' => null, 'last_error' => null]);
            ProgramSession::whereKey($session->id)->update(['online_url' => $m['join_url'], 'online_platform' => 'teams']);

            return $meeting;
        }, ['session' => $session->id]);
    }

    public function cancelMeeting(ProgramSession $session): void
    {
        $m = TeamsMeeting::where('session_id', $session->id)->where('status', '!=', 'cancelled')->first();
        if (! $m || ! $this->ready()) {
            return;
        }
        $this->cancelEvent($session, $m);
    }

    /** Cancels the meeting or town hall in Teams; it is marked cancelled here whatever Teams answers (the error is kept). */
    private function cancelEvent(ProgramSession $session, TeamsMeeting $m): void
    {
        try {
            $m->kind === 'townhall'
                ? $this->hub->call('teams', 'cancel_townhall', fn (array $s) => $this->api($s)->cancelTownhall($m->meeting_id), ['session' => $session->id])
                : $this->hub->call('teams', 'cancel_meeting', fn (array $s) => $this->api($s)->deleteMeeting($m->organizer_upn ?: $this->organizer($session, $s), $m->meeting_id), ['session' => $session->id]);
        } catch (Throwable $e) {
            $m->update(['last_error' => mb_substr($e->getMessage(), 0, 300)]);
        }
        $m->update(['status' => 'cancelled']);
    }

    // ---- town halls -----------------------------------------------------------------------------------

    /**
     * A town hall in three steps — draft, publish, read its session for the join link — so a step that fails is picked up
     * where it stopped (a draft is published later, never created twice).
     */
    private function ensureTownhall(ProgramSession $session, ?TeamsMeeting $existing): TeamsMeeting
    {
        $subject = $session->title_ar ?: $session->title_en ?: 'TEDC';
        $attendees = $this->attendeeIds($session);
        $hash = hash('sha256', implode(',', $attendees));
        $mine = $existing && $existing->kind === 'townhall' && $existing->status !== 'cancelled' ? $existing : null;

        if ($mine && $mine->status !== 'draft') {
            $this->hub->call('teams', 'update_townhall', fn (array $s) => $this->api($s)->updateTownhall($mine->meeting_id, $subject, $session->starts_at, $session->ends_at, $mine->attendees_hash === $hash ? null : $attendees), ['session' => $session->id]);
            $mine->update(['attendees_hash' => $hash, 'invited_count' => count($attendees), 'last_error' => null]);

            return $mine;
        }
        if (! $mine) {
            $description = trim(strip_tags((string) ($session->description ?: $session->program?->title_ar ?: '')));
            $id = $this->hub->call('teams', 'create_townhall', fn (array $s) => $this->api($s)->createTownhall($subject, $description, $session->starts_at, $session->ends_at, $attendees, (bool) ($s['townhall_invite_only'] ?? false)), ['session' => $session->id]);
            $s = $this->hub->settings('teams');
            $mine = TeamsMeeting::updateOrCreate(['session_id' => $session->id], ['kind' => 'townhall', 'meeting_id' => $id, 'graph_session_id' => null, 'join_url' => '', 'organizer_upn' => ($s['organizer_account'] ?? null) ?: ($s['organizer_upn'] ?? null),
                'status' => 'draft', 'lobby' => null, 'attendees_hash' => $hash, 'invited_count' => count($attendees), 'attendance_attempts' => 0, 'attendance_synced_at' => null, 'last_error' => null]);
        }
        $this->hub->call('teams', 'publish_townhall', fn (array $s) => $this->api($s)->publishTownhall($mine->meeting_id), ['session' => $session->id]);
        $first = $this->hub->call('teams', 'townhall_sessions', fn (array $s) => $this->api($s)->townhallSessions($mine->meeting_id), ['session' => $session->id])[0] ?? null;
        $mine->update(['status' => 'scheduled', 'graph_session_id' => $first['id'] ?? null, 'join_url' => (string) ($first['join_url'] ?? '')]);
        ProgramSession::whereKey($session->id)->update(['online_url' => $first['join_url'] ?? null, 'online_platform' => 'teams_townhall']);

        return $mine;
    }

    /** Brings a coming town hall's invitations in line with the registrations; true when Teams was told. */
    public function syncAttendees(ProgramSession $session): bool
    {
        $m = TeamsMeeting::where('session_id', $session->id)->where('kind', 'townhall')->where('status', 'scheduled')->first();
        if (! $m || $this->blocked($session) !== null) {
            return false;
        }
        $ids = $this->attendeeIds($session);
        $hash = hash('sha256', implode(',', $ids));
        if ($hash === $m->attendees_hash) {
            return false;
        }
        $subject = $session->title_ar ?: $session->title_en ?: 'TEDC';
        $this->hub->call('teams', 'update_townhall', fn (array $s) => $this->api($s)->updateTownhall($m->meeting_id, $subject, $session->starts_at, $session->ends_at, $ids), ['session' => $session->id]);
        $m->update(['attendees_hash' => $hash, 'invited_count' => count($ids), 'last_error' => null]);

        return true;
    }

    /** @return list<string> Entra object ids of the trainees registered for the session (sorted, each once) */
    public function attendeeIds(ProgramSession $session): array
    {
        $ids = [];
        foreach ($this->registrationsFor($session)->map(fn ($r) => $r->employee?->user)->filter() as $user) {
            if ($oid = $this->objectId($user)) {
                $ids[$oid] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * A person's Entra object id: from their Microsoft sign-in (Entra ID, or SAML with the object id claim), else — when
     * $lookup — found in the tenant by e-mail (remembered for a day, misses too).
     */
    public function objectId(User $user, bool $lookup = true): ?string
    {
        foreach (UserIdentity::where('user_id', $user->id)->whereIn('provider', ['entra', 'saml'])->orderByDesc('last_login_at')->get(['provider', 'subject']) as $i) {
            if ($i->provider === 'entra' || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $i->subject)) {
                return $i->subject;
            }
        }
        if (! $lookup || ! filter_var($user->email, FILTER_VALIDATE_EMAIL) || ! $this->ready()) {
            return null;
        }
        $found = Cache::remember('teams.oid.'.md5(strtolower($user->email)), now()->addDay(), function () use ($user) {
            try {
                return (string) $this->api($this->hub->settings('teams'))->userId($user->email);
            } catch (Throwable) {
                return '';
            }
        });

        return $found !== '' ? $found : null;
    }

    /** The registrations a session's attendance (and its town hall's invitations) is about. */
    private function registrationsFor(ProgramSession $session)
    {
        return Registration::with('employee.user')->where('program_id', $session->program_id)
            ->when($session->training_group_id, fn ($q) => $q->where(fn ($w) => $w->where('training_group_id', $session->training_group_id)->orWhereNull('training_group_id')))
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get();
    }

    /** The organiser is a service account (settings), never a trainee. @param  array<string, mixed>  $s */
    private function organizer(ProgramSession $session, array $s): string
    {
        $upn = (string) ($s['organizer_upn'] ?? '');
        if ($upn === '') {
            throw new IntegrationUnavailable('No Teams organiser account is configured.', 'not_configured');
        }

        return $upn;
    }

    // ---- attendance by duration ----------------------------------------------------------------------

    /**
     * Pulls the attendance report after the session and turns time in the call into attendance.
     *
     * @return array{matched: int, unmatched: int, applied: int}
     */
    public function syncAttendance(ProgramSession $session): array
    {
        $meeting = TeamsMeeting::where('session_id', $session->id)->where('status', '!=', 'cancelled')->first();
        if (! $meeting) {
            return ['matched' => 0, 'unmatched' => 0, 'applied' => 0];
        }
        $meeting->increment('attendance_attempts');
        $people = $meeting->kind === 'townhall'
            ? $this->hub->call('teams', 'attendance_report', fn (array $s) => $this->api($s)->townhallAttendance($meeting->meeting_id, $meeting->graph_session_id), ['session' => $session->id])
            : $this->hub->call('teams', 'attendance_report', fn (array $s) => $this->api($s)->attendance($meeting->organizer_upn ?: $this->organizer($session, $s), $meeting->meeting_id), ['session' => $session->id]);
        $s = $this->hub->settings('teams');
        $minPercent = (float) ($s['min_presence_percent'] ?? 50);
        $duration = max(1, $session->durationMinutes());

        $registrations = $this->registrationsFor($session);
        $byEmail = $registrations->keyBy(fn ($r) => strtolower((string) $r->employee?->user?->email));

        TeamsAttendanceRecord::where('session_id', $session->id)->delete();
        $out = ['matched' => 0, 'unmatched' => 0, 'applied' => 0];
        foreach ($people as $p) {
            $minutes = $this->minutesInside($session, $p['intervals'] ?? [], (int) ($p['total_seconds'] ?? 0));
            $percent = round(min(100, $minutes / $duration * 100), 1);
            $reg = $p['email'] ? $byEmail->get(strtolower($p['email'])) : null;
            $rec = TeamsAttendanceRecord::create(['session_id' => $session->id, 'email' => $p['email'], 'display_name' => $p['name'], 'role' => $p['role'], 'total_seconds' => (int) $p['total_seconds'], 'intervals' => $p['intervals'] ?? [],
                'employee_id' => $reg?->employee_id, 'registration_id' => $reg?->id, 'minutes' => $minutes, 'percent' => $percent]);
            if (! $reg) {
                $out['unmatched']++;   // an organiser, a guest, or someone who joined with another account

                continue;
            }
            $out['matched']++;
            $this->apply($session, $reg, $p['intervals'] ?? [], $minutes, $percent >= $minPercent);
            $rec->update(['applied' => true]);
            $out['applied']++;
        }
        // Registered people who never appear in the report were absent from the call (unless attendance was recorded another way).
        $seen = $registrations->filter(fn ($r) => TeamsAttendanceRecord::where('session_id', $session->id)->where('registration_id', $r->id)->exists())->pluck('id');
        foreach ($registrations->whereNotIn('id', $seen) as $reg) {
            if (! Attendance::where('program_session_id', $session->id)->where('registration_id', $reg->id)->exists()) {
                Attendance::create(['program_session_id' => $session->id, 'registration_id' => $reg->id, 'employee_id' => $reg->employee_id, 'training_group_id' => $session->training_group_id, 'method' => 'teams', 'status' => 'absent', 'minutes_attended' => 0, 'location_status' => 'remote']);
                $this->attendance->recalculate($reg);
            }
        }
        $meeting->update(['attendance_synced_at' => now(), 'last_error' => null, 'status' => 'ended']);

        return $out;
    }

    /** Minutes spent in the call inside the scheduled time (joining early or staying late does not add to it). @param  list<array{join: string, leave: string, seconds: int}>  $intervals */
    private function minutesInside(ProgramSession $session, array $intervals, int $totalSeconds): int
    {
        if ($intervals === []) {
            return (int) min($session->durationMinutes(), round($totalSeconds / 60));
        }
        $seconds = 0;
        foreach ($intervals as $iv) {
            try {
                $from = Carbon::parse($iv['join'])->max($session->starts_at);
                $to = Carbon::parse($iv['leave'])->min($session->ends_at);
            } catch (Throwable) {
                continue;
            }
            $seconds += max(0, $from->diffInSeconds($to, false));
        }

        return (int) min($session->durationMinutes(), round($seconds / 60));
    }

    private function apply(ProgramSession $session, Registration $reg, array $intervals, int $minutes, bool $enough): void
    {
        $first = null;
        $last = null;
        foreach ($intervals as $iv) {
            try {
                $j = Carbon::parse($iv['join']);
                $l = Carbon::parse($iv['leave']);
            } catch (Throwable) {
                continue;
            }
            $first = $first ? $first->min($j) : $j;
            $last = $last ? $last->max($l) : $l;
        }
        $late = $first && $first->gt($session->starts_at->copy()->addMinutes((int) config('tedc.attendance.late_after_minutes', 15)));
        $existing = Attendance::where('program_session_id', $session->id)->where('registration_id', $reg->id)->first();
        // A trainer's manual mark (an excuse, a device problem) is not overwritten by the report.
        if ($existing && $existing->method === 'manual') {
            return;
        }
        Attendance::updateOrCreate(['program_session_id' => $session->id, 'registration_id' => $reg->id], [
            'employee_id' => $reg->employee_id, 'training_group_id' => $session->training_group_id, 'method' => 'teams', 'location_status' => 'remote',
            'status' => $enough ? ($late ? 'late' : 'present') : 'absent', 'minutes_attended' => $minutes, 'check_in_at' => $first, 'check_out_at' => $last, 'join_count' => max(1, count($intervals)),
        ]);
        $this->attendance->recalculate($reg);
    }

    // ---- teams, members and files ---------------------------------------------------------------------

    public function ensureTeam(TrainingGroup $group): ?TeamsTeam
    {
        $s = $this->hub->settings('teams');
        if (! $this->ready() || ! ($s['create_teams'] ?? false)) {
            return null;
        }
        $team = TeamsTeam::where('group_id', $group->id)->first();
        if (! $team) {
            $t = $this->hub->call('teams', 'create_team', fn (array $set) => $this->api($set)->createTeam($group->displayTitle('ar'), (string) $group->program?->title_ar, $this->organizer(new ProgramSession, $set)), ['group' => $group->id]);
            $team = TeamsTeam::create(['group_id' => $group->id, 'team_id' => $t['id'], 'channel_id' => $t['channel_id'], 'drive_id' => $t['drive_id'], 'folder_item_id' => $t['folder_item_id'], 'web_url' => $t['web_url']]);
        }

        return $team;
    }

    /** Makes the team's members match: registered trainees, the group's trainers and its supervisor. @return array{added: int, removed: int} */
    public function syncMembers(TrainingGroup $group): array
    {
        $team = $this->ensureTeam($group);
        if (! $team) {
            return ['added' => 0, 'removed' => 0];
        }
        $group->loadMissing('program');
        $want = Registration::with('employee.user')->where('training_group_id', $group->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_PENDING, Registration::STATUS_COMPLETED])->get()
            ->map(fn ($r) => strtolower((string) $r->employee?->user?->email))->filter()->flip();
        $staff = $group->trainers()->with('trainer')->get()->map(fn ($g) => strtolower((string) ($g->trainer?->email)))->filter();
        if ($group->supervisor?->email) {
            $staff->push(strtolower($group->supervisor->email));
        }
        $staff = $staff->filter()->unique()->flip();
        $owner = strtolower((string) ($this->hub->settings('teams')['organizer_upn'] ?? ''));

        return $this->hub->call('teams', 'sync_members', function (array $s) use ($team, $want, $staff, $owner) {
            $api = $this->api($s);
            $have = $api->members($team->team_id);
            $haveEmails = array_flip($have);
            $added = 0;
            $removed = 0;
            foreach ($want->keys()->merge($staff->keys()) as $email) {
                if (! isset($haveEmails[$email])) {
                    $api->addMember($team->team_id, $email, $staff->has($email));
                    $added++;
                }
            }
            foreach ($have as $membershipId => $email) {
                // People who left the group are removed; the service owner and the group's staff stay.
                if ($email !== '' && $email !== $owner && ! $want->has($email) && ! $staff->has($email)) {
                    $api->removeMember($team->team_id, (string) $membershipId);
                    $removed++;
                }
            }
            $team->update(['members_synced_at' => now(), 'last_error' => null]);

            return ['added' => $added, 'removed' => $removed];
        }, ['group' => $team->group_id]);
    }

    /** Copies the group's materials into the channel's SharePoint folder. @return int files sent */
    public function pushMaterials(TrainingGroup $group): int
    {
        $team = TeamsTeam::where('group_id', $group->id)->first();
        if (! $team || ! $team->drive_id || ! $team->folder_item_id) {
            return 0;
        }
        $n = 0;
        foreach (Material::where('program_id', $group->program_id)->whereNotNull('file_path')->limit(50)->get() as $m) {
            try {
                $content = $this->storage->get('materials', (string) $m->file_path);
                $this->hub->call('teams', 'upload_file', fn (array $s) => $this->api($s)->uploadFile($team->drive_id, $team->folder_item_id, basename((string) $m->file_path), $content, (string) ($m->mime ?: 'application/octet-stream')), ['material' => $m->id]);
                $n++;
            } catch (Throwable) {
                continue;
            }
        }

        return $n;
    }

    // ---- Office 365 Forms ------------------------------------------------------------------------------

    /**
     * Brings the results of a Forms quiz in from the CSV/Excel export Forms offers (Graph has no Forms results API): one row per respondent
     * with an e-mail and a score. Each becomes a graded attempt for the matching registration.
     *
     * @param  list<array{email: string, score: float|int|string, max?: float|int|string|null}>  $rows
     * @return array{imported: int, unmatched: int}
     */
    public function importFormsResults(Assessment $assessment, array $rows): array
    {
        $out = ['imported' => 0, 'unmatched' => 0];
        $regs = Registration::with('employee.user')->where('program_id', $assessment->program_id)->get()->keyBy(fn ($r) => strtolower((string) $r->employee?->user?->email));
        foreach ($rows as $row) {
            $reg = $regs->get(strtolower(trim((string) ($row['email'] ?? ''))));
            if (! $reg || ! is_numeric($row['score'] ?? null)) {
                $out['unmatched']++;

                continue;
            }
            $max = isset($row['max']) && is_numeric($row['max']) && $row['max'] > 0 ? (float) $row['max'] : 100.0;
            $percent = round(min(100, (float) $row['score'] / $max * 100), 1);
            $attempt = ((int) AssessmentAttempt::where('assessment_id', $assessment->id)->where('registration_id', $reg->id)->max('attempt_no')) + 1;
            AssessmentAttempt::create(['assessment_id' => $assessment->id, 'registration_id' => $reg->id, 'attempt_no' => $attempt, 'started_at' => now(), 'submitted_at' => now(), 'delivery' => 'forms', 'questions' => [], 'answers' => [],
                'auto_score' => $percent, 'max_score' => 100, 'score_percent' => $percent, 'passed' => $percent >= (float) $assessment->pass_percent, 'status' => 'graded', 'graded_at' => now()]);
            $out['imported']++;
        }

        return $out;
    }
}
