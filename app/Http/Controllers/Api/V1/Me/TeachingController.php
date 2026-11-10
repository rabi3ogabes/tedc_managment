<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\KitAccessEvent;
use App\Models\KitAsset;
use App\Models\KitFile;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TrainingKit;
use App\Services\FileStorage;
use App\Services\Kits\KitAccess;
use App\Services\Teaching\KitProtection;
use App\Services\Teaching\TeachingAccess;
use App\Services\Teaching\TrainerNotices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The trainer's teaching desk: the programmes they teach, the training kits attached to them — read and presented in the
 * protected viewer only, never downloaded — the popup notices of new assignments, and starting a session. The training kit
 * belongs to the training centre; nothing here returns a storage address, and every file is streamed through the API
 * with no caching so the browser keeps no copy.
 */
class TeachingController extends Controller
{
    public function __construct(private readonly TeachingAccess $access, private readonly KitProtection $protection, private readonly TrainerNotices $notices, private readonly FileStorage $storage) {}

    // ───────────────────────────── the desk

    public function home(): JsonResponse
    {
        $user = $this->user();
        $programs = Program::with(['kits:id,code,title_ar,title_en,version,status,duration_hours', 'groups', 'coordinator'])->whereIn('id', $this->access->programIds($user))->orderBy('start_date')->get();
        $now = now();

        $rows = $programs->map(function (Program $p) use ($now) {
            $sessions = ProgramSession::with('room')->where('program_id', $p->id)->where('status', '!=', 'cancelled')->orderBy('starts_at')->get();
            $next = $sessions->first(fn ($s) => $s->ends_at >= $now) ?? $sessions->last();
            $groups = $p->groups->sortBy('sequence')->map(fn ($g) => ['id' => $g->id, 'title_ar' => $g->displayTitle('ar'), 'title_en' => $g->displayTitle('en')] + Arr::only($this->notices->details($p, $g), ['from', 'to', 'rooms', 'supervisor']))->values();

            return ['id' => $p->id, 'code' => $p->code, 'title_ar' => $p->title_ar, 'title_en' => $p->title_en ?: $p->title_ar, 'status' => $p->status, 'delivery_mode' => $p->delivery_mode, 'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString(),
                'sessions' => $sessions->count(), 'next_session' => $next ? $this->sessionRow($next) : null, 'groups' => $groups,
                'trainees' => Registration::where('program_id', $p->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->count(),
                'kits' => $p->kits->filter(fn ($k) => in_array($k->status, [TrainingKit::APPROVED, TrainingKit::PUBLISHED], true))->map(fn ($k) => ['id' => $k->id, 'code' => $k->code, 'title_ar' => $k->title_ar, 'title_en' => $k->title_en ?: $k->title_ar, 'version' => $k->version, 'hours' => (float) $k->duration_hours, 'files' => $k->files()->count()])->values()->all()];
        })->values();

        return response()->json(['data' => ['programs' => $rows, 'ownership' => ['ar' => TrainerNotices::OWNERSHIP_AR, 'en' => TrainerNotices::OWNERSHIP_EN]]]);
    }

    public function program(Program $program): JsonResponse
    {
        abort_unless($this->access->teaches($this->user(), $program), 403, __('auth.forbidden'));
        $program->load(['kits', 'groups']);
        $sessions = ProgramSession::with('room')->where('program_id', $program->id)->where('status', '!=', 'cancelled')->orderBy('starts_at')->get();

        return response()->json(['data' => ['id' => $program->id, 'code' => $program->code, 'title_ar' => $program->title_ar, 'title_en' => $program->title_en ?: $program->title_ar, 'status' => $program->status, 'delivery_mode' => $program->delivery_mode,
            'sessions' => $sessions->map(fn ($s) => $this->sessionRow($s))->values(), 'roster' => $this->access->roster($program),
            'kits' => $program->kits->filter(fn ($k) => in_array($k->status, [TrainingKit::APPROVED, TrainingKit::PUBLISHED], true))->map(fn ($k) => ['id' => $k->id, 'code' => $k->code, 'title_ar' => $k->title_ar, 'title_en' => $k->title_en ?: $k->title_ar, 'version' => $k->version, 'files' => $k->files->map(fn ($f) => $this->access->fileRow($f))->values()])->values()]]);
    }

    // ───────────────────────────── kits: reading, never taking

    public function kit(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        $program = $this->access->programFor($this->user(), $kit, $request->query('program'));
        $kit->load('files');

        return response()->json(['data' => ['id' => $kit->id, 'code' => $kit->code, 'title_ar' => $kit->title_ar, 'title_en' => $kit->title_en ?: $kit->title_ar, 'description_ar' => $kit->description_ar, 'description_en' => $kit->description_en, 'version' => $kit->version, 'hours' => (float) $kit->duration_hours,
            'objectives' => $kit->objectives, 'files' => $kit->files->sortBy('sort_order')->map(fn ($f) => $this->access->fileRow($f))->values(), 'program_id' => $program?->id,
            'acknowledged' => $this->access->acknowledged($this->user(), $kit), 'protection' => $this->protection->all(), 'viewer' => $this->viewerStamp(),
            'ownership' => ['ar' => TrainerNotices::OWNERSHIP_AR, 'en' => TrainerNotices::OWNERSHIP_EN]]]);
    }

    public function acknowledge(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        if (! $this->access->acknowledged($this->user(), $kit)) {
            $this->access->log($this->user(), $kit, 'acknowledged', $request);
        }

        return response()->json(['data' => ['acknowledged' => true]]);
    }

    /** What the viewer renders: the deck (pictures through the protected route) or the way to stream the file — never a storage address. */
    public function view(Request $request, TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->viewable($kit);
        $program = $this->access->programFor($this->user(), $kit, $request->query('program'));
        $this->access->log($this->user(), $kit, 'opened', $request, $file, $program, null, ['kind' => $file->kind]);
        $base = "/me/teaching/kits/{$kit->id}/files/{$file->id}";

        $data = ['file' => $this->access->fileRow($file), 'kit' => ['id' => $kit->id, 'title_ar' => $kit->title_ar, 'title_en' => $kit->title_en ?: $kit->title_ar], 'stream' => $file->storage_path ? "{$base}/stream" : null, 'deck' => null];
        if ($file->isDeck()) {
            $data['deck'] = $this->access->deckFor($file, $kit);
        }

        return response()->json(['data' => $data]);
    }

    /** The file's bytes for the viewer — inline, uncached, with range support for video; there is no download route. */
    public function stream(Request $request, TrainingKit $kit, KitFile $file): Response
    {
        $this->viewable($kit);
        abort_unless($file->storage_path, 404);

        return $this->bytes($request, $this->storage->get('documents', $file->storage_path), (string) ($file->mime ?: 'application/octet-stream'));
    }

    public function asset(Request $request, TrainingKit $kit, KitAsset $asset): Response
    {
        $this->viewable($kit);
        abort_unless($asset->kit_id === $kit->id, 404);

        return $this->bytes($request, $this->storage->get($asset->bucket(), $asset->storage_path), (string) ($asset->mime ?: 'application/octet-stream'));
    }

    // ───────────────────────────── the trail

    public function event(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        $data = $request->validate(['kind' => ['required', Rule::in(KitAccessEvent::KINDS)], 'file_id' => ['nullable', 'uuid'], 'program_id' => ['nullable', 'uuid'], 'session_id' => ['nullable', 'uuid'], 'meta' => ['nullable', 'array'], 'meta.*' => ['nullable']]);
        $file = ! empty($data['file_id']) ? $kit->files()->whereKey($data['file_id'])->first() : null;
        $program = $this->access->programFor($this->user(), $kit, $data['program_id'] ?? null);
        $meta = collect($data['meta'] ?? [])->only(['reason', 'slides', 'seen', 'seconds', 'slide', 'rehearsal', 'surface', 'key'])->map(fn ($v) => is_scalar($v) ? (is_string($v) ? mb_substr($v, 0, 80) : $v) : null)->all();
        $this->access->log($this->user(), $kit, $data['kind'], $request, $file, $program, $data['session_id'] ?? null, $meta);

        return response()->json(['data' => ['logged' => true]]);
    }

    // ───────────────────────────── starting a session

    /** Starts the session the trainer is about to teach (from an hour before it begins until it ends); the presenter opens from here. */
    public function startSession(ProgramSession $session): JsonResponse
    {
        $user = $this->user();
        $program = $session->program;
        abort_unless($this->access->teaches($user, $program), 403, __('auth.forbidden'));
        $trainer = $this->access->trainerOf($user);
        if (! KitAccess::isStaff($user) && $session->trainer_id && $trainer && $session->trainer_id !== $trainer->id) {
            throw new BusinessRuleException(__('messages.teaching.not_your_session'), 'not_your_session');
        }
        if ($session->status === 'cancelled' || $session->status === 'completed') {
            throw new BusinessRuleException(__('messages.teaching.session_closed'), 'session_closed');
        }
        if ($session->status !== 'live') {
            if (now()->lt($session->starts_at->copy()->subHour()) || now()->gt($session->ends_at)) {
                throw new BusinessRuleException(__('messages.teaching.outside_window'), 'outside_window');
            }
            DB::transaction(fn () => $session->update(['status' => 'live']));
        }

        return response()->json(['data' => $this->sessionRow($session->fresh('room'))]);
    }

    // ───────────────────────────── the popup on the trainer's home

    public function notices(): JsonResponse
    {
        return response()->json(['data' => $this->notices->popup($this->user())]);
    }

    public function dismiss(string $notice): JsonResponse
    {
        $this->notices->dismiss($this->user(), $notice);

        return response()->json(['data' => ['dismissed' => true]]);
    }

    public function dismissAll(): JsonResponse
    {
        return response()->json(['data' => ['dismissed' => $this->notices->dismissAll($this->user())]]);
    }

    // ───────────────────────────── helpers

    private function viewable(TrainingKit $kit): void
    {
        $user = $this->user();
        abort_unless(in_array($kit->status, [TrainingKit::APPROVED, TrainingKit::PUBLISHED], true) && ($this->access->canView($user, $kit) || (KitAccess::isStaff($user) && $user->hasPermission('kits.view'))), 403, __('auth.forbidden'));
    }

    /** The name and number the watermark carries. @return array{name: string, number: string, centre: string} */
    private function viewerStamp(): array
    {
        $user = $this->user();

        return ['name' => $user->displayName(app()->getLocale()), 'number' => (string) ($user->employee?->employee_no ?? substr((string) $user->id, 0, 8)), 'id' => substr((string) $user->id, 0, 8)];
    }

    /** @return array<string, mixed> */
    private function sessionRow(ProgramSession $s): array
    {
        return ['id' => $s->id, 'program_id' => $s->program_id, 'sequence' => $s->sequence, 'title_ar' => $s->title_ar, 'title_en' => $s->title_en, 'starts_at' => $s->starts_at?->toIso8601String(), 'ends_at' => $s->ends_at?->toIso8601String(), 'status' => $s->status, 'mode' => $s->mode,
            'room' => $s->room ? ['ar' => $s->room->name_ar ?: $s->room->name_en, 'en' => $s->room->name_en ?: $s->room->name_ar] : null];
    }

    /** Serves bytes inline with no caching and nosniff; honours a Range header so video can seek. */
    private function bytes(Request $request, string $bytes, string $mime): Response
    {
        $headers = ['Content-Type' => $mime, 'Content-Disposition' => 'inline', 'Cache-Control' => 'no-store, private, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Accept-Ranges' => 'bytes'];
        $size = strlen($bytes);
        if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) $request->header('Range'), $m) && ($m[1] !== '' || $m[2] !== '')) {
            $start = $m[1] === '' ? max(0, $size - (int) $m[2]) : (int) $m[1];
            $end = $m[1] === '' || $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
            if ($start > $end || $start >= $size) {
                return response('', 416, $headers + ['Content-Range' => "bytes */{$size}"]);
            }

            return response(substr($bytes, $start, $end - $start + 1), 206, $headers + ['Content-Range' => "bytes {$start}-{$end}/{$size}", 'Content-Length' => (string) ($end - $start + 1)]);
        }

        return response($bytes, 200, $headers + ['Content-Length' => (string) $size]);
    }
}
