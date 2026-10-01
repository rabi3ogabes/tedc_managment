<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\ProfileChangeRequest;
use App\Services\Account\AccountFields;
use App\Services\Account\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** "My account": everything known about the signed-in user, read-only, plus change requests. */
class AccountController extends Controller
{
    public function __construct(private readonly AccountService $account) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->account->profile($request->user())]);
    }

    public function requests(Request $request): JsonResponse
    {
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';

        return response()->json(['data' => ProfileChangeRequest::where('user_id', $request->user()->id)->latest()->limit(50)->get()->map(fn (ProfileChangeRequest $r) => $this->row($r, $locale))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'string', Rule::in(array_keys(AccountFields::all()))],
            'kind' => ['nullable', Rule::in(['wrong', 'missing', 'update'])],
            'requested_value' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $created = $this->account->request($request->user(), $data);

        return response()->json(['data' => $this->row($created, app()->getLocale() === 'en' ? 'en' : 'ar')], 201);
    }

    /** Withdraws a request that has not been reviewed yet. */
    public function cancel(Request $request, ProfileChangeRequest $changeRequest): JsonResponse
    {
        abort_unless($changeRequest->user_id === $request->user()->id && $changeRequest->status === 'pending', 404);
        $changeRequest->update(['status' => 'cancelled']);

        return response()->json(['data' => $this->row($changeRequest, app()->getLocale() === 'en' ? 'en' : 'ar')]);
    }

    private function row(ProfileChangeRequest $r, string $locale): array
    {
        $f = AccountFields::field($r->field);

        return [
            'id' => $r->id, 'field' => $r->field, 'label' => $f['label'][$locale] ?? $r->field, 'kind' => $r->kind, 'status' => $r->status,
            'current_value' => $r->current_value, 'requested_value' => $f && $f['options'] ? ($f['options'][$r->requested_value][$locale] ?? $r->requested_value) : $r->requested_value,
            'note' => $r->note, 'applied' => $r->applied, 'review_note' => $r->review_note,
            'created_at' => $r->created_at->toIso8601String(), 'reviewed_at' => $r->reviewed_at?->toIso8601String(),
        ];
    }
}
