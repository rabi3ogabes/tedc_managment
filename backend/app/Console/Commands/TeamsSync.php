<?php

namespace App\Console\Commands;

use App\Integrations\Teams\TeamsService;
use App\Models\ProgramSession;
use App\Models\TeamsMeeting;
use App\Models\TeamsTeam;
use Illuminate\Console\Command;
use Throwable;

class TeamsSync extends Command
{
    protected $signature = 'tedc:teams-sync';

    protected $description = 'Creates Teams meetings for upcoming online sessions, pulls attendance after sessions end and keeps group teams in line with registrations';

    public function handle(TeamsService $teams): int
    {
        if (! $teams->ready()) {
            return self::SUCCESS;
        }
        $made = 0;
        $synced = 0;
        // Online sessions in the next two weeks that have no meeting yet.
        ProgramSession::whereIn('mode', ['online', 'hybrid', 'blended'])->where('status', '!=', 'cancelled')->whereBetween('starts_at', [now(), now()->addDays(14)])->whereDoesntHave('teamsMeeting')->limit(100)->get()->each(function (ProgramSession $s) use ($teams, &$made) {
            try {
                $teams->ensureMeeting($s) && $made++;
            } catch (Throwable) {
            }
        });
        // Sessions that ended ten minutes ago or more and have no attendance yet; retried a few times because Teams publishes the report late.
        TeamsMeeting::where('status', 'scheduled')->where('attendance_attempts', '<', 6)->whereHas('session', fn ($q) => $q->where('ends_at', '<=', now()->subMinutes(10))->where('ends_at', '>=', now()->subDays(3)))->with('session')->limit(50)->get()->each(function (TeamsMeeting $m) use ($teams, &$synced) {
            try {
                $teams->syncAttendance($m->session);
                $synced++;
            } catch (Throwable $e) {
                $m->update(['last_error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        });
        // Group teams: membership once a day.
        if (now()->hour === 3 && now()->minute < 10) {
            TeamsTeam::with('group')->get()->each(function (TeamsTeam $t) use ($teams) {
                try {
                    $t->group && $teams->syncMembers($t->group);
                } catch (Throwable) {
                }
            });
        }
        $this->info("Teams: {$made} meetings created, {$synced} attendance reports pulled.");

        return self::SUCCESS;
    }
}
