<?php

namespace App\Services\Reports;

use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\Role;
use App\Models\User;
use App\Services\Channels\EmailSender;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Throwable;

/** Reports sent on a timetable: each due schedule produces a run, and the recipients get a link that works without signing in for two days. */
class ReportScheduleService
{
    public function __construct(private readonly ReportRunService $runs, private readonly NotificationService $notifications, private readonly EmailSender $email) {}

    public static function nextRun(string $frequency, ?Carbon $from = null): Carbon
    {
        $from = ($from ?? now())->copy()->startOfDay()->addHours(6);   // reports go out at 06:00

        return match ($frequency) {
            'daily' => $from->addDay(),
            'weekly' => $from->addWeek(),
            default => $from->addMonthNoOverflow(),
        };
    }

    /** @return int schedules processed */
    public function runDue(): int
    {
        $n = 0;
        ReportSchedule::where('is_active', true)->where('next_run_at', '<=', now())->orderBy('next_run_at')->limit(20)->get()->each(function (ReportSchedule $s) use (&$n) {
            $n++;
            try {
                $this->send($s);
                $s->update(['last_run_at' => now(), 'next_run_at' => self::nextRun($s->frequency), 'last_error' => null]);
            } catch (Throwable $e) {
                $s->update(['last_run_at' => now(), 'next_run_at' => self::nextRun($s->frequency), 'last_error' => mb_substr($e->getMessage(), 0, 400)]);
                $this->alert($s, $e->getMessage());
            }
        });

        return $n;
    }

    public function send(ReportSchedule $s): ReportRun
    {
        $owner = User::with('roles')->findOrFail($s->created_by);
        $run = $this->runs->request($s->definition, (array) $s->params, $owner, $s->formats ?? ['pdf'], $s->lang, $s->id);
        if ($run->status !== 'ready') {
            $run = $this->runs->execute($run);   // scheduled runs are always produced now: the people waiting are not at a screen
        }
        if ($run->status !== 'ready') {
            throw new \RuntimeException($run->error ?: 'The report could not be produced.');
        }

        $links = [];
        foreach ($run->formats ?? [] as $f) {
            $links[$f] = URL::temporarySignedRoute('report-runs.signed', now()->addDays(2), ['run' => $run->id, 'format' => $f]);
        }
        $title = ['ar' => $s->definition->title_ar, 'en' => $s->definition->title_en];
        $r = (array) $s->recipients;

        $userIds = collect($r['users'] ?? []);
        foreach ((array) ($r['roles'] ?? []) as $slug) {
            $userIds = $userIds->merge(User::where('status', 'active')->whereHas('roles', fn ($q) => $q->where('slug', $slug))->pluck('id'));
        }
        foreach ($userIds->unique() as $uid) {
            $this->notifications->send($uid, 'report.scheduled', ['ar' => 'تقرير دوري: '.$title['ar'], 'en' => 'Scheduled report: '.$title['en']],
                ['ar' => 'روابط التنزيل: '.implode(' · ', $links), 'en' => 'Download: '.implode(' · ', $links)], ['route' => '/admin/reports?run='.$run->id], raw: true);
        }
        foreach ((array) ($r['emails'] ?? []) as $to) {
            if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $this->email->send($to, ($s->lang === 'ar' ? 'تقرير دوري: ' : 'Scheduled report: ').$title[$s->lang === 'ar' ? 'ar' : 'en'], implode("\n", array_map(fn ($f, $u) => strtoupper($f).': '.$u, array_keys($links), $links)), $s->lang === 'ar' ? 'ar' : 'en');
            }
        }

        return $run;
    }

    private function alert(ReportSchedule $s, string $error): void
    {
        $admins = User::where('status', 'active')->whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::SUPER_ADMIN, Role::CENTER_ADMIN]))->pluck('id');
        $this->notifications->broadcast($admins, 'report.schedule_failed', ['ar' => 'تعذّر إرسال تقرير مجدول', 'en' => 'A scheduled report could not be sent'],
            ['ar' => $s->definition->title_ar.' — '.$error, 'en' => $s->definition->title_en.' — '.$error], ['route' => '/admin/reports'], raw: true);
    }
}
