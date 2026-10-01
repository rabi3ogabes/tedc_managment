<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Services\GeoFence;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('tedc:attendance-nudges')]
#[Description('Smart attendance nudges: tell participants when check-in opens, and remind those who have not checked in')]
class SendAttendanceNudges extends Command
{
    public function handle(NotificationService $notifications, GeoFence $geofence): int
    {
        $sent = 0;
        $opens = (int) config('tedc.attendance.check_in_opens_minutes_before');
        $late = (int) config('tedc.attendance.late_after_minutes');

        $sessions = ProgramSession::with('program', 'room')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('starts_at', [now()->subMinutes($late + 30), now()->addMinutes(max($opens, 240))])
            ->get();

        foreach ($sessions as $session) {
            $remote = $session->mode === 'online';
            $venue = $remote ? null : $geofence->venue($session);
            $where = $venue ? " ({$venue['name']})" : '';
            $minutesToStart = now()->diffInMinutes($session->starts_at, false);
            $opensFor = $remote ? (int) ($session->program->remote['join_opens_minutes'] ?? 15) : $opens;

            if ($remote) {
                // Online session: the notification opens the session page, where "Join" records the attendance.
                if ($minutesToStart > 0 && $minutesToStart <= $opensFor) {
                    $sent += $this->nudge($notifications, $session, 'open', $this->participants($session, checkedIn: false),
                        ['ar' => 'الجلسة عن بُعد على وشك البدء', 'en' => 'Your online session is about to start'],
                        ['ar' => "جلسة «{$session->title_ar}» تبدأ بعد {$minutesToStart} دقيقة. اضغط للانضمام وتسجيل حضورك.", 'en' => "\"{$session->title_en}\" starts in {$minutesToStart} minutes. Tap to join and record your attendance."]);
                } elseif ($minutesToStart <= -$late && $session->ends_at->isFuture()) {
                    $sent += $this->nudge($notifications, $session, 'missed', $this->participants($session, checkedIn: false),
                        ['ar' => 'لم تنضم إلى الجلسة بعد', 'en' => 'You have not joined the session yet'],
                        ['ar' => "بدأت جلسة «{$session->title_ar}». انضم الآن لتسجيل حضورك.", 'en' => "\"{$session->title_en}\" has started. Join now to record your attendance."]);
                }

                continue;
            }

            if ($minutesToStart > 0 && $minutesToStart <= $opens) {
                $sent += $this->nudge($notifications, $session, 'open', $this->participants($session, checkedIn: false),
                    ['ar' => 'بدأ تسجيل الحضور', 'en' => 'Check-in is open'],
                    ['ar' => "افتح التطبيق وامسح رمز الحضور لجلسة «{$session->title_ar}»{$where}. يتطلب التسجيل وجودك في مكان التدريب.", 'en' => "Open the app and scan the code for \"{$session->title_en}\"{$where}. You must be at the venue to check in."]);
            } elseif ($minutesToStart <= -$late && $session->ends_at->isFuture()) {
                $sent += $this->nudge($notifications, $session, 'missed', $this->participants($session, checkedIn: false),
                    ['ar' => 'لم تسجّل حضورك بعد', 'en' => 'You have not checked in yet'],
                    ['ar' => "بدأت جلسة «{$session->title_ar}». سجّل حضورك الآن قبل أن يُحتسب تأخير أكبر.", 'en' => "\"{$session->title_en}\" has started. Check in now to avoid being marked late."]);
            }
        }

        $this->info("Sent {$sent} attendance nudges.");

        return self::SUCCESS;
    }

    /** One nudge per session and phase. */
    private function nudge(NotificationService $notifications, ProgramSession $session, string $phase, $userIds, array $title, array $body): int
    {
        if ($userIds->isEmpty() || ! Cache::add("nudge:attendance:{$phase}:{$session->id}", true, now()->addDay())) {
            return 0;
        }

        return $notifications->broadcast($userIds, 'session.attendance_'.$phase, $title, $body, [
            'session_id' => $session->id, 'program_id' => $session->program_id,
            // Online sessions open the session page; in-person ones open the QR scanner.
            'route' => $session->mode === 'online' ? '/sessions/'.$session->id : '/scan',
        ]);
    }

    /** Approved participants; optionally only those without a check-in for this session. */
    private function participants(ProgramSession $session, bool $checkedIn)
    {
        $registrations = Registration::where('program_id', $session->program_id)->where('status', Registration::STATUS_APPROVED)
            ->when(! $checkedIn, fn ($q) => $q->whereNotIn('id', Attendance::where('program_session_id', $session->id)->whereNotNull('check_in_at')->select('registration_id')));

        return Employee::whereIn('id', $registrations->select('employee_id'))->pluck('user_id');
    }
}
