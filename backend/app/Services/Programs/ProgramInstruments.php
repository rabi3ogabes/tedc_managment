<?php

namespace App\Services\Programs;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Program;
use App\Models\ProgramInstrument;
use App\Models\TrainingKit;
use App\Models\User;
use App\Services\EvaluationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A programme's training kits and the evaluation instruments generated from them: the trainee survey (the programme
 * survey, opened automatically some hours after the programme ends), the trainer's self-reflection (assigned to the
 * programme's trainers when their group completes), and the pre-test and post-test — drafted as tests of the programme
 * when a chosen kit carries assessment tools, and reported as "not available in the kit" when none does. Every
 * instrument can be switched off; nothing reaches a trainee or a trainer from here, it is all prepared for its moment.
 */
class ProgramInstruments
{
    public const PRE = '/(قبلي|قبل|pre|diagnostic|entry)/iu';

    public const POST = '/(بعدي|بعد|post|final|exit)/iu';

    public function __construct(private readonly EvaluationService $evaluations) {}

    // ───────────────────────────── kits

    /** Kits a programme may use: approved or published, with what their assessment tools offer. @return list<array<string, mixed>> */
    public function kitOptions(?string $deliveryMode = null, ?string $q = null): array
    {
        return TrainingKit::with(['files:id,kit_id,name,category,kind'])->whereIn('status', [TrainingKit::APPROVED, TrainingKit::PUBLISHED])
            ->when($q, fn ($b) => $b->where(fn ($w) => $w->where('title_ar', 'like', "%$q%")->orWhere('title_en', 'like', "%$q%")->orWhere('code', 'like', "%$q%")))
            ->orderBy('title_ar')->limit(200)->get()->map(fn (TrainingKit $k) => $this->kitRow($k, $deliveryMode))
            ->sortBy([['matches', 'desc'], ['title_ar', 'asc']])->values()->all();
    }

    /** @return array<string, mixed> */
    public function kitRow(TrainingKit $k, ?string $deliveryMode = null): array
    {
        $tests = $this->testsOf($k);

        return ['id' => $k->id, 'code' => $k->code, 'title_ar' => $k->title_ar, 'title_en' => $k->title_en, 'delivery' => $k->delivery, 'duration_hours' => (float) $k->duration_hours, 'version' => $k->version, 'status' => $k->status,
            'files' => $k->files->count(), 'matches' => ! $deliveryMode || $k->delivery === $deliveryMode || ($deliveryMode === 'hybrid'), 'has_pre' => $tests['pre'] !== [], 'has_post' => $tests['post'] !== [], 'assessment_files' => array_values(array_unique(array_merge($tests['pre'], $tests['post'])))];
    }

    /** The assessment tools of a kit, split into what serves as a pre-test and what serves as a post-test (a file that says neither serves both). @return array{pre: list<string>, post: list<string>} */
    public function testsOf(TrainingKit $kit): array
    {
        $files = $kit->relationLoaded('files') ? $kit->files : $kit->files()->get();
        $pre = [];
        $post = [];
        foreach ($files->where('category', 'assessment') as $f) {
            $isPre = (bool) preg_match(self::PRE, (string) $f->name);
            $isPost = (bool) preg_match(self::POST, (string) $f->name);
            if ($isPre || ! $isPost) {
                $pre[] = $f->name;
            }
            if ($isPost || ! $isPre) {
                $post[] = $f->name;
            }
        }

        return ['pre' => $pre, 'post' => $post];
    }

    /** Sets the programme's kits (each pinned at the version it has now). @param  list<string>  $kitIds */
    public function attachKits(Program $program, array $kitIds): void
    {
        $kits = TrainingKit::whereIn('id', $kitIds)->whereIn('status', [TrainingKit::APPROVED, TrainingKit::PUBLISHED])->get();
        DB::transaction(function () use ($program, $kits) {
            $existing = DB::table('kit_program')->where('program_id', $program->id)->pluck('pinned_version', 'kit_id');
            DB::table('kit_program')->where('program_id', $program->id)->delete();
            foreach ($kits as $kit) {
                DB::table('kit_program')->insert(['id' => (string) Str::uuid(), 'kit_id' => $kit->id, 'program_id' => $program->id, 'pinned_version' => $existing[$kit->id] ?? $kit->version, 'created_at' => now(), 'updated_at' => now()]);
                if (! $kit->program_id) {
                    $kit->update(['program_id' => $program->id]);
                }
            }
        });
    }

    // ───────────────────────────── instruments

    /**
     * Generates (or refreshes) the instruments from the programme's kits. $options: `trainee_survey`, `trainer_reflection`,
     * `pre_test`, `post_test` (bool, default on) and `survey_auto_hours` (hours after the programme ends, default 24).
     *
     * @param  array<string, mixed>  $options
     */
    public function generate(Program $program, array $options, User $by): array
    {
        $program->loadMissing('sessions');
        $kits = $program->kits()->with('files:id,kit_id,name,category,kind')->get();
        $on = fn (string $k) => (bool) ($options[$k] ?? true);
        $hours = max(0, min(720, (int) ($options['survey_auto_hours'] ?? $program->survey_auto_hours ?? 24)));

        // The trainee survey is the programme survey: opened by the system a number of hours after the programme ends.
        $trainee = $this->put($program, 'trainee_survey', $on('trainee_survey'), $by, ['form_id' => $this->evaluations->defaultFor('satisfaction')->id, 'meta' => ['auto_hours' => $hours]]);
        $program->update($on('trainee_survey') ? ['survey_mode' => 'auto', 'survey_auto_hours' => $hours] : ['survey_mode' => 'manual']);

        // The trainer's self-reflection goes to the programme's trainers when their group completes (EvaluationService::assignForGroup).
        $this->put($program, 'trainer_reflection', $on('trainer_reflection'), $by, ['form_id' => $this->evaluations->defaultFor('trainer_reflection')->id]);

        foreach (['pre_test' => 'pre', 'post_test' => 'post'] as $kind => $which) {
            $source = $kits->first(fn (TrainingKit $k) => $this->testsOf($k)[$which] !== []);
            $existing = Assessment::where('program_id', $program->id)->where('kind', $kind)->orderBy('created_at')->first();
            $assessment = $existing;
            if ($on($kind) && ! $existing && $source) {
                $assessment = $this->draftTest($program, $kind, $source, $this->testsOf($source)[$which]);
            }
            $this->put($program, $kind, $on($kind), $by, ['assessment_id' => $assessment?->id, 'source_kit_id' => $source?->id, 'meta' => ['kit_files' => $source ? $this->testsOf($source)[$which] : []]]);
        }
        AuditLog::create(['user_id' => $by->id, 'action' => 'program_instruments_generated', 'auditable_type' => Program::class, 'auditable_id' => $program->id, 'new_values' => ['kits' => $kits->pluck('code')->all(), 'options' => array_intersect_key($options, array_flip(ProgramInstrument::KINDS))]]);

        return $this->overview($program->refresh());
    }

    /** @return array{kits: list<array>, instruments: list<array>} what the programme page and the form show */
    public function overview(Program $program): array
    {
        $kits = $program->kits()->with('files:id,kit_id,name,category,kind')->get();
        $rows = ProgramInstrument::with(['assessment:id,kind,status,title_ar,title_en', 'kit:id,code,title_ar,title_en'])->where('program_id', $program->id)->get()->keyBy('kind');
        $instruments = [];
        foreach (ProgramInstrument::KINDS as $kind) {
            $r = $rows->get($kind);
            $isTest = in_array($kind, ['pre_test', 'post_test'], true);
            $which = $kind === 'pre_test' ? 'pre' : 'post';
            $available = ! $isTest || $kits->contains(fn (TrainingKit $k) => $this->testsOf($k)[$which] !== []) || ($r?->assessment_id !== null);
            $status = ! $available ? 'unavailable' : ($r && ! $r->enabled ? 'off' : (! $r ? 'planned' : ($isTest ? ($r->assessment?->status === 'published' ? 'ready' : 'needs_questions') : 'ready')));
            $instruments[] = [
                'kind' => $kind, 'enabled' => $r?->enabled ?? true, 'available' => $available, 'status' => $status,
                'form' => $r?->form_id ? ['id' => $r->form_id] : null,
                'assessment' => $r?->assessment ? ['id' => $r->assessment->id, 'status' => $r->assessment->status, 'title_ar' => $r->assessment->title_ar, 'title_en' => $r->assessment->title_en] : null,
                'kit' => $r?->kit ? ['id' => $r->kit->id, 'code' => $r->kit->code, 'title_ar' => $r->kit->title_ar, 'title_en' => $r->kit->title_en] : null,
                'kit_files' => $r?->meta['kit_files'] ?? [], 'auto_hours' => $kind === 'trainee_survey' ? (int) ($r?->meta['auto_hours'] ?? $program->survey_auto_hours ?? 24) : null,
            ];
        }

        return ['kits' => $kits->map(fn (TrainingKit $k) => $this->kitRow($k, $program->delivery_mode))->values()->all(), 'instruments' => $instruments];
    }

    /** Whether an instrument is switched off for the programme (an absent row means the default: on). */
    public function enabled(Program $program, string $kind): bool
    {
        return ProgramInstrument::where('program_id', $program->id)->where('kind', $kind)->value('enabled') ?? true;
    }

    public function formFor(Program $program, string $kind): ?string
    {
        return ProgramInstrument::where('program_id', $program->id)->where('kind', $kind)->value('form_id');
    }

    /**
     * The two forms a programme has from the start (a programme published from SABER+ had none): the trainee survey, opened automatically
     * some hours after the programme ends, and the trainer's self-reflection. A choice already made for the programme is kept.
     */
    public function ensureForms(Program $program, User $by): void
    {
        if (! ProgramInstrument::where('program_id', $program->id)->where('kind', 'trainee_survey')->exists()) {
            $hours = (int) ($program->survey_auto_hours ?: 24);
            $this->put($program, 'trainee_survey', true, $by, ['form_id' => $this->evaluations->defaultFor('satisfaction')->id, 'meta' => ['auto_hours' => $hours]]);
            if (! $program->survey_opened_at && $program->survey_mode === 'always') {
                $program->update(['survey_mode' => 'auto', 'survey_auto_hours' => $hours]);
            }
        }
        if (! ProgramInstrument::where('program_id', $program->id)->where('kind', 'trainer_reflection')->exists()) {
            $this->put($program, 'trainer_reflection', true, $by, ['form_id' => $this->evaluations->defaultFor('trainer_reflection')->id]);
        }
    }

    // ───────────────────────────── internals

    /** @param  array<string, mixed>  $fields */
    private function put(Program $program, string $kind, bool $enabled, User $by, array $fields): ProgramInstrument
    {
        return ProgramInstrument::updateOrCreate(['program_id' => $program->id, 'kind' => $kind], ['enabled' => $enabled, 'created_by' => $by->id] + $fields);
    }

    /** A draft test of the programme, named after the kit's tool; it has no questions yet and trainees see nothing until it is published. @param  list<string>  $files */
    private function draftTest(Program $program, string $kind, TrainingKit $kit, array $files): Assessment
    {
        $pre = $kind === 'pre_test';
        $first = $program->sessions->first();
        $last = $program->sessions->last();
        $names = implode('، ', array_slice($files, 0, 3));

        return Assessment::create([
            'program_id' => $program->id, 'kind' => $kind, 'status' => 'draft',
            'title_ar' => ($pre ? 'الاختبار القبلي — ' : 'الاختبار البعدي — ').Str::limit($program->title_ar, 150, ''), 'title_en' => ($pre ? 'Pre-test — ' : 'Post-test — ').Str::limit($program->title_en ?: $program->title_ar, 150, ''),
            'instructions_ar' => "مُولَّد من أدوات التقويم في الحقيبة «{$kit->title_ar}» ({$names}). أضف الأسئلة ثم انشر الاختبار.",
            'instructions_en' => "Generated from the assessment tools of the kit \"{$kit->title_en}\" ({$names}). Add the questions, then publish the test.",
            'delivery' => 'either', 'max_attempts' => 1, 'pass_percent' => 60, 'show_score' => true, 'feedback_mode' => $pre ? 'after_close' : 'after_submit',
            'window_opens_at' => $pre ? ($first ? $first->starts_at->copy()->subDays(3) : null) : ($last ? $last->ends_at : null),
            'window_closes_at' => $pre ? ($first ? $first->starts_at : null) : ($last ? $last->ends_at->copy()->addDays(7) : null),
        ]);
    }
}
