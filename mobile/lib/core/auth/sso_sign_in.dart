import 'package:flutter_appauth/flutter_appauth.dart';

import '../api/api_client.dart';
import '../config.dart';

typedef Authorize = Future<AuthorizationResponse> Function(AuthorizationRequest request);

/// "Sign in with your Ministry account" (NFR-07): the system browser (AppAuth, never a web view) opens TEDC's single
/// sign-on with PKCE (S256); the Ministry's identity provider (Entra ID or SAML) signs the person in, and TEDC hands a
/// one-time code back to `qa.edu.tedc.app://sso`. The code works once, briefly, and only with this app's verifier.
/// Multi-factor sign-in is the identity provider's; the app's fingerprint lock keeps working afterwards.
class SsoSignIn {
  SsoSignIn(this.api, {Authorize? authorize}) : _authorize = authorize ?? const FlutterAppAuth().authorize;

  static const redirectUrl = 'qa.edu.tedc.app://sso';

  final ApiClient api;
  final Authorize _authorize;

  /// The session, as a password sign-in returns it; null when the person closed the browser.
  Future<Map<String, dynamic>?> signIn() async {
    final base = AppConfig.apiUrl;
    final AuthorizationResponse r;
    try {
      r = await _authorize(AuthorizationRequest(
        'tedc-mobile',
        redirectUrl,
        serviceConfiguration: AuthorizationServiceConfiguration(authorizationEndpoint: '$base/auth/sso/start', tokenEndpoint: '$base/auth/sso/exchange'),
        scopes: const ['openid'],
        additionalParameters: const {'client': 'mobile'},
        // A test server over plain HTTP; production is HTTPS.
        allowInsecureConnections: base.startsWith('http://'),
        // No "wants to use … to sign in" prompt and no shared browser cookies on iOS.
        externalUserAgent: ExternalUserAgent.ephemeralAsWebAuthenticationSession,
      ));
    } on FlutterAppAuthUserCancelledException {
      return null;
    }
    final code = r.authorizationCode;
    final verifier = r.codeVerifier;
    if (code == null || verifier == null) throw ApiException('Single sign-on did not complete.');
    return Map<String, dynamic>.from(await api.post('/auth/sso/exchange', {'code': code, 'code_verifier': verifier}) as Map);
  }
}
