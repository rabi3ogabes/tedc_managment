<?php

namespace App\Security;

use App\Auth\JwtVerifier;
use App\Models\SsoState;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A sign-in that passed the password and is waiting for the second factor. The finished session is kept encrypted for ten minutes
 * under a `cid` carried in a short `mfa` token; the browser gets only that token until the code is right.
 */
class PendingLogin
{
    public function __construct(private readonly JwtVerifier $verifier) {}

    /** @param  array<string, mixed>  $session  the session array of AuthService (tokens and user id) */
    public function hold(User $user, array $session, string $purpose = 'verify'): string
    {
        $cid = (string) Str::uuid();
        $data = $session;
        unset($data['user']);
        SsoState::create(['state' => $cid, 'nonce' => $user->id, 'verifier' => $purpose, 'kind' => 'mfa', 'session' => ['enc' => Crypt::encryptString(json_encode($data))], 'expires_at' => now()->addMinutes(10)]);

        return $this->verifier->issue(['sub' => $user->id, 'typ' => 'mfa', 'cid' => $cid, 'purpose' => $purpose], 600);
    }

    /** @return array{0: User, 1: string, 2: SsoState} */
    public function resolve(string $mfaToken): array
    {
        try {
            $claims = $this->verifier->decodeTyped($mfaToken, 'mfa');
        } catch (Throwable) {
            throw ValidationException::withMessages(['mfa_token' => __('auth.mfa_expired')]);
        }
        $state = SsoState::where('state', $claims->cid)->where('kind', 'mfa')->first();
        $user = User::with('roles')->whereKey($claims->sub)->where('status', 'active')->first();
        if (! $state || $state->expires_at->isPast() || ! $user) {
            throw ValidationException::withMessages(['mfa_token' => __('auth.mfa_expired')]);
        }

        return [$user, $state->state, $state];
    }

    /** The held session, once, with the user attached. @return array<string, mixed> */
    public function release(User $user, SsoState $state): array
    {
        $data = json_decode(Crypt::decryptString($state->session['enc']), true);
        $state->delete();

        return ['user' => $user] + $data;
    }
}
