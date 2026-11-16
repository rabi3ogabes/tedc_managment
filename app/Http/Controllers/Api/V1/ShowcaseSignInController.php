<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Admin\ShowcaseAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The sign-in page's showcase accounts (web and app): the supervisors, trainers and trainees, opened by anyone in one tap while
 * the system administrator leaves that on (Settings → Showcase accounts). Each opening is a normal session, written to the audit log.
 */
class ShowcaseSignInController extends AuthController
{
    public function index(ShowcaseAccounts $showcase): JsonResponse
    {
        // `leadership` only says that a locked section exists: nothing about who is in it.
        return response()->json(['data' => $showcase->publicAccounts(), 'leadership' => $showcase->leadership()['enabled']]);
    }

    /** The code unlocks the leadership accounts: the list, if it is right. */
    public function leadership(Request $request, ShowcaseAccounts $showcase): JsonResponse
    {
        $this->assertCode($request, $showcase);

        return response()->json(['data' => $showcase->leadershipAccounts()]);
    }

    /** One tap on a leadership account, with the code: a normal session for it, written to the audit log. */
    public function leadershipSignIn(Request $request, string $key, ShowcaseAccounts $showcase): JsonResponse
    {
        $this->assertCode($request, $showcase);
        $user = $showcase->findLeadership($key);
        abort_unless($user !== null, 404);
        $showcase->audit($user, 'showcase_leadership_sign_in', ['as' => $user->email, 'ip' => $request->ip()], $user);

        return $this->complete($this->auth->issueForUser($user, 'showcase'));
    }

    private function assertCode(Request $request, ShowcaseAccounts $showcase): void
    {
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:40']])['code'];
        if (! $showcase->codeIsRight($code, $request->ip())) {
            throw ValidationException::withMessages(['code' => __('messages.showcase.code_wrong')]);
        }
    }

    public function signIn(string $key, ShowcaseAccounts $showcase): JsonResponse
    {
        $user = $showcase->findPublic($key);
        abort_unless($user !== null, 404);
        $showcase->audit($user, 'showcase_public_sign_in', ['as' => $user->email], $user);

        return $this->complete($this->auth->issueForUser($user, 'showcase'));
    }
}
