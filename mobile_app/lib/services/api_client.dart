import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class ApiException implements Exception {
  const ApiException(this.message, [this.statusCode]);

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}

class ApiClient {
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/mobile',
  );

  static const String _tokenKey = 'mobile_api_token';

  SharedPreferences? _preferences;
  String? _token;

  bool get hasToken => _token != null && _token!.isNotEmpty;

  Future<void> init() async {
    _preferences = await SharedPreferences.getInstance();
    _token = _preferences?.getString(_tokenKey);
  }

  Future<Map<String, dynamic>> login({
    required String email,
    required String password,
  }) async {
    final data = await _post('/login', {'email': email, 'password': password});

    await _storeToken(data['token'] as String?);
    return data;
  }

  Future<Map<String, dynamic>> register({
    required String firstName,
    required String lastName,
    required String middleInitial,
    required String email,
    required String username,
    required String contactNumber,
    required String password,
    required String passwordConfirmation,
  }) async {
    final data = await _post('/register', {
      'first_name': firstName,
      'last_name': lastName,
      'middle_initial': middleInitial,
      'email': email,
      'username': username,
      'contact_number': contactNumber,
      'password': password,
      'password_confirmation': passwordConfirmation,
    });

    await _storeToken(data['token'] as String?);
    return data;
  }

  Future<Map<String, dynamic>> profile() async {
    return _get('/profile');
  }

  Future<void> logout() async {
    if (hasToken) {
      try {
        await _post('/logout', {});
      } on ApiException {
        // Clear local state even if the server token has already expired.
      }
    }

    await clearToken();
  }

  Future<void> clearToken() async {
    _token = null;
    await _preferences?.remove(_tokenKey);
  }

  Future<void> _storeToken(String? token) async {
    if (token == null || token.isEmpty) {
      throw const ApiException('The server did not return an access token.');
    }

    _token = token;
    await _preferences?.setString(_tokenKey, token);
  }

  Future<Map<String, dynamic>> _get(String path) async {
    final response = await http.get(
      Uri.parse('$baseUrl$path'),
      headers: _headers(),
    );

    return _decode(response);
  }

  Future<Map<String, dynamic>> _post(
    String path,
    Map<String, dynamic> payload,
  ) async {
    final response = await http.post(
      Uri.parse('$baseUrl$path'),
      headers: _headers(),
      body: jsonEncode(payload),
    );

    return _decode(response);
  }

  Map<String, String> _headers() {
    return {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      if (hasToken) 'Authorization': 'Bearer $_token',
    };
  }

  Map<String, dynamic> _decode(http.Response response) {
    final decoded = response.body.isNotEmpty
        ? jsonDecode(response.body) as Map<String, dynamic>
        : <String, dynamic>{};

    if (response.statusCode >= 200 && response.statusCode < 300) {
      return decoded;
    }

    throw ApiException(
      decoded['message'] as String? ?? 'Something went wrong.',
      response.statusCode,
    );
  }
}
