<?php

namespace App\Console\Commands;

use App\Gamification\GamificationService;
use App\Services\FeatureSettings;
use App\Social\CourseSocial;
use App\Social\DailyDigest;
use App\Social\SocialFiles;
use App\Social\SpaceService;
use Illuminate\Console\Command;

class SocialTick extends Command
{
    protected $signature = 'tedc:social-tick';

    protected $description = 'Event reminders, overdue trainer questions, the daily digest, closing ended challenges and leaderboard snapshots (Phase 14)';

    public function handle(SpaceService $spaces, CourseSocial $course, DailyDigest $digest, GamificationService $game, FeatureSettings $features, SocialFiles $files): int
    {
        if ($features->enabled('plc') || $features->enabled('forums')) {
            $this->line('reminders: '.$spaces->remindEvents());
            $this->line('digest: '.$digest->run());
        }
        // Files uploaded for a post that was never sent are removed after a day (once an hour is enough).
        if (now()->minute < 5) {
            $this->line('unused uploads removed: '.$files->cleanup());
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
