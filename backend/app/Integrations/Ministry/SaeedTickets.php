<?php

namespace App\Integrations\Ministry;

use App\Integrations\IntegrationManager;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\FileStorage;
use App\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * "Report a problem": the person describes it in the portal (the page and context are captured for them), the ticket goes to Saaed, and
 * its number and status come back to their notifications. If Saaed cannot be reached the ticket waits and is sent again.
 */
class SaeedTickets
{
    public const CATEGORIES = ['bug', 'access', 'data', 'request', 'other'];

    public function __construct(private readonly IntegrationManager $hub, private readonly NotificationService $notifications, private readonly FileStorage $storage) {}

    /** @param  array{category: string, priority?: ?string, subject: string, description: string, page_url?: ?string, context?: ?array<string, mixed>}  $d */
    public function create(User $user, array $d, ?UploadedFile $screenshot = null): SupportTicket
    {
        $ticket = SupportTicket::create([
            'user_id' => $user->id, 'category' => $d['category'], 'priority' => $d['priority'] ?? 'normal', 'subject' => $d['subject'], 'description' => $d['description'], 'page_url' => $d['page_url'] ?? null,
            'context' => $this->context($user, $d['context'] ?? []), 'status' => 'queued',
        ]);
        if ($screenshot) {
            $ticket->update(['screenshot_path' => $this->storage->upload($screenshot, 'documents', 'tickets/'.$ticket->id)]);
        }
        $this->send($ticket);

        return $ticket->refresh();
    }

    /** Sends a queued ticket to Saaed. Without Saaed switched on the ticket stays with the administrators ("open"). */
    public function send(SupportTicket $t): void
    {
        if (! $this->hub->isReady('saaed')) {
            $t->update(['status' => 'open', 'last_error' => null]);

            return;
        }
        $user = $t->user;
        $map = $this->hub->settings('saaed')['category_map'] ?? [];
        $map = is_string($map) ? (json_decode($map, true) ?: []) : (array) $map;
        $payload = [
            'reference' => $t->id, 'subject' => $t->subject, 'description' => $t->description, 'category' => $map[$t->category] ?? $t->category, 'priority' => $t->priority, 'page_url' => $t->page_url, 'context' => $t->context,
            'reporter' => ['name' => $user?->displayName(), 'email' => $user?->email, 'employee_no' => $user?->employee?->employee_no],
            'screenshot' => $t->screenshot_path ? $this->screenshot($t) : null,
        ];
        try {
            $res = $this->hub->call('saaed', 'create_ticket', fn (array $s, $i) => $i->driver === 'fake' ? ['ticket_no' => 'SAEED-'.strtoupper(substr(md5($t->id), 0, 6)), 'status' => 'open'] : (new MinistryHttp($s))->post('/tickets', $payload), ['ticket' => $t->id, 'category' => $t->category]);
            $t->update(['saaed_ticket_no' => (string) ($res['ticket_no'] ?? $res['id'] ?? ''), 'saaed_status' => $res['status'] ?? 'open', 'status' => 'open', 'attempts' => $t->attempts + 1, 'last_error' => null, 'last_synced_at' => now()]);
            if ($user) {
                $this->notifications->send($user, 'ticket.created', ['ar' => 'استلمنا بلاغك', 'en' => 'We received your report'], ['ar' => 'رقم التذكرة: '.$t->saaed_ticket_no, 'en' => 'Ticket number: '.$t->saaed_ticket_no], ['route' => '/support'], raw: true);
            }
        } catch (Throwable $e) {
            $attempts = $t->attempts + 1;
            $t->update(['attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 300), 'status' => $attempts >= 6 ? 'failed' : 'queued']);
        }
    }

    /** Sends the ones that waited and refreshes the status of open ones. @return array{sent: int, updated: int} */
    public function tick(): array
    {
        $out = ['sent' => 0, 'updated' => 0];
        SupportTicket::where('status', 'queued')->where('attempts', '<', 6)->orderBy('created_at')->limit(30)->get()->each(function (SupportTicket $t) use (&$out) {
            $this->send($t);
            $out['sent'] += $t->fresh()->status === 'open' ? 1 : 0;
        });
        if ($this->hub->isReady('saaed') && $this->hub->get('saaed')->driver !== 'fake') {
            SupportTicket::whereIn('status', ['open', 'in_progress'])->whereNotNull('saaed_ticket_no')->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes(30)))->limit(30)->get()->each(function (SupportTicket $t) use (&$out) {
                try {
                    $r = $this->hub->call('saaed', 'ticket_status', fn (array $s) => (new MinistryHttp($s))->get('/tickets/'.rawurlencode((string) $t->saaed_ticket_no)), ['ticket' => $t->id], 0);
                    $this->setStatus($t, (string) ($r['status'] ?? ''), $r['comment'] ?? null);
                    $out['updated']++;
                } catch (Throwable) {
                    $t->update(['last_synced_at' => now()]);
                }
            });
        }

        return $out;
    }

    /** @param  array<string, mixed>  $payload @return string processed | ignored */
    public function applyInbound(array $payload): string
    {
        $no = (string) ($payload['ticket_no'] ?? '');
        $t = $no !== '' ? SupportTicket::where('saaed_ticket_no', $no)->first() : null;
        if (($payload['type'] ?? '') !== 'ticket.updated' || ! $t) {
            return 'ignored';
        }
        $this->setStatus($t, (string) ($payload['status'] ?? ''), $payload['comment'] ?? null);

        return 'processed';
    }

    private function setStatus(SupportTicket $t, string $saaedStatus, ?string $comment): void
    {
        $status = match (strtolower($saaedStatus)) {
            'resolved', 'solved', 'done' => 'resolved', 'closed' => 'closed', 'in_progress', 'inprogress', 'working', 'assigned' => 'in_progress', default => $t->status,
        };
        $changed = $status !== $t->status || strtolower($saaedStatus) !== strtolower((string) $t->saaed_status);
        $t->update(['saaed_status' => $saaedStatus ?: $t->saaed_status, 'status' => $status, 'last_synced_at' => now()]);
        if ($changed && $t->user) {
            $label = ['ar' => ['resolved' => 'تم حل البلاغ', 'closed' => 'أُغلق البلاغ', 'in_progress' => 'البلاغ قيد المعالجة'], 'en' => ['resolved' => 'Your report was resolved', 'closed' => 'Your report was closed', 'in_progress' => 'Your report is being handled']];
            $this->notifications->send($t->user, 'ticket.updated', ['ar' => $label['ar'][$status] ?? 'تحديث على بلاغك', 'en' => $label['en'][$status] ?? 'Update on your report'], ['ar' => $t->saaed_ticket_no.($comment ? ' — '.$comment : ''), 'en' => $t->saaed_ticket_no.($comment ? ' — '.$comment : '')], ['route' => '/support'], raw: true);
        }
    }

    /** @return array<string, mixed> */
    private function context(User $user, array $given): array
    {
        return array_filter([
            'role' => $user->roles->sortByDesc('level')->first()?->slug, 'locale' => $user->locale, 'app_version' => $given['app_version'] ?? null, 'platform' => $given['platform'] ?? null, 'browser' => mb_substr((string) ($given['browser'] ?? request()->userAgent()), 0, 200),
            'viewport' => $given['viewport'] ?? null, 'at' => now()->toIso8601String(),
        ]);
    }

    /** The screenshot as a small base64 block for Saaed (large files are not sent inline). */
    private function screenshot(SupportTicket $t): ?array
    {
        try {
            $bytes = $this->storage->get('documents', (string) $t->screenshot_path);

            return strlen($bytes) <= 2_000_000 ? ['name' => basename((string) $t->screenshot_path), 'content_base64' => base64_encode($bytes)] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
