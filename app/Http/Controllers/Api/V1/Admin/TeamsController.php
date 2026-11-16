<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationManager;
use App\Integrations\Teams\GraphPermissions;
use App\Integrations\Teams\TeamsActivityFeed;
use App\Integrations\Teams\TeamsAppPackage;
use App\Integrations\Teams\TeamsService;
use App\Models\Assessment;
use App\Models\ProgramSession;
use App\Models\TeamsAttendanceRecord;
use App\Models\TeamsMeeting;
use App\Models\TeamsTeam;
use App\Models\TrainingGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/** Microsoft Teams on the session page and the group page: the meeting or town hall, attendance by time in the call, the group's team and Forms quizzes; and the integration card. */
class TeamsController extends Controller
{
    public function __construct(private readonly TeamsService $teams, private readonly IntegrationManager $hub, private readonly TeamsActivityFeed $feed) {}

    public function session(ProgramSession $session): JsonResponse
    {
        $m = TeamsMeeting::where('session_id', $session->id)->first();

        return response()->json(['data' => [
            'ready' => $this->teams->ready(), 'wants' => $this->teams->wants($session), 'kind' => $this->teams->kind($session), 'blocked' => $this->teams->blocked($session),
            'meeting' => $m ? $m->only(['id', 'kind', 'join_url', 'status', 'organizer_upn', 'lobby', 'invited_count', 'recording_url', 'attendance_synced_at', 'attendance_attempts', 'last_error']) : null,
            'attendance' => TeamsAttendanceRecord::where('session_id', $session->id)->orderByDesc('minutes')->get(['email', 'display_name', 'role', 'minutes', 'percent', 'applied', 'registration_id']),
        ]]);
    }

    public function createMeeting(ProgramSession $session): JsonResponse
    {
        $m = $this->teams->ensureMeeting($session);

        return response()->json(['data' => $m], $m ? 201 : 422);
    }

    public function cancelMeeting(ProgramSession $session): JsonResponse
    {
        $this->teams->cancelMeeting($session);

        return response()->json(['data' => ['cancelled' => true]]);
    }

    public function syncAttendance(ProgramSession $session): JsonResponse
    {
        return response()->json(['data' => $this->teams->syncAttendance($session)]);
    }

    /** Settings → Integrations → Teams: what is switched on, whether the organiser account is connected, and what the tenant granted. */
    public function overview(): JsonResponse
    {
        $s = $this->hub->settings('teams');
        $granted = ['application' => [], 'delegated' => null];
        $probe = ['ok' => false, 'error' => 'not_configured'];
        if ($this->teams->ready()) {
            try {
                $granted = $this->hub->call('teams', 'permissions', fn () => $this->teams->graph()->granted(), [], 0);
                $probe = ['ok' => true, 'error' => null];
            } catch (Throwable $e) {
                $probe = ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300)];
            }
        }

        return response()->json(['data' => [
            'ready' => $this->teams->ready(), 'driver' => $this->hub->get('teams')->driver, 'probe' => $probe, 'permissions' => GraphPermissions::checklist($granted),
            'townhalls' => ['enabled' => ! empty($s['townhalls']), 'invite_only' => ! empty($s['townhall_invite_only']),
                'organizer' => ['connected' => filled($s['organizer_refresh_token'] ?? null), 'account' => ($s['organizer_account'] ?? '') ?: null, 'hint' => ($s['organizer_upn'] ?? '') ?: null]],
            'activity_feed' => ['enabled' => ! empty($s['activity_feed']), 'active' => $this->feed->enabled(), 'app_id' => ($s['teams_app_id'] ?? '') ?: null, 'version' => TeamsActivityFeed::APP_VERSION, 'types' => count(array_unique(array_values(TeamsActivityFeed::TYPES)))],
            'redirect_uri' => url('/api/v1/integrations/teams/organizer/callback'), 'tab_url' => url('/api/v1/teams/tab'),
        ]]);
    }

    /** The Teams app package for the Ministry's Teams administrator to upload. */
    public function appPackage(TeamsAppPackage $package): BinaryFileResponse
    {
        if (blank($this->hub->settings('teams')['client_id'] ?? null)) {
            throw ValidationException::withMessages(['teams' => __('teams.not_configured')]);
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'tedc-teams-');
        $package->build($path);

        return response()->download($path, 'tedc-teams-app.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    public function group(TrainingGroup $group): JsonResponse
    {
        $t = TeamsTeam::where('group_id', $group->id)->first();

        return response()->json(['data' => ['ready' => $this->teams->ready(), 'team' => $t?->only(['team_id', 'web_url', 'members_synced_at', 'last_error'])]]);
    }

    /** Creates the group's team if needed, brings its members in line and (optionally) copies the group's files into the channel. */
    public function syncGroup(Request $request, TrainingGroup $group): JsonResponse
    {
        $team = $this->teams->ensureTeam($group);
        abort_if(! $team, 422, 'Teams is not set up to create teams.');
        $members = $this->teams->syncMembers($group);
        $files = $request->boolean('files') ? $this->teams->pushMaterials($group) : null;

        return response()->json(['data' => ['members' => $members, 'files' => $files, 'web_url' => $team->web_url]]);
    }

    public function linkForms(Request $request, Assessment $assessment): JsonResponse
    {
        $d = $request->validate(['forms_url' => ['nullable', 'url:https', 'max:500', 'regex:#^https://(forms\.office\.com|forms\.microsoft\.com|forms\.cloud\.microsoft)/#']]);
        $assessment->update(['forms_url' => $d['forms_url'] ?? null]);

        return response()->json(['data' => $assessment->only(['id', 'forms_url'])]);
    }

    /** Results exported from Forms (CSV with e-mail and score columns) become graded attempts. */
    public function importForms(Request $request, Assessment $assessment): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']]);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $head = null;
        $rows = [];
        while (($line = fgetcsv($handle, 0, ',')) !== false) {
            $line = array_map(fn ($c) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c)), $line);
            if ($head === null) {
                $head = array_map('strtolower', $line);

                continue;
            }
            $row = array_combine($head, array_pad($line, count($head), ''));
            $email = $row['email'] ?? $row['e-mail'] ?? $row['email address'] ?? '';
            $score = $row['score'] ?? $row['total points'] ?? $row['points'] ?? '';
            $rows[] = ['email' => $email, 'score' => str_replace(',', '.', (string) $score), 'max' => $row['max'] ?? $row['out of'] ?? null];
        }
        fclose($handle);

        return response()->json(['data' => $this->teams->importFormsResults($assessment, array_slice($rows, 0, 5000))]);
    }
}
