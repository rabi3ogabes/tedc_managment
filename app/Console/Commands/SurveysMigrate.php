<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessRuleException;
use App\Services\Surveys\SurveyMigrator;
use Illuminate\Console\Command;

/** Survey Studio v2, Gate 5: moves the legacy needs surveys and evaluation forms onto the engine, with a dry run and a rollback. */
class SurveysMigrate extends Command
{
    protected $signature = 'tedc:surveys:migrate
        {--dry-run : Show what a run would do without changing anything}
        {--only=all : What to migrate: all, needs or evaluations}
        {--rollback= : Roll back one batch (its id as printed by a run)}
        {--force : Roll back even when answers arrived after the migration (they are lost)}';

    protected $description = 'Migrate the legacy needs surveys and evaluation forms onto the survey engine (dry run, run, rollback).';

    public function handle(SurveyMigrator $migrator): int
    {
        if ($batch = $this->option('rollback')) {
            try {
                $r = $migrator->rollback((string) $batch, (bool) $this->option('force'));
            } catch (BusinessRuleException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info("Rolled back batch {$batch}: {$r['surveys']} surveys removed, {$r['needs_surveys']} needs surveys and {$r['evaluation_forms']} evaluation forms restored.");

            return self::SUCCESS;
        }

        $plan = $migrator->plan();
        $this->table(['What', 'Pending', 'Detail'], [
            ['Needs surveys', $plan['needs_surveys']['count'], "recipients {$plan['needs_surveys']['recipients']}, responses {$plan['needs_surveys']['responses']}"],
            ['Evaluation forms', $plan['evaluation_forms']['count'], "assignments {$plan['evaluation_forms']['assignments']}, responses {$plan['evaluation_forms']['responses']}, system forms skipped {$plan['evaluation_forms']['system_skipped']}"],
            ['Already migrated', $plan['migrated']['needs_surveys'] + $plan['migrated']['evaluation_forms'], "needs {$plan['migrated']['needs_surveys']}, forms {$plan['migrated']['evaluation_forms']}"],
        ]);
        foreach ($plan['warnings'] as $w) {
            $this->warn("{$w['type']} {$w['id']} ({$w['title']}): {$w['issue']}");
        }
        if ($this->option('dry-run')) {
            $this->line('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $only = in_array($this->option('only'), ['all', 'needs', 'evaluations'], true) ? $this->option('only') : 'all';
        $r = $migrator->run($only);
        $this->info("Batch {$r['batch']}: {$r['needs_surveys']} needs surveys and {$r['evaluation_forms']} evaluation forms migrated; {$r['participants']} participants, {$r['submissions']} submissions, {$r['files']} files.");
        foreach ($r['skipped'] as $s) {
            $this->warn("Skipped {$s['type']} {$s['id']}: {$s['reason']}");
        }
        $this->line("Roll back with: php artisan tedc:surveys:migrate --rollback={$r['batch']}");

        return self::SUCCESS;
    }
}
