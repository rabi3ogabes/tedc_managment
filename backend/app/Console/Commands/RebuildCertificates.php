<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Services\CertificateService;
use App\Services\FileStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tedc:rebuild-certificates {--program= : Only certificates of this program id}')]
#[Description('Re-render the stored certificate PDFs with the current design and center name')]
class RebuildCertificates extends Command
{
    public function handle(CertificateService $service, FileStorage $storage): int
    {
        $rebuilt = 0;
        $failed = 0;

        Certificate::with(['employee.user', 'employee.school', 'program'])
            ->when($this->option('program'), fn ($q, $id) => $q->where('program_id', $id))
            ->orderBy('issued_at')
            ->each(function (Certificate $certificate) use ($service, $storage, &$rebuilt, &$failed) {
                try {
                    $path = $storage->put('certificates', "{$certificate->program_id}/{$certificate->certificate_no}.pdf", $service->render($certificate), 'application/pdf');
                    $certificate->update(['file_path' => $path]);
                    $rebuilt++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("{$certificate->certificate_no}: {$e->getMessage()}");
                }
            });

        $this->info("Rebuilt {$rebuilt} certificate(s), {$failed} failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
