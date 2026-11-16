<?php

namespace App\Console\Commands;

use App\Models\ExternalCompletionIssue;
use App\Models\ExternalSyncRun;
use App\Services\Library\ExternalLearningService;
use Illuminate\Console\Command;

class LearningProvidersSync extends Command
{
    protected $signature = 'tedc:learning-providers-sync
        {provider? : coursera, udemy, linkedin_learning or edx (all that are switched on when not given)}
        {--full : Read the whole catalogue instead of what changed}
        {--completions : Only the completions, not the catalogue}
        {--prune : Only tidy what the readings keep (old readings, problems that are solved or no longer reported)}';

    protected $description = 'Reads the learning providers (Coursera, Udemy Business, LinkedIn Learning, Open edX): their catalogue and who completed what';

    public function handle(ExternalLearningService $learning): int
    {
        if ($this->option('prune')) {
            $runs = ExternalSyncRun::where('started_at', '<', now()->subDays(180))->delete();
            // A problem is kept while it is reported. Once solved it stays 90 days for the record, and one that stopped being reported goes after 90 days too.
            $issues = ExternalCompletionIssue::where(fn ($q) => $q->where('resolved_at', '<', now()->subDays(90))->orWhere(fn ($w) => $w->whereNull('resolved_at')->where('last_seen_at', '<', now()->subDays(90))))->delete();
            $this->line("tidied: {$runs} old reading(s), {$issues} old problem(s)");

            return self::SUCCESS;
        }
        $only = $this->argument('provider');
        if ($only !== null && ! in_array($only, ExternalLearningService::PROVIDERS, true)) {
            $this->error('There is no provider "'.$only.'". The providers: '.implode(', ', ExternalLearningService::PROVIDERS).'.');

            return self::FAILURE;
        }
        $result = $learning->sync($only, (bool) $this->option('full'), 'schedule', null, ! $this->option('completions'));
        foreach ($result['providers'] as $provider => $one) {
            $this->line(sprintf('%s: %s — %d course(s), %d completion(s) reported, %d counted, %d problem(s)%s', $provider, $one['status'], $one['courses'], $one['completions'], $one['completed'], $one['issues'], $one['error'] ? ' — '.$one['error'] : ''));
        }
        if ($result['providers'] === []) {
            $this->line('No provider is switched on.');
        }

        return self::SUCCESS;
    }
}
