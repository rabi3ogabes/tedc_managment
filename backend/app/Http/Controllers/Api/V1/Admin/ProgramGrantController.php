<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\ProgramGrant;
use App\Models\User;
use App\Services\ProgramGrantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff & grants tab of a program: who may mark attendance, notify trainees, review tasks or assign kits for it. */
class ProgramGrantController extends Controller
{
    public function __construct(private readonly ProgramGrantService $grants) {}

    public function index(Program $program): JsonResponse
    {
        return response()->json(['data' => ProgramGrant::with('user:id,name,name_ar,email')->where('program_id', $program->id)->orderBy('ability')->get()->map(fn (ProgramGrant $g) => [
            'id' => $g->id, 'ability' => $g->ability, 'expires_at' => $g->expires_at?->toIso8601String(), 'expired' => $g->expires_at?->isPast() ?? false, 'created_at' => $g->created_at->toIso8601String(),
            'user' => ['id' => $g->user->id, 'name' => $g->user->displayName(), 'email' => $g->user->email],
        ])->values()]);
    }

    public function store(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'uuid', 'exists:users,id'], 'ability' => ['required', Rule::in(ProgramGrant::ABILITIES)], 'expires_at' => ['nullable', 'date', 'after:now']]);
        [$grant, $created] = $this->grants->grant($program, User::findOrFail($data['user_id']), $data['ability'], $this->user(), $data['expires_at'] ?? null, $request);

        return response()->json(['data' => ['id' => $grant->id, 'ability' => $grant->ability, 'expires_at' => $grant->expires_at?->toIso8601String()]], $created ? 201 : 200);
    }

    public function destroy(Request $request, Program $program, ProgramGrant $grant): JsonResponse
    {
        abort_unless($grant->program_id === $program->id, 404);
        $this->grants->revoke($grant, $this->user(), $request);

        return response()->json(['data' => ['revoked' => true]]);
    }
}
