<?php

namespace App\Console\Commands;

use App\Gamification\GamificationService;
use App\Services\FeatureSettings;
use App\Social\CourseSocial;
use App\Social\DailyDigest;
use App\Social\SpaceService;
use Illuminate\Console\Command;

class SocialTick extends Command
{
    protected $signature = 'tedc:social-tick';

    protected $description = 'Event reminders, overdue trainer questions, the daily digest, closing ended challenges and leaderboard snapshots (Phase 14)';

    public function handle(SpaceService $spaces, CourseSocial $course, DailyDigest $digest, GamificationService $game, FeatureSettings $features): int
    {
        if ($features->enabled('plc') || $features->enabled('forums')) {
            $this->line('reminders: '.$spaces->remindEvents());
            $this->line('digest: '.$digest->run());
        }
        if ($features->enabled('forums')) {
            $this->line('overdue chased: '.$course->chaseOverdue());
        }
        if ($game->enabled()) {
            $this->line('challenges closed: '.$game->closeEnded());
            if (now()->minute < 5) {
                $this->line('snapshots: '.$game->snapshot());
            }
        }

        return self::SUCCESS;
    }
}
