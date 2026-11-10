<?php

use App\Models\EvaluationForm;
use App\Services\EvaluationService;
use Illuminate\Database\Migrations\Migration;

/**
 * The centre's own forms become Shaheen's (docs/saber/08-centre-forms.md) — EvaluationService::adoptCentreForms, which also runs the first
 * time the forms are needed. A database without forms yet gets them when they are first needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (EvaluationForm::query()->exists()) {
            app(EvaluationService::class)->adoptCentreForms();
        }
    }

    public function down(): void
    {
        // Data only: the forms stay; switch defaults back in «نماذج التقييم» if needed.
    }
};
