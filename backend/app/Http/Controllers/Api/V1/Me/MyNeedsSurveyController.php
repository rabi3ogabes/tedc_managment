<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\NeedsSurvey;
use App\Models\NeedsSurveyRecipient;
use App\Models\NeedsSurveyResponse;
use App\Services\IndividualNeedsService;
use App\Services\NeedsSurveys\SurveySchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Training-needs surveys addressed to the signed-in employee. */
class MyNeedsSurveyController extends Controller
{
    public function index(): JsonResponse
    {
        $recipients = NeedsSurveyRecipient::with('survey')
            ->where('user_id', $this->user()->id)
            ->whereHas('survey', fn ($q) => $q->whereIn('status', ['published', 'closed']))
            ->latest()
            ->get();

        return response()->json(['data' => $recipients->map(fn ($r) => $this->card($r->survey, $r))->values()]);
    }

    public function show(NeedsSurvey $needsSurvey): JsonResponse
    {
        $recipient = $this->recipient($needsSurvey);
        $response = NeedsSurveyResponse::where('survey_id', $needsSurvey->id)->where('user_id', $this->user()->id)->first();

        return response()->json(['data' => $this->card($needsSurvey, $recipient) + [
            'questions' => $needsSurvey->questions,
            'settings' => (object) ($needsSurvey->settings ?? []),
            'answers' => $response ? (object) $response->answers : null,
        ]]);
    }

    public function submit(Request $request, NeedsSurvey $needsSurvey): JsonResponse
    {
        $recipient = $this->recipient($needsSurvey);
        abort_unless($needsSurvey->isOpen(), 422, __('This survey is closed.'));

        $data = $request->validate([
            'answers' => ['present', 'array'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);
        $answers = SurveySchema::validateAnswers($needsSurvey->questions, $data['answers']);
        $employee = $this->user()->employee;

        NeedsSurveyResponse::updateOrCreate(
            ['survey_id' => $needsSurvey->id, 'user_id' => $this->user()->id],
            [
                'answers' => $answers,
                'duration_seconds' => $data['duration_seconds'] ?? null,
                'submitted_at' => now(),
                'school_id' => $employee?->school_id,
                'job_title_id' => $employee?->job_title_id,
                'experience_years' => $employee?->experience_years,
                'specialization' => $employee?->specialization,
                'nationality' => $employee?->nationality,
                'gender' => $employee?->gender,
                'education_stage' => $employee?->education_stage,
            ],
        );
        $recipient->update(['responded_at' => now()]);
        rescue(fn () => app(IndividualNeedsService::class)->fromSurveyResponse($needsSurvey, NeedsSurveyResponse::where('survey_id', $needsSurvey->id)->where('user_id', $this->user()->id)->first()), null, true);

        return response()->json(['message' => $needsSurvey->settings['thank_you'] ?? __('Thank you, your answers were saved.')]);
    }

    private function recipient(NeedsSurvey $needsSurvey): NeedsSurveyRecipient
    {
        abort_if($needsSurvey->status === 'draft', 404);

        return NeedsSurveyRecipient::where('survey_id', $needsSurvey->id)->where('user_id', $this->user()->id)->firstOr(fn () => abort(403, __('This survey is not addressed to you.')));
    }

    private function card(NeedsSurvey $needsSurvey, NeedsSurveyRecipient $recipient): array
    {
        return [
            'id' => $needsSurvey->id,
            'title' => $needsSurvey->title,
            'description' => $needsSurvey->description,
            'accent' => $needsSurvey->settings['accent'] ?? null,
            'anonymous' => $needsSurvey->isAnonymous(),
            'open' => $needsSurvey->isOpen(),
            'questions_count' => count(array_filter($needsSurvey->questions ?? [], fn ($q) => $q['type'] !== 'section')),
            'estimated_minutes' => max(1, (int) ceil(count($needsSurvey->questions ?? []) * 0.4)),
            'closes_at' => $needsSurvey->closes_at,
            'responded_at' => $recipient->responded_at,
            'invited_at' => $recipient->created_at,
        ];
    }
}
