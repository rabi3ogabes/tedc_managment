<?php

namespace App\Console\Commands;

use App\Deliverables\DeliverablesBuilder;
use App\Services\RfpRegister;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:deliverables {--format=md : md (generated documents only), docx, pdf or all} {--out= : Folder for the exported files (default docs/deliverables/export)} {--results= : JSON written by php artisan test, recorded in the test report}')]
#[Description('Generate the BRD, traceability matrix and test report, and export the deliverables pack to Word and PDF')]
class DeliverablesCommand extends Command
{
    public function handle(DeliverablesBuilder $b, RfpRegister $register): int
    {
        $root = base_path('../docs/deliverables');
        $register = $register->build(base_path('../docs/rfp/gap-register.md'));
        $results = null;
        if ($file = $this->option('results')) {
            $results = json_decode((string) @file_get_contents($file), true);
            if (! is_array($results) || ! isset($results['tests'], $results['passed'])) {
                $this->error('The results file is not the JSON written by php artisan test.');

                return self::FAILURE;
            }
        }
        file_put_contents("{$root}/brd.generated.md", $b->brd($register));
        file_put_contents("{$root}/traceability.generated.md", $b->traceability($register));
        file_put_contents("{$root}/test-report.generated.md", $b->testReport($register, $results));
        $this->info('Generated brd, traceability and test report in docs/deliverables.');

        $format = (string) $this->option('format');
        if (! in_array($format, ['md', 'docx', 'pdf', 'all'], true)) {
            $this->error('Format must be md, docx, pdf or all.');

            return self::FAILURE;
        }
        if ($format === 'md') {
            return self::SUCCESS;
        }
        $out = rtrim((string) ($this->option('out') ?: "{$root}/export"), '/');
        @mkdir($out, 0775, true);
        $files = array_merge(glob("{$root}/*.md") ?: [], glob("{$root}/uat/*.md") ?: [], [base_path('../docs/rfp/compliance-sheet.md')]);
        $n = 0;
        foreach ($files as $f) {
            if (basename($f) === 'README.md' || ! is_file($f)) {
                continue;
            }
            $md = (string) file_get_contents($f);
            $name = pathinfo($f, PATHINFO_FILENAME);
            if (in_array($format, ['docx', 'all'], true)) {
                file_put_contents("{$out}/{$name}.docx", $b->docx($md));
                $n++;
            }
            if (in_array($format, ['pdf', 'all'], true)) {
                file_put_contents("{$out}/{$name}.pdf", $b->pdf($md, $name));
                $n++;
            }
        }
        $this->info("Wrote {$n} file(s) to {$out}.");

        return self::SUCCESS;
    }
}
