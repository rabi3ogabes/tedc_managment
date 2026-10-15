<?php

namespace App\Console\Commands;

use App\Services\Surveys\SurveyRetention;
use Illuminate\Console\Command;

/** Survey Studio v2, Gate 7: the daily retention run (drafts, orphan files, invitations, pseudonym keys, answers). */
class SurveysRetention extends Command
{
    protected $signature = 'tedc:surveys-retention {--dry-run : Count what would be removed without changing anything}';

    protected $description = 'Apply the survey engine\'s retention policy: expired drafts and orphan files, invitations after close, pseudonym keys after the grace period, answers after the retention period (aggregates kept).';

    public function handle(SurveyRetention $retention): int
    {
        $r = $retention->run((bool) $this->option('dry-run'));
        $this->info(($r['dry_run'] ? '[dry run] ' : '')."drafts {$r['drafts']}, orphan files {$r['orphan_files']}, invitations {$r['invitations']}, keys {$r['keys']}, surveys retired {$r['answers']}.");
        foreach ($r['retired'] as $s) {
            $this->line(" - {$s['title']} ({$s['id']})");
        }

        return self::SUCCESS;
    }
}
