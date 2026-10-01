<?php

namespace App\Console\Commands;

use App\Models\LessonProgress;
use App\Models\Registration;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('tedc:course-nudges {--days=5 : Days without activity before a reminder}')]
#[Description('Remind learners who stopped working through an online course')]
class SendCourseNudges extends Command
{
    public function handle(NotificationService $notifications): int
    {
        $days = max(1, (int) $this->option('days'));
        $sent = 0;

        Registration::with(['program', 'employee'])
            ->where('status', Registration::STATUS_APPROVED)->where('course_completed', false)
            ->whereHas('program', fn ($q) => $q->where('has_course', true)->whereIn('status', ['registration_open', 'in_progress', 'published']))
            ->each(function (Registration $r) use ($notifications, $days, &$sent) {
                $last = LessonProgress::where('registration_id', $r->id)->max('last_activity_at');
                $since = $last ? Carbon::parse($last) : $r->created_at;
                if (! $r->employee?->user_id || $since->gt(now()->subDays($days)) || ! Cache::add("course-nudge:{$r->id}", true, now()->addDays($days))) {
                    return;
                }
                $left = max(0, 100 - (int) round($r->course_percent));
                $notifications->send($r->employee->user_id, 'course.nudge',
                    ['ar' => 'أكمل من حيث توقفت', 'en' => 'Pick up where you left off'],
                    ['ar' => "محتوى برنامج «{$r->program->title_ar}» ينتظرك — تبقّى {$left}٪ فقط.", 'en' => "The content of \"{$r->program->title_en}\" is waiting for you — only {$left}% left."],
                    ['registration_id' => $r->id, 'program_id' => $r->program_id, 'route' => '/courses/'.$r->id]);
                $sent++;
            });

        $this->info("Sent {$sent} course reminders.");

        return self::SUCCESS;
    }
}
