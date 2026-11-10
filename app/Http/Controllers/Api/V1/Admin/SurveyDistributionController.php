<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Models\SurveyDistribution;
use App\Models\SurveyFile;
use App\Models\SurveyInvitation;
use App\Services\Channels\ChannelSettings;
use App\Services\Surveys\SurveyDistributor;
use App\Services\Surveys\SurveyFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Survey Studio v2, Gate 4: the public link and its QR, invitations, reminders, and the files answers carry. */
class SurveyDistributionController extends Controller
{
    public function __construct(private readonly SurveyDistributor $distributor, private readonly SurveyFiles $files) {}

    public function index(Survey $studioSurvey): JsonResponse
    {
        $public = SurveyDistribution::where('survey_id', $studioSurvey->id)->where('channel', 'public')->first();
        $invitations = SurveyInvitation::where('survey_id', $studioSurvey->id)->orderByDesc('created_at')->limit(500)->get();

        return response()->json(['data' => [
            'public' => $public ? $this->distributor->present($public) : null,
            'invitations' => $invitations->map(fn ($i) => $this->distributor->presentInvitation($i))->values(),
            'invitation_stats' => ['total' => $invitations->count(), 'sent' => $invitations->where('send_status', 'sent')->count(), 'manual' => $invitations->where('send_status', 'manual')->count(),
                'failed' => $invitations->where('send_status', 'failed')->count(), 'opened' => $invitations->whereNotNull('opened_at')->count(), 'responded' => $invitations->whereNotNull('responded_at')->count()],
            'mail_ready' => app(ChannelSettings::class)->ready('email'),
            'web_url' => $this->distributor->webUrl(),
        ]]);
    }

    public function storePublic(Request $request, Survey $studioSurvey): JsonResponse
    {
        $d = $request->validate(['duplicate_rule' => ['sometimes', Rule::in(SurveyDistribution::RULES)], 'max_responses' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $dist = $this->distributor->publicLinkFor($studioSurvey, $this->user(), $d['duplicate_rule'] ?? 'device', $d['max_responses'] ?? null);

        return response()->json(['data' => $this->distributor->present($dist)], 201);
    }

    public function update(Request $request, Survey $studioSurvey, SurveyDistribution $distribution): JsonResponse
    {
        abort_unless($distribution->survey_id === $studioSurvey->id, 404);
        $d = $request->validate(['is_active' => ['sometimes', 'boolean'], 'duplicate_rule' => ['sometimes', Rule::in(SurveyDistribution::RULES)], 'max_responses' => ['nullable', 'integer', 'min:1', 'max:1000000']]);

        return response()->json(['data' => $this->distributor->present($this->distributor->update($distribution, $this->user(), $d))]);
    }

    public function regenerate(Survey $studioSurvey, SurveyDistribution $distribution): JsonResponse
    {
        abort_unless($distribution->survey_id === $studioSurvey->id, 404);

        return response()->json(['data' => $this->distributor->present($this->distributor->regenerate($distribution, $this->user()))]);
    }

    public function qr(Survey $studioSurvey, SurveyDistribution $distribution, string $format): Response
    {
        abort_unless($distribution->survey_id === $studioSurvey->id && $distribution->channel === 'public', 404);
        $format = $format === 'png' ? 'png' : 'svg';
        $body = $this->distributor->qr($distribution, $format);

        return response($body, 200, ['Content-Type' => $format === 'png' ? 'image/png' : 'image/svg+xml', 'Content-Disposition' => 'inline; filename="survey-'.$distribution->slug.'.'.$format.'"', 'Cache-Control' => 'private, max-age=300']);
    }

    public function invite(Request $request, Survey $studioSurvey): JsonResponse
    {
        $d = $request->validate(['people' => ['required', 'array', 'min:1', 'max:500'], 'people.*.email' => ['required', 'email:rfc', 'max:190'], 'people.*.name' => ['nullable', 'string', 'max:120']]);

        return response()->json(['data' => $this->distributor->invite($studioSurvey, $this->user(), $d['people'])], 201);
    }

    public function remind(Survey $studioSurvey): JsonResponse
    {
        return response()->json(['data' => $this->distributor->remind($studioSurvey, $this->user())]);
    }

    /** A short-lived link to one answer file, for people allowed to see results. */
    public function file(Survey $studioSurvey, SurveyFile $surveyFile): JsonResponse
    {
        abort_unless($surveyFile->survey_id === $studioSurvey->id && $surveyFile->submission_id, 404);

        return response()->json(['data' => $this->files->present($surveyFile) + ['url' => $this->files->url($surveyFile)]]);
    }
}
