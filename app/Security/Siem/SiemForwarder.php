<?php

namespace App\Security\Siem;

use App\Integrations\IntegrationManager;
use App\Models\SiemOutbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Audit records, security events and integration failures leave the platform for the SIEM (Microsoft Sentinel through the Logs Ingestion API, or Splunk HEC).
 * Events are written to an outbox first, so an unreachable SIEM never slows or breaks a request, and sent in batches; what could not be sent is retried.
 */
class SiemForwarder
{
    /** @var list<array<string, mixed>> what the training driver "received" (tests read this) */
    public static array $received = [];

    public function __construct(private readonly IntegrationManager $hub) {}

    /** True when a SIEM is switched on (cached for a few seconds: this is asked on every audit write). */
    public function active(): bool
    {
        return Cache::remember('siem.active', 15, fn () => $this->hub->isReady('siem'));
    }

    /** @param  array<string, mixed>  $payload */
    public function push(string $kind, array $payload): void
    {
        if (! $this->active()) {
            return;
        }
        try {
            SiemOutbox::create(['kind' => $kind, 'payload' => $payload + ['timestamp' => now()->toIso8601String(), 'platform' => (string) config('app.name')], 'created_at' => now()]);
        } catch (Throwable) {
            // never fail the action that was being recorded
        }
    }

    /** Sends waiting events. @return array{sent: int, failed: int} */
    public function flush(int $limit = 500): array
    {
        if (! $this->active()) {
            return ['sent' => 0, 'failed' => 0];
        }
        $rows = SiemOutbox::whereNull('sent_at')->where('attempts', '<', 8)->orderBy('created_at')->limit($limit)->get();
        if ($rows->isEmpty()) {
            return ['sent' => 0, 'failed' => 0];
        }
        $sent = $failed = 0;
        foreach ($rows->chunk(100) as $batch) {
            $events = $batch->map(fn (SiemOutbox $r) => ['kind' => $r->kind] + $r->payload)->values()->all();
            try {
                $this->hub->call('siem', 'send', fn (array $s, $i) => $this->deliver($i->driver, $s, $events), ['events' => count($events)], 1);
                SiemOutbox::whereIn('id', $batch->pluck('id'))->update(['sent_at' => now()]);
                $sent += $batch->count();
            } catch (Throwable $e) {
                SiemOutbox::whereIn('id', $batch->pluck('id'))->increment('attempts', 1, ['last_error' => mb_substr($e->getMessage(), 0, 240)]);
                $failed += $batch->count();
            }
        }
        SiemOutbox::whereNotNull('sent_at')->where('sent_at', '<', now()->subDays(7))->delete();

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** @param  array<string, mixed>  $s  @param  list<array<string, mixed>>  $events */
    private function deliver(string $driver, array $s, array $events): void
    {
        match ($driver) {
            'splunk_hec' => $this->splunk($s, $events),
            'log_analytics' => $this->logAnalytics($s, $events),
            default => self::$received = array_merge(self::$received, $events),
        };
    }

    /** Splunk HTTP Event Collector: newline-delimited JSON events with the HEC token. */
    private function splunk(array $s, array $events): void
    {
        $host = gethostname() ?: 'tedc';
        $body = implode("\n", array_map(fn ($e) => json_encode(['time' => strtotime($e['timestamp'] ?? 'now'), 'host' => $host, 'source' => 'tedc', 'sourcetype' => (string) ($s['sourcetype'] ?? '_json'), 'index' => $s['index'] ?? null, 'event' => $e], JSON_UNESCAPED_UNICODE), $events));
        Http::timeout(20)->withHeaders(['Authorization' => 'Splunk '.($s['token'] ?? '')])->withBody($body, 'application/json')->post(rtrim((string) ($s['url'] ?? ''), '/').'/services/collector/event')->throw();
    }

    /** Microsoft Sentinel / Azure Monitor Logs Ingestion API: an Entra token for the monitor scope, then the rows to the data collection rule's stream. */
    private function logAnalytics(array $s, array $events): void
    {
        $token = Cache::remember('siem.la-token', 3000, function () use ($s) {
            return Http::asForm()->post('https://login.microsoftonline.com/'.rawurlencode((string) ($s['tenant_id'] ?? '')).'/oauth2/v2.0/token', [
                'client_id' => $s['client_id'] ?? '', 'client_secret' => $s['client_secret'] ?? '', 'scope' => 'https://monitor.azure.com/.default', 'grant_type' => 'client_credentials',
            ])->throw()->json('access_token');
        });
        $rows = array_map(fn ($e) => ['TimeGenerated' => $e['timestamp'] ?? now()->toIso8601String(), 'Kind' => $e['kind'], 'RawData' => json_encode($e, JSON_UNESCAPED_UNICODE)], $events);
        $url = rtrim((string) ($s['dce_endpoint'] ?? ''), '/').'/dataCollectionRules/'.rawurlencode((string) ($s['dcr_id'] ?? '')).'/streams/'.rawurlencode((string) ($s['stream_name'] ?? 'Custom-TedcSecurity_CL')).'?api-version=2023-01-01';
        Http::timeout(30)->withToken((string) $token)->post($url, $rows)->throw();
    }
}
