import 'package:flutter_appauth/flutter_appauth.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/api/api_client.dart';
import 'package:tedc_mobile/core/auth/session_store.dart';
import 'package:tedc_mobile/core/auth/sso_sign_in.dart';
import 'package:tedc_mobile/core/config.dart';

class _Api extends ApiClient {
  _Api() : super(SessionStore(), locale: () => 'ar', onSessionExpired: () {});

  String? path;
  Object? body;

  @override
  Future<dynamic> post(String path, [Object? body]) async {
    this.path = path;
    this.body = body;
    return {'access_token': 'a', 'refresh_token': 'r', 'expires_in': 900, 'user': {'id': 'u1'}};
  }
}

void main() {
  test('the system browser opens TEDC single sign-on with PKCE, and the code is exchanged with the verifier', () async {
    AuthorizationRequest? asked;
    final api = _Api();
    final sso = SsoSignIn(api, authorize: (r) async {
      asked = r;
      return const AuthorizationResponse(authorizationCode: 'one-time', codeVerifier: 'the-verifier');
    });

    final session = await sso.signIn();

    expect(asked!.redirectUrl, 'qa.edu.tedc.app://sso');
    expect(asked!.additionalParameters, {'client': 'mobile'});
    expect(asked!.serviceConfiguration!.authorizationEndpoint, '${AppConfig.apiUrl}/auth/sso/start');
    expect(api.path, '/auth/sso/exchange');
    expect(api.body, {'code': 'one-time', 'code_verifier': 'the-verifier'});
    expect(session!['access_token'], 'a');
  });

  test('closing the browser is not an error', () async {
    final sso = SsoSignIn(_Api(), authorize: (r) async => throw FlutterAppAuthUserCancelledException(
          code: 'user_cancelled', platformErrorDetails: FlutterAppAuthPlatformErrorDetails(type: '0', code: '1', error: 'cancelled')));
    expect(await sso.signIn(), isNull);
  });
}
