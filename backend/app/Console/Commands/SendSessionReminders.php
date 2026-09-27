<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('tedc:session-reminders')]
#[Description('Remind approved participants about sessions starting within the next 24 hours')]
class SendSessionReminders extends Command
{
    public function handle(NotificationService $notifications): int
    {
        $sessions = ProgramSession::with('program')
            ->where('status', 'scheduled')
            ->whereBetween('starts_at', [now(), now()->addDay()])
            ->get();

        $sent = 0;
        foreach ($sessions as $session) {
            // Idempotent: one reminder per session.
            if (! Cache::add("reminder:session:{$session->id}", true, now()->addDays(2))) {
                continue;
            }

            $userIds = Employee::whereIn('id', Registration::where('program_id', $session->program_id)
                ->where('status', Registration::STATUS_APPROVED)->select('employee_id'))->pluck('user_id');

            $time = $session->starts_at->timezone(config('app.timezone'));
            $sent += $notifications->broadcast(
                $userIds,
                'session.reminder',
                ['ar' => 'تذكير بجلسة تدريبية', 'en' => 'Training session reminder'],
                [
                    'ar' => "جلسة «{$session->title_ar}» من برنامج «{$session->program->title_ar}» تبدأ {$time->format('Y/m/d H:i')}.",
                    'en' => "\"{$session->title_en}\" ({$session->program->title_en}) starts {$time->format('Y-m-d H:i')}.",
                ],
                ['session_id' => $session->id, 'program_id' => $session->program_id],
            );
        }

        $this->info("Sent {$sent} reminders.");

        return self::SUCCESS;
    }
}
