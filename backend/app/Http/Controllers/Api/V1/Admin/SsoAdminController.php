<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationManager;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Security\Sso\SamlSp;
use App\Security\Sso\SsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Single sign-on: TEDC's SAML addresses and keys (generated, published for a rollover, promoted), the IdP's
 * metadata (pasted or fetched, previewed before it is applied, refreshed daily from its address) and the certificates with
 * their expiry, and a test sign-in for either protocol.
 */
class SsoAdminController extends Controller
{
    public function __construct(private readonly IntegrationManager $hub, private readonly SamlSp $saml, private readonly SsoService $sso) {}

    public function show(): JsonResponse
    {
        $s = $this->hub->settings('saml');

        return response()->json(['data' => [
            'protocol' => $this->sso->protocol(), 'enabled' => $this->saml->enabled(), 'urls' => $this->saml->urls(), 'app_redirects' => $this->sso->appRedirects(),
            'settings' => $this->hub->present('saml')['settings'], 'integration' => $this->hub->present('saml'),
            'sp' => ['current' => SamlSp::describe($s['sp_certificate'] ?? null), 'next' => SamlSp::describe($s['sp_next_certificate'] ?? null)],
            'idp_certificates' => array_values(array_filter(array_map(fn ($c) => SamlSp::describe($c), SamlSp::certificates((string) ($s['idp_certificates'] ?? ''))))),
            'configured' => ['saml' => $this->saml->configured(), 'oidc' => $this->sso->configured('oidc')], 'mandatory' => $this->sso->mandatory(),
            'oidc' => ['redirect_uri' => url('/api/v1/auth/sso/callback'), 'enabled' => $this->hub->get('entra')->enabled],
            'metadata_refresh' => Cache::get('saml.metadata_refresh'),
        ]]);
    }

    /**
     * TEDC's SAML keys: `current` makes a new key at once; `next` publishes a new key beside the current one (give the IdP
     * time to pick it up), and `promote` switches to it.
     */
    public function keys(Request $request): JsonResponse
    {
        $stage = $request->validate(['stage' => ['nullable', 'in:current,next,promote']])['stage'] ?? 'current';
        $s = $this->hub->settings('saml');
        if ($stage === 'promote') {
            if (empty($s['sp_next_private_key']) || empty($s['sp_next_certificate'])) {
                throw ValidationException::withMessages(['stage' => __('auth.saml_no_next_key')]);
            }
            $this->hub->update('saml', ['settings' => ['sp_private_key' => $s['sp_next_private_key'], 'sp_certificate' => $s['sp_next_certificate'], 'sp_next_certificate' => ''], 'clear' => ['sp_next_private_key']]);
        } else {
            $pair = SamlSp::newKeyPair();
            $this->hub->update('saml', ['settings' => $stage === 'next' ? ['sp_next_private_key' => $pair['key'], 'sp_next_certificate' => $pair['cert']] : ['sp_private_key' => $pair['key'], 'sp_certificate' => $pair['cert']]]);
        }
        $s = $this->hub->settings('saml');
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'saml_keys_'.$stage, 'auditable_type' => Integration::class, 'auditable_id' => $this->hub->get('saml')->id,
            'new_values' => ['current' => SamlSp::describe($s['sp_certificate'] ?? null)['fingerprint'] ?? null, 'next' => SamlSp::describe($s['sp_next_certificate'] ?? null)['fingerprint'] ?? null], 'ip_address' => $request->ip()]);

        return response()->json(['data' => ['current' => SamlSp::describe($s['sp_certificate'] ?? null), 'next' => SamlSp::describe($s['sp_next_certificate'] ?? null)]]);
    }

    /** Reads the IdP's metadata (pasted XML, or fetched over HTTPS), shows what it says, and applies it when asked. */
    public function metadata(Request $request): JsonResponse
    {
        $d = $request->validate(['xml' => ['nullable', 'string', 'max:500000'], 'url' => ['nullable', 'url:https', 'max:500'], 'apply' => ['sometimes', 'boolean']]);
        try {
            $xml = ($d['xml'] ?? null) ?: (! empty($d['url']) ? SamlSp::fetchMetadata($d['url']) : null);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['url' => __('auth.saml_metadata_unreadable')]);
        }
        try {
            $m = $xml ? SamlSp::parseMetadata($xml) : throw new \RuntimeException('empty');
        } catch (\Throwable) {
            throw ValidationException::withMessages(['xml' => __('auth.saml_metadata_unreadable')]);
        }
        $preview = ['entity_id' => $m['entity_id'], 'sso_url' => $m['sso_url'], 'slo_url' => $m['slo_url'], 'certificates' => $m['certificates']];
        if ($request->boolean('apply')) {
            $this->hub->update('saml', ['settings' => array_filter(['idp_entity_id' => $m['entity_id'], 'idp_sso_url' => $m['sso_url'], 'idp_slo_url' => $m['slo_url'], 'idp_certificates' => $m['pem'],
                'idp_metadata_url' => $d['url'] ?? null], fn ($v) => $v !== null)]);
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'saml_idp_metadata_applied', 'auditable_type' => Integration::class, 'auditable_id' => $this->hub->get('saml')->id,
                'new_values' => ['entity_id' => $m['entity_id'], 'certificates' => array_column($m['certificates'], 'fingerprint')], 'ip_address' => $request->ip()]);
        }

        return response()->json(['data' => $preview + ['applied' => $request->boolean('apply')]]);
    }

    /**
     * Test sign-in: the administrator signs in at the identity provider (in a new window) and sees what TEDC received and
     * what it would do — the account and the roles — without anyone being signed in or created. Works before switching on.
     */
    public function test(Request $request): JsonResponse
    {
        $protocol = $request->validate(['protocol' => ['nullable', 'in:saml,oidc']])['protocol'] ?? ($this->sso->protocol() ?? ($this->saml->configured() ? 'saml' : 'oidc'));
        if (! $this->sso->configured($protocol)) {
            throw ValidationException::withMessages(['protocol' => __('auth.sso_off')]);
        }

        return response()->json(['data' => ['url' => $this->sso->start(null, null, ['by' => $request->user()->id, 'protocol' => $protocol])['url'], 'protocol' => $protocol]]);
    }

    public function testResult(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->sso->testOutcome($id, $request->user()) ?? abort(404)]);
    }
}
