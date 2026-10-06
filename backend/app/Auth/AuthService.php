<?php

namespace App\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Security\AuthSessions;
use App\Support\Supabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Issues sessions either through Supabase Auth (GoTrue) or locally.
 */
class AuthService
{
    public function __construct(private readonly JwtVerifier $verifier) {}

    public function usesSupabase(): bool
    {
        return config('tedc.auth.driver') === 'supabase';
    }

    /** @return array{user: User, access_token: string, refresh_token: string, expires_in: int} */
    public function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));

        return $this->usesSupabase()
            ? $this->supabaseGrant('password', ['email' => $email, 'password' => $password])
            : $this->localLogin($email, $password);
    }

    /** Confirms the signed-in user's password again (unlocking an idle dashboard), without opening a new session. */
    public function checkPassword(User $user, string $password): bool
    {
        try {
            $this->usesSupabase() ? $this->supabaseGrant('password', ['email' => strtolower($user->email), 'password' => $password]) : $this->localLogin(strtolower($user->email), $password);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function refresh(string $refreshToken): array
    {
        // Sessions the platform opened itself (local, SSO, LDAP, after a second factor) carry a platform refresh token even when Supabase is the main identity store.
        $claims = null;
        try {
            $claims = $this->verifier->decodeRefresh($refreshToken);
        } catch (Throwable) {
            if (! $this->usesSupabase()) {
                throw ValidationException::withMessages(['refresh_token' => __('auth.invalid_refresh')]);
            }
        }
        if ($claims === null) {
            return $this->supabaseGrant('refresh_token', ['refresh_token' => $refreshToken]);
        }

        $user = User::whereKey($claims->sub)->where('status', 'active')->first();
        if (! $user) {
            throw ValidationException::withMessages(['refresh_token' => __('auth.invalid_refresh')]);
        }
        // A session that timed out, ended or was terminated cannot be renewed.
        $sid = $claims->sid ?? null;
        if ($sid) {
            $session = app(AuthSessions::class)->touch((string) $sid, $user);
            if (! $session) {
                throw ValidationException::withMessages(['refresh_token' => __('auth.session_ended')]);
            }

            return $this->issueLocal($user, $session->method, $session);
        }

        return $this->issueLocal($user);
    }

    private function localLogin(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->password || ! Hash::check($password, $user->password) || $user->status !== 'active') {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->issueLocal($user);
    }

    /** Signs a platform session for someone already verified (password, second factor, SSO, LDAP). */
    public function issueForUser(User $user, string $method = 'password'): array
    {
        $user->forceFill(['last_login_at' => now(), 'last_active_at' => now(), 'locked_at' => null])->saveQuietly();

        return $this->issueLocal($user, $method);
    }

    private function issueLocal(User $user, string $method = 'password', ?AuthSession $session = null): array
    {
        $ttl = config('tedc.auth.token_ttl');
        $session ??= app(AuthSessions::class)->open($user, request(), $method);
        $claims = ['sub' => $user->auth_id ?? $user->id, 'email' => $user->email, 'sid' => $session->id];
        // The refresh token never outlives the session's absolute lifetime.
        $refreshTtl = max(60, min((int) config('tedc.auth.refresh_ttl'), (int) now()->diffInSeconds($session->expires_at, false)));

        return [
            'user' => $user,
            'access_token' => $this->verifier->issue($claims + ['typ' => 'access'], $ttl),
            'refresh_token' => $this->verifier->issue(['sub' => $user->id, 'typ' => 'refresh', 'sid' => $session->id], $refreshTtl),
            'expires_in' => $ttl,
            'session_id' => $session->id,
        ];
    }

    /** Records the provider's session (Supabase `session_id`) in the registry so it can expire and be terminated. */
    private function register(User $user, string $accessToken): void
    {
        $parts = explode('.', $accessToken);
        $payload = isset($parts[1]) ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
        $external = $payload['session_id'] ?? null;
        if ($external) {
            app(AuthSessions::class)->open($user, request(), 'password', (string) $external);
        }
    }

    private function supabaseGrant(string $grant, array $payload): array
    {
        try {
            $response = Supabase::public()->post(Supabase::url("/auth/v1/token?grant_type={$grant}"), $payload);
        } catch (ConnectionException $e) {
            Log::error('Supabase Auth is unreachable: '.$e->getMessage(), Supabase::isCertificateError($e) ? ['hint' => Supabase::certificateHint()] : []);

            throw ValidationException::withMessages(['email' => __(Supabase::isCertificateError($e) ? 'auth.tls_error' : 'auth.unreachable')]);
        }

        if ($response->failed()) {
            $code = $response->json('error_code') ?? $response->json('code');
            Log::info('Supabase Auth rejected the '.$grant.' grant', [
                'status' => $response->status(),
                'error_code' => $code,
                'message' => $response->json('msg') ?? $response->json('error_description') ?? $response->json('message'),
            ]);

            if ($code === 'email_not_confirmed') {
                throw ValidationException::withMessages(['email' => __('auth.email_not_confirmed')]);
            }

            if ($grant === 'password' && User::where('email', strtolower((string) ($payload['email'] ?? '')))->whereNull('auth_id')->exists()) {
                // Not revealed to the client (account enumeration); tells the operator what to do.
                Log::warning('Supabase login failed for a platform user that is not linked to Supabase Auth. Run: php artisan tedc:supabase-sync-users --password=...', ['email' => $payload['email']]);
            }

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $data = $response->json();
        $authUser = $data['user'] ?? [];

        $user = User::where('auth_id', $authUser['id'] ?? null)->first()
            ?? User::where('email', strtolower($authUser['email'] ?? ''))->first();

        if (! $user || $user->status !== 'active') {
            throw ValidationException::withMessages(['email' => __('auth.not_provisioned')]);
        }

        $user->forceFill(['auth_id' => $authUser['id'], 'last_login_at' => now()])->save();
        $this->register($user, $data['access_token']);

        return [
            'user' => $user,
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_in' => (int) ($data['expires_in'] ?? 3600),
        ];
    }
}
