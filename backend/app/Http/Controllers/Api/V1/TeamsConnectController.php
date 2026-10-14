<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationManager;
use App\Integrations\Teams\HttpGraph;
use App\Integrations\Teams\TeamsActivityFeed;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\SsoState;
use App\Support\Supabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Microsoft 365 setup that needs a person at a browser: connecting the account that organises Teams town halls (Graph has
 * no application permission for them, so TEDC acts as that account, with the refresh token kept encrypted), and the page
 * TEDC's Teams tab shows.
 */
class TeamsConnectController extends Controller
{
    public function __construct(private readonly IntegrationManager $hub) {}

    private function callbackUrl(): string
    {
        return url('/api/v1/integrations/teams/organizer/callback');
    }

    private function back(string $result): RedirectResponse
    {
        return redirect()->away(rtrim((string) config('tedc.web_url'), '/').'/admin/integrations?teams_organizer='.$result);
    }

    /** The address where the administrator signs in as the organiser account (a new window); consent is asked once. */
    public function connect(Request $request): JsonResponse
    {
        $s = $this->hub->settings('teams');
        if ($this->hub->get('teams')->driver === 'fake') {
            throw ValidationException::withMessages(['teams' => __('teams.training_mode')]);
        }
        foreach (['tenant_id', 'client_id', 'client_secret'] as $k) {
            if (blank($s[$k] ?? null)) {
                throw ValidationException::withMessages(['teams' => __('teams.not_configured')]);
            }
        }
        $verifier = Str::random(96);
        $state = Str::random(48);
        SsoState::create(['state' => $state, 'nonce' => $request->user()->id, 'verifier' => $verifier, 'kind' => 'teams', 'session' => ['purpose' => 'teams_organizer'], 'expires_at' => now()->addMinutes(10)]);

        return response()->json(['data' => ['url' => 'https://login.microsoftonline.com/'.rawurlencode((string) $s['tenant_id']).'/oauth2/v2.0/authorize?'.http_build_query(array_filter([
            'client_id' => $s['client_id'], 'response_type' => 'code', 'redirect_uri' => $this->callbackUrl(), 'response_mode' => 'query', 'scope' => implode(' ', HttpGraph::ORGANIZER_SCOPES),
            'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
            'login_hint' => $s['organizer_upn'] ?? null, 'prompt' => 'select_account',
        ]), '', '&', PHP_QUERY_RFC3986)]]);
    }

    /** Entra sends the browser back here with a code; the refresh token it yields is kept encrypted, and the sign-in itself is forgotten. */
    public function callback(Request $request): RedirectResponse
    {
        $row = SsoState::where('state', (string) $request->query('state'))->where('kind', 'teams')->first();
        if (! $row || $row->expires_at->isPast()) {
            return $this->back('failed');
        }
        $row->delete();   // a state works once
        $code = (string) $request->query('code');
        if ($code === '' || $request->query('error')) {
            return $this->back('failed');
        }
        $s = $this->hub->settings('teams');
        try {
            $r = Http::withOptions(['verify' => Supabase::caBundle()])->timeout(15)->asForm()->post('https://login.microsoftonline.com/'.rawurlencode((string) ($s['tenant_id'] ?? '')).'/oauth2/v2.0/token', [
                'grant_type' => 'authorization_code', 'client_id' => $s['client_id'] ?? '', 'client_secret' => $s['client_secret'] ?? '', 'code' => $code,
                'redirect_uri' => $this->callbackUrl(), 'code_verifier' => $row->verifier, 'scope' => implode(' ', HttpGraph::ORGANIZER_SCOPES),
            ]);
            // The token came straight from Entra over TLS: its claims are read for the account name and the granted scopes, not trusted for anything else.
            $claims = $r->successful() ? HttpGraph::claims((string) $r->json('access_token')) : [];
            $refresh = (string) $r->json('refresh_token');
            $account = (string) ($claims['upn'] ?? $claims['preferred_username'] ?? $claims['unique_name'] ?? '');
            $scopes = preg_split('/\s+/', strtolower((string) ($claims['scp'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            $tenant = (string) ($s['tenant_id'] ?? '');
            $foreign = isset($claims['tid']) && preg_match('/^[0-9a-f-]{36}$/i', $tenant) && strcasecmp((string) $claims['tid'], $tenant) !== 0;
            if ($refresh === '' || $account === '' || $foreign || ! in_array('virtualevent.readwrite', $scopes, true)) {
                return $this->back('failed');
            }
        } catch (Throwable) {
            return $this->back('failed');
        }
        $this->hub->update('teams', ['settings' => ['organizer_refresh_token' => $refresh, 'organizer_account' => $account]]);
        AuditLog::create(['user_id' => $row->nonce, 'action' => 'teams_organizer_connected', 'auditable_type' => Integration::class, 'auditable_id' => $this->hub->get('teams')->id,
            'new_values' => ['account' => $account, 'scopes' => $scopes], 'ip_address' => $request->ip()]);

        return $this->back('connected');
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->hub->update('teams', ['settings' => ['organizer_account' => ''], 'clear' => ['organizer_refresh_token']]);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'teams_organizer_disconnected', 'auditable_type' => Integration::class, 'auditable_id' => $this->hub->get('teams')->id, 'ip_address' => $request->ip()]);

        return response()->json(['data' => ['connected' => false]]);
    }

    /**
     * TEDC's tab in Teams: a small branded page that opens the portal at the page the notification was about. Only Teams may
     * frame it, and only a path inside TEDC is followed (anything else opens the home page).
     */
    public function tab(Request $request): Response
    {
        $ar = ! str_starts_with(strtolower((string) $request->query('locale')), 'en');
        $web = rtrim((string) config('tedc.web_url'), '/');
        $target = $web.(TeamsActivityFeed::safePath((string) $request->query('to')) ?? '/');
        $nonce = base64_encode(random_bytes(16));
        $t = fn (string $key) => e(__('teams.'.$key, [], $ar ? 'ar' : 'en'));
        $html = '<!doctype html><html lang="'.($ar ? 'ar' : 'en').'" dir="'.($ar ? 'rtl' : 'ltr').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark">'
            .'<title>'.$t('tab_title').'</title><style nonce="'.$nonce.'">'
            .':root{--bg:#f6f3ea;--card:#fff;--ink:#161738;--muted:#5d607a;--brand:#8a1538;--gold:#c9a24b}@media (prefers-color-scheme:dark){:root{--bg:#14101a;--card:#1d1824;--ink:#f3eee2;--muted:#b4adc2;--brand:#d4637e;--gold:#e5cd8a}}'
            .'*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--ink);font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;padding:24px}'
            .'main{max-width:420px;width:100%;background:var(--card);border-radius:18px;padding:32px 28px;text-align:center;border:1px solid color-mix(in srgb,var(--gold) 45%,transparent)}'
            .'h1{margin:0 0 8px;font-size:1.35rem;color:var(--brand)}p{margin:0 0 22px;color:var(--muted);line-height:1.7}'
            .'a{display:inline-block;background:var(--brand);color:#fff;text-decoration:none;font-weight:700;padding:12px 26px;border-radius:12px}a:focus-visible{outline:3px solid var(--gold);outline-offset:3px}small{display:block;margin-top:14px;color:var(--muted)}'
            .'</style></head><body><main><h1>'.$t('tab_heading').'</h1><p>'.$t('tab_text').'</p><a href="'.e($target).'" target="_blank" rel="noopener noreferrer">'.$t('tab_open').'</a><small>'.$t('tab_note').'</small></main></body></html>';
        $frames = "frame-ancestors 'self' https://teams.microsoft.com https://*.teams.microsoft.com https://*.skype.com https://*.office.com https://*.microsoft365.com https://*.cloud.microsoft";

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, max-age=300', 'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; ".$frames]);
    }
}
