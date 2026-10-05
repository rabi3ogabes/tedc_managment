<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\EvaluationForm;
use App\Models\EvaluationResponse;
use App\Models\NeedsSurvey;
use App\Models\Program;
use App\Services\EvaluationSettings;
use App\Services\NeedsSurveys\SurveyAnalyzer;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\SurveyDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Excel, PDF, Word (and CSV) for the results of every survey and evaluation. */
class SurveyExportController extends Controller
{
    public function __construct(private readonly ReportExporter $exporter, private readonly SurveyAnalyzer $analyzer) {}

    /** Needs surveys. */
    public function needs(Request $request, NeedsSurvey $needsSurvey): Response
    {
        [$format, $lang] = $this->options($request);
        $responses = $needsSurvey->responses()->with('user:id,name,name_ar')->orderBy('submitted_at')->get();
        $anonymous = $needsSurvey->isAnonymous();
        $raw = $responses->map(fn ($r) => ['who' => $anonymous ? null : ($r->user?->name_ar ?? $r->user?->name), 'at' => $r->submitted_at->format('Y-m-d H:i'), 'answers' => $r->answers])->all();
        $doc = SurveyDocument::build($needsSurvey->title, $lang === 'ar' ? 'استبيان حصر الاحتياجات' : 'Needs survey', $responses->count(), $this->analyzer->questionStats($needsSurvey->questions, $responses), $needsSurvey->questions, $raw, $lang);

        return $this->send($doc, $format, $lang, 'survey');
    }

    /** An evaluation form's responses for a program (and optionally one group). */
    public function form(Request $request, EvaluationForm $form): Response
    {
        [$format, $lang] = $this->options($request);
        $q = $request->validate(['program_id' => ['required', 'uuid', 'exists:programs,id'], 'group' => ['nullable', 'uuid']]);
        $responses = EvaluationResponse::with('assignment.respondent:id,name,name_ar')->whereHas('assignment', fn ($w) => $w->where('form_id', $form->id)->where('program_id', $q['program_id'])->when($q['group'] ?? null, fn ($x, $g) => $x->where('group_id', $g)))->get();
        $anonymous = (bool) ($form->settings['anonymous'] ?? false);
        $raw = $responses->map(fn ($r) => ['who' => $anonymous ? null : $r->assignment->respondent?->displayName(), 'at' => $r->submitted_at->format('Y-m-d H:i'), 'answers' => $r->answers])->all();
        $doc = SurveyDocument::build($lang === 'ar' ? $form->title_ar : $form->title_en, Program::find($q['program_id'])?->translate('title') ?? '', $responses->count(), $this->analyzer->questionStats($form->questions, $responses), $form->questions, $raw, $lang);

        return $this->send($doc, $format, $lang, 'evaluation');
    }

    /** The satisfaction survey of a program. */
    public function satisfaction(Request $request, Program $program): Response
    {
        [$format, $lang] = $this->options($request);
        $evals = Evaluation::with('registration.employee.user:id,name,name_ar')->where('program_id', $program->id)->get();
        $anonMin = (int) app(EvaluationSettings::class)->get('anonymous_min_responses', 3);
        abort_if($evals->count() > 0 && $evals->count() < $anonMin, 422, __('messages.evaluation.too_few', ['n' => $anonMin]));
        $keys = $evals->flatMap(fn ($e) => array_keys($e->ratings ?? []))->unique()->values();
        $questions = $keys->map(fn ($k) => ['id' => $k, 'type' => 'rating', 'title' => $k, 'scale' => ['min' => 1, 'max' => 5]])->push(['id' => 'comments', 'type' => 'long_text', 'title' => $lang === 'ar' ? 'ملاحظات' : 'Comments'])->all();
        $responses = $evals->map(fn ($e) => (object) ['answers' => ($e->ratings ?? []) + ['comments' => $e->comments]]);
        $raw = $evals->map(fn ($e) => ['who' => null, 'at' => $e->submitted_at->format('Y-m-d H:i'), 'answers' => ($e->ratings ?? []) + ['comments' => $e->comments]])->all();
        $doc = SurveyDocument::build($lang === 'ar' ? 'استبيان رضا المتدربين' : 'Trainee satisfaction survey', $program->translate('title'), $evals->count(), $this->analyzer->questionStats($questions, $responses), $questions, $raw, $lang);

        return $this->send($doc, $format, $lang, 'satisfaction');
    }

    /** @return array{0: string, 1: string} */
    private function options(Request $request): array
    {
        $d = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'pdf', 'docx', 'csv'])], 'lang' => ['sometimes', Rule::in(['ar', 'en'])]]);

        return [$d['format'] ?? 'xlsx', $d['lang'] ?? 'ar'];
    }

    private function send(array $doc, string $format, string $lang, string $name): Response
    {
        return response($this->exporter->render($doc, $format, $lang), 200, ['Content-Type' => ReportExporter::MIME[$format], 'Content-Disposition' => "attachment; filename=\"{$name}.{$format}\""]);
    }
}
