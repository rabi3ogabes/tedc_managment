<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\RegistrationForm;
use App\Services\ExternalRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The public side of external registration: the form, the e-mail code and the submission. */
class ExternalFormController extends Controller
{
    public function __construct(private readonly ExternalRegistrationService $external) {}

    public function show(string $slug): JsonResponse
    {
        $form = RegistrationForm::where('slug', $slug)->where('is_active', true)->firstOrFail();
        if (($form->closes_at && $form->closes_at->isPast()) || ($form->opens_at && $form->opens_at->isFuture())) {
            return response()->json(['message' => __('messages.external.closed'), 'code' => 'form_closed'], 410);
        }

        return response()->json(['data' => [
            'slug' => $form->slug, 'title_ar' => $form->title_ar, 'title_en' => $form->title_en, 'intro_ar' => $form->intro_ar, 'intro_en' => $form->intro_en, 'audience' => $form->audience,
            'fields' => $form->fields, 'allowed_email_domains' => $form->conditions['allowed_email_domains'] ?? [], 'closes_at' => $form->closes_at?->toIso8601String(),
        ]]);
    }

    public function verifyEmail(Request $request, string $slug): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);
        $this->external->sendCode(RegistrationForm::where('slug', $slug)->firstOrFail(), strtolower($d['email']));

        return response()->json(['data' => ['sent' => true]]);
    }

    public function submit(Request $request, string $slug): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email:rfc', 'max:190'], 'code' => ['required', 'string', 'size:6'], 'data' => ['required', 'array']]);
        $r = $this->external->submit(RegistrationForm::where('slug', $slug)->firstOrFail(), $d['email'], $d['code'], $d['data']);

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]], 201);
    }
}
