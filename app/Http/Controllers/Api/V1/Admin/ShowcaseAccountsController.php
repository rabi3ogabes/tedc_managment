<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Auth\JwtVerifier;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\ShowcaseAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings → Showcase accounts (system administrator only): create the showcase accounts and their sample training, open any of
 * them in this browser (one hour, audited, like "sign in as"), give one a password for the mobile app, or remove them all.
 */
class ShowcaseAccountsController extends Controller
{
    private const TTL = 3600;

    public function __construct(private readonly ShowcaseAccounts $showcase) {}

    public function show(Request $request): JsonResponse
    {
        $this->admin($request);

        return response()->json(['data' => $this->showcase->overview()]);
    }

    public function build(Request $request): JsonResponse
    {
        $this->showcase->build($this->admin($request));

        return response()->json(['data' => $this->showcase->overview()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $removed = $this->showcase->remove($this->admin($request));

        return response()->json(['data' => $this->showcase->overview(), 'removed' => $removed]);
    }

    public function open(Request $request, string $key, JwtVerifier $jwt, AuthController $auth): JsonResponse
    {
        $admin = $this->admin($request);
        $user = $this->account($key);
        $this->showcase->audit($admin, 'showcase_account_opened', ['admin' => $admin->email, 'as' => $user->email], $user);

        return response()->json([
            'access_token' => $jwt->issue(['sub' => $user->id, 'email' => $user->email, 'typ' => 'access', 'imp' => $admin->id], self::TTL),
            'refresh_token' => '',
            'token_type' => 'bearer',
            'expires_in' => self::TTL,
            'user' => $auth->profile($user),
            'impersonator' => ['id' => $admin->id, 'name' => $admin->displayName()],
        ]);
    }

    /** Records the return to the administrator's own account (the client discards the borrowed token). */
    public function close(Request $request, string $key): JsonResponse
    {
        $user = $this->account($key);
        $this->showcase->audit($request->user(), 'showcase_account_closed', ['as' => $user->email], $user);

        return response()->json(['message' => 'ok']);
    }

    /** Shows or hides the supervisors, trainers and trainees on the sign-in page (web and app). */
    public function publicSignIn(Request $request): JsonResponse
    {
        $this->showcase->setPublicSignIn($request->validate(['enabled' => ['required', 'boolean']])['enabled'], $this->admin($request));

        return response()->json(['data' => $this->showcase->overview()]);
    }

    /** Switches the code-protected leadership buttons on or off, and makes a new code. */
    public function leadership(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['sometimes', 'boolean'], 'regenerate' => ['sometimes', 'boolean']]);
        $this->showcase->setLeadership($data['enabled'] ?? null, (bool) ($data['regenerate'] ?? false), $this->admin($request));

        return response()->json(['data' => $this->showcase->overview()]);
    }

    public function appPassword(Request $request, string $key): JsonResponse
    {
        $admin = $this->admin($request);

        return response()->json(['data' => ['email' => ($user = $this->account($key))->email, 'password' => $this->showcase->appPassword($user, $admin)]]);
    }

    private function admin(Request $request): User
    {
        $admin = $request->user();
        abort_unless($admin?->isSuperAdmin(), 403, __('auth.forbidden'));

        return $admin;
    }

    private function account(string $key): User
    {
        $user = $this->showcase->find($key);
        abort_unless($user && $user->status === 'active', 404);

        return $user;
    }
}
