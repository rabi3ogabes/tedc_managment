import 'package:dio/dio.dart';

import '../auth/session_store.dart';
import '../config.dart';
import '../error_reporter.dart';

class ApiException implements Exception {
  ApiException(this.message, {this.code, this.details, this.status});

  final String message;
  final String? code;
  final Map<String, dynamic>? details;
  final int? status;

  @override
  String toString() => message;

  static ApiException from(Object error) {
    if (error is ApiException) return error;
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map<String, dynamic>) {
        final errors = data['errors'];
        final first = errors is Map && errors.isNotEmpty ? (errors.values.first as List).first.toString() : null;
        return ApiException(
          first ?? data['message']?.toString() ?? 'Error',
          code: data['code']?.toString(),
          details: data['details'] is Map<String, dynamic> ? data['details'] as Map<String, dynamic> : null,
          status: error.response?.statusCode,
        );
      }
      return ApiException(error.message ?? 'Network error', status: error.response?.statusCode);
    }
    return ApiException(error.toString());
  }
}

/// REST client for the TEDC API with bearer auth, locale header and transparent token refresh.
class ApiClient {
  ApiClient(this._store, {required this.locale, required this.onSessionExpired}) {
    dio = Dio(BaseOptions(
      baseUrl: AppConfig.apiUrl,
      connectTimeout: const Duration(seconds: 15),
      receiveTimeout: const Duration(seconds: 30),
      headers: {'Accept': 'application/json'},
    ));

    dio.interceptors.add(QueuedInterceptorsWrapper(
      onRequest: (options, handler) async {
        options.baseUrl = AppConfig.apiUrl; // follows a server change made in the app
        final session = await _store.read();
        if (session != null) options.headers['Authorization'] = 'Bearer ${session.accessToken}';
        options.headers['X-Locale'] = locale();
        // The language is also part of the URL so shared CDN caches keep one copy per language.
        if (options.method == 'GET') options.queryParameters = {'lang': locale(), ...options.queryParameters};
        handler.next(options);
      },
      onError: (error, handler) async {
        final retried = error.requestOptions.extra['retried'] == true;
        if (error.response?.statusCode == 401 && !retried && await _refresh()) {
          final options = error.requestOptions..extra['retried'] = true;
          final session = await _store.read();
          options.headers['Authorization'] = 'Bearer ${session!.accessToken}';
          try {
            return handler.resolve(await dio.fetch(options));
          } on DioException catch (e) {
            return handler.next(e);
          }
        }
        // A server failure (5xx) is reported to the administrator's error log.
        final status = error.response?.statusCode ?? 0;
        if (status >= 500 && !error.requestOptions.path.contains('client-errors')) {
          ErrorReporter.report('${error.requestOptions.method} ${error.requestOptions.path} → $status', error.stackTrace, statusCode: status);
        }
        handler.next(error);
      },
    ));
  }

  final SessionStore _store;
  final String Function() locale;
  final void Function() onSessionExpired;
  late final Dio dio;

  Future<bool> _refresh() async {
    final session = await _store.read();
    if (session == null) return false;
    try {
      final response = await Dio(BaseOptions(baseUrl: AppConfig.apiUrl)).post('/auth/refresh', data: {'refresh_token': session.refreshToken});
      await _store.write(sessionFromResponse(response.data as Map<String, dynamic>));
      return true;
    } catch (_) {
      await _store.write(null);
      onSessionExpired();
      return false;
    }
  }

  static Session sessionFromResponse(Map<String, dynamic> data) => Session(
        accessToken: data['access_token'] as String,
        refreshToken: data['refresh_token'] as String,
        expiresAt: DateTime.now().add(Duration(seconds: (data['expires_in'] as num).toInt())),
      );

  Future<dynamic> get(String path, {Map<String, dynamic>? query}) => _run(() => dio.get(path, queryParameters: query));

  Future<dynamic> post(String path, [Object? body]) => _run(() => dio.post(path, data: body));

  Future<dynamic> put(String path, [Object? body]) => _run(() => dio.put(path, data: body));

  Future<List<int>> bytes(String path) async {
    try {
      final response = await dio.get<List<int>>(path, options: Options(responseType: ResponseType.bytes));
      return response.data ?? const [];
    } catch (e) {
      throw ApiException.from(e);
    }
  }

  Future<dynamic> _run(Future<Response<dynamic>> Function() call) async {
    try {
      return (await call()).data;
    } catch (e) {
      throw ApiException.from(e);
    }
  }
}
