<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PresenceSession;
use App\Services\PresenceService;
use App\Services\SecuritySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Live presence for administrators (who is online now) and the usage report. */
class PresenceController extends Controller
{
    public function __construct(private readonly PresenceService $presence) {}

    /** Heartbeat sent by the dashboard / portal (every ~30 s) and by the mobile app. */
    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(['web', 'mobile'])],
            'path' => ['nullable', 'string', 'max:191'],
            'idle_seconds' => ['nullable', 'integer', 'min:0', 'max:604800'],
            'app_version' => ['nullable', 'string', 'max:24'],
        ]);
        $this->presence->heartbeat($request->user(), $data, $request->userAgent());

        $user = $request->user();
        $security = app(SecuritySettings::class);

        return response()->json(['data' => [
            'locked' => (bool) $user->locked_at,
            // Only the administration team is locked when idle.
            'lock' => ['enabled' => $security->lockEnabled() && PresenceService::isStaff($user), 'seconds' => $security->lockSeconds()],
        ]]);
    }

    public function live(): JsonResponse
    {
        return response()->json(['data' => $this->presence->live()]);
    }

    public function report(Request $request): JsonResponse
    {
        [$from, $to, $team, $platform] = $this->filters($request);
        $sessions = $this->presence->sessions($from, $to, $team, $platform);

        return response()->json(['data' => [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'summary' => $this->presence->summary($sessions),
            'sessions' => $sessions->reverse()->take(200)->map(fn (PresenceSession $s) => $this->row($s))->values(),
        ]]);
    }

    /** CSV download (UTF-8 with BOM so Arabic opens correctly in Excel). */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to, $team, $platform] = $this->filters($request);
        $sessions = $this->presence->sessions($from, $to, $team, $platform);
        $name = 'online-report-'.$from->toDateString().'_'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($sessions) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Name', 'Email', 'Team', 'Role', 'Platform', 'Device', 'Started', 'Last seen', 'Minutes', 'Requests', 'Last page', 'App version']);
            foreach ($sessions as $s) {
                $r = $this->row($s);
                fputcsv($out, [$r['name'], $r['email'], $r['team'], $r['role'], $r['platform'], $r['device'], $r['started_at'], $r['last_seen_at'], $r['minutes'], $r['hits'], $r['path'], $r['app_version']]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: ?string, 3: ?string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'team' => ['nullable', Rule::in(['staff', 'members'])], 'platform' => ['nullable', Rule::in(['web', 'mobile'])],
        ]);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays(6)->startOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        return [$from, $to, $data['team'] ?? null, $data['platform'] ?? null];
    }

    private function row(PresenceSession $s): array
    {
        $tz = config('app.timezone');

        return [
            'name' => $s->user?->displayName() ?? '—', 'email' => $s->user?->email, 'team' => $s->team, 'role' => $s->role_label, 'platform' => $s->platform,
            'device' => $s->device, 'started_at' => $s->started_at->timezone($tz)->format('Y-m-d H:i'), 'last_seen_at' => $s->last_seen_at->timezone($tz)->format('Y-m-d H:i'),
            'minutes' => $s->minutes(), 'hits' => $s->hits, 'path' => $s->last_path, 'app_version' => $s->app_version,
        ];
    }
}
