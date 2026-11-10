<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankCategory;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Services\Assessment\QuestionBankService;
use App\Services\Assessment\QuestionTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Question banks, categories and questions. */
class QuestionBankController extends Controller
{
    public function __construct(private readonly QuestionBankService $banks) {}

    public function types(): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($k) => ['key' => $k], QuestionTypes::keys())]);
    }

    public function index(): JsonResponse
    {
        $user = $this->user();
        $rows = QuestionBank::withCount(['questions as questions_count' => fn ($q) => $q->where('status', 'active')])->where(fn ($q) => $q->where('visibility', '!=', 'private')->orWhere('owner_id', $user->id))->orderBy('title_ar')->get();

        return response()->json(['data' => $rows->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['title_ar' => ['required', 'string', 'max:200'], 'title_en' => ['required', 'string', 'max:200'], 'program_id' => ['nullable', 'uuid', 'exists:programs,id'], 'visibility' => ['sometimes', Rule::in(['private', 'program', 'center'])], 'description' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => QuestionBank::create($d + ['owner_id' => $this->user()->id])], 201);
    }

    public function update(Request $request, QuestionBank $bank): JsonResponse
    {
        $bank->update($request->validate(['title_ar' => ['sometimes', 'string', 'max:200'], 'title_en' => ['sometimes', 'string', 'max:200'], 'visibility' => ['sometimes', Rule::in(['private', 'program', 'center'])], 'description' => ['nullable', 'string', 'max:2000']]));

        return response()->json(['data' => $bank->fresh()]);
    }

    public function destroy(QuestionBank $bank): JsonResponse
    {
        $bank->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function categories(QuestionBank $bank): JsonResponse
    {
        return response()->json(['data' => $this->banks->categories($bank)->all()]);
    }

    public function saveCategory(Request $request, QuestionBank $bank, ?BankCategory $category = null): JsonResponse
    {
        $d = $request->validate(['name_ar' => [$category ? 'sometimes' : 'required', 'string', 'max:200'], 'name_en' => [$category ? 'sometimes' : 'required', 'string', 'max:200'], 'parent_id' => ['nullable', 'uuid', 'exists:bank_categories,id'], 'sort_order' => ['sometimes', 'integer', 'min:0']]);
        $category = $category ? tap($category)->update($d) : BankCategory::create($d + ['bank_id' => $bank->id]);

        return response()->json(['data' => $category->fresh()], $category->wasRecentlyCreated ? 201 : 200);
    }

    public function questions(Request $request, QuestionBank $bank): JsonResponse
    {
        $q = Question::where('bank_id', $bank->id)->where('status', $request->query('status', 'active'))->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))->when($request->query('difficulty'), fn ($q, $v) => $q->where('difficulty', $v))
            ->when($request->query('category_id'), fn ($q, $v) => $q->where('category_id', $v))->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->whereLike('stem_ar', "%{$t}%")->orWhereLike('stem_en', "%{$t}%")))
            ->when($request->query('tag'), fn ($q, $t) => $q->whereJsonContains('tags', $t))->orderByDesc('created_at')->limit(500)->get();

        return response()->json(['data' => $q->all()]);
    }

    public function storeQuestion(Request $request, QuestionBank $bank): JsonResponse
    {
        $r = $this->banks->create($bank, $request->validate($this->rules(true)), $this->user());

        return response()->json(['data' => $r['question']->toArray() + ['duplicate_of' => $r['duplicate_of']]], 201);
    }

    public function updateQuestion(Request $request, Question $question): JsonResponse
    {
        return response()->json(['data' => $this->banks->update($question, $request->validate($this->rules(false)), $this->user())]);
    }

    public function destroyQuestion(Question $question): JsonResponse
    {
        $question->update(['status' => 'retired']);

        return response()->json(['data' => ['retired' => true]]);
    }

    public function bulk(Request $request, QuestionBank $bank): JsonResponse
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['uuid'], 'category_id' => ['nullable', 'uuid'], 'difficulty' => ['nullable', Rule::in(['easy', 'medium', 'hard'])], 'tags' => ['nullable', 'array', 'max:20'], 'tags.*' => ['string', 'max:40'], 'status' => ['nullable', Rule::in(['draft', 'active', 'retired'])]]);

        return response()->json(['data' => ['updated' => $this->banks->bulk($bank, $d['ids'], $d)]]);
    }

    public function import(Request $request, QuestionBank $bank): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx']]);

        return response()->json(['data' => $this->banks->import($bank, $request->file('file')->getRealPath(), $this->user())]);
    }

    public function export(Request $request, QuestionBank $bank): Response
    {
        $format = $request->validate(['format' => ['sometimes', Rule::in(['csv', 'xlsx'])]])['format'] ?? 'xlsx';

        return response($this->banks->export($bank, $format), 200, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => "attachment; filename=\"questions.{$format}\""]);
    }

    private function rules(bool $create): array
    {
        $r = $create ? 'required' : 'sometimes';

        return ['type' => [$r, 'string', 'max:20'], 'stem_ar' => [$r, 'string', 'max:5000'], 'stem_en' => ['nullable', 'string', 'max:5000'], 'category_id' => ['nullable', 'uuid', 'exists:bank_categories,id'], 'payload' => [$r, 'array'], 'media' => ['nullable', 'array'], 'points' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'difficulty' => ['sometimes', Rule::in(['easy', 'medium', 'hard'])], 'skill_ids' => ['nullable', 'array', 'max:20'], 'skill_ids.*' => ['uuid'], 'explanation_ar' => ['nullable', 'string', 'max:3000'], 'explanation_en' => ['nullable', 'string', 'max:3000'], 'tags' => ['nullable', 'array', 'max:20'], 'tags.*' => ['string', 'max:40'], 'status' => ['sometimes', Rule::in(['draft', 'active', 'retired'])]];
    }
}
