<?php

namespace App\Services\Saber;

use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationForm;
use App\Models\GroupTrainer;
use App\Models\Program;
use App\Models\ProgramInstrument;
use App\Models\Registration;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\EvaluationService;
use App\Services\Notifications\ProgramSurvey;
use Illuminate\Support\Collection;

/**
 * «استمارات البرنامج» (docs/saber/07-programme-forms.md): every programme's two built-in forms as links of its own — no upload. The trainee
 * survey (`/f/t/{code}`) opens the signed-in trainee's evaluation of that programme; the trainer's self-reflection (`/f/r/{code}`) opens the
 * signed-in trainer's reflection. Each comes with where it stands (open, scheduled, closed, switched off; waiting for the group to end) and
 * how many answered. The same resolvers tell a person who scans the QR code what they can do.
 */
class FormLinks
{
    public function __construct(private readonly ProgramSurvey $survey, private readonly EvaluationService $evaluations) {}

    public static function traineeUrl(Program $program): string
    {
        return rtrim((string) config('tedc.web_url'), '/').'/f/t/'.rawurlencode($program->code);
    }

    public static function reflectionUrl(Program $program): string
    {
        return rtrim((string) config('tedc.web_url'), '/').'/f/r/'.rawurlencode($program->code);
    }

    /**
     * The two forms of each programme, keyed by programme id, counted in a handful of queries for a whole page of programmes.
     *
     * @param  Collection<int, Program>  $programs
     * @return array<string, array{trainee: array<string, mixed>, reflection: array<string, mixed>}>
     */
    public function many(Collection $programs): array
    {
        $ids = $programs->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $count = fn ($q, string $col = 'program_id') => $q->whereIn($col, $ids)->groupBy($col)->selectRaw("{$col} as id, count(*) as n")->pluck('n', 'id');
        $instruments = ProgramInstrument::whereIn('program_id', $ids)->whereIn('kind', ['trainee_survey', 'trainer_reflection'])->get()->groupBy('program_id');
        $trainees = $count(Registration::whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED]));
        $answered = $count(Evaluation::query());
        $assigned = $count(EvaluationAssignment::where('respondent_type', 'trainer')->where('status', '!=', 'expired'));
        $reflected = $count(EvaluationAssignment::where('respondent_type', 'trainer')->where('status', 'submitted'));
        $groups = TrainingGroup::whereIn('program_id', $ids)->whereNotIn('status', ['cancelled'])->get(['id', 'program_id', 'status'])->groupBy('program_id');
        $trainers = GroupTrainer::query()->join('training_groups', 'training_groups.id', '=', 'group_trainers.group_id')->join('trainers', 'trainers.id', '=', 'group_trainers.trainer_id')
            ->whereIn('training_groups.program_id', $ids)->where('group_trainers.status', 'approved')->whereNotNull('trainers.user_id')
            ->groupBy('training_groups.program_id')->selectRaw('training_groups.program_id as id, count(distinct trainers.user_id) as n')->pluck('n', 'id');
        $defaultReflection = $this->evaluations->defaultFor('trainer_reflection');
        $forms = EvaluationForm::whereIn('id', $instruments->flatten()->pluck('form_id')->filter()->unique())->get(['id', 'title_ar', 'title_en'])->keyBy('id');

        $out = [];
        foreach ($programs as $p) {
            $own = $instruments->get($p->id, collect())->keyBy('kind');
            $survey = $own->get('trainee_survey');
            $reflection = $own->get('trainer_reflection');
            $form = $forms->get($reflection?->form_id) ?? $defaultReflection;
            $g = $groups->get($p->id, collect());
            $out[$p->id] = [
                'trainee' => $this->traineeState($p, $survey?->enabled ?? true) + [
                    'url' => self::traineeUrl($p), 'expected' => (int) ($trainees[$p->id] ?? 0), 'answered' => (int) ($answered[$p->id] ?? 0),
                ],
                'reflection' => [
                    'url' => self::reflectionUrl($p), 'enabled' => $reflection?->enabled ?? true,
                    'status' => ! ($reflection?->enabled ?? true) ? 'off' : (($assigned[$p->id] ?? 0) === 0 ? 'waiting' : (($reflected[$p->id] ?? 0) >= ($assigned[$p->id] ?? 0) ? 'done' : 'open')),
                    'trainers' => (int) ($trainers[$p->id] ?? 0), 'assigned' => (int) ($assigned[$p->id] ?? 0), 'answered' => (int) ($reflected[$p->id] ?? 0),
                    'groups' => $g->count(), 'groups_done' => $g->where('status', 'completed')->count(),
                    'form' => ['id' => $form->id, 'title' => app()->getLocale() === 'en' ? ($form->title_en ?: $form->title_ar) : $form->title_ar],
                ],
            ];
        }

        return $out;
    }

    /** @return array{trainee: array<string, mixed>, reflection: array<string, mixed>} */
    public function one(Program $program): array
    {
        return $this->many(collect([$program]))[$program->id];
    }

    /**
     * What the signed-in person who opened `/f/t/{code}` can do: open (their registration), submitted, scheduled (opens at), closed, off,
     * pending (registration not approved yet) or not registered.
     *
     * @return array<string, mixed>
     */
    public function traineeFor(Program $program, User $user): array
    {
        $registration = Registration::where('program_id', $program->id)->whereHas('employee', fn ($q) => $q->where('user_id', $user->id))
            ->orderByRaw("case when status in ('approved','completed') then 0 else 1 end")->latest()->first();
        $base = ['program' => ['id' => $program->id, 'code' => $program->code, 'title' => $program->translate('title')]];
        if (! $registration || ! in_array($registration->status, [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED], true)) {
            return $base + ['state' => $registration ? 'pending' : 'not_registered'];
        }
        if ($registration->evaluation_completed || Evaluation::where('registration_id', $registration->id)->exists()) {
            return $base + ['state' => 'submitted'];
        }
        $enabled = ProgramInstrument::where('program_id', $program->id)->where('kind', 'trainee_survey')->value('enabled') ?? true;
        $state = $this->traineeState($program, (bool) $enabled);

        return $base + ['state' => $state['status'] === 'open' ? 'open' : $state['status'], 'opens_at' => $state['opens_at'], 'registration_id' => $registration->id];
    }

    /**
     * What the signed-in person who opened `/f/r/{code}` can do: open (their reflection), submitted, waiting (a trainer of the programme whose
     * group has not finished), off, or not a trainer of this programme.
     *
     * @return array<string, mixed>
     */
    public function reflectionFor(Program $program, User $user): array
    {
        $base = ['program' => ['id' => $program->id, 'code' => $program->code, 'title' => $program->translate('title')]];
        $mine = EvaluationAssignment::where('program_id', $program->id)->where('respondent_user_id', $user->id)->where('respondent_type', 'trainer')
            ->orderByRaw("case status when 'pending' then 0 when 'submitted' then 1 else 2 end")->latest()->first();
        if ($mine && $mine->status === 'pending') {
            return $base + ['state' => 'open', 'assignment_id' => $mine->id];
        }
        if ($mine && $mine->status === 'submitted') {
            return $base + ['state' => 'submitted'];
        }
        $groups = TrainingGroup::where('program_id', $program->id)->whereHas('trainers', fn ($q) => $q->where('status', 'approved')->whereHas('trainer', fn ($t) => $t->where('user_id', $user->id)))->get(['id', 'title_ar', 'title_en', 'end_date', 'status']);
        if ($groups->isEmpty()) {
            return $base + ['state' => 'not_trainer'];
        }
        if (! (ProgramInstrument::where('program_id', $program->id)->where('kind', 'trainer_reflection')->value('enabled') ?? true)) {
            return $base + ['state' => 'off'];
        }

        return $base + ['state' => $mine ? 'closed' : 'waiting', 'ends_on' => $groups->max('end_date')?->toDateString()];
    }

    /** The trainee survey's state: off, open (also «always available»), scheduled with its time, or closed. @return array{enabled: bool, status: string, opens_at: ?string, mode: string} */
    private function traineeState(Program $program, bool $enabled): array
    {
        $opens = $program->survey_mode === 'auto' && ! $program->survey_opened_at ? $this->survey->autoOpensAt($program) : null;
        $status = match (true) {
            ! $enabled => 'off',
            $program->surveyIsOpen() => 'open',
            $opens !== null => 'scheduled',
            default => 'closed',
        };

        return ['enabled' => $enabled, 'status' => $status, 'opens_at' => $status === 'scheduled' ? $opens?->toIso8601String() : null, 'mode' => (string) $program->survey_mode];
    }
}
