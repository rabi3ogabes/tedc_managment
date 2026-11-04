<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Admin\ShowcaseAccounts;
use Illuminate\Http\JsonResponse;

/**
 * The sign-in page's showcase accounts (web and app): the supervisors, trainers and trainees, opened by anyone in one tap while
 * the system administrator leaves that on (Settings → Showcase accounts). Each opening is a normal session, written to the audit log.
 */
class ShowcaseSignInController extends AuthController
{
    public function index(ShowcaseAccounts $showcase): JsonResponse
    {
        return response()->json(['data' => $showcase->publicAccounts()]);
    }

    public function signIn(string $key, ShowcaseAccounts $showcase): JsonResponse
    {
        $user = $showcase->findPublic($key);
        abort_unless($user !== null, 404);
        $showcase->audit($user, 'showcase_public_sign_in', ['as' => $user->email], $user);

        return $this->complete($this->auth->issueForUser($user, 'showcase'));
    }
}
