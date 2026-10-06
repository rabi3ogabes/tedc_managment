<?php

namespace App\Security\Sso;

use App\Auth\AuthService;
use App\Integrations\IntegrationManager;
use App\Models\Role;
use App\Models\SsoState;
use App\Models\User;
use App\Security\PendingLogin;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Single sign-on with the Ministry's Microsoft Entra ID: start, callback, one-time exchange, logout and the break-glass rule for local passwords. */
class SsoService
{
    public function __construct(private readonly IntegrationManager $hub, private readonly OidcClient $oidc, private readonly IdentityProvisioner $provisioner, private readonly AuthService $auth) {}

    public function enabled(): bool
    {
        $i = $this->hub->get('entra');

        // The fake driver (claims typed into the callback) exists for tests and training only.
        return $i->enabled && (($i->driver === 'oidc' && ! empty($this->hub->settings('entra')['client_id'])) || ($i->driver === 'fake' && ! app()->isProduction()));
    }

    /** Where to send the browser. `redirect` is where the app wants the finished sign-in delivered (the web app or the mobile app's own scheme). @return array{url: string, state: string} */
    public function start(?string $redirect): array
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages(['sso' => __('auth.sso_off')])->status(404);
        }
        $s = $this->hub->settings('entra');
        $state = Str::random(48);
        $nonce = Str::random(32);
        $verifier = Str::random(96);
        SsoState::create(['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'redirect_after' => $this->safeRedirect($redirect), 'kind' => 'state', 'expires_at' => now()->addMinutes(10)]);

        $url = $this->hub->get('entra')->driver === 'fake' ? url('/api/v1/auth/sso/callback').'?'.http_build_query(['state' => $state, 'code' => 'fake']) : $this->oidc->authorizationUrl($s, $state, $nonce, $verifier);

        return ['url' => $url, 'state' => $state];
    }

    /**
     * The provider sent the person back. Returns where to send the browser, with a one-time code the app swaps for its session.
     *
     * @throws ValidationException
     */
    public function callback(string $code, string $state, ?string $fakeProfile = null): string
    {
        $row = SsoState::where('state', $state)->where('kind', 'state')->first();
        if (! $row || $row->expires_at->isPast()) {
            throw ValidationException::withMessages(['sso' => __('auth.sso_failed')])->status(400);
        }
        $row->delete();   // a state works once
        $s = $this->hub->settings('entra');

        try {
            $claims = $this->hub->get('entra')->driver === 'fake'
                ? (json_decode((string) base64_decode((string) $fakeProfile), true) ?: [])
                : $this->hub->call('entra', 'token_exchange', fn () => $this->oidc->validate($s, $this->oidc->exchange($s, $code, $row->verifier)['id_token'], $row->nonce), ['state' => 'ok'], 0);
        } catch (Throwable) {
            throw ValidationException::withMessages(['sso' => __('auth.sso_failed')])->status(400);
        }
        if (empty($claims['sub'] ?? $claims['subject'] ?? null)) {
            throw ValidationException::withMessages(['sso' => __('auth.sso_failed')])->status(400);
        }

        $user = $this->provisioner->resolve('entra', [
            'subject' => (string) ($claims['oid'] ?? $claims['sub'] ?? $claims['subject']), 'email' => $claims['email'] ?? $claims['preferred_username'] ?? null, 'upn' => $claims['preferred_username'] ?? $claims['upn'] ?? null,
            'name' => $claims['name'] ?? null, 'employee_no' => $claims['employeeid'] ?? $claims['employee_no'] ?? null, 'groups' => array_map('strval', (array) ($claims['groups'] ?? [])),
        ], $s);

        $session = $this->auth->issueForUser($user, 'sso');
        $exchange = Str::random(48);
        $data = $session;
        unset($data['user']);
        SsoState::create(['state' => $exchange, 'nonce' => $user->id, 'verifier' => '-', 'kind' => 'code', 'session' => ['enc' => Crypt::encryptString(json_encode($data))], 'expires_at' => now()->addSeconds(90)]);
        $base = $row->redirect_after ?: rtrim((string) config('tedc.web_url'), '/').'/sso/callback';

        return $base.(str_contains($base, '?') ? '&' : '?').'code='.$exchange;
    }

    /** Swaps the one-time code for the session (once, within ninety seconds). @return array<string, mixed> */
    public function exchange(string $code): array
    {
        $row = SsoState::where('state', $code)->where('kind', 'code')->first();
        if (! $row || $row->expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => __('auth.sso_failed')])->status(400);
        }
        $user = User::with('roles')->findOrFail($row->nonce);
        $session = app(PendingLogin::class)->release($user, $row);

        return $session;
    }

    /** The provider's sign-out address, to end the single sign-on session too. */
    public function logoutUrl(?string $returnTo = null): ?string
    {
        if (! $this->enabled() || $this->hub->get('entra')->driver === 'fake') {
            return null;
        }
        try {
            $end = $this->oidc->discovery($this->hub->settings('entra'))['end_session_endpoint'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return $end ? $end.'?'.http_build_query(array_filter(['post_logout_redirect_uri' => $this->safeRedirect($returnTo) ?: rtrim((string) config('tedc.web_url'), '/').'/login'])) : null;
    }

    /** With single sign-on in force, local passwords are for external users and break-glass administrators only. */
    public function localLoginAllowed(User $user): bool
    {
        if (! $this->enabled()) {
            return true;
        }
        $s = $this->hub->settings('entra');
        if ($s['break_glass'] ?? true) {
            return true;
        }
        if ($user->roles->contains(fn ($r) => $r->slug === Role::SUPER_ADMIN)) {
            return true;
        }
        $domains = array_filter(array_map(fn ($d) => strtolower(trim($d)), explode(',', (string) ($s['allowed_domains'] ?? ''))));
        $domain = strtolower(substr(strrchr($user->email, '@') ?: '', 1));

        return $domains !== [] && ! in_array($domain, $domains, true);   // ministry accounts use SSO; everyone else keeps their own sign-in
    }

    /** Only the web app's own address or the mobile app's scheme may receive a finished sign-in. */
    private function safeRedirect(?string $redirect): ?string
    {
        if (! $redirect) {
            return null;
        }
        $web = rtrim((string) config('tedc.web_url'), '/');
        if (str_starts_with($redirect, $web.'/') || str_starts_with($redirect, 'tedc://')) {
            return $redirect;
        }

        return null;
    }
}
