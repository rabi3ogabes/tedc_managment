<?php

namespace App\Security\Sso;

use App\Auth\AuthService;
use App\Integrations\IntegrationManager;
use App\Models\AuthSession;
use App\Models\Role;
use App\Models\SsoState;
use App\Models\User;
use App\Models\UserIdentity;
use App\Security\AuthSessions;
use App\Security\PendingLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Single sign-on with the Ministry's identity provider — OpenID Connect (Microsoft Entra ID) or SAML 2.0 — for the web and
 * the app: start, callback, one-time exchange (bound to the app's PKCE verifier), logout and single logout, and the
 * break-glass rule for local passwords.
 */
class SsoService
{
    /** Where the app may receive a finished sign-in (its own scheme). */
    public const APP_REDIRECTS = ['qa.edu.tedc.app://sso'];

    public function __construct(private readonly IntegrationManager $hub, private readonly OidcClient $oidc, private readonly IdentityProvisioner $provisioner, private readonly AuthService $auth, private readonly SamlSp $saml) {}

    /** The protocol in force: SAML when it is set up, else OpenID Connect; null when single sign-on is off. */
    public function protocol(): ?string
    {
        if ($this->saml->enabled()) {
            return 'saml';
        }

        return $this->hub->get('entra')->enabled && $this->configured('oidc') ? 'oidc' : null;
    }

    /** A protocol has what it needs to run, switched on or not (an administrator can test sign-in first). */
    public function configured(string $protocol): bool
    {
        if ($protocol === 'saml') {
            return $this->saml->configured();
        }
        $i = $this->hub->get('entra');

        // The fake driver (claims typed into the callback) exists for tests and training only.
        return ($i->driver === 'oidc' && ! empty($this->hub->settings('entra')['client_id'])) || ($i->driver === 'fake' && ! app()->isProduction());
    }

    /**
     * Single sign-on is mandatory for Ministry staff: local passwords stay for outside accounts and the super administrator
     * (break-glass). A setting saved before the switch was named (break_glass off) means the same.
     */
    public function mandatory(): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $s = $this->idpSettings();

        return ! empty($s['sso_mandatory']) || (array_key_exists('break_glass', $s) && ! $s['break_glass']);
    }

    public function enabled(): bool
    {
        return $this->protocol() !== null;
    }

    /** The settings of the protocol in force (domains, groups, just-in-time accounts, break-glass). @return array<string, mixed> */
    private function idpSettings(): array
    {
        return $this->protocol() === 'saml' ? $this->hub->settings('saml') : $this->hub->settings('entra');
    }

    /** @return list<string> */
    public function appRedirects(): array
    {
        return (array) config('tedc.mobile.sso_redirects', self::APP_REDIRECTS);
    }

    /**
     * Where to send the browser. `redirect` is where the web app wants the finished sign-in; `app` is the app's AppAuth
     * request (its registered address, its state and a PKCE S256 challenge); `test` is an administrator's test sign-in
     * (who runs it and which protocol), which signs nobody in.
     *
     * @param  array<string, mixed>|null  $app
     * @param  array{by: string, protocol: string}|null  $test
     * @return array{url: string, state: string, redirect: ?string}
     */
    public function start(?string $redirect, ?array $app = null, ?array $test = null): array
    {
        $protocol = $test ? ($this->configured($test['protocol']) ? $test['protocol'] : null) : $this->protocol();
        if ($protocol === null) {
            throw ValidationException::withMessages(['sso' => __('auth.sso_off')])->status(404);
        }
        $client = $app !== null ? $this->appRequest($app) : [];
        $safe = $test ? null : $this->safeRedirect($redirect);
        $state = Str::random(48);
        $row = ['state' => $state, 'redirect_after' => $client ? null : $safe, 'kind' => 'state', 'expires_at' => now()->addMinutes(10),
            'session' => ['protocol' => $protocol] + $client + ($test ? ['test' => $test['by']] : [])];

        if ($protocol === 'saml') {
            $r = $this->saml->loginUrl($state);
            SsoState::create($row + ['nonce' => mb_substr($r['request_id'], 0, 64), 'verifier' => '-']);

            return ['url' => $r['url'], 'state' => $state, 'redirect' => $safe];
        }
        $nonce = Str::random(32);
        $verifier = Str::random(96);
        SsoState::create($row + ['nonce' => $nonce, 'verifier' => $verifier]);
        $url = $this->hub->get('entra')->driver === 'fake'
            ? url('/api/v1/auth/sso/callback').'?'.http_build_query(['state' => $state, 'code' => 'fake'])
            : $this->oidc->authorizationUrl($this->hub->settings('entra'), $state, $nonce, $verifier);

        return ['url' => $url, 'state' => $state, 'redirect' => $safe];
    }

    /** The app's sign-in request: only its registered address, its own state, and PKCE with S256. @return array<string, string> */
    private function appRequest(array $app): array
    {
        $uri = (string) ($app['redirect_uri'] ?? '');
        if (! in_array($uri, $this->appRedirects(), true)) {
            throw ValidationException::withMessages(['redirect_uri' => __('auth.sso_redirect')]);
        }
        $challenge = (string) ($app['code_challenge'] ?? '');
        if (($app['code_challenge_method'] ?? '') !== 'S256' || ! preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
            throw ValidationException::withMessages(['code_challenge' => __('auth.sso_pkce')]);
        }
        $state = (string) ($app['state'] ?? '');
        if ($state === '' || mb_strlen($state) > 200) {
            throw ValidationException::withMessages(['state' => __('auth.sso_failed')]);
        }

        return ['client' => 'mobile', 'redirect_uri' => $uri, 'challenge' => $challenge, 'app_state' => $state];
    }

    /**
     * OpenID Connect: the provider sent the person back. Returns where to send the browser, with a one-time code.
     *
     * @throws ValidationException
     */
    public function callback(string $code, string $state, ?string $fakeProfile = null): string
    {
        $row = $this->takeState($state, 'oidc');
        $s = $this->hub->settings('entra');
        $test = $row->session['test'] ?? null;
        $exchange = fn () => $this->oidc->validate($s, $this->oidc->exchange($s, $code, $row->verifier)['id_token'], $row->nonce);

        try {
            $claims = match (true) {
                $this->hub->get('entra')->driver === 'fake' => json_decode((string) base64_decode((string) $fakeProfile), true) ?: [],
                $test !== null => $exchange(),   // may run before the integration is switched on
                default => $this->hub->call('entra', 'token_exchange', $exchange, ['state' => 'ok'], 0),
            };
        } catch (Throwable $e) {
            if ($test !== null) {
                return $this->testResult($test, 'oidc', ['ok' => false, 'reason' => 'invalid', 'detail' => mb_substr($e->getMessage(), 0, 300)]);
            }
            throw ValidationException::withMessages(['sso' => __('auth.sso_failed')])->status(400);
        }
        if (empty($claims['sub'] ?? $claims['subject'] ?? null)) {
            if ($test !== null) {
                return $this->testResult($test, 'oidc', ['ok' => false, 'reason' => 'no_subject', 'detail' => null]);
            }
            throw ValidationException::withMessages(['sso' => __('auth.sso_failed')])->status(400);
        }

        $verified = $claims['email_verified'] ?? $claims['xms_edov'] ?? null;
        $profile = [
            'subject' => (string) ($claims['oid'] ?? $claims['sub'] ?? $claims['subject']), 'email' => $claims['email'] ?? $claims['preferred_username'] ?? null, 'upn' => $claims['preferred_username'] ?? $claims['upn'] ?? null,
            'email_verified' => $verified === null ? null : filter_var($verified, FILTER_VALIDATE_BOOL), 'name' => $claims['name'] ?? null,
            'employee_no' => $claims['employeeid'] ?? $claims['employee_no'] ?? null, 'groups' => array_map('strval', (array) ($claims['groups'] ?? [])),
        ];
        if ($test !== null) {
            return $this->testResult($test, 'oidc', ['ok' => true, 'claims' => $this->shown($profile), 'account' => $this->provisioner->preview('entra', $profile, $s)]);
        }
        $user = $this->provisioner->resolve('entra', $profile, $s);

        return $this->finish($row, $user, ['protocol' => 'oidc']);
    }

    /**
     * SAML: the IdP posted its answer to the assertion consumer service. Returns where to send the browser.
     *
     * @throws ValidationException
     */
    public function samlAcs(string $samlResponse, string $relayState): string
    {
        $row = $this->takeState($relayState, 'saml');
        $test = $row->session['test'] ?? null;
        try {
            $p = $this->saml->acs($samlResponse, $row->nonce);
        } catch (SamlRefused $e) {
            Log::warning('SAML response refused', ['reason' => $e->reason]);
            if ($test !== null) {
                return $this->testResult($test, 'saml', ['ok' => false, 'reason' => $e->reason, 'detail' => mb_substr($e->getMessage(), 0, 300)]);
            }
            throw ValidationException::withMessages(['sso' => __('auth.sso_failed')])->status(400);
        }
        if ($test !== null) {
            return $this->testResult($test, 'saml', ['ok' => true, 'claims' => $this->shown($p), 'account' => $this->provisioner->preview('saml', $p, $this->hub->settings('saml'))]);
        }
        $user = $this->provisioner->resolve('saml', $p, $this->hub->settings('saml'));

        return $this->finish($row, $user, ['protocol' => 'saml', 'name_id' => $p['name_id'], 'name_id_format' => $p['name_id_format'], 'session_index' => $p['session_index']]);
    }

    /** Keeps a test sign-in's outcome for the administrator who ran it, and sends the browser back to the settings. */
    private function testResult(string $by, string $protocol, array $result): string
    {
        $id = Str::random(40);
        Cache::put('sso-test:'.$id, ['by' => $by, 'protocol' => $protocol, 'at' => now()->toIso8601String()] + $result + ['claims' => null, 'account' => null], now()->addMinutes(15));

        return rtrim((string) config('tedc.web_url'), '/').'/admin/integrations?sso_test='.$id;
    }

    /** @return array<string, mixed>|null a test sign-in's outcome, for the administrator who ran it only */
    public function testOutcome(string $id, User $viewer): ?array
    {
        $r = Cache::get('sso-test:'.$id);

        return is_array($r) && hash_equals((string) $r['by'], (string) $viewer->id) ? Arr::except($r, ['by']) : null;
    }

    /** What the identity provider sent, as TEDC reads it (e-mail as it will be stored). @return array<string, mixed> */
    private function shown(array $p): array
    {
        return ['email' => isset($p['email']) ? mb_strtolower(trim((string) $p['email'])) : null] + Arr::only($p, ['subject', 'upn', 'name', 'name_ar', 'employee_no', 'groups', 'email_verified', 'name_id', 'name_id_format']);
    }

    /** A state works once, within ten minutes, for the protocol that made it. */
    private function takeState(string $state, string $protocol): SsoState
    {
        $row = SsoState::where('state', $state)->where('kind', 'state')->first();
        if (! $row || $row->expires_at->isPast() || ($row->session['protocol'] ?? 'oidc') !== $protocol) {
            throw ValidationException::withMessages(['sso' => __('auth.sso_failed')])->status(400);
        }
        $row->delete();

        return $row;
    }

    /** Opens the session and hands a one-time code to the web app, or to the app's own address with its state. */
    private function finish(SsoState $row, User $user, array $meta): string
    {
        $session = $this->auth->issueForUser($user, 'sso');
        AuthSession::find($session['session_id'])?->update(['sso_meta' => $meta]);
        $data = $session;
        unset($data['user']);
        $app = ($row->session['client'] ?? null) === 'mobile' ? $row->session : null;
        $exchange = Str::random(48);
        // The app's code is bound to its PKCE challenge; the web's is not (it never leaves TEDC's own origin).
        SsoState::create(['state' => $exchange, 'nonce' => $user->id, 'verifier' => $app['challenge'] ?? '-', 'kind' => 'code',
            'session' => ['enc' => Crypt::encryptString(json_encode($data))], 'expires_at' => now()->addSeconds(90)]);
        if ($app) {
            return $app['redirect_uri'].'?'.http_build_query(['code' => $exchange, 'state' => $app['app_state']]);
        }
        $base = $row->redirect_after ?: rtrim((string) config('tedc.web_url'), '/').'/sso/callback';

        return $base.(str_contains($base, '?') ? '&' : '?').'code='.$exchange;
    }

    /**
     * Swaps the one-time code for the session (once, within ninety seconds). An app's code needs its PKCE verifier; a
     * wrong verifier burns the code.
     *
     * @return array<string, mixed>
     */
    public function exchange(string $code, ?string $verifier = null): array
    {
        $row = SsoState::where('state', $code)->where('kind', 'code')->first();
        if (! $row || $row->expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => __('auth.sso_failed')])->status(400);
        }
        if ($row->verifier !== '-') {
            $given = rtrim(strtr(base64_encode(hash('sha256', (string) $verifier, true)), '+/', '-_'), '=');
            if ($verifier === null || $verifier === '' || ! hash_equals($row->verifier, $given)) {
                $row->delete();
                throw ValidationException::withMessages(['code' => __('auth.sso_failed')])->status(400);
            }
        }
        $user = User::with('roles')->findOrFail($row->nonce);

        return app(PendingLogin::class)->release($user, $row);
    }

    /** Where to send the browser to end the provider's session too: SAML single logout, or the OIDC end-session address. */
    public function logoutUrl(?AuthSession $session = null, ?string $returnTo = null): ?string
    {
        $meta = $session?->sso_meta ?? [];
        if (($meta['protocol'] ?? null) === 'saml') {
            if (! $this->saml->enabled() || empty($meta['name_id']) || empty($this->hub->settings('saml')['idp_slo_url'])) {
                return null;
            }
            $relay = Str::random(40);
            $r = $this->saml->logoutUrl($relay, (string) $meta['name_id'], $meta['name_id_format'] ?? null, $meta['session_index'] ?? null);
            SsoState::create(['state' => $relay, 'nonce' => mb_substr($r['request_id'], 0, 64), 'verifier' => '-', 'kind' => 'slo', 'expires_at' => now()->addMinutes(10)]);

            return $r['url'];
        }
        if ($this->protocol() !== 'oidc' || $this->hub->get('entra')->driver === 'fake') {
            return null;
        }
        try {
            $end = $this->oidc->discovery($this->hub->settings('entra'))['end_session_endpoint'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return $end ? $end.'?'.http_build_query(array_filter(['post_logout_redirect_uri' => $this->safeRedirect($returnTo) ?: rtrim((string) config('tedc.web_url'), '/').'/login'])) : null;
    }

    /**
     * SAML single logout from the IdP's side: its answer to TEDC's request (the browser goes to the sign-in page), or its own
     * request (the TEDC sessions it names end and the IdP gets its answer). Returns where to send the browser.
     *
     * @throws ValidationException
     */
    public function samlLogout(Request $request): string
    {
        $web = rtrim((string) config('tedc.web_url'), '/').'/login';
        try {
            if ($request->query->has('SAMLResponse')) {
                $row = SsoState::where('state', (string) $request->query('RelayState'))->where('kind', 'slo')->first();
                if (! $row || $row->expires_at->isPast()) {
                    throw new SamlRefused('logout', 'unknown relay state');
                }
                $row->delete();
                $this->saml->logout($request, $row->nonce);

                return $web;
            }
            $r = $this->saml->logout($request, null);
        } catch (SamlRefused $e) {
            Log::warning('SAML logout refused', ['reason' => $e->getMessage()]);
            throw ValidationException::withMessages(['sso' => __('auth.sso_failed')])->status(400);
        }
        $this->endSamlSessions((string) $r['name_id'], $r['session_indexes']);

        return $r['redirect'] ?? $web;
    }

    /** Ends the TEDC sessions the IdP's logout names (by NameID, and session index when given). */
    private function endSamlSessions(string $nameId, array $indexes): int
    {
        if ($nameId === '') {
            return 0;
        }
        $users = UserIdentity::where('provider', 'saml')->where(fn ($q) => $q->where('upn', $nameId)->orWhere('subject', $nameId))->pluck('user_id')
            ->merge(User::whereRaw('lower(email) = ?', [mb_strtolower($nameId)])->pluck('id'))->unique()->values();
        $ended = 0;
        foreach (AuthSession::whereIn('user_id', $users)->where('method', 'sso')->whereNull('revoked_at')->get() as $s) {
            $m = $s->sso_meta ?? [];
            if (($m['protocol'] ?? null) !== 'saml' || ($m['name_id'] ?? null) !== $nameId || ($indexes !== [] && ! in_array((string) ($m['session_index'] ?? ''), $indexes, true))) {
                continue;
            }
            app(AuthSessions::class)->revoke($s, 'sso_logout');
            $ended++;
        }

        return $ended;
    }

    /** With single sign-on in force, local passwords are for external users and break-glass administrators only. */
    public function localLoginAllowed(User $user): bool
    {
        if (! $this->mandatory()) {
            return true;
        }
        if ($user->roles->contains(fn ($r) => $r->slug === Role::SUPER_ADMIN)) {
            return true;
        }
        $s = $this->idpSettings();
        $domains = array_filter(array_map(fn ($d) => strtolower(trim($d)), explode(',', (string) ($s['allowed_domains'] ?? ''))));
        $domain = strtolower(substr(strrchr($user->email, '@') ?: '', 1));

        return $domains !== [] && ! in_array($domain, $domains, true);   // ministry accounts use SSO; everyone else keeps their own sign-in
    }

    /** Only the web app's own address may receive a finished sign-in (the app's addresses go through the app request). */
    private function safeRedirect(?string $redirect): ?string
    {
        if (! $redirect) {
            return null;
        }
        $web = rtrim((string) config('tedc.web_url'), '/');

        return str_starts_with($redirect, $web.'/') ? $redirect : null;
    }
}
