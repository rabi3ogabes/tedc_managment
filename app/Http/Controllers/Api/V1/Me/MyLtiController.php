<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Services\Lti\LtiToolException;
use App\Services\Lti\LtiToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A launch from another LMS that waits for the person to sign in and confirm linking their TEDC account. */
class MyLtiController extends Controller
{
    public function link(Request $request, LtiToolService $tool): JsonResponse
    {
        $d = $request->validate(['ticket' => ['required', 'string', 'max:64']]);
        try {
            return response()->json(['data' => $tool->confirmLink($this->user(), $d['ticket'])]);
        } catch (LtiToolException $e) {
            return response()->json(['message' => __('messages.lti_tool.'.$e->key), 'code' => 'lti_'.$e->key], 422);
        }
    }
}
