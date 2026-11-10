<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Security\Sso\SamlSp;
use App\Security\Sso\SsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** TEDC as a SAML 2.0 service provider: metadata, sign-in, the assertion consumer service and single logout (NFR-07). */
class SamlController extends Controller
{
    /** What the Ministry's identity provider imports: entity id, ACS and SLO addresses, signing and encryption keys. */
    public function metadata(SamlSp $saml): Response|JsonResponse
    {
        try {
            $xml = $saml->metadata();
        } catch (\Throwable) {
            return response()->json(['message' => __('auth.sso_off')], 404);
        }

        return response($xml, 200, ['Content-Type' => 'application/samlmetadata+xml', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** Starts a SAML sign-in: a signed AuthnRequest to the IdP, with a single-use state as RelayState. */
    public function login(Request $request, SsoService $sso): RedirectResponse|JsonResponse
    {
        if ($sso->protocol() !== 'saml') {
            return response()->json(['message' => __('auth.sso_off')], 404);
        }

        return redirect()->away($sso->start($request->query('redirect') ? (string) $request->query('redirect') : null)['url']);
    }

    /** The IdP posts its answer here; the browser goes on to the app with a one-time code. */
    public function acs(Request $request, SsoService $sso): RedirectResponse
    {
        $d = $request->validate(['SAMLResponse' => ['required', 'string', 'max:300000'], 'RelayState' => ['required', 'string', 'max:100']]);
        try {
            return redirect()->away($sso->samlAcs($d['SAMLResponse'], $d['RelayState']));
        } catch (ValidationException) {
            return redirect()->away(rtrim((string) config('tedc.web_url'), '/').'/login?sso_error=1');
        }
    }

    /** Single logout: the IdP's answer to TEDC's request, or its own request to end a session (signed in both cases). */
    public function slo(Request $request, SsoService $sso): RedirectResponse|JsonResponse
    {
        if (! $request->query->has('SAMLRequest') && ! $request->query->has('SAMLResponse')) {
            return response()->json(['message' => __('auth.sso_failed')], 400);
        }
        try {
            return redirect()->away($sso->samlLogout($request));
        } catch (ValidationException) {
            return response()->json(['message' => __('auth.sso_failed')], 400);
        }
    }
}
