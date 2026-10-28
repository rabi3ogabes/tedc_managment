<?php

use App\Models\EvaluationForm;
use App\Models\ProgramInstrument;
use App\Services\NeedsSurveys\SurveySchema;
use App\Support\EvaluationTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The centre's own forms become Shaheen's (docs/saber/08-centre-forms.md): the trainee form's questions go onto the satisfaction form, and
 * «التقييم الذاتي للمدرب» becomes the default trainer self-reflection, taking over the programmes that used the previous default. Earlier
 * forms and every answer stay as they are. A database without forms yet gets them when they are first needed (EvaluationService).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! EvaluationForm::query()->exists()) {
            return;
        }
        DB::transaction(function () {
            $trainee = EvaluationTemplates::trainee();
            foreach (EvaluationForm::where('kind', 'satisfaction')->get() as $form) {
                $form->update(['title_ar' => $trainee['title_ar'], 'title_en' => $trainee['title_en'], 'questions' => SurveySchema::normalize($trainee['questions']),
                    'settings' => array_merge($form->settings ?? [], ['template' => EvaluationTemplates::TRAINEE, 'intro_ar' => $trainee['intro_ar'], 'intro_en' => $trainee['intro_en']])]);
            }

            if (EvaluationForm::where('kind', 'trainer_reflection')->get(['id', 'settings'])->contains(fn ($f) => ($f->settings['template'] ?? null) === EvaluationTemplates::REFLECTION)) {
                return;
            }
            $previous = EvaluationForm::where('kind', 'trainer_reflection')->where('is_default', true)->pluck('id');
            $reflection = EvaluationTemplates::trainerReflection();
            $form = EvaluationForm::create(['kind' => 'trainer_reflection', 'title_ar' => $reflection['title_ar'], 'title_en' => $reflection['title_en'], 'questions' => SurveySchema::normalize($reflection['questions']),
                'approval_status' => 'approved', 'approved_at' => now(), 'is_default' => false, 'settings' => ['evidence' => false, 'max_files' => 3, 'template' => EvaluationTemplates::REFLECTION]]);
            EvaluationForm::where('kind', 'trainer_reflection')->whereKeyNot($form->id)->update(['is_default' => false]);
            $form->update(['is_default' => true]);
            // Programmes that used the previous default now use the centre's form; one that chose another form keeps its choice.
            ProgramInstrument::where('kind', 'trainer_reflection')->where(fn ($q) => $q->whereIn('form_id', $previous)->orWhereNull('form_id'))->update(['form_id' => $form->id]);
        });
    }

    public function down(): void
    {
        // Data only: the forms stay; switch defaults back in «نماذج التقييم» if needed.
    }
};
