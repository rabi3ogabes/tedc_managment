<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\RegistrationForm;
use App\Models\RegistrationRequest;
use App\Services\ExternalRegistrationService;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** External registration: the form builder and the review queue. */
class ExternalRequestController extends Controller
{
    public function __construct(private readonly ExternalRegistrationService $external) {}

    public function forms(): JsonResponse
    {
        return response()->json(['data' => RegistrationForm::withCount(['requests as requests_count'])->latest()->get()->map(fn ($f) => $f->toArray() + ['share_url' => rtrim((string) config('tedc.web_url'), '/').'/join/'.$f->slug])->all()]);
    }

    public function saveForm(Request $request, ?RegistrationForm $form = null): JsonResponse
    {
        $r = $form ? 'sometimes' : 'required';
        $d = $request->validate([
            'slug' => ['sometimes', 'alpha_dash', 'max:80', Rule::unique('registration_forms', 'slug')->ignore($form?->id)], 'title_ar' => [$r, 'string', 'max:200'], 'title_en' => [$r, 'string', 'max:200'], 'intro_ar' => ['nullable', 'string', 'max:3000'], 'intro_en' => ['nullable', 'string', 'max:3000'],
            'audience' => [$r, Rule::in(['trainee', 'trainer', 'other'])], 'fields' => [$r, 'array', 'min:1', 'max:60'], 'fields.*.key' => ['required', 'alpha_dash', 'max:40'], 'fields.*.type' => ['required', Rule::in(['text', 'email', 'phone', 'number', 'date', 'select', 'multiselect', 'file', 'national_id', 'textarea'])],
            'fields.*.label_ar' => ['required', 'string', 'max:200'], 'fields.*.label_en' => ['required', 'string', 'max:200'], 'fields.*.required' => ['sometimes', 'boolean'], 'fields.*.options' => ['sometimes', 'array', 'max:100'],
            'conditions' => ['sometimes', 'nullable', 'array'], 'conditions.allowed_email_domains' => ['sometimes', 'array', 'max:50'], 'conditions.allowed_email_domains.*' => ['string', 'max:120'], 'opens_at' => ['nullable', 'date'], 'closes_at' => ['nullable', 'date'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        if (! $form) {
            $d['slug'] ??= Str::slug($d['title_en']).'-'.Str::lower(Str::random(4));
        }
        $form = $form ? tap($form)->update($d) : RegistrationForm::create($d);

        return response()->json(['data' => $form->fresh()->toArray() + ['share_url' => rtrim((string) config('tedc.web_url'), '/').'/join/'.$form->slug]], $form->wasRecentlyCreated ? 201 : 200);
    }

    public function requests(Request $request): JsonResponse
    {
        $rows = RegistrationRequest::with('form:id,title_ar,title_en,audience,slug')->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))->when($request->query('form_id'), fn ($q, $v) => $q->where('form_id', $v))->latest()->limit(300)->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r))->all()]);
    }

    public function show(RegistrationRequest $registrationRequest): JsonResponse
    {
        return response()->json(['data' => $this->present($registrationRequest->load('form'), true)]);
    }

    public function snapshot(RegistrationRequest $registrationRequest, FileStorage $files): Response
    {
        abort_unless($registrationRequest->snapshot_path, 404);

        return response($files->get('documents', $registrationRequest->snapshot_path), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$registrationRequest->number.'.pdf"']);
    }

    public function approve(RegistrationRequest $registrationRequest): JsonResponse
    {
        return response()->json(['data' => $this->present($this->external->approve($registrationRequest->load('form'), $this->user()))]);
    }

    public function reject(Request $request, RegistrationRequest $registrationRequest): JsonResponse
    {
        return response()->json(['data' => $this->present($this->external->reject($registrationRequest->load('form'), $this->user(), $request->input('note')))]);
    }

    public function requestInfo(Request $request, RegistrationRequest $registrationRequest): JsonResponse
    {
        return response()->json(['data' => $this->present($this->external->requestInfo($registrationRequest->load('form'), $this->user(), $request->input('note')))]);
    }

    private function present(RegistrationRequest $r, bool $full = false): array
    {
        return ['id' => $r->id, 'number' => $r->number, 'email' => $r->email, 'phone' => $r->phone, 'status' => $r->status, 'decision_note' => $r->decision_note, 'form' => $r->form ? ['title_ar' => $r->form->title_ar, 'title_en' => $r->form->title_en, 'audience' => $r->form->audience] : null, 'created_at' => $r->created_at?->toIso8601String(), 'has_snapshot' => $r->snapshot_path !== null]
            + ($full ? ['data' => $r->data, 'fields' => $r->form?->fields] : []);
    }
}
