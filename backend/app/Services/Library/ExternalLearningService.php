<?php

namespace App\Services\Library;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\ExternalCompletion;
use App\Models\ExternalCourse;
use App\Models\Program;
use App\Models\Registration;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\EvaluationService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Courses on other platforms. Providers (Coursera for Business, edX, Udemy Business, LinkedIn Learning) are reached through a
 * generic REST adapter configured in Settings, plus a `fake` driver for development; programs on other platforms (I-earn,
 * cyber-security platforms …) are completed by evidence the centre reviews. Either way a completion counts hours in the record.
 */
class ExternalLearningService
{
    public const PROVIDERS = ['coursera', 'edx', 'udemy', 'linkedin_learning'];

    public function __construct(private readonly CertificateService $certificates, private readonly EvaluationService $evaluations, private readonly NotificationService $notifications) {}

    /** @return array<string, array<string, mixed>> */
    public function config(): array
    {
        $base = array_fill_keys(self::PROVIDERS, ['enabled' => false, 'driver' => 'rest', 'base_url' => '', 'api_key' => '', 'catalogue_path' => '/catalogue', 'completions_path' => '/completions']);

        return array_replace_recursive($base, SiteSetting::find('content_providers')?->value ?? []);
    }

    public function update(array $in, ?User $by = null): array
    {
        $cur = $this->config();
        foreach (self::PROVIDERS as $p) {
            foreach (['enabled', 'driver', 'base_url', 'api_key', 'catalogue_path', 'completions_path'] as $f) {
                if (isset($in[$p]) && array_key_exists($f, $in[$p]) && $in[$p][$f] !== '••••••••') {
                    $cur[$p][$f] = $f === 'enabled' ? (bool) $in[$p][$f] : (string) $in[$p][$f];
                }
            }
        }
        SiteSetting::updateOrCreate(['key' => 'content_providers'], ['value' => $cur, 'updated_by' => $by?->id]);

        return $this->masked();
    }

    public function masked(): array
    {
        $c = $this->config();
        foreach ($c as $k => $v) {
            $c[$k]['api_key'] = $v['api_key'] !== '' ? '••••••••' : '';
        }

        return $c;
    }

    /** @return list<array<string, mixed>> the provider's courses: id, title, url, hours */
    private function fetchCatalogue(string $provider, array $c): array
    {
        if ($c['driver'] === 'fake') {
            return [['id' => $provider.'-101', 'title' => ucfirst($provider).' course 101', 'url' => "https://{$provider}.example/c/101", 'hours' => 6], ['id' => $provider.'-102', 'title' => ucfirst($provider).' course 102', 'url' => "https://{$provider}.example/c/102", 'hours' => 10]];
        }

        return Http::timeout(15)->acceptJson()->withToken($c['api_key'])->get(rtrim($c['base_url'], '/').$c['catalogue_path'])->throw()->json('courses') ?? [];
    }

    /** @return list<array<string, mixed>> completions: course_id, employee_no | email, completed_at, hours? */
    private function fetchCompletions(string $provider, array $c): array
    {
        if ($c['driver'] === 'fake') {
            return $c['fake_completions'] ?? [];
        }

        return Http::timeout(15)->acceptJson()->withToken($c['api_key'])->get(rtrim($c['base_url'], '/').$c['completions_path'], ['since' => now()->subDays(7)->toDateString()])->throw()->json('completions') ?? [];
    }

    /** Nightly: refresh the catalogue and read completions. @return array{courses: int, completed: int, errors: list<string>} */
    public function sync(): array
    {
        $courses = $completed = 0;
        $errors = [];
        foreach ($this->config() as $provider => $c) {
            if (! $c['enabled']) {
                continue;
            }
            try {
                foreach ($this->fetchCatalogue($provider, $c) as $row) {
                    ExternalCourse::updateOrCreate(['provider' => $provider, 'external_id' => (string) $row['id']], ['title' => (string) $row['title'], 'url' => (string) $row['url'], 'hours' => (int) ($row['hours'] ?? 0), 'meta' => $row, 'synced_at' => now()]);
                    $courses++;
                }
                foreach ($this->fetchCompletions($provider, $c) as $done) {
                    $completed += (int) $this->completeFromProvider($provider, $done);
                }
            } catch (\Throwable $e) {
                $errors[] = "{$provider}: ".Str::limit($e->getMessage(), 160, '');
            }
        }

        return compact('courses', 'completed', 'errors');
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
        $course = ExternalCourse::where('provider', $provider)->where('external_id', (string) ($done['course_id'] ?? ''))->first();
        if (! $course?->program_id) {
            return false;
        }
        $employee = Employee::when(! empty($done['employee_no']), fn ($q) => $q->where('employee_no', $done['employee_no']), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('email', $done['email'] ?? '-')))->first();
        $r = $employee ? Registration::where('program_id', $course->program_id)->where('employee_id', $employee->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->first() : null;
        if (! $r || $r->certificates()->exists()) {
            return false;
        }
        ExternalCompletion::updateOrCreate(['registration_id' => $r->id], ['source' => 'provider', 'status' => 'approved', 'decided_at' => now(), 'note' => 'Reported by '.$provider]);
        $this->certificates->issueExternal($r, null);

        return true;
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
