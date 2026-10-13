<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Models\OfflineDevice;
use App\Models\Registration;
use App\Services\Content\OfflineService;
use App\Services\Library\ExternalLearningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Offline learning for the app, and the trainee's side of courses on other platforms. */
class MyOfflineController extends MeController
{
    public function __construct(private readonly OfflineService $offline, private readonly ExternalLearningService $external) {}

    public function manifest(Request $request, Registration $registration): JsonResponse
    {
        $this->own($registration);
        $d = $request->validate(['device_id' => ['nullable', 'string', 'min:8', 'max:64'], 'platform' => ['nullable', 'string', 'max:20']]);

        return response()->json(['data' => $this->offline->manifest($registration->loadMissing('employee'), $d['device_id'] ?? null, $d['platform'] ?? null)]);
    }

    /** Applies offline events once, and tells the device which courses it must delete (withdrawn, or the device revoked). */
    public function sync(Request $request): JsonResponse
    {
        $d = $request->validate(['batch_id' => ['required', 'string', 'max:80'], 'items' => ['present', 'array', 'max:500'], 'device_id' => ['nullable', 'string', 'max:64'],
            'held' => ['nullable', 'array', 'max:200'], 'held.*' => ['string', 'max:64']]);
        $result = $this->offline->sync($this->user(), $d['batch_id'], $d['items']);

        return response()->json(['data' => $result + ['purge' => $this->offline->purge($this->user(), $d['device_id'] ?? null, $d['held'] ?? [])]]);
    }

    /** The phones that keep this person's courses offline. */
    public function devices(): JsonResponse
    {
        return response()->json(['data' => OfflineDevice::where('user_id', $this->user()->id)->latest('last_seen_at')->get()
            ->map(fn (OfflineDevice $d) => ['id' => $d->id, 'platform' => $d->platform, 'name' => $d->name, 'last_seen_at' => $d->last_seen_at?->toIso8601String(), 'revoked' => $d->revoked()])->values()]);
    }

    /** Revokes a lost or replaced phone: its next contact deletes the offline content and its key is replaced. */
    public function revokeDevice(OfflineDevice $offlineDevice): JsonResponse
    {
        abort_unless($offlineDevice->user_id === $this->user()->id, 404);
        $offlineDevice->update(['revoked_at' => now(), 'content_key' => base64_encode(random_bytes(32))]);

        return response()->json(['message' => 'ok']);
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
