<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Surveys\SurveyDataSubject;
use App\Services\Surveys\SurveyRetention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Survey Studio v2, Gate 7: the retention panel and a person's survey data for the privacy handlers. */
class SurveyPrivacyController extends Controller
{
    public function __construct(private readonly SurveyRetention $retention, private readonly SurveyDataSubject $subject) {}

    public function retention(): JsonResponse
    {
        return response()->json(['data' => $this->retention->report()]);
    }

    public function runRetention(Request $request): JsonResponse
    {
        $d = $request->validate(['dry_run' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $this->retention->run((bool) ($d['dry_run'] ?? true))]);
    }

    public function subject(User $user): JsonResponse
    {
        return response()->json(['data' => $this->subject->preview($user) + ['export' => $this->subject->export($user)]]);
    }

    public function erase(User $user): JsonResponse
    {
        return response()->json(['data' => $this->subject->erase($user, $this->user())]);
    }
}
