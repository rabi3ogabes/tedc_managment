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
use App\Services\AttendanceService;
use App\Services\FileStorage;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Microsoft Teams for training: a meeting for every online session (updated or cancelled when the schedule changes), attendance computed from
 * how long each person was in the call, a Team per training group with membership kept in line with registrations, files in its channel,
 * and Office 365 Forms quizzes as assessment sources.
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
        return $this->hub->get('teams')->driver === 'fake' ? new FakeGraph($s) : new HttpGraph($s);
    }

    /** True when sessions of this kind should have a Teams meeting. */
    public function wants(ProgramSession $session): bool
    {
        if (! $this->ready() || ! in_array($session->mode, self::ONLINE_MODES, true) || $session->status === 'cancelled') {
            return false;
        }
        $s = $this->hub->settings('teams');

        return ($s['auto_meetings'] ?? true) && ($session->online_platform === null || $session->online_platform === 'teams');
    }

    // ---- meetings ------------------------------------------------------------------------------------

    /** Creates the meeting if there is none, otherwise brings it in line with the session. */
    public function ensureMeeting(ProgramSession $session): ?TeamsMeeting
    {
        if (! $this->wants($session)) {
            return null;
        }
        $existing = TeamsMeeting::where('session_id', $session->id)->first();
        $subject = $session->title_ar ?: $session->title_en ?: 'TEDC';

        return $this->hub->call('teams', $existing ? 'update_meeting' : 'create_meeting', function (array $s) use ($session, $existing, $subject) {
            $organizer = $this->organizer($session, $s);
            $api = $this->api($s);
            if ($existing && $existing->status !== 'cancelled') {
                $api->updateMeeting($existing->organizer_upn ?: $organizer, $existing->meeting_id, $subject, $session->starts_at, $session->ends_at);

                return $existing;
            }
            $m = $api->createMeeting($organizer, $subject, $session->starts_at, $session->ends_at, (string) ($s['lobby'] ?? 'organization'));
            $meeting = TeamsMeeting::updateOrCreate(['session_id' => $session->id], ['meeting_id' => $m['id'], 'join_url' => $m['join_url'], 'organizer_upn' => $organizer, 'status' => 'scheduled', 'lobby' => $s['lobby'] ?? 'organization', 'last_error' => null]);
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
        try {
            $this->hub->call('teams', 'cancel_meeting', fn (array $s) => $this->api($s)->deleteMeeting($m->organizer_upn ?: $this->organizer($session, $s), $m->meeting_id), ['session' => $session->id]);
        } catch (Throwable $e) {
            $m->update(['last_error' => mb_substr($e->getMessage(), 0, 300)]);
        }
        $m->update(['status' => 'cancelled']);
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
        $people = $this->hub->call('teams', 'attendance_report', fn (array $s) => $this->api($s)->attendance($meeting->organizer_upn ?: $this->organizer($session, $s), $meeting->meeting_id), ['session' => $session->id]);
        $s = $this->hub->settings('teams');
        $minPercent = (float) ($s['min_presence_percent'] ?? 50);
        $duration = max(1, $session->durationMinutes());

        $registrations = Registration::with('employee.user')->where('program_id', $session->program_id)
            ->when($session->training_group_id, fn ($q) => $q->where(fn ($w) => $w->where('training_group_id', $session->training_group_id)->orWhereNull('training_group_id')))
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get();
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
