<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\Teams\TeamsService;
use App\Models\Assessment;
use App\Models\ProgramSession;
use App\Models\TeamsAttendanceRecord;
use App\Models\TeamsMeeting;
use App\Models\TeamsTeam;
use App\Models\TrainingGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Microsoft Teams on the session page and the group page: the meeting, attendance by time in the call, the group's team and Forms quizzes. */
class TeamsController extends Controller
{
    public function __construct(private readonly TeamsService $teams) {}

    public function session(ProgramSession $session): JsonResponse
    {
        $m = TeamsMeeting::where('session_id', $session->id)->first();

        return response()->json(['data' => [
            'ready' => $this->teams->ready(), 'wants' => $this->teams->wants($session),
            'meeting' => $m ? $m->only(['id', 'join_url', 'status', 'organizer_upn', 'lobby', 'recording_url', 'attendance_synced_at', 'attendance_attempts', 'last_error']) : null,
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
