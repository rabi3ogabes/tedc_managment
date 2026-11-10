<?php

namespace App\Console\Commands;

use App\Models\AssessmentAttempt;
use App\Services\Assessment\ProctoringSettings;
use App\Services\FileStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:proctoring-retention')]
#[Description('Delete exam-integrity snapshots, event logs and timelines past the retention period; attempts keep their final flags, reviews and consent')]
class ProctoringRetention extends Command
{
    public function handle(ProctoringSettings $settings, FileStorage $files): int
    {
        $cutoff = now()->subDays($settings->retentionDays());
        $purged = 0;
        AssessmentAttempt::whereNotNull('integrity')->where('started_at', '<', $cutoff)->where(fn ($q) => $q->whereNull('submitted_at')->orWhere('submitted_at', '<', $cutoff))
            ->orderBy('started_at')->chunkById(200, function ($attempts) use ($files, &$purged) {
                foreach ($attempts as $at) {
                    $i = $at->integrity ?? [];
                    if (! array_intersect_key($i, array_flip(['log', 'timeline', 'snapshots', 'events']))) {
                        continue;
                    }
                    foreach ((array) ($i['snapshots'] ?? []) as $path) {
                        try {
                            $files->delete('submissions', $path);
                        } catch (\Throwable $e) {
                            report($e); // the record is still cleared; a stray file is found by the storage report
                        }
                    }
                    $at->update(['integrity' => array_intersect_key($i, array_flip(['flags', 'reviews', 'consent'])) + ['purged_at' => now()->toIso8601String()]]);
                    $purged++;
                }
            });
        $this->info("{$purged} attempts past {$settings->retentionDays()} days cleared of integrity details.");

        return self::SUCCESS;
    }
}
