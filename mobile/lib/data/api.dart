import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

/// What went wrong talking to the server. [status] is 0 when the server could not be reached at all.
class ApiException implements Exception {
  ApiException(this.status, this.code, [this.seconds, this.message]);

  final int status;
  final String code; // invalid_credentials | throttled | deactivated | unauthenticated | forbidden | not_found | network | timeout | server
  final int? seconds;

  /// The server's own explanation of a refusal (the first validation message, or its text).
  final String? message;

  bool get unauthorized => status == 401 && code == 'unauthenticated';
  bool get network => status == 0;

  @override
  String toString() => 'ApiException($status, $code)';
}

/// The company's server (a Laravel application): JSON over HTTPS with the token made at sign-in. Money is whole tiyin, dates are the company's own time.
class ApiClient {
  ApiClient(this.baseUrl, {this.token});

  final String baseUrl;
  String? token;

  static const _timeout = Duration(seconds: 40);

  /// "mening" + the platform's domain, a full host name, or a full address, into the address of the company.
  static String resolve(String input, String domain) {
    var s = input.trim().toLowerCase();
    if (s.isEmpty) return '';
    if (s.startsWith('http://') || s.startsWith('https://')) return s.replaceAll(RegExp(r'/+$'), '');
    s = s.replaceAll(RegExp(r'/+$'), '');
    final local = s.startsWith('localhost') || s.contains('.localhost') || RegExp(r'^\d+\.\d+\.\d+\.\d+').hasMatch(s);
    if (local) return 'http://$s';
    if (!s.contains('.')) return 'https://$s.$domain';
    return 'https://$s';
  }

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      };

  Future<Map<String, dynamic>> get(String path) => _send('GET', path);

  Future<Map<String, dynamic>> post(String path, Map<String, dynamic> body) => _send('POST', path, body);

  Future<Map<String, dynamic>> put(String path, Map<String, dynamic> body) => _send('PUT', path, body);

  Future<Map<String, dynamic>> patch(String path, [Map<String, dynamic>? body]) => _send('PATCH', path, body);

  Future<Map<String, dynamic>> delete(String path) => _send('DELETE', path);

  /// Any method by name (used by the queue of changes).
  Future<Map<String, dynamic>> send(String method, String path, [Map<String, dynamic>? body]) => _send(method, path, body);

  Future<Map<String, dynamic>> _send(String method, String path, [Map<String, dynamic>? body]) async {
    final uri = Uri.parse('$baseUrl$path');
    late http.Response r;
    try {
      final req = http.Request(method, uri)..headers.addAll(_headers);
      if (body != null) req.body = jsonEncode(body);
      final streamed = await req.send().timeout(_timeout);
      r = await http.Response.fromStream(streamed);
    } on TimeoutException {
      throw ApiException(0, 'timeout');
    } catch (_) {
      throw ApiException(0, 'network');
    }

    Map<String, dynamic> json = const {};
    try {
      final d = jsonDecode(utf8.decode(r.bodyBytes));
      if (d is Map<String, dynamic>) json = d;
    } catch (_) {
      // not JSON (for example an error page of a proxy): handled by the status below
    }

    if (r.statusCode >= 200 && r.statusCode < 300) return json;
    final code = (json['error'] as String?) ??
        switch (r.statusCode) {
          401 => 'unauthenticated',
          403 => 'forbidden',
          404 => 'not_found',
          422 => 'invalid',
          429 => 'throttled',
          _ => 'server',
        };
    // the first validation message, else the server's message
    String? message = json['message'] as String?;
    final errors = json['errors'];
    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      if (first is List && first.isNotEmpty) message = '${first.first}';
    }
    throw ApiException(r.statusCode, code, (json['seconds'] as num?)?.toInt(), message);
  }
}
