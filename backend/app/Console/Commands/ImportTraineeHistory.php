<?php

namespace App\Console\Commands;

use App\Services\Import\TraineeHistoryImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:import-trainee-history {files* : One or more .xlsx extracts of employees and the courses they attended} {--dry-run : Check everything and write nothing} {--source= : Name recorded as the source of the data} {--report= : Write the report (counts and issue codes, no personal data) to this JSON file}')]
#[Description('Import the Ministry trainee extract: employees, their schools and job titles, and every course they attended')]
class ImportTraineeHistory extends Command
{
    public function handle(TraineeHistoryImport $import): int
    {
        ini_set('memory_limit', '2G');
        $report = $import->run((array) $this->argument('files'), (bool) $this->option('dry-run'), fn (string $m) => $this->line($m), $this->option('source') ?: null);

        $this->newLine();
        $this->info($report['dry_run'] ? 'DRY RUN — nothing was written.' : 'Import finished.');
        $this->table(['', ''], collect($report)->except(['issue_samples', 'files'])->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'yes' : 'NO') : (is_array($v) ? json_encode($v) : $v)])->values()->all());
        if ($report['issue_samples']) {
            $this->warn('First issues (no personal data):');
            $this->table(['code', 'employee no.', 'row', 'file'], array_map(fn ($i) => [$i['code'], $i['empno'] ?? '', $i['row'] ?? '', $i['file'] ?? ''], array_slice($report['issue_samples'], 0, 15)));
        }
        if ($this->option('report')) {
            file_put_contents((string) $this->option('report'), json_encode($report + ['unmapped_nationality_values' => $import->unmappedNationalities()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
            $this->line('Report written to '.$this->option('report'));
        }

        return $report['reconciled'] ? self::SUCCESS : self::FAILURE;
    }
}
