import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'package:http/http.dart' as http;
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../utils/config.dart';
import 'connectivity_service.dart';
import 'outbox_policy.dart';
import 'session_models.dart';
import 'telemetry_service.dart';

/// API response wrapper
class ApiResponse {
  final bool success;
  final String? message;
  final dynamic data;
  final int statusCode;
  final bool isNetworkError;
  final bool isAuthError;

  /// Structured transport/protocol evidence used by durable outboxes. Callers
  /// classify this evidence separately from exact local settlement.
  final String? errorCode;
  final int? retryAfterSeconds;
  final bool idempotencyReplayed;
  final ApiFailureKind failureKind;

  /// Typed result of the one refresh attempt made for this response. Durable
  /// workers must distinguish a rejected credential, a transient refresh
  /// failure and an authorization-scope transition instead of flattening all
  /// three into another 401.
  final AuthRefreshOutcome? refreshOutcome;

  /// The coordinator generation captured before this request left the
  /// process. A response from an older generation is never allowed to drive
  /// state or persist server data.
  final int requestGeneration;
  final bool sessionSuperseded;

  /// P74 Phase 3 — the `ETag` response header, when the server sends
  /// one (summary/thread conditional GETs). Null on ordinary calls.
  final String? etag;

  /// S1 — the server's opaque correlation id for this exact HTTP request
  /// (`X-Request-Id`). Recorded against the attempt so one transmission can
  /// be followed from the device into the server log. Absent when talking to
  /// an older server, which is why nothing depends on it being present.
  final String? serverRequestId;

  /// P74 Phase 3 — a conditional GET answered 304 Not Modified: the
  /// body is empty and the caller keeps its current state.
  bool get notModified => statusCode == 304;

  ApiResponse({
    required this.success,
    this.message,
    this.data,
    this.statusCode = 200,
    this.isNetworkError = false,
    this.isAuthError = false,
    this.errorCode,
    this.retryAfterSeconds,
    this.idempotencyReplayed = false,
    this.failureKind = ApiFailureKind.none,
    this.refreshOutcome,
    this.requestGeneration = 0,
    this.sessionSuperseded = false,
    this.etag,
    this.serverRequestId,
  });

  factory ApiResponse.fromJson(Map<String, dynamic> json, int code,
      {Map<String, String> headers = const {}, String? etag}) {
    final data = json['data'];
    final nestedCode = data is Map ? data['code'] : null;
    final errorCode = '${json['code'] ?? nestedCode ?? ''}'.trim();
    final success = json['status'] == 'success';
    final failureKind = success
        ? ApiFailureKind.none
        : _failureKindForHttp(code, errorCode);
    return ApiResponse(
      success: success,
      message: json['message'],
      // Some endpoints wrap their payload in `data`, while the Mezmur
      // contract returns fields such as `items` at the response root.
      data: data ?? json,
      statusCode: code,
      isAuthError: code == 401 || code == 403,
      errorCode: errorCode.isEmpty ? null : errorCode,
      retryAfterSeconds: _retryAfterSeconds(headers['retry-after']),
      idempotencyReplayed:
          headers['idempotency-replayed']?.toLowerCase() == 'true',
      failureKind: _receiptAwareFailureKind(failureKind, json, data),
      etag: etag,
      serverRequestId: _trimmedOrNull(headers['x-request-id']),
    );
  }

  /// Inspect protocol containers only, never arbitrary records/member data.
  /// Even a true/malformed marker is not validated manifest receipt proof.
  static bool _hasReceiptMetadata(dynamic value) {
    if (value is! Map) return false;
    for (final item in [value, value['lookup'], value['capabilities']]) {
      if (item is Map &&
          (item.containsKey('authorizes_settlement') ||
              item.containsKey('durable_receipt_versions') ||
              item['authority'] == 'legacy_replay_cache')) {
        return true;
      }
    }
    return false;
  }

  static ApiFailureKind _receiptAwareFailureKind(
      ApiFailureKind kind, dynamic envelope,
      [dynamic payload]) {
    if (kind == ApiFailureKind.authentication ||
        kind == ApiFailureKind.authorizationScope) {
      return kind;
    }
    return _hasReceiptMetadata(envelope) || _hasReceiptMetadata(payload)
        ? ApiFailureKind.nonSettlement
        : kind;
  }

  static String? _trimmedOrNull(String? value) {
    final trimmed = (value ?? '').trim();
    if (trimmed.isEmpty || trimmed.length > 128) return null;
    return trimmed;
  }

  factory ApiResponse.error(String msg,
      [int code = 0,
      bool network = false,
      ApiFailureKind failureKind = ApiFailureKind.unknown]) {
    return ApiResponse(
      success: false,
      message: msg,
      statusCode: code,
      isNetworkError: network,
      isAuthError: code == 401 || code == 403,
      failureKind: failureKind,
    );
  }

  factory ApiResponse.protocolError(
    String message,
    int code, {
    Map<String, String> headers = const {},
    String? etag,
  }) =>
      ApiResponse(
        success: false,
        message: message,
        statusCode: code,
        isAuthError: code == 401 || code == 403,
        retryAfterSeconds: _retryAfterSeconds(headers['retry-after']),
        idempotencyReplayed:
            headers['idempotency-replayed']?.toLowerCase() == 'true',
        failureKind: code == 401
            ? ApiFailureKind.authentication
            : ApiFailureKind.protocol,
        etag: etag,
      );

  factory ApiResponse.superseded(int generation) => ApiResponse(
        success: false,
        message: 'This request belongs to an older signed-in session.',
        refreshOutcome: AuthRefreshOutcome.superseded,
        requestGeneration: generation,
        sessionSuperseded: true,
      );

  ApiResponse withGeneration(int generation) => ApiResponse(
        success: success,
        message: message,
        data: data,
        statusCode: statusCode,
        isNetworkError: isNetworkError,
        isAuthError: isAuthError,
        errorCode: errorCode,
        retryAfterSeconds: retryAfterSeconds,
        idempotencyReplayed: idempotencyReplayed,
        failureKind: failureKind,
        refreshOutcome: refreshOutcome,
        requestGeneration: generation,
        sessionSuperseded: sessionSuperseded,
        etag: etag,
        serverRequestId: serverRequestId,
      );

  ApiResponse withRefreshOutcome(AuthRefreshOutcome? outcome) => ApiResponse(
        success: success,
        message: message,
        data: data,
        statusCode: statusCode,
        isNetworkError: isNetworkError,
        isAuthError: isAuthError,
        errorCode: errorCode,
        retryAfterSeconds: retryAfterSeconds,
        idempotencyReplayed: idempotencyReplayed,
        failureKind: failureKind,
        refreshOutcome: outcome,
        requestGeneration: requestGeneration,
        sessionSuperseded: sessionSuperseded,
        etag: etag,
        serverRequestId: serverRequestId,
      );

  OutboxResponseEvidence toOutboxEvidence({
    required int automaticAttemptCount,
    bool hasCanonicalConflictItem = false,
  }) =>
      OutboxResponseEvidence(
        success: success,
        statusCode: statusCode,
        errorCode: errorCode,
        idempotencyReplayed: idempotencyReplayed,
        hasCanonicalConflictItem: hasCanonicalConflictItem,
        failureKind: sessionSuperseded
            ? ApiFailureKind.unknown
            : _receiptAwareFailureKind(failureKind, data),
        refreshOutcome: sessionSuperseded
            ? AuthRefreshOutcome.superseded
            : refreshOutcome,
        automaticAttemptCount: automaticAttemptCount,
      );

  static ApiFailureKind _failureKindForHttp(int status, String code) {
    if (const {
      'AUTH_SCOPE_CHANGED',
      'AUTH_SCOPE_REFRESH_REQUIRED',
    }.contains(code)) {
      return ApiFailureKind.authorizationScope;
    }
    if (status == 401 || const {
      'INVALID_REFRESH_TOKEN',
      'REFRESH_EXPIRED',
      'REFRESH_REUSED',
      'REFRESH_REVOKED',
      'ACCOUNT_DISABLED',
      'ACCOUNT_REMOVED',
    }.contains(code)) {
      return ApiFailureKind.authentication;
    }
    return ApiFailureKind.http;
  }

  static int? _retryAfterSeconds(String? value) {
    final seconds = int.tryParse('${value ?? ''}'.trim());
    if (seconds == null) return null;
    if (seconds < 1) return 1;
    if (seconds > 3600) return 3600;
    return seconds;
  }
}

/// Core API client — singleton
class ApiService {
  static final ApiService _instance = ApiService._internal();
  static final RegExp _profileVersionPattern =
      RegExp(r'^[A-Fa-f0-9]{64}$');
  factory ApiService() => _instance;
  ApiService._internal();

  final _connectivity = ConnectivityService();
  final _secureStorage = const FlutterSecureStorage();
  final http.Client _http = http.Client();
  final Map<String, Future<ApiResponse>> _getInflight = {};

  String? _token;
  String? _refreshToken;
  Map<String, dynamic>? _userData;
  Future<AuthRefreshOutcome>? _refreshInFlight;
  int? _refreshInFlightGeneration;
  Future<void> _credentialMutationTail = Future<void>.value();
  bool _refreshWasRejected = false;
  bool _authExpiryNotified = false;

  /// Installed by SessionCoordinator once at bootstrap. ApiService never chooses a
  /// root or destroys local data; it reports definitive credential loss to the
  /// one coordinator which owns that transition.
  Future<void> Function(String reason)? onAuthExpired;

  /// The coordinator owns scope transitions because they cross secure storage,
  /// SQLite, workers and navigation. Returning false means it failed closed;
  /// the original request is still never retried under the new token.
  Future<bool> Function(AuthBundle candidate, int sourceGeneration)?
      onAuthorizationScopeChanged;
  int Function()? sessionGenerationProvider;

  // Getters
  String? get token => _token;
  Map<String, dynamic>? get userData => _userData;
  bool get isLoggedIn => _token != null && _refreshToken != null;
  String get userRole => _userData?['role']?.toString() ?? '';
  String get userName => _userData?['full_name']?.toString() ?? '';
  int get userId => _positiveInt(_userData?['id']) ?? 0;
  int get authorizationVersion =>
      _nonNegativeInt(_userData?['authorization_version']) ?? 0;

  /// Stable refresh family extracted from the access JWT (payload.fid).
  /// Null when the token is absent or unparsable — the fetcher must
  /// surface a typed error rather than silently using a dummy.
  String? get familyId {
    final t = _token;
    if (t == null || t.isEmpty) return null;
    try {
      final parts = t.split('.');
      if (parts.length != 3) return null;
      final padded = parts[1].replaceAll('-', '+').replaceAll('_', '/');
      final normalized = base64.normalize(padded);
      final payload = jsonDecode(utf8.decode(base64.decode(normalized)));
      if (payload is! Map) return null;
      final fid = payload['fid'];
      if (fid is String && RegExp(r'^[a-f0-9]{64}$').hasMatch(fid)) return fid;
      return null;
    } catch (_) {
      return null;
    }
  }

  int get _requestGeneration => sessionGenerationProvider?.call() ?? 0;
  bool _generationIsCurrent(int captured) =>
      captured == (sessionGenerationProvider?.call() ?? 0);

  /// Read secure credentials without mutating them. In particular, exceptions
  /// are not converted into "logged out" and never trigger credential deletion.
  Future<CredentialLoadResult> loadCredentials() async {
    _clearMemoryCredentials();

    String? token;
    String? refreshToken;
    String? userJson;
    try {
      token = await _secureStorage.read(key: AppConfig.tokenKey);
      refreshToken =
          await _secureStorage.read(key: AppConfig.refreshTokenKey);
      userJson = await _secureStorage.read(key: AppConfig.userDataKey);
    } on MissingPluginException catch (error) {
      return CredentialLoadResult.storageUnavailable('$error');
    } on PlatformException catch (error) {
      return CredentialLoadResult.storageUnavailable('$error');
    } catch (error) {
      return CredentialLoadResult.unreadable('$error');
    }

    // Versions <= 1.1.14 stored only the profile in SharedPreferences. The
    // plaintext key is removed strictly after the secure write succeeds.
    if (userJson == null) {
      try {
        final prefs = await SharedPreferences.getInstance();
        final legacyUserJson = prefs.getString(AppConfig.userDataKey);
        if (legacyUserJson != null && legacyUserJson.isNotEmpty) {
          try {
            await _secureStorage.write(
              key: AppConfig.userDataKey,
              value: legacyUserJson,
            );
          } on MissingPluginException catch (error) {
            return CredentialLoadResult.storageUnavailable('$error');
          } on PlatformException catch (error) {
            return CredentialLoadResult.storageUnavailable('$error');
          } catch (error) {
            return CredentialLoadResult.unreadable('$error');
          }
          userJson = legacyUserJson;
          await prefs.remove(AppConfig.userDataKey);
        }
      } catch (error) {
        return CredentialLoadResult.unreadable('$error');
      }
    }

    final present = [token, refreshToken, userJson]
        .where((value) => value != null && value.isNotEmpty)
        .length;
    if (present == 0) return const CredentialLoadResult.absent();
    if (present != 3) {
      return const CredentialLoadResult.incomplete(
        'Secure credentials are present but incomplete.',
      );
    }

    final bundle = _bundleFromValues(token!, refreshToken!, userJson!);
    if (bundle == null || !bundle.isComplete) {
      return const CredentialLoadResult.unreadable(
        'The protected staff profile is malformed.',
      );
    }
    return CredentialLoadResult.complete(bundle);
  }

  /// Backward-compatible name for bootstrap callers. The typed result must be
  /// inspected; it is never flattened to a nullable session.
  Future<CredentialLoadResult> init() => loadCredentials();

  AuthBundle? _bundleFromValues(
      String token, String refreshToken, String userJson) {
    try {
      final decoded = jsonDecode(userJson);
      if (decoded is! Map<String, dynamic>) return null;
      final userId = _positiveInt(decoded['id']);
      final role = decoded['role']?.toString().trim() ?? '';
      if (userId == null || role.isEmpty) return null;
      return AuthBundle(
        accessToken: token,
        refreshToken: refreshToken,
        userId: userId,
        username: decoded['username']?.toString() ?? '',
        displayName: decoded['full_name']?.toString() ?? '',
        role: role,
        authorizationVersion:
            _nonNegativeInt(decoded['authorization_version']) ?? 0,
        userJson: userJson,
      );
    } catch (_) {
      return null;
    }
  }

  AuthBundle? bundleFromLoginResponse(ApiResponse response) {
    if (!response.success || response.data is! Map) return null;
    return _bundleFromAuthData(
      Map<String, dynamic>.from(response.data as Map),
    );
  }

  AuthBundle? _bundleFromAuthData(Map<String, dynamic> data) {
    final token = data['token'];
    final refreshToken = data['refresh_token'];
    final rawUser = data['user'];
    if (token is! String ||
        token.isEmpty ||
        refreshToken is! String ||
        refreshToken.isEmpty ||
        rawUser is! Map) {
      return null;
    }
    final user = Map<String, dynamic>.from(rawUser);
    final current = _userData;
    if (current != null &&
        _positiveInt(current['id']) == _positiveInt(user['id']) &&
        current['profile_version'] is String) {
      for (final field in const [
        'email',
        'is_active',
        'member_id',
        'profile_image',
        'profile_version',
        'created_at',
        'last_login',
        'assignments',
      ]) {
        if (current.containsKey(field) && !user.containsKey(field)) {
          user[field] = current[field];
        }
      }
    }
    return _bundleFromValues(token, refreshToken, jsonEncode(user));
  }

  /// Persist canonical self-profile fields inside the existing protected
  /// session profile. Tokens and authorization metadata are never accepted
  /// from the profile payload, and the immutable authenticated owner must match.
  Future<void> cacheCanonicalProfile(Map<String, dynamic> profile) async {
    final ownerUserId = _positiveInt(profile['id']);
    final generation = _requestGeneration;
    if (ownerUserId == null || ownerUserId != userId) {
      throw StateError('Canonical profile owner does not match the session.');
    }
    final profileRole = profile['role']?.toString() ?? '';
    if (profileRole.isEmpty || profileRole != userRole) {
      throw StateError('Canonical profile role does not match the session.');
    }

    await _serializeCredentialMutation(() async {
      if (!_generationIsCurrent(generation) ||
          ownerUserId != userId ||
          _token == null ||
          _refreshToken == null ||
          _userData == null) {
        throw StateError('The authenticated session changed.');
      }
      final merged = Map<String, dynamic>.from(_userData!);
      for (final field in const [
        'id',
        'username',
        'email',
        'full_name',
        'role',
        'is_active',
        'member_id',
        'profile_image',
        'profile_version',
        'created_at',
        'last_login',
        'assignments',
      ]) {
        if (profile.containsKey(field)) merged[field] = profile[field];
      }
      // Preserve authorization_version from the authenticated bundle; it is
      // deliberately absent from the self-profile contract.
      final encoded = jsonEncode(merged);
      await _secureStorage.write(key: AppConfig.userDataKey, value: encoded);
      _userData = merged;
    });
  }

  /// Phase two of login. SessionCoordinator calls this only after owner and local
  /// inventory reconciliation succeeds. Candidate login credentials remain in
  /// memory owned by the call stack until this point.
  Future<void> activateCredentials(AuthBundle bundle) async {
    final decoded = jsonDecode(bundle.userJson);
    if (decoded is! Map<String, dynamic>) {
      throw const FormatException('Invalid staff profile');
    }
    await _serializeCredentialMutation(() async {
      await _secureStorage.write(
          key: AppConfig.userDataKey, value: bundle.userJson);
      await _secureStorage.write(
          key: AppConfig.refreshTokenKey, value: bundle.refreshToken);
      // Commit marker last: bootstrap never accepts an access token without
      // the profile and one-time refresh token which support it.
      await _secureStorage.write(
          key: AppConfig.tokenKey, value: bundle.accessToken);
      _token = bundle.accessToken;
      _refreshToken = bundle.refreshToken;
      _userData = Map<String, dynamic>.from(decoded);
      _authExpiryNotified = false;
      _refreshWasRejected = false;
    });
  }

  /// Adopt a complete bundle which was already read from secure storage. No
  /// writes occur during this bootstrap-only operation.
  void adoptLoadedCredentials(AuthBundle bundle) {
    final decoded = jsonDecode(bundle.userJson);
    if (decoded is! Map<String, dynamic>) {
      throw const FormatException('Invalid staff profile');
    }
    _token = bundle.accessToken;
    _refreshToken = bundle.refreshToken;
    _userData = Map<String, dynamic>.from(decoded);
    _authExpiryNotified = false;
    _refreshWasRejected = false;
  }

  Future<void> revokeBundle(AuthBundle bundle) async {
    try {
      await _http
          .post(
            Uri.parse('${AppConfig.apiBaseUrl}/auth/logout'),
            headers: _headers(withAuth: false),
            body: jsonEncode({'refresh_token': bundle.refreshToken}),
          )
          .timeout(const Duration(seconds: 5));
    } catch (_) {
      // Rejection/owner mismatch must remain safe while offline. Candidate
      // credentials are never activated even if best-effort revocation fails.
    }
  }

  Future<void> clearCredentials() async {
    // Clear memory before waiting so no new authenticated request can start.
    _clearMemoryCredentials();
    await _serializeCredentialMutation(() async {
      await _secureStorage.delete(key: AppConfig.tokenKey);
      await _secureStorage.delete(key: AppConfig.refreshTokenKey);
      await _secureStorage.delete(key: AppConfig.userDataKey);
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(AppConfig.userDataKey);
    });
  }

  Future<T> _serializeCredentialMutation<T>(Future<T> Function() action) async {
    final previous = _credentialMutationTail;
    final done = Completer<void>();
    _credentialMutationTail = done.future;
    try {
      await previous;
    } catch (_) {
      // The previous caller receives its own error; it must not deadlock the
      // next fail-closed cleanup attempt.
    }
    try {
      return await action();
    } finally {
      done.complete();
    }
  }

  void _clearMemoryCredentials() {
    _token = null;
    _refreshToken = null;
    _userData = null;
    _authExpiryNotified = false;
    _refreshWasRejected = false;
  }

  /// Revoke the currently active server-side refresh family and clear only
  /// credentials. Private SQLite/PIN policy belongs to SessionCoordinator.
  Future<void> logout() async {
    final bundle = _currentBundle();
    if (bundle != null) await revokeBundle(bundle);
    await clearCredentials();
  }

  AuthBundle? _currentBundle() {
    final token = _token;
    final refreshToken = _refreshToken;
    final user = _userData;
    if (token == null || refreshToken == null || user == null) return null;
    return _bundleFromValues(token, refreshToken, jsonEncode(user));
  }

  /// Refresh responses intentionally contain only session claims. Preserve a
  /// previously validated canonical profile across a same-scope token rotation
  /// so ordinary refreshes do not silently destroy the offline profile cache.
  /// A claim mismatch or authorization-scope change deliberately skips this
  /// merge and forces the profile service to obtain fresh canonical data.
  AuthBundle _withSameScopeCanonicalProfile(AuthBundle candidate) {
    final cached = _userData;
    if (cached == null ||
        _positiveInt(cached['id']) != candidate.userId ||
        cached['username']?.toString() != candidate.username ||
        cached['full_name']?.toString() != candidate.displayName) {
      return candidate;
    }
    final profileVersion = cached['profile_version']?.toString() ?? '';
    if (!_profileVersionPattern.hasMatch(profileVersion)) return candidate;

    final decoded = jsonDecode(candidate.userJson);
    if (decoded is! Map<String, dynamic>) return candidate;
    final merged = Map<String, dynamic>.from(decoded);
    for (final field in const [
      'email',
      'is_active',
      'member_id',
      'profile_image',
      'profile_version',
      'created_at',
      'last_login',
      'assignments',
    ]) {
      if (!merged.containsKey(field) && cached.containsKey(field)) {
        merged[field] = cached[field];
      }
    }
    return AuthBundle(
      accessToken: candidate.accessToken,
      refreshToken: candidate.refreshToken,
      userId: candidate.userId,
      username: candidate.username,
      displayName: candidate.displayName,
      role: candidate.role,
      authorizationVersion: candidate.authorizationVersion,
      userJson: jsonEncode(merged),
    );
  }

  static int? _positiveInt(Object? value) {
    final parsed = value is int ? value : int.tryParse('${value ?? ''}');
    return parsed != null && parsed > 0 ? parsed : null;
  }

  static int? _nonNegativeInt(Object? value) {
    final parsed = value is int ? value : int.tryParse('${value ?? ''}');
    return parsed != null && parsed >= 0 ? parsed : null;
  }

  /// Build headers
  Map<String, String> _headers({bool withAuth = true}) {
    final h = <String, String>{
      'Content-Type': 'application/json',
      'X-App-Version': AppConfig.appVersion,
      'X-App-Build': '${AppConfig.appBuild}',
    };
    if (withAuth && _token != null) {
      h['Authorization'] = 'Bearer $_token';
    }
    return h;
  }

  /// Core GET request. Reuses one TLS session (Telegram keeps a socket open)
  /// and collapses identical in-flight reads so Home + WarmStore + Sync
  /// do not open three handshakes on 4G.
  Future<ApiResponse> get(String path,
      {Map<String, String>? params,
      bool auth = true,
      Map<String, String>? headers}) async {
    var uri = Uri.parse('${AppConfig.apiBaseUrl}$path');
    if (params != null && params.isNotEmpty) {
      uri = uri.replace(queryParameters: params);
    }
    final generation = _requestGeneration;
    // P74 Phase 3: conditional GETs carry caller-specific headers
    // (If-None-Match), so they never share an in-flight slot with a
    // plain GET of the same URI. A generation is part of the key so a newly
    // activated owner can never inherit the previous owner's future.
    if (headers == null) {
      final key = '$generation:${uri.toString()}';
      final existing = _getInflight[key];
      if (existing != null) return existing;

      final future = _doGet(uri, auth, null, generation);
      _getInflight[key] = future;
      try {
        return await future;
      } finally {
        if (identical(_getInflight[key], future)) {
          _getInflight.remove(key);
        }
      }
    }
    return _doGet(uri, auth, headers, generation);
  }

  Future<ApiResponse> _doGet(Uri uri, bool auth,
      Map<String, String>? extraHeaders, int generation) async {
    try {
      final sentToken = auth ? _token : null;
      Map<String, String> requestHeaders = _headers(withAuth: auth);
      if (extraHeaders != null) requestHeaders.addAll(extraHeaders);
      var response = await _http
          .get(uri, headers: requestHeaders)
          .timeout(Duration(seconds: AppConfig.connectionTimeout));
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      AuthRefreshOutcome? refreshOutcome;
      if (response.statusCode == 401 && auth) {
        refreshOutcome = await _refreshAfterUnauthorized(sentToken);
        if (!_generationIsCurrent(generation) ||
            refreshOutcome == AuthRefreshOutcome.scopeChanged ||
            refreshOutcome == AuthRefreshOutcome.superseded) {
          return ApiResponse.superseded(generation);
        }
        if (refreshOutcome == AuthRefreshOutcome.sameScope) {
          final retryHeaders = _headers(withAuth: true);
          if (extraHeaders != null) retryHeaders.addAll(extraHeaders);
          response = await _http
              .get(uri, headers: retryHeaders)
              .timeout(Duration(seconds: AppConfig.connectionTimeout));
        } else if (refreshOutcome == AuthRefreshOutcome.rejected) {
          await _notifyIfRefreshRejected();
        }
      }
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      final handled =
          (await _handleResponseAsync(response)).withRefreshOutcome(refreshOutcome);
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return handled.withGeneration(generation);
    } catch (e) {
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleError(e).withGeneration(generation);
    }
  }

  /// Core POST request
  Future<ApiResponse> post(String path,
      {Map<String, dynamic>? body,
      bool auth = true,
      String? idempotencyKey,
      String? attemptUid,
      int? attemptNumber,
      String? executionSource}) async {
    final generation = _requestGeneration;
    try {
      final uri = Uri.parse('${AppConfig.apiBaseUrl}$path');
      var headers = _headers(withAuth: auth);
      final sentToken = auth ? _token : null;
      final key = (idempotencyKey ?? '').trim();
      if (key.isNotEmpty) {
        headers['Idempotency-Key'] = key;
        body = {...?body, 'client_op_id': key};
      }
      // S1 correlation: identifies this single transmission, not the
      // operation. Opaque and random, so it leaks nothing; a server that
      // does not know the header simply ignores it.
      final attempt = (attemptUid ?? '').trim();
      if (attempt.isNotEmpty) {
        headers['X-Client-Attempt-Id'] = attempt;
      }
      if (attemptNumber != null && attemptNumber > 0) {
        headers['X-Client-Attempt-Number'] = '$attemptNumber';
      }
      final source = (executionSource ?? '').trim();
      if (source == 'foreground' || source == 'background') {
        headers['X-Execution-Source'] = source;
      }
      // Fleet context: the anonymous installation identity telemetry already
      // uses, so the server can slice sync failures by device cohort. Only
      // on sync writes (idempotent / attempt-observed calls); a server that
      // does not know the header simply ignores it.
      String? installId;
      if (auth && (key.isNotEmpty || attempt.isNotEmpty)) {
        installId = await TelemetryService.instance.getInstallationId();
        if (installId.isNotEmpty) headers['X-Installation-Id'] = installId;
      }
      var response = await _http
          .post(
            uri,
            headers: headers,
            body: body != null ? jsonEncode(body) : null,
          )
          .timeout(Duration(seconds: AppConfig.postTimeout));
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      AuthRefreshOutcome? refreshOutcome;
      if (response.statusCode == 401 && auth) {
        refreshOutcome = await _refreshAfterUnauthorized(sentToken);
        if (!_generationIsCurrent(generation) ||
            refreshOutcome == AuthRefreshOutcome.scopeChanged ||
            refreshOutcome == AuthRefreshOutcome.superseded) {
          return ApiResponse.superseded(generation);
        }
        if (refreshOutcome == AuthRefreshOutcome.sameScope) {
          headers = _headers(withAuth: true);
          if (key.isNotEmpty) headers['Idempotency-Key'] = key;
          if (attempt.isNotEmpty) headers['X-Client-Attempt-Id'] = attempt;
          if (attemptNumber != null && attemptNumber > 0) {
            headers['X-Client-Attempt-Number'] = '$attemptNumber';
          }
          if (source == 'foreground' || source == 'background') {
            headers['X-Execution-Source'] = source;
          }
          if (installId != null && installId.isNotEmpty) {
            headers['X-Installation-Id'] = installId;
          }
          response = await _http
              .post(
                uri,
                headers: headers,
                body: body != null ? jsonEncode(body) : null,
              )
              .timeout(Duration(seconds: AppConfig.postTimeout));
        } else if (refreshOutcome == AuthRefreshOutcome.rejected) {
          await _notifyIfRefreshRejected();
        }
      }
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleResponse(response)
          .withRefreshOutcome(refreshOutcome)
          .withGeneration(generation);
    } catch (e) {
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleError(e).withGeneration(generation);
    }
  }

  /// Core PUT request
  Future<ApiResponse> put(String path, {Map<String, dynamic>? body}) async {
    final generation = _requestGeneration;
    try {
      final uri = Uri.parse('${AppConfig.apiBaseUrl}$path');
      final sentToken = _token;
      var response = await _http
          .put(
            uri,
            headers: _headers(),
            body: body != null ? jsonEncode(body) : null,
          )
          .timeout(Duration(seconds: AppConfig.postTimeout));
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      AuthRefreshOutcome? refreshOutcome;
      if (response.statusCode == 401) {
        refreshOutcome = await _refreshAfterUnauthorized(sentToken);
        if (!_generationIsCurrent(generation) ||
            refreshOutcome == AuthRefreshOutcome.scopeChanged ||
            refreshOutcome == AuthRefreshOutcome.superseded) {
          return ApiResponse.superseded(generation);
        }
        if (refreshOutcome == AuthRefreshOutcome.sameScope) {
          response = await _http
              .put(
                uri,
                headers: _headers(),
                body: body != null ? jsonEncode(body) : null,
              )
              .timeout(Duration(seconds: AppConfig.postTimeout));
        } else if (refreshOutcome == AuthRefreshOutcome.rejected) {
          await _notifyIfRefreshRejected();
        }
      }
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleResponse(response)
          .withRefreshOutcome(refreshOutcome)
          .withGeneration(generation);
    } catch (e) {
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleError(e).withGeneration(generation);
    }
  }

  /// Handle response
  ApiResponse _handleResponse(http.Response response) {
    // Mark as online since we got a response
    _connectivity.markOnline();

    try {
      final json = _decodeJson(response.body);
      if (json is Map<String, dynamic>) {
        return ApiResponse.fromJson(json, response.statusCode,
            headers: response.headers);
      }
      return ApiResponse.protocolError(
        _httpErrorLabel(response.statusCode),
        response.statusCode,
        headers: response.headers,
      );
    } catch (e) {
      return ApiResponse.protocolError(
        _httpErrorLabel(response.statusCode),
        response.statusCode,
        headers: response.headers,
      );
    }
  }

  /// Async variant used by GETs: large payloads (member rosters, reports)
  /// are JSON-decoded in an isolate so a page arriving mid-fling never
  /// stalls the UI thread. Small payloads stay on the main isolate because
  /// spawning one costs more than parsing them.
  Future<ApiResponse> _handleResponseAsync(http.Response response) async {
    _connectivity.markOnline();
    // P74 Phase 3 — conditional GETs: a 304 has an empty body by
    // design; surface it as notModified with the (re-sent) ETag.
    if (response.statusCode == 304) {
      return ApiResponse(
        success: false,
        message: 'Not modified',
        statusCode: 304,
        etag: response.headers['etag'],
      );
    }
    try {
      final body = response.body;
      final dynamic json = body.length > 32 * 1024
          ? await compute(_decodeJsonIsolate, body)
          : _decodeJson(body);
      if (json is Map<String, dynamic>) {
        return ApiResponse.fromJson(json, response.statusCode,
            headers: response.headers, etag: response.headers['etag']);
      }
      return ApiResponse.protocolError(
        _httpErrorLabel(response.statusCode),
        response.statusCode,
        headers: response.headers,
        etag: response.headers['etag'],
      );
    } catch (_) {
      return ApiResponse.protocolError(
        _httpErrorLabel(response.statusCode),
        response.statusCode,
        headers: response.headers,
        etag: response.headers['etag'],
      );
    }
  }

  /// Isolate-safe copy of [_decodeJson] (no instance state).
  static dynamic _decodeJsonIsolate(String body) {
    final trimmed = body.trim();
    if (trimmed.isEmpty) return null;
    try {
      return jsonDecode(trimmed);
    } catch (_) {}
    final start = trimmed.indexOf('{');
    final end = trimmed.lastIndexOf('}');
    if (start >= 0 && end > start) {
      try {
        return jsonDecode(trimmed.substring(start, end + 1));
      } catch (_) {}
    }
    return null;
  }

  /// WhatsApp/Gmail: never show "parse failed". Pull JSON out of mixed HTML.
  dynamic _decodeJson(String body) {
    final trimmed = body.trim();
    if (trimmed.isEmpty) return null;
    try {
      return jsonDecode(trimmed);
    } catch (_) {}
    final start = trimmed.indexOf('{');
    final end = trimmed.lastIndexOf('}');
    if (start >= 0 && end > start) {
      try {
        return jsonDecode(trimmed.substring(start, end + 1));
      } catch (_) {}
    }
    return null;
  }

  String _httpErrorLabel(int code) {
    if (code == 429) return 'School is busy. Will retry on its own.';
    if (code == 409) return 'Already submitted. Only Education can change it.';
    if (code >= 500) return 'School is busy. Your work is still on this phone.';
    if (code == 401 || code == 403) return 'Please sign in again.';
    return 'Could not finish this request. Your work is still on this phone.';
  }

  /// Handle network errors.
  /// Timeout ≠ offline. Only a missing radio is a network error.
  ApiResponse _handleError(dynamic error) {
    final msg = error.toString();
    if (msg.contains('TimeoutException')) {
      return ApiResponse.error(
          'The school is taking longer than usual. Try again.',
          0,
          false,
          ApiFailureKind.timeout);
    }
    if (msg.contains('SocketException') ||
        msg.contains('HandshakeException') ||
        msg.contains('OS Error')) {
      final noRadio = !_connectivity.hasLink;
      return ApiResponse.error(
        noRadio
            ? 'Waiting for network. Your work is still on this phone.'
            : 'Could not reach the school right now. Your work is still on this phone.',
        0,
        noRadio,
        ApiFailureKind.transport,
      );
    }
    return ApiResponse.error('Could not finish this request. Please try again.');
  }

  Future<void> _notifyIfRefreshRejected() async {
    if (_refreshWasRejected && !_authExpiryNotified && onAuthExpired != null) {
      _authExpiryNotified = true;
      await onAuthExpired!('refresh_rejected');
    }
  }

  // ============================================================
  // AUTH
  // ============================================================

  /// Phase one of login: authenticate only. The returned candidate is never
  /// persisted here; SessionCoordinator performs owner/inventory reconciliation
  /// before calling [activateCredentials].
  Future<ApiResponse> login(String username, String password) =>
      post('/auth/login', body: {
        'username': username,
        'password': password,
      }, auth: false);

  Future<AuthRefreshOutcome> _refreshAfterUnauthorized(
      String? sentAccessToken) async {
    // Another request may already have completed a same-generation refresh.
    // A scope transition advances the generation before installing its token,
    // so it can never be mistaken for this fast path.
    if (_token != null && _token != sentAccessToken) {
      return AuthRefreshOutcome.sameScope;
    }
    return refreshAccessToken();
  }

  /// Rotate the refresh token exactly once even when several requests receive
  /// a 401 together. The typed result prevents scope changes, rejections and
  /// temporary network failures from sharing the old boolean retry path.
  Future<AuthRefreshOutcome> refreshAccessToken() async {
    final generation = _requestGeneration;
    final existing = _refreshInFlight;
    if (existing != null && _refreshInFlightGeneration == generation) {
      return existing;
    }

    // A refresh from a superseded generation may still be unwinding after
    // logout. The new owner never joins that future; both remain safe because
    // credential writes are serialized and generation/token checked.
    final attempt = _performRefreshAccessToken();
    _refreshInFlight = attempt;
    _refreshInFlightGeneration = generation;
    try {
      return await attempt;
    } finally {
      if (identical(_refreshInFlight, attempt)) {
        _refreshInFlight = null;
        _refreshInFlightGeneration = null;
      }
    }
  }

  Future<AuthRefreshOutcome> _performRefreshAccessToken() async {
    final generation = _requestGeneration;
    final presentedRefreshToken = _refreshToken;
    _refreshWasRejected = presentedRefreshToken == null;
    if (presentedRefreshToken == null) {
      return AuthRefreshOutcome.rejected;
    }

    try {
      final response = await _http
          .post(
            Uri.parse('${AppConfig.apiBaseUrl}/auth/refresh-token'),
            headers: _headers(withAuth: false),
            body: jsonEncode({'refresh_token': presentedRefreshToken}),
          )
          .timeout(Duration(seconds: AppConfig.postTimeout));
      if (!_generationIsCurrent(generation) ||
          _refreshToken != presentedRefreshToken) {
        return AuthRefreshOutcome.superseded;
      }
      _connectivity.markOnline();

      if (response.statusCode == 401 || response.statusCode == 403) {
        _refreshWasRejected = true;
        return AuthRefreshOutcome.rejected;
      }
      _refreshWasRejected = false;
      if (response.statusCode < 200 || response.statusCode >= 300) {
        return AuthRefreshOutcome.transientFailure;
      }

      final decoded = _decodeJson(response.body);
      if (decoded is! Map<String, dynamic> ||
          decoded['status'] != 'success' ||
          decoded['data'] is! Map) {
        return AuthRefreshOutcome.transientFailure;
      }
      final candidate = _bundleFromAuthData(
        Map<String, dynamic>.from(decoded['data'] as Map),
      );
      if (candidate == null) return AuthRefreshOutcome.transientFailure;
      if (candidate.userId != userId) {
        // A refresh token is bound to one user. A different subject is a
        // definitive credential failure, never an account switch.
        _refreshWasRejected = true;
        return AuthRefreshOutcome.rejected;
      }

      final scopeChanged = candidate.role != userRole ||
          candidate.authorizationVersion != authorizationVersion;
      if (scopeChanged) {
        final apply = onAuthorizationScopeChanged;
        if (apply == null) return AuthRefreshOutcome.transientFailure;
        final accepted = await apply(candidate, generation);
        if (!_generationIsCurrent(generation)) {
          return AuthRefreshOutcome.scopeChanged;
        }
        // The coordinator must advance generation before reporting success.
        // Fail closed if it declined the candidate or violated that fence.
        return accepted
            ? AuthRefreshOutcome.superseded
            : AuthRefreshOutcome.transientFailure;
      }

      final sameScopeCandidate = _withSameScopeCanonicalProfile(candidate);
      final persisted = await _serializeCredentialMutation(() async {
        if (!_generationIsCurrent(generation) ||
            _refreshToken != presentedRefreshToken) {
          return false;
        }
        final user = jsonDecode(sameScopeCandidate.userJson);
        if (user is! Map<String, dynamic>) return false;
        // Profile first and access token last: the token remains the secure
        // commit marker for one complete same-scope credential bundle.
        await _secureStorage.write(
          key: AppConfig.userDataKey,
          value: sameScopeCandidate.userJson,
        );
        await _secureStorage.write(
          key: AppConfig.refreshTokenKey,
          value: sameScopeCandidate.refreshToken,
        );
        await _secureStorage.write(
          key: AppConfig.tokenKey,
          value: sameScopeCandidate.accessToken,
        );
        _userData = Map<String, dynamic>.from(user);
        _refreshToken = sameScopeCandidate.refreshToken;
        _token = sameScopeCandidate.accessToken;
        _refreshWasRejected = false;
        _authExpiryNotified = false;
        return true;
      });
      return persisted
          ? AuthRefreshOutcome.sameScope
          : AuthRefreshOutcome.superseded;
    } catch (_) {
      if (!_generationIsCurrent(generation) ||
          _refreshToken != presentedRefreshToken) {
        return AuthRefreshOutcome.superseded;
      }
      // Network, timeout, protocol and storage failures preserve the current
      // session. Only an explicit 401/403 is definitive credential loss.
      return AuthRefreshOutcome.transientFailure;
    }
  }

  // ============================================================
  // SELF-SERVICE PROFILE (online-only; never routed through an outbox)
  // ============================================================

  Future<ApiResponse> getOwnProfile() => get('/users/me');

  Future<ApiResponse> updateOwnProfile(Map<String, dynamic> fields) =>
      _profileJsonMutation('PATCH', '/users/me', fields);

  Future<ApiResponse> changeOwnPassword({
    required String currentPassword,
    required String newPassword,
    required String confirmation,
  }) =>
      post('/users/change-password', body: {
        'current_password': currentPassword,
        'new_password': newPassword,
        'confirm_password': confirmation,
      });

  Future<ApiResponse> removeOwnProfileImage(String profileVersion) =>
      _profileJsonMutation('DELETE', '/users/me/profile-image', {
        'profile_version': profileVersion,
      });

  Future<ApiResponse> _profileJsonMutation(
    String method,
    String path,
    Map<String, dynamic> body,
  ) async {
    final generation = _requestGeneration;
    final sentToken = _token;
    try {
      final uri = Uri.parse('${AppConfig.apiBaseUrl}$path');
      Future<http.Response> send({String? httpMethod, Map<String, String>? extraHeaders}) {
        final headers = _headers();
        if (extraHeaders != null) headers.addAll(extraHeaders);
        final m = httpMethod ?? method;
        final encoded = jsonEncode(body);
        if (m == 'PATCH') {
          return _http
              .patch(uri, headers: headers, body: encoded)
              .timeout(Duration(seconds: AppConfig.postTimeout));
        } else if (m == 'PUT') {
          return _http
              .put(uri, headers: headers, body: encoded)
              .timeout(Duration(seconds: AppConfig.postTimeout));
        } else if (m == 'DELETE') {
          return _http
              .delete(uri, headers: headers, body: encoded)
              .timeout(Duration(seconds: AppConfig.postTimeout));
        }
        return _http
            .post(uri, headers: headers, body: encoded)
            .timeout(Duration(seconds: AppConfig.postTimeout));
      }

      var response = await send();
      // Telegram/WhatsApp fallback: If server or proxy rejects PATCH with 404/405,
      // transparently retry with POST + X-HTTP-Method-Override and POST field
      if ((response.statusCode == 404 || response.statusCode == 405) &&
          (response.body.contains('No handler') || response.body.contains('not allowed') || response.body.contains('404'))) {
        final postHeaders = {
          ..._headers(),
          'X-HTTP-Method-Override': method,
        };
        final postBody = {
          ...body,
          '_method': method,
        };
        response = await _http
            .post(
              uri,
              headers: postHeaders,
              body: jsonEncode(postBody),
            )
            .timeout(Duration(seconds: AppConfig.postTimeout));
      }

      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      AuthRefreshOutcome? refreshOutcome;
      if (response.statusCode == 401) {
        refreshOutcome = await _refreshAfterUnauthorized(sentToken);
        if (!_generationIsCurrent(generation) ||
            const {
              AuthRefreshOutcome.scopeChanged,
              AuthRefreshOutcome.superseded,
            }.contains(refreshOutcome)) {
          return ApiResponse.superseded(generation);
        }
        if (const {AuthRefreshOutcome.sameScope}.contains(refreshOutcome)) {
          response = await send();
        } else if (refreshOutcome == AuthRefreshOutcome.rejected) {
          await _notifyIfRefreshRejected();
        }
      }
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleResponse(response)
          .withRefreshOutcome(refreshOutcome)
          .withGeneration(generation);
    } catch (error) {
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleError(error).withGeneration(generation);
    }
  }

  Future<ApiResponse> uploadOwnProfileImage({
    required String filePath,
    required String profileVersion,
  }) async {
    final generation = _requestGeneration;
    final sentToken = _token;
    try {
      Future<http.Response> send({String? url}) async {
        final targetUrl = url ?? '${AppConfig.apiBaseUrl}/users/me/profile-image';
        final request = http.MultipartRequest(
          'POST',
          Uri.parse(targetUrl),
        )
          ..fields['profile_version'] = profileVersion
          ..files.add(await http.MultipartFile.fromPath('image', filePath));
        final headers = _headers();
        headers.remove('Content-Type');
        request.headers.addAll(headers);
        final streamed = await _http
            .send(request)
            .timeout(const Duration(seconds: 60));
        return http.Response.fromStream(streamed);
      }

      var response = await send();
      if ((response.statusCode == 404 || response.statusCode == 405) &&
          response.body.contains('No handler')) {
        // Fallback to alternative endpoint path
        response = await send(url: '${AppConfig.apiBaseUrl}/users/profile-image');
      }

      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      AuthRefreshOutcome? refreshOutcome;
      if (response.statusCode == 401) {
        refreshOutcome = await _refreshAfterUnauthorized(sentToken);
        if (!_generationIsCurrent(generation) ||
            const {
              AuthRefreshOutcome.scopeChanged,
              AuthRefreshOutcome.superseded,
            }.contains(refreshOutcome)) {
          return ApiResponse.superseded(generation);
        }
        if (const {AuthRefreshOutcome.sameScope}.contains(refreshOutcome)) {
          response = await send();
        } else if (refreshOutcome == AuthRefreshOutcome.rejected) {
          await _notifyIfRefreshRejected();
        }
      }
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleResponse(response)
          .withRefreshOutcome(refreshOutcome)
          .withGeneration(generation);
    } catch (error) {
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleError(error).withGeneration(generation);
    }
  }

  Future<ApiResponse> downloadOwnProfileImage() async {
    final generation = _requestGeneration;
    final sentToken = _token;
    try {
      final uri =
          Uri.parse('${AppConfig.apiBaseUrl}/users/me/profile-image');
      Future<http.Response> send() => _http
          .get(uri, headers: _headers())
          .timeout(Duration(seconds: AppConfig.connectionTimeout));

      var response = await send();
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      AuthRefreshOutcome? refreshOutcome;
      if (response.statusCode == 401) {
        refreshOutcome = await _refreshAfterUnauthorized(sentToken);
        if (!_generationIsCurrent(generation) ||
            const {
              AuthRefreshOutcome.scopeChanged,
              AuthRefreshOutcome.superseded,
            }.contains(refreshOutcome)) {
          return ApiResponse.superseded(generation);
        }
        if (const {AuthRefreshOutcome.sameScope}.contains(refreshOutcome)) {
          response = await send();
        } else if (refreshOutcome == AuthRefreshOutcome.rejected) {
          await _notifyIfRefreshRejected();
        }
      }
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }

      final contentType = response.headers['content-type']?.toLowerCase() ?? '';
      if (response.statusCode >= 200 &&
          response.statusCode < 300 &&
          contentType.startsWith('image/jpeg') &&
          response.bodyBytes.isNotEmpty) {
        _connectivity.markOnline();
        return ApiResponse(
          success: true,
          data: Uint8List.fromList(response.bodyBytes),
          statusCode: response.statusCode,
          etag: response.headers['etag'],
          refreshOutcome: refreshOutcome,
          requestGeneration: generation,
        );
      }
      return _handleResponse(response)
          .withRefreshOutcome(refreshOutcome)
          .withGeneration(generation);
    } catch (error) {
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleError(error).withGeneration(generation);
    }
  }

  // ============================================================
  // DASHBOARD
  // ============================================================

  Future<ApiResponse> getDashboardStats() => get('/dashboard/stats');
  Future<ApiResponse> getRecentActivity({int limit = 20}) =>
      get('/dashboard/recent', params: {'limit': '$limit'});

  // ============================================================
  // TIMETABLE (read-only; built by Education on the web)
  // ============================================================

  /// Weekly grid of the signed-in teacher (lessons keyed by ISO weekday)
  /// plus upcoming makeup classes. Teachers always get their own schedule.
  Future<ApiResponse> getMyTimetableWeek() => get('/timetable/week');

  // ============================================================
  // MEMBERS
  // ============================================================

  Future<ApiResponse> getMembers(
      {int page = 1,
      int limit = 20,
      String? search,
      String? status,
      String? gender}) {
    final params = <String, String>{'page': '$page', 'limit': '$limit'};
    if (search != null && search.isNotEmpty) params['search'] = search;
    if (status != null && status.isNotEmpty) params['status'] = status;
    if (gender != null && gender.isNotEmpty) params['gender'] = gender;
    return get('/members', params: params);
  }

  Future<ApiResponse> getMember(int id) => get('/members/$id');
  Future<ApiResponse> updateMember(int id, Map<String, dynamic> data) =>
      put('/members/$id', body: data);
  Future<ApiResponse> getMemberAttendance(int id, {int days = 90}) =>
      get('/members/$id/attendance', params: {'days': '$days'});

  // ============================================================
  // ATTENDANCE WARNINGS (attendance alerting Phase 2)
  // Server: api/v1/routes/attendance_warnings.php + members.php.
  // Every date in these payloads is already an Ethiopian-calendar
  // label (opened_ec, closed_ec, start_ec …) — the app never formats
  // a Gregorian date for this feature.
  // ============================================================

  Future<ApiResponse> getAttendanceWarnings({
    String department = 'all',
    String status = 'open',
    String kind = '',
    String query = '',
    int memberId = 0,
    int limit = 100,
    int offset = 0,
  }) {
    final params = <String, String>{
      'department': department,
      'status': status,
      'limit': '$limit',
      'offset': '$offset',
    };
    if (kind.isNotEmpty) params['kind'] = kind;
    if (query.trim().isNotEmpty) params['q'] = query.trim();
    if (memberId > 0) params['member_id'] = '$memberId';
    return get('/attendance/warnings', params: params);
  }

  Future<ApiResponse> getAttendanceWarningCounts() =>
      get('/attendance/warnings/counts');

  Future<ApiResponse> getAttendanceWarning(int id) =>
      get('/attendance/warnings/$id');

  /// Close with an outcome (contacted | excused | escalated | deactivated |
  /// no_action). The server enforces who may close (owning department,
  /// HR, Information) and audits the action.
  Future<ApiResponse> closeAttendanceWarning(int id,
          {required String outcome, String note = ''}) =>
      post('/attendance/warnings/$id/close',
          body: {'outcome': outcome, 'note': note});

  /// Per-member streak / month / year risk for the departments this role
  /// may see. 403 for roles outside the attendance-warning readers.
  Future<ApiResponse> getMemberAttendanceRisk(int id) =>
      get('/members/$id/attendance-risk');

  // ── Phase 9: department review inbox (approve / return) ───────
  // dept ∈ {edu, mezmur, hr} — each department reviews ONLY its own
  // packets; the server re-checks the bearer's role on every call.
  String _reviewBase(String dept) =>
      dept == 'edu' ? '/grades' : (dept == 'hr' ? '/hr' : '/mezmur');

  Future<ApiResponse> getReviewSubmissions(String dept,
      {String status = 'attention', int page = 1, int perPage = 50}) {
    // Phase 3: with_total=1 asks edu for total/total_pages (mezmur
    // always sends them); older servers ignore it.
    final params = <String, String>{
      'page': '$page',
      'per_page': '$perPage',
      'with_total': '1',
    };
    if (dept == 'edu') {
      params['status_filter'] = status;
    } else {
      params['status'] = status;
    }
    return get('${_reviewBase(dept)}/submissions', params: params);
  }

  Future<ApiResponse> getReviewSubmission(String dept, int id) =>
      get('${_reviewBase(dept)}/submission', params: {'id': '$id'});

  Future<ApiResponse> reviewSubmission(
    String dept,
    int id,
    String status, {
    String notes = '',
    String? clientOpId,
  }) =>
      post('${_reviewBase(dept)}/submission-review',
          body: {'id': id, 'status': status, 'notes': notes},
          idempotencyKey: clientOpId);

  // ============================================================
  // CLASSES
  // ============================================================

  Future<ApiResponse> getClasses() => get('/classes');
  Future<ApiResponse> getClassStudents(int classId) =>
      get('/classes/$classId/students');

  // ============================================================
  // ATTENDANCE
  // ============================================================

  /// Compact server-computed calendar for offline class-day gating.
  Future<ApiResponse> getAttendanceCalendar({String? etag}) => get(
      '/attendance/calendar',
      headers: etag == null ? null : {'If-None-Match': etag});

  Future<ApiResponse> getAttendance(int classId, {String? date}) {
    final params = <String, String>{'class_id': '$classId'};
    if (date != null) params['date'] = date;
    return get('/attendance', params: params);
  }

  /// One page of attendance changes after [cursor] from the server's
  /// change feed (PHASE A). A response may be partial: the caller must
  /// follow `next_cursor` while `has_more` is true, and must honour
  /// `bootstrap_required` instead of treating an empty page as "up to
  /// date". Read-only, so no idempotency key is involved.
  Future<ApiResponse> getAttendanceChanges({int cursor = 0, int limit = 200}) =>
      get('/sync/changes', params: {
        'entity': 'attendance',
        'cursor': '$cursor',
        'limit': '$limit',
      });

  Future<ApiResponse> saveAttendance(
          int classId, String date, List<Map<String, dynamic>> records,
          {String? clientOpId,
          String? attemptUid,
          int? attemptNumber,
          String? executionSource}) =>
      post('/attendance',
          body: {
            'class_id': classId,
            'date': date,
            'records': records,
          },
          idempotencyKey: clientOpId,
          attemptUid: attemptUid,
          attemptNumber: attemptNumber,
          executionSource: executionSource);

  Future<ApiResponse> submitAttendance(
          int classId, String date, List<Map<String, dynamic>> records,
          {String? clientOpId,
          String? attemptUid,
          int? attemptNumber,
          String? executionSource}) =>
      post('/attendance/submit',
          body: {
            'class_id': classId,
            'date': date,
            'records': records,
          },
          idempotencyKey: clientOpId,
          attemptUid: attemptUid,
          attemptNumber: attemptNumber,
          executionSource: executionSource);

  // ── Mezmur department (date-based, section-grouped) ─────────
  Future<ApiResponse> getMezmurDays(
      {int page = 1, String? from, String? to}) {
    final params = <String, String>{'page': '$page'};
    if (from != null && from.isNotEmpty) params['from'] = from;
    if (to != null && to.isNotEmpty) params['to'] = to;
    return get('/mezmur/days', params: params);
  }

  Future<ApiResponse> createMezmurDay({
    required String date,
    required String programType,
    String? title,
    String? notes,
  }) {
    return post('/mezmur/days', body: {
      'date': date,
      'program_type': programType,
      if (title != null && title.isNotEmpty) 'title': title,
      if (notes != null && notes.isNotEmpty) 'notes': notes,
    });
  }

  Future<ApiResponse> getMezmurSheet(String date, {String? section}) {
    final params = <String, String>{'date': date};
    if (section != null && section.isNotEmpty) params['section'] = section;
    return get('/mezmur/sheet', params: params);
  }

  /// Section-scoped save (teacher clone). [kind] = 'draft' | 'submitted'.
  Future<ApiResponse> saveMezmurSheet(
      String date, List<Map<String, dynamic>> records,
      {String? section,
      String kind = 'draft',
      String? clientOpId,
      String? attemptUid,
      int? attemptNumber,
      String? executionSource}) {
    return post('/mezmur/sheet',
        body: {
          'date': date,
          'records': records,
          if (section != null && section.isNotEmpty) 'section': section,
          if (section != null && section.isNotEmpty) 'kind': kind,
        },
        idempotencyKey: clientOpId,
        attemptUid: attemptUid,
        attemptNumber: attemptNumber,
        executionSource: executionSource);
  }

  /// Active sections with member counts (for the [Section ▾] picker).
  Future<ApiResponse> getMezmurSections() => get('/mezmur/sections');

  // ── HR department attendance — RETIRED (2026-10-07) ──
  // HR no longer takes attendance (reports live on the web dashboard);
  // the server answers 410 HR_ATTENDANCE_RETIRED to writes. The read
  // clients went with the HR screens; saveHrSheet stays as the outbox
  // drain transport so packets queued by OLD app versions settle
  // honestly against the 410 instead of hanging forever.
  Future<ApiResponse> saveHrSheet(
      String date, List<Map<String, dynamic>> records,
      {String? section,
      String kind = 'draft',
      String? clientOpId,
      String? attemptUid,
      int? attemptNumber,
      String? executionSource}) {
    return post('/hr/sheet',
        body: {
          'date': date,
          'records': records,
          if (section != null && section.isNotEmpty) 'section': section,
          if (section != null && section.isNotEmpty) 'kind': kind,
        },
        idempotencyKey: clientOpId,
        attemptUid: attemptUid,
        attemptNumber: attemptNumber,
        executionSource: executionSource);
  }

  Future<ApiResponse> getMezmurHymns(
      {int page = 1,
      int perPage = 25,
      String? search,
      String? category,
      int? categoryId,
      int? zemarianId,
      String? length,
      String? language,
      String? status}) {
    final params = <String, String>{'page': '$page', 'per_page': '$perPage'};
    if (search != null && search.isNotEmpty) params['search'] = search;
    if (category != null && category.isNotEmpty) params['category'] = category;
    if (categoryId != null && categoryId > 0) {
      params['category_id'] = '$categoryId';
    }
    if (zemarianId != null && zemarianId > 0) {
      params['zemarian_id'] = '$zemarianId';
    }
    if (length != null && length.isNotEmpty) params['length'] = length;
    if (language != null && language.isNotEmpty) params['language'] = language;
    if (status != null && status.isNotEmpty) params['status'] = status;
    return get('/mezmur/hymns', params: params);
  }

  Future<ApiResponse> getMezmurHymn(int id) =>
      get('/mezmur/hymn', params: {'id': '$id'});

  /// P46: store timed (LRC) lyrics for a hymn.
  ///
  /// Empty [lrc] clears the timings and falls back to static lyrics.
  /// Safe to retry: the server does a full REPLACE of one column keyed
  /// by hymn id, so applying the same body twice is identical to once.
  Future<ApiResponse> saveMezmurSyncedLyrics(int hymnId, String lrc,
      {String? clientOpId}) {
    // `body:` is a NAMED parameter, and idempotencyKey both sets the
    // Idempotency-Key header and injects client_op_id — the same
    // contract every other mezmur write uses.
    return post('/mezmur/lyrics-synced',
        body: {'id': hymnId, 'lrc': lrc}, idempotencyKey: clientOpId);
  }

  /// Returns a short-lived signed GET URL for a verified hymn audio object.
  /// The object key and storage credentials never leave the server.
  Future<ApiResponse> getMezmurAudioUrl(int hymnId) =>
      get('/mezmur/audio/$hymnId');

  // ── Hymn library offline sync (delta + outbox) ──────────────

  /// Delta pull: rows changed after [cursor] ("ts|id" change token).
  /// Metadata only unless [includeLyrics] — lyrics are heavy blobs
  /// downloaded lazily.
  Future<ApiResponse> getMezmurHymnsChanges(
      {String cursor = '', int limit = 200, bool includeLyrics = false}) {
    final params = <String, String>{'limit': '$limit'};
    if (cursor.isNotEmpty) params['cursor'] = cursor;
    if (includeLyrics) params['include_lyrics'] = '1';
    return get('/mezmur/hymns/changes', params: params);
  }

  /// Create/update a hymn. [baseRevision] enables conflict detection
  /// for offline edits (server returns 409 + the newest copy).
  Future<ApiResponse> saveMezmurHymn(Map<String, dynamic> hymn,
      {String? clientOpId, int? baseRevision}) {
    final body = Map<String, dynamic>.from(hymn);
    if (baseRevision != null) body['base_revision'] = baseRevision;
    return post('/mezmur/hymn', body: body, idempotencyKey: clientOpId);
  }

  Future<ApiResponse> setMezmurHymnStatus(int id, String status,
      {String? clientOpId}) {
    return post('/mezmur/hymn-status',
        body: {'id': id, 'status': status}, idempotencyKey: clientOpId);
  }

  Future<ApiResponse> getMezmurCategories() => get('/mezmur/categories');

  Future<ApiResponse> saveMezmurCategory(Map<String, dynamic> category,
      {String? clientOpId}) {
    return post('/mezmur/category',
        body: Map<String, dynamic>.from(category), idempotencyKey: clientOpId);
  }

  /// Multipart cover-image upload for a hymn category. Binary body —
  /// never queued; callers gate it on connectivity.
  Future<ApiResponse> uploadCategoryImage(int id, String filePath) =>
      _uploadTaxonomyImage('/mezmur/category-image', id, filePath);

  /// P34: singer cover images ride the same hardened chain.
  Future<ApiResponse> uploadZemarianImage(int id, String filePath) =>
      _uploadTaxonomyImage('/mezmur/zemarian-image', id, filePath);

  Future<ApiResponse> _uploadTaxonomyImage(
      String path, int id, String filePath) async {
    final generation = _requestGeneration;
    final sentToken = _token;
    try {
      Future<http.Response> send() async {
        final uri = Uri.parse('${AppConfig.apiBaseUrl}$path');
        final req = http.MultipartRequest('POST', uri)
          ..fields['id'] = '$id'
          ..files.add(await http.MultipartFile.fromPath('image', filePath));
        // P33 fix: the JSON content-type header broke the multipart
        // boundary, so the server saw no file at all — strip it and
        // let the multipart writer set its own content-type.
        final hs = _headers(withAuth: true);
        hs.remove('Content-Type');
        req.headers.addAll(hs);
        final streamed = await _http.send(req).timeout(
            const Duration(seconds: 60)); // image bytes need a longer leash
        return http.Response.fromStream(streamed);
      }

      var response = await send();
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      AuthRefreshOutcome? refreshOutcome;
      if (response.statusCode == 401) {
        refreshOutcome = await _refreshAfterUnauthorized(sentToken);
        if (!_generationIsCurrent(generation) ||
            refreshOutcome == AuthRefreshOutcome.scopeChanged ||
            refreshOutcome == AuthRefreshOutcome.superseded) {
          return ApiResponse.superseded(generation);
        }
        if (refreshOutcome == AuthRefreshOutcome.sameScope) {
          response = await send();
        } else if (refreshOutcome == AuthRefreshOutcome.rejected) {
          await _notifyIfRefreshRejected();
        }
      }
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleResponse(response)
          .withRefreshOutcome(refreshOutcome)
          .withGeneration(generation);
    } catch (e) {
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleError(e).withGeneration(generation);
    }
  }

  /// Drop a category's cover image (the gradient shows instead).
  Future<ApiResponse> removeCategoryImage(int id) {
    return post('/mezmur/category-image-remove', body: {'id': id});
  }

  /// P66 hymn art: per-hymn cover upload (multipart; the same hardened
  /// server chain as taxonomy covers — magic bytes, re-encode, square
  /// renditions, dominant color). Binary body — never queued; callers
  /// gate it on connectivity.
  Future<ApiResponse> uploadMezmurHymnArt(int id, String filePath) =>
      _uploadTaxonomyImage('/mezmur/art', id, filePath);

  /// P66 hymn art: drop a hymn's cover (the name-hash gradient shows).
  Future<ApiResponse> removeMezmurHymnArt(int id) {
    return post('/mezmur/art-remove', body: {'id': id});
  }

  Future<ApiResponse> setMezmurCategoryStatus(int id, bool active,
      {String? clientOpId}) {
    return post('/mezmur/category-status',
        body: {'id': id, 'active': active}, idempotencyKey: clientOpId);
  }

  Future<ApiResponse> getMezmurZemarians() => get('/mezmur/zemarians');

  Future<ApiResponse> saveMezmurZemarian(Map<String, dynamic> zemarian,
      {String? clientOpId}) {
    return post('/mezmur/zemarian',
        body: Map<String, dynamic>.from(zemarian), idempotencyKey: clientOpId);
  }

  Future<ApiResponse> setMezmurZemarianStatus(int id, bool active,
      {String? clientOpId}) {
    return post('/mezmur/zemarian-status',
        body: {'id': id, 'active': active}, idempotencyKey: clientOpId);
  }

  Future<ApiResponse> getMezmurAnalytics(
      {Map<String, String>? params}) =>
      get('/mezmur/analytics', params: params);

  Future<ApiResponse> getDailyStats({String? date}) {
    final params = <String, String>{};
    if (date != null) params['date'] = date;
    return get('/attendance/daily-stats', params: params);
  }

  // ============================================================
  // GRADES
  // ============================================================

  Future<ApiResponse> getGradeBootstrap(int classId) =>
      get('/grades/bootstrap', params: {'class_id': '$classId'});

  Future<ApiResponse> getClassSubjects(int classId) =>
      get('/grades/subjects', params: {'class_id': '$classId'});

  /// 1.6.5: [termId] selects a specific semester's assessments (view-only
  /// analysis of a closed semester for teachers). Omitted → the server
  /// defaults to the CURRENT semester of the active year.
  Future<ApiResponse> getAssessments(int classId, int subjectId, {int? termId}) =>
      get('/grades/assessments',
          params: {
            'class_id': '$classId',
            'subject_id': '$subjectId',
            if (termId != null) 'term_id': '$termId',
          });

  // 1.6.6: createAssessment() was removed — assessments are created and
  // managed by the Education Department (web console). The mobile API
  // endpoint refuses teacher creation with 403 by design.

  Future<ApiResponse> getGradeStudents(int assessmentId) =>
      get('/grades/students', params: {'assessment_id': '$assessmentId'});

  /// One student's results for the class + subject + semester of a test
  /// (private full-screen result card, 1.7.3).
  Future<ApiResponse> getStudentResults(int assessmentId, int memberId) =>
      get('/grades/student-results', params: {
        'assessment_id': '$assessmentId',
        'member_id': '$memberId',
      });

  Future<ApiResponse> saveGrades(
          int assessmentId, List<Map<String, dynamic>> grades,
          {String? clientOpId,
          String? attemptUid,
          int? attemptNumber,
          String? executionSource}) =>
      post('/grades/save',
          body: {
            'assessment_id': assessmentId,
            'grades': grades,
          },
          idempotencyKey: clientOpId,
          attemptUid: attemptUid,
          attemptNumber: attemptNumber,
          executionSource: executionSource);

  Future<ApiResponse> submitGrades(
          int assessmentId, List<Map<String, dynamic>> grades,
          {String? clientOpId,
          String? attemptUid,
          int? attemptNumber,
          String? executionSource}) =>
      post('/grades/submit',
          body: {
            'assessment_id': assessmentId,
            'grades': grades,
          },
          idempotencyKey: clientOpId,
          attemptUid: attemptUid,
          attemptNumber: attemptNumber,
          executionSource: executionSource);

  Future<ApiResponse> getGradeSummary(int classId, {int? subjectId}) {
    final params = <String, String>{'class_id': '$classId'};
    if (subjectId != null) params['subject_id'] = '$subjectId';
    return get('/grades/summary', params: params);
  }

  // ============================================================
  // EDUCATION (read + one-student enroll — create teacher stays on website)
  // ============================================================

  Future<ApiResponse> getTeachers({int page = 1, int limit = 50, String? search}) {
    final params = <String, String>{'page': '$page', 'limit': '$limit'};
    if (search != null && search.isNotEmpty) params['q'] = search;
    return get('/teachers', params: params);
  }

  Future<ApiResponse> getTeacher(int id) => get('/teachers/$id');

  Future<ApiResponse> getSubjects() => get('/subjects');

  Future<ApiResponse> getEnrollmentOverview() => get('/enrollment/overview');

  /// Phase 3: with_total=1 + offset — Load more after the first 20.
  Future<ApiResponse> searchEnrollment(String q,
          {int limit = 20, int offset = 0}) =>
      get('/enrollment/search', params: {
        'q': q,
        'limit': '$limit',
        'offset': '$offset',
        'with_total': '1',
      });

  Future<ApiResponse> enrollStudent(int memberId, int classId) =>
      post('/enrollment', body: {'member_id': memberId, 'class_id': classId});

  // ============================================================
  // P72 — Communication Center (notifications / announcements /
  // messaging). Same service as the web dashboards; one writer.
  // ============================================================

  /// P74 Phase 3 — conditional GET: with [ifNoneMatch] (a previously
  /// stored ETag) the server answers 304 + an empty body when nothing
  /// changed; `ApiResponse.notModified` tells the poller to no-op.
  Future<ApiResponse> getNotificationSummary({String? ifNoneMatch}) =>
      get('/notifications/summary',
          headers: (ifNoneMatch == null || ifNoneMatch.isEmpty)
              ? null
              : {'If-None-Match': ifNoneMatch});

  Future<ApiResponse> getNotificationFeed(
      {int limit = 30,
      int offset = 0,
      bool unreadOnly = false,
      int? beforeId}) {
    final params = <String, String>{
      'limit': '$limit',
      'offset': '$offset',
      if (unreadOnly) 'unread': '1',
      // P74 Phase 3 — stable cursor for "Load older".
      if (beforeId != null && beforeId > 0) 'before_id': '$beforeId',
    };
    return get('/notifications/feed', params: params);
  }

  Future<ApiResponse> markNotificationRead(int id) =>
      post('/notifications/mark-read', body: {'id': id});

  Future<ApiResponse> markAllNotificationsRead({String scope = 'alerts'}) =>
      post('/notifications/mark-all-read', body: {'scope': scope});

  Future<ApiResponse> getAnnouncements(
      {int limit = 30, int offset = 0, int? beforeId, int? beforePin}) {
    return get('/notifications/announcements', params: {
      'limit': '$limit',
      'offset': '$offset',
      // P74 Phase 3 — (before_pin, before_id) tuple cursor.
      if (beforeId != null && beforeId > 0) 'before_id': '$beforeId',
      if (beforePin != null && beforePin >= 0 && beforeId != null)
        'before_pin': '$beforePin',
    });
  }

  Future<ApiResponse> markAnnouncementRead(int id) =>
      post('/notifications/announcement-read', body: {'id': id});

  Future<ApiResponse> composeAnnouncement(
      {required String title,
      required String body,
      String priority = 'normal',
      String audience = 'roles',
      List<String> roles = const [],
      List<int> userIds = const []}) {
    return post('/notifications/compose', body: {
      'title': title,
      'body': body,
      'priority': priority,
      'audience': audience,
      'roles': roles,
      'user_ids': userIds,
    });
  }

  Future<ApiResponse> getAnnounceTargets() =>
      get('/notifications/targets');

  Future<ApiResponse> getMessagePartners() =>
      get('/notifications/partners');

  Future<ApiResponse> getThreads() => get('/notifications/threads');

  /// P74 Phase 2 — the thread window. With [beforeId] this fetches the
  /// OLDER page below that message id ("Load older"); without it, the
  /// newest window (200) plus has_older/oldest_id/read_watermark.
  ///
  /// P74 Phase 4 — [ifNoneMatch] makes the newest-window fetch a
  /// conditional GET (the open-conversation poll): a 304 means
  /// nothing in the thread changed — no body, no markRead write.
  /// Older-page fetches (beforeId) must NOT be conditional: their
  /// ETag seed differs (window variant).
  Future<ApiResponse> getThread(int id, {int? beforeId, String? ifNoneMatch}) =>
      get('/notifications/thread',
          params: {
            'id': '$id',
            if (beforeId != null && beforeId > 0) 'before_id': '$beforeId',
          },
          headers: (ifNoneMatch == null || ifNoneMatch.isEmpty || (beforeId != null && beforeId > 0))
              ? null
              : {'If-None-Match': ifNoneMatch});

  Future<ApiResponse> startThread(
          {required List<int> to,
          required String subject,
          required String body}) =>
      post('/notifications/thread-start',
          body: {'to': to, 'subject': subject, 'body': body});

  /// O3 — [clientTag] rides the payload for exactly-once sending. The
  /// server ignores it until migration 046 lands (messages.client_tag
  /// unique index); from then on a drained-and-retried send can never
  /// duplicate. Harmless extra field today by design.
  Future<ApiResponse> sendMessage(int threadId, String body,
          {String? clientTag}) =>
      post('/notifications/send-message', body: {
        'thread_id': threadId,
        'body': body,
        if (clientTag != null && clientTag.isNotEmpty) 'client_tag': clientTag,
      });

  /// P74 Phase 2 — Telegram-grade own-message management (same
  /// actions as the web center; ownership enforced server-side).
  Future<ApiResponse> editMessage(int messageId, String body) =>
      post('/notifications/message-edit',
          body: {'message_id': messageId, 'body': body});

  Future<ApiResponse> deleteMessage(int messageId) =>
      post('/notifications/message-delete', body: {'message_id': messageId});

  /// Explicit mark-read without refetching the conversation (the full
  /// window GET already marks read; this is for flows that change the
  /// thread without a refetch).
  Future<ApiResponse> markThreadRead(int id) =>
      post('/notifications/thread-read', body: {'id': id});
}
