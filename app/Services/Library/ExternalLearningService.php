<?php

namespace App\Services\Library;

use App\Exceptions\BusinessRuleException;
use App\Integrations\IntegrationManager;
use App\Integrations\IntegrationRegistry;
use App\Models\Employee;
use App\Models\ExternalCompletion;
use App\Models\ExternalCompletionIssue;
use App\Models\ExternalCourse;
use App\Models\Program;
use App\Models\Registration;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\EvaluationService;
use App\Services\Library\Providers\ProviderNormaliser;
use App\Services\Library\Providers\ProviderSync;
use App\Services\NotificationService;
use Illuminate\Support\Str;

/**
 * Courses on other platforms. The providers (Coursera for Business, Udemy Business, LinkedIn Learning, Open edX) are reached through their
 * contracts in the integration hub, each following the provider's own published API; the training mode gives sample courses. Programs on
 * other platforms (I-earn, cyber-security platforms …) are completed by evidence the centre reviews. Either way a completion counts hours in the record.
 */
class ExternalLearningService
{
    public const PROVIDERS = ['coursera', 'edx', 'udemy', 'linkedin_learning'];

    /** What happened to a completion a provider reported. */
    public const COMPLETED = 'completed';

    public const ALREADY_COMPLETED = 'already_completed';

    public const NOT_COMPLETED = 'not_completed';

    public const UNKNOWN_COURSE = 'unknown_course';       // not a course TEDC offers: the provider reports everything its account does

    public const NO_PROGRAMME = 'course_without_programme';

    public function __construct(private readonly CertificateService $certificates, private readonly EvaluationService $evaluations, private readonly NotificationService $notifications) {}

    /**
     * Reads the providers that are switched on (the catalogue, then the completions; with $catalogue false, only the completions). One failing never stops another.
     *
     * @return array{courses: int, completed: int, errors: list<string>, providers: array<string, array<string, mixed>>}
     */
    public function sync(?string $only = null, bool $full = false, string $trigger = 'schedule', ?User $by = null, bool $catalogue = true): array
    {
        return app(ProviderSync::class)->run($only, $full, $trigger, $by, $catalogue);
    }

    /** The settings a provider cannot work without that are not filled in yet. @return list<string> */
    public function missing(string $provider): array
    {
        $settings = app(IntegrationManager::class)->settings($provider);
        $missing = [];
        foreach ((array) (IntegrationRegistry::all()[$provider]['fields'] ?? []) as $field) {
            if (empty($field['required']) || filled($settings[$field['k']] ?? null)) {
                continue;
            }
            if (isset($field['unless']) && filled($settings[$field['unless']] ?? null)) {
                continue;   // an address set by hand stands in for the name it would be made from
            }
            $missing[] = $field['k'];
        }

        return $missing;
    }

    /** Creates a program from an external course (type external platform) so trainees can register in TEDC and be sent to the provider. */
    public function programFromCourse(ExternalCourse $c, User $by): Program
    {
        if ($c->program_id) {
            return Program::findOrFail($c->program_id);
        }
        $program = Program::create(['code' => 'EXT-'.strtoupper(Str::random(6)), 'title_ar' => $c->title, 'title_en' => $c->title, 'total_hours' => max(1, $c->hours), 'capacity' => 1000, 'status' => Program::STATUS_REGISTRATION_OPEN, 'delivery_mode' => 'online',
            'start_date' => today(), 'end_date' => today()->addYear(), 'registration_modes' => Program::MODES, 'min_attendance_percent' => 0, 'requires_tasks' => false, 'requires_evaluation' => false,
            'external_platform' => ['name' => ucfirst(str_replace('_', ' ', $c->provider)), 'provider' => $c->provider, 'course_id' => $c->external_id, 'url' => $c->url]]);
        $c->update(['program_id' => $program->id]);

        return $program;
    }

    /** The redirect for a registered trainee (the provider's SSO link when it has one, else the course page). */
    public function launchUrl(Registration $r): string
    {
        $p = $r->program;
        abort_unless($p->external_platform, 422);
        $url = (string) ($p->external_platform['url'] ?? '');
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url.$sep.'ref='.rawurlencode((string) ($r->employee->employee_no ?? $r->id));
    }

    /** A provider reports a completion: the registration completes and the hours count. */
    public function completeFromProvider(string $provider, array $done): bool
    {
        return $this->applyCompletion($provider, $done) === self::COMPLETED;
    }

    /**
     * What a completion a provider reported does in TEDC: the person is found by employee number, else by e-mail; the registration in the
     * programme made from that course completes and its certificate counts the hours. Says what happened (a constant of this class, or
     * unknown_employee / not_registered when the person or the registration is missing).
     *
     * @param  array<string, mixed>  $row  a completion in TEDC's field names (course_id, employee_no, email, completed, completed_at)
     */
    public function applyCompletion(string $provider, array $row): string
    {
        $done = ProviderNormaliser::completion($row);
        if (! $done['completed']) {
            return self::NOT_COMPLETED;
        }
        $course = $done['course_id'] === '' ? null : ExternalCourse::where('provider', $provider)->where('external_id', $done['course_id'])->first();
        if (! $course) {
            return self::UNKNOWN_COURSE;
        }
        if (! $course->program_id) {
            return self::NO_PROGRAMME;
        }
        $employee = $this->findEmployee($done['employee_no'], $done['email']);
        if (! $employee) {
            return ExternalCompletionIssue::UNKNOWN_EMPLOYEE;
        }
        $r = Registration::where('program_id', $course->program_id)->where('employee_id', $employee->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->first();
        if (! $r) {
            return ExternalCompletionIssue::NOT_REGISTERED;
        }
        if ($r->certificates()->exists()) {
            return self::ALREADY_COMPLETED;
        }
        ExternalCompletion::updateOrCreate(['registration_id' => $r->id], ['source' => 'provider', 'status' => 'approved', 'decided_at' => now(), 'note' => 'Reported by '.$provider]);
        $this->certificates->issueExternal($r, null);

        return self::COMPLETED;
    }

    /** By employee number, else by e-mail address (a provider may know a person by either, or by an external id that is one of them). */
    private function findEmployee(?string $employeeNo, ?string $email): ?Employee
    {
        if ($employeeNo !== null) {
            $found = Employee::where('employee_no', $employeeNo)->first() ?? Employee::whereHas('user', fn ($u) => $u->whereRaw('lower(email) = ?', [mb_strtolower($employeeNo)]))->first();
            if ($found) {
                return $found;
            }
        }

        return $email === null ? null : Employee::whereHas('user', fn ($u) => $u->whereRaw('lower(email) = ?', [mb_strtolower($email)]))->first();
    }

    /** The trainee's evidence of completing a course on another platform. @param  list<\Illuminate\Http\UploadedFile|string>  $evidence */
    public function submitEvidence(Registration $r, array $evidence, ?string $note): ExternalCompletion
    {
        abort_unless($r->program->external_platform, 422);
        if ($r->certificates()->exists()) {
            throw new BusinessRuleException(__('messages.external.already_completed'), 'already_completed');
        }
        if ($evidence === [] && ! filled($note)) {
            throw new BusinessRuleException(__('messages.external.evidence_required'), 'evidence_required');
        }
        $items = $evidence ? $this->evaluations->evidenceItems($evidence, "external/{$r->id}", 5, 'evidence') : [];

        return ExternalCompletion::updateOrCreate(['registration_id' => $r->id], ['evidence' => $items, 'note' => $note, 'source' => 'evidence', 'status' => 'pending', 'reviewer_id' => null, 'review_note' => null, 'decided_at' => null]);
    }

    /** @param  'approve'|'reject'  $decision */
    public function review(ExternalCompletion $c, string $decision, ?string $note, User $by): ExternalCompletion
    {
        if ($c->status !== 'pending') {
            throw new BusinessRuleException(__('messages.pd.already_decided'), 'already_decided');
        }
        if ($decision === 'reject' && ! filled($note)) {
            throw new BusinessRuleException(__('messages.pd.note_required'), 'note_required');
        }
        $c->loadMissing('registration.employee', 'registration.program');
        $c->update(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'reviewer_id' => $by->id, 'review_note' => $note, 'decided_at' => now()]);
        $r = $c->registration;
        if ($decision === 'approve') {
            $this->certificates->issueExternal($r, $by);
        }
        $this->notifications->send($r->employee->user_id, 'external.decided', ['ar' => 'قرار بشأن إتمامك لبرنامج خارجي', 'en' => 'Decision on your external course completion'],
            ['ar' => ($decision === 'approve' ? 'اعتُمد إتمامك لبرنامج «' : 'لم يُعتمد إتمامك لبرنامج «').$r->program->title_ar.'»'.($note ? " — {$note}" : ''), 'en' => ($decision === 'approve' ? 'Your completion of "' : 'Your completion was not approved: "').$r->program->title_en.'"'.($note ? " — {$note}" : '')], ['registration_id' => $r->id]);

        return $c->refresh();
    }
}
