<?php

namespace App\Services\Account;

use App\Integrations\Contracts\ContractGateway;
use App\Integrations\IntegrationManager;
use App\Integrations\Ministry\HrSync;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Str;
use Throwable;

/**
 * CAR-05: what HR masters is not written in TEDC. While an HR system is connected and wins, an approved change to one of those fields
 * is sent to HR through the contract (queued, retried), HR's answer comes back by signed webhook and decides the outcome, and the
 * employee file cannot be edited around HR. With HR off, or TEDC winning, or for any other field, everything works as before.
 */
class HrProfileRouting
{
    /** What an attempt to send is followed by when it fails: the minutes to wait. After the last one the request is given up and the administrators are told. */
    public const RETRY_MINUTES = [15, 60, 360, 720, 1440];

    /** What HR masters unless the settings say otherwise: name, job title, grade, qualification and experience dates. */
    public const DEFAULT_FIELDS = ['name_ar', 'name_en', 'job_title', 'grade_level', 'qualification', 'specialization', 'hire_date', 'experience_years'];

    /**
     * Each field HR can master: where it lives in the request of the employee file (payload), and in the data (source, column or relation).
     *
     * @var array<string, array{payload: string, source: string, column: string}>
     */
    public const FIELDS = [
        'name_ar' => ['payload' => 'name_ar', 'source' => 'user', 'column' => 'name_ar'],
        'name_en' => ['payload' => 'name', 'source' => 'user', 'column' => 'name'],
        'job_title' => ['payload' => 'job_title_id', 'source' => 'employee', 'column' => 'job_title_id'],
        'school' => ['payload' => 'school_id', 'source' => 'employee', 'column' => 'school_id'],
        'department' => ['payload' => 'department_id', 'source' => 'employee', 'column' => 'department_id'],
        'grade_level' => ['payload' => 'grade_level', 'source' => 'employee', 'column' => 'grade_level'],
        'education_stage' => ['payload' => 'education_stage', 'source' => 'employee', 'column' => 'education_stage'],
        'qualification' => ['payload' => 'qualification', 'source' => 'employee', 'column' => 'qualification'],
        'specialization' => ['payload' => 'specialization', 'source' => 'employee', 'column' => 'specialization'],
        'hire_date' => ['payload' => 'hire_date', 'source' => 'employee', 'column' => 'hire_date'],
        'experience_years' => ['payload' => 'experience_years', 'source' => 'employee', 'column' => 'experience_years'],
    ];

    /** @var array{system: ?string, fields: list<string>}|null */
    private ?array $state = null;

    public function __construct(private readonly IntegrationManager $hub, private readonly ContractGateway $contracts, private readonly NotificationService $notifications) {}

    /** The HR system that masters these fields right now: connected, routing on, and HR wins. */
    public function system(): ?string
    {
        return $this->resolve()['system'];
    }

    /** @return list<string> the fields HR masters now (empty when nothing is routed) */
    public function masteredFields(): array
    {
        return $this->resolve()['fields'];
    }

    public function masters(string $field): bool
    {
        return in_array($field, $this->resolve()['fields'], true);
    }

    /** The explanation shown next to a field HR masters. */
    public static function note(string $locale): string
    {
        return __('messages.hr.locked_note', [], $locale);
    }

    /**
     * Which of the changes asked for in an employee file edit are to something HR masters (and so are refused).
     *
     * @param  array<string, mixed>  $data  the validated payload of the edit
     * @return list<string> the fields, by their account names
     */
    public function lockedChanges(Employee $employee, array $data): array
    {
        $locked = [];
        foreach ($this->masteredFields() as $field) {
            $spec = self::FIELDS[$field];
            if (! array_key_exists($spec['payload'], $data)) {
                continue;
            }
            $current = $spec['source'] === 'user' ? $employee->user?->{$spec['column']} : $employee->{$spec['column']};
            if ($this->normal($current) !== $this->normal($data[$spec['payload']])) {
                $locked[] = $field;
            }
        }

        return $locked;
    }

    /** Sends an approved change to HR (it was just approved: nothing is written in TEDC). */
    public function route(ProfileChangeRequest $r): void
    {
        $r->update(['hr_system' => $this->system(), 'hr_status' => 'queued', 'hr_attempts' => 0, 'hr_next_attempt_at' => now(), 'hr_reason' => null, 'hr_last_error' => null, 'hr_reference' => null]);
        $this->send($r);
    }

    /** One attempt to send. It never throws: a failure is kept on the request and tried again later. */
    public function send(ProfileChangeRequest $r): void
    {
        if (! in_array($r->hr_status, ['queued', 'failed'], true)) {
            return;
        }
        $key = $r->hr_system ?: $this->system();
        try {
            if (! $key) {
                throw new \RuntimeException('The HR system is not connected.');
            }
            $payload = $this->payload($r);
            $answer = $this->hub->call($key, 'send_profile_change', function (array $s, $integration) use ($r, $key, $payload) {
                if ($integration->driver === 'fake') {
                    return ['reference' => 'FAKE-'.strtoupper(substr(md5($r->id), 0, 8)), 'status' => 'received'];
                }
                $response = $this->contracts->call($key, 'send_profile_change', $payload, $s, ['idempotency_key' => 'tedc-pc-'.$r->id]);   // every attempt for this request carries the same key

                return ['reference' => $response->value('hr_reference'), 'status' => $response->value('hr_status')];
            }, ['request' => $r->id, 'field' => $r->field]);
            $r->update(['hr_system' => $key, 'hr_status' => 'sent', 'hr_reference' => ($ref = mb_substr((string) ($answer['reference'] ?? ''), 0, 120)) !== '' ? $ref : null, 'hr_sent_at' => now(),
                'hr_attempts' => $r->hr_attempts + 1, 'hr_next_attempt_at' => null, 'hr_last_error' => null]);
        } catch (Throwable $e) {
            $attempts = $r->hr_attempts + 1;
            $final = $attempts > count(self::RETRY_MINUTES);
            $r->update(['hr_attempts' => $attempts, 'hr_last_error' => mb_substr($e->getMessage(), 0, 300), 'hr_status' => $final ? 'failed' : 'queued',
                'hr_next_attempt_at' => $final ? null : now()->addMinutes(self::RETRY_MINUTES[$attempts - 1])]);
            if ($final) {
                $this->tellAdministrators($r);
            }
        }
    }

    /** Sends the ones that waited and are due. @return array{sent: int, waiting: int} */
    public function tick(): array
    {
        $out = ['sent' => 0, 'waiting' => 0];
        ProfileChangeRequest::where('hr_status', 'queued')->where(fn ($q) => $q->whereNull('hr_next_attempt_at')->orWhere('hr_next_attempt_at', '<=', now()))
            ->orderBy('created_at')->limit(30)->get()->each(function (ProfileChangeRequest $r) use (&$out) {
                $this->send($r);
                $out[$r->fresh()->hr_status === 'sent' ? 'sent' : 'waiting']++;
            });

        return $out;
    }

    /** An administrator sends a request again (after it was given up, or without waiting for the next attempt). */
    public function resend(ProfileChangeRequest $r): ProfileChangeRequest
    {
        abort_unless(in_array($r->hr_status, ['queued', 'failed'], true), 422);
        $r->update(['hr_status' => 'queued', 'hr_attempts' => 0, 'hr_last_error' => null, 'hr_next_attempt_at' => now()]);
        $this->send($r);

        return $r->fresh();
    }

    /**
     * HR's answer (profile_change.result): accepted, or refused with a reason. Only a request still waiting for it is touched.
     *
     * @param  array<string, mixed>  $payload
     * @return string processed | ignored
     */
    public function applyInbound(string $source, array $payload): string
    {
        $id = (string) ($payload['reference'] ?? '');
        $r = Str::isUuid($id) ? ProfileChangeRequest::with('user.employee')->find($id) : null;
        if (! $r || ! in_array($r->hr_status, ['queued', 'sent'], true)) {
            return 'ignored';   // not ours, or already settled: a later message does not turn it around
        }
        $status = strtolower((string) ($payload['status'] ?? ''));
        $reference = mb_substr((string) ($payload['hr_reference'] ?? ''), 0, 120) ?: $r->hr_reference;

        if (in_array($status, ['accepted', 'approved', 'applied'], true)) {
            $applied = false;
            $row = (array) ($payload['employee'] ?? []);
            if ($row !== [] && ($employeeNo = $r->user?->employee?->employee_no) && ($row['employee_no'] ?? null) === $employeeNo) {
                app(HrSync::class)->applyEmployee($row, 'hr_wins');   // the record HR holds now
                $applied = true;
            }
            $r->update(['hr_status' => 'accepted', 'hr_resolved_at' => now(), 'hr_reference' => $reference, 'applied' => $applied, 'hr_next_attempt_at' => null]);
            $this->tell($r, 'profile.hr_accepted');

            return 'processed';
        }
        if (in_array($status, ['rejected', 'refused', 'declined'], true)) {
            $reason = mb_substr(trim((string) ($payload['reason'] ?? '')), 0, 500);
            $r->update(['status' => 'rejected', 'hr_status' => 'rejected', 'hr_resolved_at' => now(), 'hr_reference' => $reference, 'hr_reason' => $reason !== '' ? $reason : null, 'hr_next_attempt_at' => null,
                'review_note' => mb_substr(trim(($r->review_note ? $r->review_note.' · ' : '').'HR: '.($reason !== '' ? $reason : 'refused')), 0, 500)]);
            $this->tell($r, 'profile.hr_rejected');

            return 'processed';
        }

        return 'ignored';
    }

    // ---- internals ------------------------------------------------------------------------------------

    /** @return array{system: ?string, fields: list<string>} */
    private function resolve(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }
        $none = ['system' => null, 'fields' => []];
        $key = null;
        foreach (['mawared', 'hr'] as $candidate) {
            if ($this->hub->isReady($candidate)) {
                $key = $candidate;
                break;
            }
        }
        if ($key === null) {
            return $this->state = $none;
        }
        $s = $this->hub->settings($key);
        $routing = filter_var($s['route_profile_changes'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        if (! $routing || ($s['conflict_policy'] ?? 'hr_wins') === 'tedc_wins') {
            return $this->state = $none;
        }
        $configured = $s['hr_mastered_fields'] ?? self::DEFAULT_FIELDS;
        $configured = is_string($configured) ? (json_decode($configured, true) ?: []) : (array) $configured;

        return $this->state = ['system' => $key, 'fields' => array_values(array_intersect(array_map('strval', $configured), array_keys(self::FIELDS)))];
    }

    /** @return array<string, mixed> what HR is told about the change */
    private function payload(ProfileChangeRequest $r): array
    {
        $user = $r->user;
        $employee = $user?->employee;
        $field = AccountFields::field($r->field);
        $from = $user ? AccountFields::read($r->field, $user, $employee, 'en')['value'] : null;

        return [
            'reference' => $r->id, 'employee_no' => $employee?->employee_no, 'requested_at' => $r->created_at?->toIso8601String(), 'approved_at' => $r->reviewed_at?->toIso8601String(),
            'changes' => [['field' => $r->field, 'label' => $field['label']['en'] ?? $r->field, 'from' => $from, 'to' => $r->requested_value]],
            'note' => (string) $r->note, 'approval_note' => (string) $r->review_note,
        ];
    }

    private function normal(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value)) {
            return (string) (float) $value;
        }

        return trim((string) ($value ?? ''));
    }

    private function tell(ProfileChangeRequest $r, string $event): void
    {
        $f = AccountFields::field($r->field);
        $label = $f['label'] ?? ['ar' => $r->field, 'en' => $r->field];
        $ok = $event === 'profile.hr_accepted';
        $reason = $ok ? '' : (string) $r->hr_reason;
        $this->notifications->send(
            $r->user_id, $event,
            $ok ? ['ar' => 'قبلت الموارد البشرية طلب تعديل بياناتك', 'en' => 'HR accepted your change request'] : ['ar' => 'لم توافق الموارد البشرية على طلب التعديل', 'en' => 'HR did not accept your change request'],
            ['ar' => "طلب تعديل «{$label['ar']}»".($reason !== '' ? " — {$reason}" : ($ok ? ' — سيظهر في ملفك' : '')), 'en' => "Request to change \"{$label['en']}\"".($reason !== '' ? " — {$reason}" : ($ok ? ' — it will show on your file' : ''))],
            ['route' => '/account'],
        );
    }

    private function tellAdministrators(ProfileChangeRequest $r): void
    {
        $admins = User::where('status', 'active')->whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::SUPER_ADMIN, Role::CENTER_ADMIN]))->pluck('id');
        $name = $r->user?->displayName() ?? '';
        $this->notifications->broadcast($admins, 'profile.hr_failed',
            ['ar' => 'تعذّر إرسال طلب تعديل بيانات إلى الموارد البشرية', 'en' => 'A profile change could not be sent to HR'],
            ['ar' => "{$name} — أُوقف الإرسال بعد محاولات متكررة: ".mb_substr((string) $r->hr_last_error, 0, 120), 'en' => "{$name} — sending stopped after repeated attempts: ".mb_substr((string) $r->hr_last_error, 0, 120)],
            ['request_id' => $r->id, 'route' => '/admin/profile-requests'], raw: true);
    }
}
