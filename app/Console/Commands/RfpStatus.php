<?php

namespace App\Console\Commands;

use App\Services\RfpCompliance;
use App\Services\RfpRegister;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:rfp-status {--source= : Path of the register (default docs/rfp/gap-register.md)}')]
#[Description('Rebuild the RFP compliance status file and the compliance sheet from docs/rfp/gap-register.md')]
class RfpStatus extends Command
{
    public function handle(RfpRegister $register, RfpCompliance $sheet): int
    {
        $source = $this->option('source') ?: base_path('../docs/rfp/gap-register.md');
        if (! is_file($source)) {
            $this->error("Register not found: {$source}");

            return self::FAILURE;
        }

        $data = $register->build($source);
        file_put_contents(resource_path('rfp/status.json'), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $docs = dirname($source);
        file_put_contents($docs.'/compliance-sheet.md', $sheet->markdown($data));

        $t = $data['totals'];
        $this->info("{$t['total']} requirements: {$t['available']} available, {$t['partial']} partial, {$t['missing']} missing ({$t['open']} open).");

        return self::SUCCESS;
    }
}
