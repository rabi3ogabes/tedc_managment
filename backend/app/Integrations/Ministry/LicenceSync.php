<?php

namespace App\Integrations\Ministry;

use App\Integrations\IntegrationManager;
use App\Models\CareerPath;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\ProfessionalLicence;

/** Professional licences: read levels and validity from the licensing system, and tell it which certificates (training completions) were issued. */
class LicenceSync
{
    public function __construct(private readonly IntegrationManager $hub) {}

    /** @return array{created: int, updated: int, unmatched: int, pushed: int} */
    public function run(): array
    {
        $since = $this->hub->get('licences')->last_sync_at?->toIso8601String();
        $rows = $this->hub->call('licences', 'fetch_licences', function (array $s, $i) use ($since) {
            if ($i->driver === 'fake') {
                return (array) (is_string($s['fake_licences'] ?? null) ? json_decode($s['fake_licences'], true) : ($s['fake_licences'] ?? []));
            }

            return (array) ((new MinistryHttp($s))->get('/licences', array_filter(['since' => $since]))['data'] ?? []);
        });
        $out = ['created' => 0, 'updated' => 0, 'unmatched' => 0, 'pushed' => 0];
        foreach ($rows as $row) {
            $r = $this->apply((array) $row);
            $out[$r]++;
        }
        $out['pushed'] = $this->pushCompletions();
        $this->hub->markSynced('licences');

        return $out;
    }

    /** @param  array<string, mixed>  $r @return string created | updated | unmatched */
    public function apply(array $r): string
    {
        $employee = Employee::where('employee_no', (string) ($r['employee_no'] ?? ''))->first();
        $path = ! empty($r['path_id']) ? CareerPath::find($r['path_id']) : (! empty($r['path_title']) ? CareerPath::where('title_ar', $r['path_title'])->orWhere('title_en', $r['path_title'])->first() : null);
        if (! $employee || ! $path || empty($r['licence_no'])) {
            return 'unmatched';
        }
        $licence = ProfessionalLicence::firstOrNew(['employee_id' => $employee->id, 'path_id' => $path->id, 'licence_no' => (string) $r['licence_no']]);
        $new = ! $licence->exists;
        // Only what the licensing system sent is changed.
        $fields = ['source' => 'ministry', 'synced_at' => now()];
        if (isset($r['level_no'])) {
            $fields['level_no'] = (int) $r['level_no'];
        }
        foreach (['issued_at', 'expires_at'] as $col) {
            if (! empty($r[$col])) {
                $fields[$col] = $r[$col];
            }
        }
        if (isset($r['status'])) {
            $fields['status'] = in_array($r['status'], ['active', 'expired', 'suspended', 'revoked'], true) ? $r['status'] : 'active';
        }
        if ($new) {
            $fields += ['level_no' => 1, 'issued_at' => now()->toDateString(), 'status' => 'active'];
        }
        $licence->fill($fields)->save();

        return $new ? 'created' : 'updated';
    }

    /** @param  array<string, mixed>  $payload @return string processed | ignored */
    public function applyInbound(array $payload): string
    {
        if (($payload['type'] ?? '') !== 'licence.updated' || empty($payload['licence'])) {
            return 'ignored';
        }

        return $this->apply((array) $payload['licence']) === 'unmatched' ? 'ignored' : 'processed';
    }

    /** Certificates issued since the last push go to the licensing system. */
    public function pushCompletions(): int
    {
        $i = $this->hub->get('licences');
        $since = $i->settings()['last_push_at'] ?? null;
        $certs = Certificate::with('employee:id,employee_no', 'program:id,code')->where('status', 'valid')->when($since, fn ($q) => $q->where('issued_at', '>', $since))->limit(500)->get();
        if ($certs->isEmpty()) {
            return 0;
        }
        $payload = $certs->map(fn ($c) => ['employee_no' => $c->employee?->employee_no, 'program_code' => $c->program?->code, 'certificate_no' => $c->certificate_no, 'hours' => (float) $c->hours, 'issued_at' => $c->issued_at?->toDateString()])->all();
        $this->hub->call('licences', 'push_completions', function (array $s, $integration) use ($payload) {
            return $integration->driver === 'fake' ? ['accepted' => count($payload)] : (new MinistryHttp($s))->post('/completions', ['items' => $payload]);
        }, ['items' => $certs->count()]);
        $settings = $i->settings();
        $settings['last_push_at'] = $certs->max('issued_at')?->toDateTimeString();
        $i->putSettings($settings);
        $i->saveQuietly();

        return $certs->count();
    }
}
