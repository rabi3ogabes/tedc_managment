<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Security\DemoAccountAudit;
use App\Support\DemoGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings → Security: accounts that may still use the shared demo password, and suspending or resetting them. */
class DemoAccountsController extends Controller
{
    public function __construct(private readonly DemoAccountAudit $audit) {}

    public function show(Request $request): JsonResponse
    {
        if ($request->boolean('rescan')) {
            $this->audit->ids(true);
        }

        return response()->json(['data' => ['accounts' => $this->audit->list($this->user()), 'demo_mode' => DemoGuard::allowed()]]);
    }

    public function neutralise(Request $request): JsonResponse
    {
        $d = $request->validate(['mode' => ['required', Rule::in(['suspend', 'reset'])], 'user_ids' => ['required', 'array', 'min:1', 'max:1000'], 'user_ids.*' => ['uuid']]);

        return response()->json(['data' => $this->audit->neutralise($this->user(), $d['user_ids'], $d['mode'], $request->ip())]);
    }
}
