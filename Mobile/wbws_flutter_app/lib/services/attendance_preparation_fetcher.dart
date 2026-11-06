import '../utils/config.dart';
import 'api_service.dart';
import 'attendance_preparation_dataset.dart';
import 'attendance_preparation_envelope.dart';
import 'attendance_preparation_store.dart';
import 'legacy_outbox_models.dart';

/// Thrown when the preparation endpoint cannot be used to produce a
/// durable snapshot. Callers should preserve the previous snapshot and
/// surface a retryable message; no retry should be performed inside
/// this fetcher.
class AttendancePreparationFetchException implements Exception {
  final String code;
  final String message;
  const AttendancePreparationFetchException(this.code, this.message);
  @override
  String toString() => 'AttendancePreparationFetchException($code): $message';
}

/// Inactive fetcher: GET /attendance/preparation → validate → publish.
///
/// No existing app code calls this. The UI layer will call it during
/// pull-to-refresh and before offline session creation, handling the
/// typed exceptions to show the correct banner (401 authority change →
/// re-auth, 503 unavailable → keep old, etc.).
///
/// The fetcher never extends leases, never synthesizes missing fields and
/// never deletes the previous snapshot on failure (fail-closed keep-old
/// is enforced by [AttendancePreparationStore.publishPreparation]).
final class AttendancePreparationFetcher {
  final AttendancePreparationStore _store;
  AttendancePreparationFetcher({AttendancePreparationStore? store}) : _store = store ?? AttendancePreparationStore();

  /// Fetches the preparation JSON via [api] and publishes it for [session].
  ///
  /// [api] is injected so tests can supply a fake ApiService without
  /// network. The production caller passes `ApiService().get`.
  /// Returns the newly published snapshot on success.
  Future<AttendancePreparationSnapshot> fetchAndPublish({
    required LegacyWriteSession session,
    required Future<ApiResponse> Function(String path) get,
    AttendancePreparationScope? expectedScope,
    int maxPayloadBytes = 1024 * 1024,
  }) async {
    if (!session.isValid) {
      throw const AttendancePreparationFetchException(
        'STALE_SESSION',
        'Local session is invalid; cannot refresh preparation.',
      );
    }
    final res = await get('/attendance/preparation');
    if (!res.success) {
      final code = _codeFromBody(res.data) ?? res.errorCode ?? 'ATTENDANCE_PREPARATION_UNAVAILABLE';
      final msg = res.message ?? _messageFromBody(res.data) ?? 'Preparation unavailable (${res.statusCode}). Preserve previous dataset.';
      throw AttendancePreparationFetchException(code, msg);
    }
    final body = res.data;
    if (body is! Map) {
      throw const AttendancePreparationFetchException(
        'ATTENDANCE_PREPARATION_MALFORMED',
        'Preparation response is not an object.',
      );
    }
    final data = (body['data'] is Map) ? body['data'] as Map : body;
    final envelopeMap = <String, dynamic>{};
    for (final e in (data as Map).entries) {
      envelopeMap[e.key.toString()] = e.value;
    }
    try {
      final envelope = AttendancePreparationEnvelope.decode(
        envelopeMap,
        expected: expectedScope ?? AttendancePreparationScope(
          ownerUserId: session.ownerUserId,
          authorizationVersion: session.authorizationVersion,
          role: 'teacher',
          familyId: '00' * 32,
          serverIdentity: AppConfig.apiBaseUrl,
        ),
        maxPayloadBytes: maxPayloadBytes,
      );
      final payloadJson = envelope.payloadUtf8;
      final dataset = AttendancePreparationDataset.decode(payloadJson);
      await _store.publishPreparation(
        expectedSession: session,
        envelope: envelope,
        dataset: dataset,
      );
      final snapshot = await _store.getSnapshot();
      if (snapshot == null) {
        throw const AttendancePreparationFetchException(
          'ATTENDANCE_PREPARATION_PUBLISH_FAILED',
          'Publish succeeded but snapshot missing.',
        );
      }
      return snapshot;
    } on AttendancePreparationFetchException {
      rethrow;
    } on StaleLegacyWriteSession catch (e) {
      throw AttendancePreparationFetchException('STALE_SESSION', e.toString());
    } on FormatException catch (e) {
      throw AttendancePreparationFetchException('ATTENDANCE_PREPARATION_MALFORMED', e.message);
    } catch (e) {
      throw AttendancePreparationFetchException('ATTENDANCE_PREPARATION_MALFORMED', e.toString());
    }
  }

  String? _codeFromBody(dynamic body) {
    if (body is Map) {
      final c = body['code'] ?? (body['data'] is Map ? (body['data'] as Map)['code'] : null);
      if (c is String && c.isNotEmpty) return c;
    }
    return null;
  }

  String? _messageFromBody(dynamic body) {
    if (body is Map) {
      final m = body['message'] ?? body['error'] ?? (body['data'] is Map ? (body['data'] as Map)['message'] : null);
      if (m is String && m.isNotEmpty) return m;
    }
    return null;
  }
}
