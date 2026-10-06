<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DataSubjectRequest;
use App\Ops\DataSubjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** "My data": download everything held about me, and ask for correction, erasure, restriction or to object — answered within 30 days. */
class PrivacyController extends Controller
{
    public function __construct(private readonly DataSubjectService $service) {}

    public function mine(): JsonResponse
    {
        return response()->json(['data' => DataSubjectRequest::where('user_id', $this->user()->id)->latest()->limit(50)->get()->map(fn ($r) => $this->present($r))]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['type' => ['required', Rule::in(DataSubjectService::TYPES)], 'details' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $this->present($this->service->request($this->user(), $d['type'], $d['details'] ?? null))], 201);
    }

    public function export(): Response
    {
        $json = json_encode($this->service->export($this->user()), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);

        return response($json, 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="my-data-'.now()->format('Ymd').'.json"', 'Cache-Control' => 'no-store']);
    }

    // ---- handlers ------------------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $rows = DataSubjectRequest::with('user:id,name,name_ar,email')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->orderBy('due_at')->limit(300)->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r) + ['person' => $r->user?->displayName(), 'email' => $r->user?->email])->values()]);
    }

    public function decide(Request $request, string $dsr): JsonResponse
    {
        abort_unless(Str::isUuid($dsr), 404);
        $d = $request->validate(['status' => ['required', Rule::in(['in_progress', 'completed', 'rejected'])], 'resolution' => ['nullable', 'string', 'max:3000']]);

        return response()->json(['data' => $this->present($this->service->decide(DataSubjectRequest::findOrFail($dsr), $this->user(), $d['status'], $d['resolution'] ?? null))]);
    }

    /** @return array<string, mixed> */
    private function present(DataSubjectRequest $r): array
    {
        return ['id' => $r->id, 'type' => $r->type, 'details' => $r->details, 'status' => $r->status, 'due_at' => $r->due_at->toIso8601String(), 'overdue' => in_array($r->status, ['received', 'in_progress'], true) && $r->due_at->isPast(), 'resolution' => $r->resolution, 'created_at' => $r->created_at->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String()];
    }
}
