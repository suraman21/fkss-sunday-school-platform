import 'api_service.dart';
import 'grades_preparation_dataset.dart';
import 'grades_preparation_envelope.dart';
import 'grades_preparation_store.dart';
import 'legacy_outbox_models.dart';

class GradesPreparationFetchException implements Exception {
  final String code; final String message; const GradesPreparationFetchException(this.code, this.message);
  @override String toString() => 'GradesPreparationFetchException($code): $message';
}

final class GradesPreparationFetcher {
  final GradesPreparationStore _store; GradesPreparationFetcher({GradesPreparationStore? store}) : _store = store ?? GradesPreparationStore();
  Future<GradesPreparationSnapshot> fetchAndPublish({required LegacyWriteSession session, required Future<ApiResponse> Function(String path) get, GradesPreparationScope? expectedScope, int maxPayloadBytes = 1024*1024}) async {
    if (!session.isValid) throw const GradesPreparationFetchException('STALE_SESSION','Local session is invalid; cannot refresh preparation.');
    final res = await get('/grades/preparation');
    if (!res.success) {
      final code = _code(res.data) ?? res.errorCode ?? 'GRADES_PREPARATION_UNAVAILABLE';
      final msg = res.message ?? _msg(res.data) ?? 'Preparation unavailable (${res.statusCode}). Preserve previous dataset.';
      throw GradesPreparationFetchException(code, msg);
    }
    final body = res.data; if (body is! Map) throw const GradesPreparationFetchException('GRADES_PREPARATION_MALFORMED','Preparation response is not an object.');
    final data = (body['data'] is Map) ? body['data'] as Map : body;
    final envelopeMap = <String,dynamic>{}; for (final e in (data as Map).entries) envelopeMap[e.key.toString()]=e.value;
    try {
      final envelope = GradesPreparationEnvelope.decode(envelopeMap, expected: expectedScope ?? GradesPreparationScope(ownerUserId: session.ownerUserId, authorizationVersion: session.authorizationVersion, role: 'teacher', familyId: '00'*32, serverIdentity: 'https://school.example/api/v1'), maxPayloadBytes: maxPayloadBytes);
      final dataset = GradesPreparationDataset.decode(envelope.payloadUtf8);
      await _store.publishPreparation(expectedSession: session, envelope: envelope, dataset: dataset);
      final snap = await _store.getSnapshot(); if (snap == null) throw const GradesPreparationFetchException('GRADES_PREPARATION_PUBLISH_FAILED','Publish succeeded but snapshot missing.');
      return snap;
    } on GradesPreparationFetchException { rethrow; } on StaleLegacyWriteSession catch (e) { throw GradesPreparationFetchException('STALE_SESSION', e.toString()); } on FormatException catch (e) { throw GradesPreparationFetchException('GRADES_PREPARATION_MALFORMED', e.message); } catch (e) { throw GradesPreparationFetchException('GRADES_PREPARATION_MALFORMED', e.toString()); }
  }
  String? _code(dynamic b) { if (b is Map) { final c=b['code'] ?? (b['data'] is Map ? (b['data'] as Map)['code']:null); if (c is String && c.isNotEmpty) return c; } return null; }
  String? _msg(dynamic b) { if (b is Map) { final m=b['message'] ?? b['error'] ?? (b['data'] is Map ? (b['data'] as Map)['message']:null); if (m is String && m.isNotEmpty) return m; } return null; }
}
