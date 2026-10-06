<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Models\Registration;
use App\Services\Content\OfflineService;
use App\Services\Library\ExternalLearningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Offline learning for the app, and the trainee's side of courses on other platforms. */
class MyOfflineController extends MeController
{
    public function __construct(private readonly OfflineService $offline, private readonly ExternalLearningService $external) {}

    public function manifest(Registration $registration): JsonResponse
    {
        $this->own($registration);

        return response()->json(['data' => $this->offline->manifest($registration)]);
    }

    public function sync(Request $request): JsonResponse
    {
        $d = $request->validate(['batch_id' => ['required', 'string', 'max:80'], 'items' => ['required', 'array', 'max:500']]);

        return response()->json(['data' => $this->offline->sync($this->user(), $d['batch_id'], $d['items'])]);
    }

    /** Where to go to take the course on the other platform. */
    public function launch(Registration $registration): JsonResponse
    {
        $this->own($registration);
        abort_unless(in_array($registration->status, [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED], true), 422);
        $registration->loadMissing('program', 'employee');

        return response()->json(['data' => ['url' => $this->external->launchUrl($registration), 'platform' => $registration->program->external_platform['name'] ?? null]]);
    }

    public function evidence(Request $request, Registration $registration): JsonResponse
    {
        $this->own($registration);
        $d = $request->validate(['note' => ['nullable', 'string', 'max:2000'], 'evidence' => ['sometimes', 'array', 'max:5'], 'evidence.*' => ['nullable']]);
        $files = array_merge($request->file('evidence', []) ?: [], array_filter((array) $request->input('evidence', []), 'is_string'));
        $registration->loadMissing('program');

        return response()->json(['data' => $this->external->submitEvidence($registration, $files, $d['note'] ?? null)], 201);
    }

    private function own(Registration $r): void
    {
        abort_unless($r->employee_id === $this->employee()->id, 404);
    }
}
