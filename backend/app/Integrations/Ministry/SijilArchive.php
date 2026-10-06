<?php

namespace App\Integrations\Ministry;

use App\Integrations\IntegrationManager;
use App\Models\ArchiveItem;
use App\Models\Certificate;
use App\Services\CertificateService;
use Throwable;

/**
 * Sijil, the Ministry's central archive: certificates (and later program records) are queued when they are issued, then sent with metadata and an archive
 * reference is stored. A failed send is retried; nothing is lost if the archive is down.
 */
class SijilArchive
{
    public function __construct(private readonly IntegrationManager $hub, private readonly CertificateService $certificates) {}

    /** Puts a certificate in the queue (called when it is issued). */
    public function queueCertificate(Certificate $c): ?ArchiveItem
    {
        if (! $this->hub->isReady('sijil') || ! ($this->hub->settings('sijil')['archive_certificates'] ?? true)) {
            return null;
        }

        return ArchiveItem::firstOrCreate(['kind' => 'certificate', 'subject_id' => $c->id], ['status' => 'pending']);
    }

    /** Sends what is waiting (and queues certificates that were never queued). @return array{archived: int, failed: int} */
    public function run(int $limit = 50): array
    {
        if ($this->hub->isReady('sijil') && ($this->hub->settings('sijil')['archive_certificates'] ?? true)) {
            Certificate::where('status', 'valid')->whereNotIn('id', ArchiveItem::where('kind', 'certificate')->select('subject_id'))->orderBy('issued_at')->limit($limit)->get()->each(fn ($c) => $this->queueCertificate($c));
        }
        $out = ['archived' => 0, 'failed' => 0];
        ArchiveItem::where('status', 'pending')->where('attempts', '<', 5)->orderBy('created_at')->limit($limit)->get()->each(function (ArchiveItem $item) use (&$out) {
            $item->attempts++;
            try {
                $ref = $item->kind === 'certificate' ? $this->sendCertificate($item) : null;
                if ($ref === null) {
                    $item->update(['status' => 'failed', 'error' => 'nothing to send', 'attempts' => $item->attempts]);
                    $out['failed']++;

                    return;
                }
                $item->update(['status' => 'archived', 'sijil_ref' => $ref, 'archived_at' => now(), 'error' => null, 'attempts' => $item->attempts]);
                $out['archived']++;
            } catch (Throwable $e) {
                $item->update(['error' => mb_substr($e->getMessage(), 0, 300), 'attempts' => $item->attempts, 'status' => $item->attempts >= 5 ? 'failed' : 'pending']);
                $out['failed'] += $item->attempts >= 5 ? 1 : 0;
            }
        });
        $this->hub->markSynced('sijil');

        return $out;
    }

    private function sendCertificate(ArchiveItem $item): ?string
    {
        $c = Certificate::with('employee.user', 'program')->find($item->subject_id);
        if (! $c || $c->status !== 'valid') {
            return null;
        }
        $pdf = $this->certificates->pdf($c);
        $meta = ['type' => 'certificate', 'certificate_no' => $c->certificate_no, 'verification_code' => $c->verification_code, 'employee_no' => $c->employee?->employee_no, 'program_code' => $c->program?->code, 'program' => $c->program?->title_ar, 'hours' => (float) $c->hours, 'issued_at' => $c->issued_at?->toDateString()];
        $res = $this->hub->call('sijil', 'archive_certificate', fn (array $s, $i) => $i->driver === 'fake' ? ['reference' => 'SIJIL-'.strtoupper(substr(md5($c->id), 0, 8))] : (new MinistryHttp($s))->post('/archive', ['metadata' => $meta, 'file' => ['name' => $c->certificate_no.'.pdf', 'mime' => 'application/pdf', 'content_base64' => base64_encode($pdf)]]), ['certificate' => $c->certificate_no]);

        return (string) ($res['reference'] ?? $res['id'] ?? '') ?: null;
    }
}
