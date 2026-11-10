<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\EvaluationAssignment;
use App\Models\ImpactSurvey;
use App\Models\Program;
use App\Models\Registration;
use App\Models\SupervisorEvaluation;
use App\Models\User;

/**
 * Impact Measurement System.
 *
 * After completion, follow-up surveys are scheduled at 30 / 60 / 90 days.
 * The Training Impact Score (0..100) blends five signals, inspired by the
 * Kirkpatrick model:
 *   attendance  - participation (attendance %)
 *   learning    - post-test score and satisfaction (L1/L2)
 *   application - self-reported application in follow-up surveys (L3)
 *   supervisor  - supervisor observed application (L3)
 *   follow_up   - share of due follow-up surveys answered (engagement)
 * Weights come from config('tedc.impact_weights') and are re-normalised over the
 * signals that are available, so early scores are meaningful but not inflated.
 */
class ImpactService
{
    public function __construct(private readonly NotificationService $notifications, private readonly EvaluationSettings $settings) {}

    public function scheduleFollowUps(Registration $registration): void
    {
        $base = ($registration->completed_at ?? now())->copy()->startOfDay();

        // The trainee form goes out at least one and a half months after the program (45 days by default); a second one is optional.
        $impact = $this->settings->get('impact');
        foreach (array_filter([(int) $impact['trainee_days'], (int) ($impact['optional_days'] ?? 0)]) as $days) {
            ImpactSurvey::firstOrCreate(
                ['registration_id' => $registration->id, 'stage_days' => $days],
                [
                    'program_id' => $registration->program_id,
                    'employee_id' => $registration->employee_id,
                    'scheduled_for' => $base->copy()->addDays($days),
                    'status' => 'scheduled',
                ],
            );
        }
    }

    /**
     * Sends due surveys and expires unanswered ones. Run daily by the scheduler.
     *
     * @return array{sent: int, expired: int}
     */
    public function dispatchDue(): array
    {
        $sent = 0;

        ImpactSurvey::with(['employee.supervisor.user', 'program'])
            ->where('status', 'scheduled')
            ->whereDate('scheduled_for', '<=', today())
            ->chunkById(200, function ($surveys) use (&$sent) {
                foreach ($surveys as $survey) {
                    $survey->update(['status' => 'sent', 'sent_at' => now()]);
                    $this->notifications->send(
                        $survey->employee->user_id,
                        'impact.survey',
                        ['ar' => "استبيان أثر التدريب ({$survey->stage_days} يوماً)", 'en' => "Training impact survey ({$survey->stage_days} days)"],
                        [
                            'ar' => "شاركنا كيف طبّقت ما تعلمته في برنامج «{$survey->program->title_ar}».",
                            'en' => "Tell us how you applied what you learned in \"{$survey->program->title_en}\".",
                        ],
                        ['survey_id' => $survey->id],
                    );

                    // The direct manager is asked on the manager's own schedule (EvaluationService::dispatchManagerImpact).
                    $sent++;
                }
            });

        $expired = ImpactSurvey::where('status', 'sent')->where('sent_at', '<', now()->subDays((int) $this->settings->get('impact.expiry_days', 30)))->update(['status' => 'expired']);

        return ['sent' => $sent, 'expired' => $expired];
    }

    public function submitSurvey(ImpactSurvey $survey, array $answers): ImpactSurvey
    {
        if ($survey->status === 'completed') {
            throw new BusinessRuleException(__('messages.survey.completed'), 'survey_completed');
        }
        if ($survey->scheduled_for->isFuture()) {
            throw new BusinessRuleException(__('messages.survey.not_due'), 'survey_not_due');
        }

        $score = $answers['application_score'] ?? match ($answers['applied_learning']) {
            'yes' => 100, 'partially' => 60, default => 20,
        };

        $survey->update([
            'applied_learning' => $answers['applied_learning'],
            'application_score' => $score,
            'changes_observed' => $answers['changes_observed'] ?? null,
            'skills_improved' => $answers['skills_improved'] ?? [],
            'needs_support' => (bool) ($answers['needs_support'] ?? false),
            'support_details' => $answers['support_details'] ?? null,
            'status' => 'completed',
            'completed_at' => now(),
            'sent_at' => $survey->sent_at ?? now(),
        ]);

        $this->score($survey->registration);

        return $survey;
    }

    public function submitSupervisorEvaluation(Registration $registration, User $supervisor, array $data): SupervisorEvaluation
    {
        $evaluation = SupervisorEvaluation::create([
            'registration_id' => $registration->id,
            'program_id' => $registration->program_id,
            'employee_id' => $registration->employee_id,
            'supervisor_user_id' => $supervisor->id,
            'application_score' => $data['application_score'],
            'behavior_change' => $data['behavior_change'] ?? null,
            'comments' => $data['comments'] ?? null,
            'recommendations' => $data['recommendations'] ?? null,
            'submitted_at' => now(),
        ]);

        // The same instrument can be tracked as an assignment (the manager's impact form).
        EvaluationAssignment::where('subject_registration_id', $registration->id)->where('respondent_user_id', $supervisor->id)->where('respondent_type', 'manager')->where('status', 'pending')->update(['status' => 'submitted']);
        $this->score($registration);

        return $evaluation;
    }

    /** @return array{score: float|null, components: array<string, float|null>} */
    public function breakdown(Registration $registration): array
    {
        $registration->loadMissing(['evaluation', 'impactSurveys', 'supervisorEvaluations']);

        $evaluation = $registration->evaluation;
        $learning = null;
        if ($evaluation) {
            $learning = $evaluation->post_test_score !== null
                ? 0.6 * $evaluation->post_test_score + 0.4 * $evaluation->satisfaction_score
                : $evaluation->satisfaction_score;
        }

        $surveys = $registration->impactSurveys;
        $answered = $surveys->where('status', 'completed');
        $due = $surveys->whereIn('status', ['sent', 'completed', 'expired']);

        $components = [
            'attendance' => in_array($registration->status, [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED], true) ? (float) $registration->attendance_percent : null,
            'learning' => $learning,
            'application' => $answered->isNotEmpty() ? (float) $answered->avg('application_score') : null,
            'supervisor' => $registration->supervisorEvaluations->isNotEmpty() ? (float) $registration->supervisorEvaluations->avg('application_score') : null,
            'follow_up' => $due->isNotEmpty() ? $answered->count() / $due->count() * 100 : null,
        ];

        $weights = config('tedc.impact_weights');
        $available = array_filter($components, fn ($v) => $v !== null);
        $totalWeight = array_sum(array_intersect_key($weights, $available));

        $score = $totalWeight > 0
            ? round(array_sum(array_map(fn ($key) => $available[$key] * $weights[$key], array_keys($available))) / $totalWeight, 2)
            : null;

        return ['score' => $score, 'components' => array_map(fn ($v) => $v === null ? null : round($v, 2), $components), 'weights' => $weights];
    }

    public function score(Registration $registration): ?float
    {
        $score = $this->breakdown($registration)['score'];
        $registration->update(['impact_score' => $score]);

        return $score;
    }

    public function programImpact(Program $program): array
    {
        $registrations = $program->registrations()->where('status', Registration::STATUS_COMPLETED)->get();
        $surveys = ImpactSurvey::where('program_id', $program->id)->where('status', 'completed')->get();

        return [
            'participants' => $registrations->count(),
            'impact_score' => $registrations->whereNotNull('impact_score')->avg('impact_score') ? round($registrations->whereNotNull('impact_score')->avg('impact_score'), 1) : null,
            'applied_rate' => $surveys->isNotEmpty() ? round($surveys->whereIn('applied_learning', ['yes', 'partially'])->count() / $surveys->count() * 100, 1) : null,
            'needs_support' => $surveys->where('needs_support', true)->count(),
            'by_stage' => $surveys->pluck('stage_days')->unique()->sort()->values()->mapWithKeys(fn ($d) => [$d => [
                'responses' => $surveys->where('stage_days', $d)->count(),
                'avg_application' => round((float) $surveys->where('stage_days', $d)->avg('application_score'), 1),
            ]]),
            'skills_improved' => $surveys->pluck('skills_improved')->flatten()->filter()->countBy()->sortDesc()->take(10),
        ];
    }
}
