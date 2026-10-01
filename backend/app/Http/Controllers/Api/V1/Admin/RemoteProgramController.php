<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Services\CalendarService;
use App\Services\NeedsSurveys\SurveyAudience;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Remote (online) programs: live tracking of who joined, reminders to those who did not, and smart time slots
 * that avoid clashes with the sessions the target audience is already attending.
 */
class RemoteProgramController extends Controller
{
    public function __construct(private readonly SurveyAudience $audience, private readonly CalendarService $calendar) {}

    /** Per-session join rates and a per-participant summary for a program. */
    public function tracking(Program $program): JsonResponse
    {
        $sessions = $program->sessions()->where('status', '!=', 'cancelled')->get();
        $registrations = Registration::with(['employee.user:id,name,name_ar', 'employee.school:id,name_ar,name_en'])
            ->where('program_id', $program->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get();
        $records = Attendance::whereIn('program_session_id', $sessions->pluck('id'))->get()->groupBy('program_session_id');
        $now = now();

        $rows = $sessions->map(function (ProgramSession $s) use ($records, $registrations, $now) {
            $r = $records->get($s->id, collect());
            $joined = $r->whereNotNull('check_in_at');

            return [
                'id' => $s->id, 'sequence' => $s->sequence, 'title' => $s->translate('title'), 'mode' => $s->mode,
                'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
                'state' => $s->ends_at->lt($now) ? 'ended' : ($s->starts_at->lte($now) ? 'live' : 'upcoming'),
                'expected' => $registrations->count(), 'joined' => $joined->count(), 'late' => $r->where('status', 'late')->count(),
                'not_joined' => max(0, $registrations->count() - $joined->count()),
                'avg_minutes' => $joined->count() ? (int) round($joined->avg('minutes_attended')) : 0,
                'has_link' => filled($s->online_url), 'has_recording' => filled($s->recording_url),
            ];
        })->values();

        $ended = $rows->where('state', 'ended');
        $people = $registrations->map(function (Registration $reg) use ($records, $ended) {
            $mine = $records->flatten()->where('registration_id', $reg->id);
            $joinedEnded = $mine->whereIn('program_session_id', $ended->pluck('id'))->whereNotNull('check_in_at')->count();

            return [
                'registration_id' => $reg->id, 'name' => $reg->employee->user->displayName(), 'school' => $reg->employee->school?->translate('name'),
                'joined' => $mine->whereNotNull('check_in_at')->count(), 'late' => $mine->where('status', 'late')->count(),
                'missed' => max(0, $ended->count() - $joinedEnded), 'minutes' => (int) $mine->sum('minutes_attended'),
                'last_join_at' => $mine->max('last_join_at') ? CarbonImmutable::parse($mine->max('last_join_at'))->toIso8601String() : null,
                'attendance_percent' => (float) $reg->attendance_percent,
            ];
        })->sortBy('attendance_percent')->values();

        $expected = $ended->sum('expected');

        return response()->json(['data' => [
            'sessions' => $rows, 'participants' => $people,
            'summary' => [
                'participants' => $registrations->count(), 'sessions' => $rows->count(), 'ended' => $ended->count(), 'live' => $rows->where('state', 'live')->count(),
                'join_rate' => $expected > 0 ? round($ended->sum('joined') / $expected * 100, 1) : null,
                'at_risk' => $people->filter(fn ($p) => $ended->count() > 0 && $p['attendance_percent'] < $program->min_attendance_percent)->count(),
            ],
        ]]);
    }

    /** Nudges the participants who have not joined a live (or about to start) session. */
    public function remind(ProgramSession $session, NotificationService $notifications): JsonResponse
    {
        if ($session->mode !== 'online') {
            throw new BusinessRuleException(__('messages.attendance.not_online'), 'not_online');
        }
        if ($session->ends_at->isPast()) {
            throw new BusinessRuleException(__('messages.attendance.closed'), 'closed');
        }
        if (! Cache::add("remind:session:{$session->id}", true, now()->addMinutes(10))) {
            throw new BusinessRuleException(__('messages.remote.remind_wait'), 'remind_wait');
        }

        $pending = Employee::whereIn('id', Registration::where('program_id', $session->program_id)->where('status', Registration::STATUS_APPROVED)
            ->whereNotIn('id', Attendance::where('program_session_id', $session->id)->whereNotNull('check_in_at')->select('registration_id'))->select('employee_id'))->pluck('user_id');

        $count = $notifications->broadcast($pending, 'session.attendance_missed',
            ['ar' => 'لم تنضم إلى الجلسة بعد', 'en' => 'You have not joined the session yet'],
            ['ar' => "جلسة «{$session->title_ar}» جارية أو على وشك البدء. انضم الآن لتسجيل حضورك.", 'en' => "\"{$session->title_en}\" is live or about to start. Join now to record your attendance."],
            ['session_id' => $session->id, 'program_id' => $session->program_id, 'route' => '/sessions/'.$session->id], force: true);

        return response()->json(['data' => ['reminded' => $count]]);
    }

    /**
     * Best start times for online sessions: for the coming training days, each candidate slot is scored by how many
     * people of the target audience are already in another session at that time.
     */
    public function slots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'], 'hours' => ['required', 'numeric', 'min:0.5', 'max:8'],
            'days' => ['nullable', 'integer', 'between:1,30'],
        ] + SurveyAudience::rules('audience.'));

        $audience = $data['audience'] ?? [];
        $size = $this->audience->query($audience)->count();
        $from = CarbonImmutable::parse($data['from']);
        $days = collect($this->calendar->range($from, $from->addDays(($data['days'] ?? 14) + 14), false)['days'])->where('training_allowed', true)->take($data['days'] ?? 14);

        $slots = [];
        foreach ($days as $day) {
            foreach (['09:00', '11:00', '13:00', '15:00'] as $time) {
                $start = CarbonImmutable::parse("{$day['date']} {$time}");
                $end = $start->addMinutes((int) round($data['hours'] * 60));
                $busy = $size === 0 ? 0 : Registration::whereIn('status', [Registration::STATUS_APPROVED])
                    ->whereIn('employee_id', $this->audience->query($audience)->select('employees.id'))
                    ->whereIn('program_id', ProgramSession::where('status', '!=', 'cancelled')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->select('program_id'))
                    ->distinct('employee_id')->count('employee_id');

                $slots[] = ['date' => $day['date'], 'time' => $time, 'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s'), 'busy' => $busy, 'free' => max(0, $size - $busy), 'free_percent' => $size ? round(($size - $busy) / $size * 100) : 100];
            }
        }

        usort($slots, fn ($a, $b) => [$a['busy'], $a['starts_at']] <=> [$b['busy'], $b['starts_at']]);

        return response()->json(['data' => ['audience' => $size, 'slots' => array_slice($slots, 0, 8)]]);
    }
}
