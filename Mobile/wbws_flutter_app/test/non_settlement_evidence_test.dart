import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/outbox_policy.dart';
import 'package:fkss_app/services/session_models.dart';
import 'package:fkss_app/services/sync_attempt_models.dart';

void main() {
  final observations = <String, Map<String, dynamic>>{
    'cache unknown': {'authority': 'legacy_replay_cache', 'state': 'unknown'},
    'cache completed 201': {
      'authority': 'legacy_replay_cache',
      'state': 'completed',
      'cached_http_status': 201,
    },
    'cache completed 500': {
      'authority': 'legacy_replay_cache',
      'state': 'completed',
      'cached_http_status': 500,
    },
    'negative capability': {'durable_receipt_versions': [], 'durable': false},
    'malformed capability': {'durable_receipt_versions': '1'},
    'future capability': {
      'durable_receipt_versions': [1]
    },
    for (final value in [false, true, null, 'true', 0, 1])
      'settlement marker ${value.runtimeType}:$value': {
        'authorizes_settlement': value
      },
  };

  for (final entry in observations.entries) {
    for (final location in ['root', 'data', 'lookup', 'capabilities']) {
      test('${entry.key} at $location never settles a successful replay', () {
        final json = <String, dynamic>{'status': 'success'};
        if (location == 'root') {
          json.addAll(entry.value);
          json['data'] = {'saved': 4}; // root evidence must survive unwrapping
        } else {
          json[location] = entry.value;
        }
        final parsed = ApiResponse.fromJson(json, 200,
            headers: {'idempotency-replayed': 'true'});
        for (final response in [
          parsed,
          parsed.withGeneration(7),
          parsed
              .withGeneration(7)
              .withRefreshOutcome(AuthRefreshOutcome.sameScope)
        ]) {
          expect(response.success, isTrue,
              reason: 'HTTP observation itself succeeded');
          for (final attempts in [0, 1, 9]) {
            final evidence =
                response.toOutboxEvidence(automaticAttemptCount: attempts);
            expect(classifyOutboxResponse(evidence),
                OutboxDecision.needsAttention);
            expect(classifySyncErrorCategory(evidence),
                SyncErrorCategory.protocolError);
          }
        }
      });
    }
  }

  test('direct normalized ApiResponse data cannot bypass the guard', () {
    final response =
        ApiResponse(success: true, data: {'authorizes_settlement': false});
    expect(
        classifyOutboxResponse(
            response.toOutboxEvidence(automaticAttemptCount: 1)),
        OutboxDecision.needsAttention);
  });

  for (final status in [200, 201, 409, 412, 503]) {
    test('unsupported receipt code $status holds immediately without metadata',
        () {
      final response = ApiResponse.fromJson({
        'status': status < 300 ? 'success' : 'error',
        'code': 'ATTENDANCE_RECEIPT_CONTRACT_UNSUPPORTED',
      }, status);
      final evidence = response.toOutboxEvidence(automaticAttemptCount: 1);
      expect(classifyOutboxResponse(evidence), OutboxDecision.needsAttention);
      expect(
          classifySyncErrorCategory(evidence), SyncErrorCategory.protocolError);
    });
  }

  test(
      'negative evidence cannot resolve a canonical conflict or superseded edit',
      () {
    const evidence = OutboxResponseEvidence(
        success: false,
        statusCode: 409,
        errorCode: 'REVISION_CONFLICT',
        hasCanonicalConflictItem: true,
        failureKind: ApiFailureKind.nonSettlement);
    expect(classifyOutboxResponse(evidence), OutboxDecision.needsAttention);
    const superseded = OutboxResponseEvidence(
        success: true,
        statusCode: 200,
        supersededLocal: true,
        failureKind: ApiFailureKind.nonSettlement);
    expect(classifyOutboxResponse(superseded), OutboxDecision.supersededLocal);
  });

  test('ordinary legacy 2xx, including unrelated nested records, is unchanged',
      () {
    for (final data in [
      {'saved': 4},
      {'durable': false},
      {
        'records': [
          {'authorizes_settlement': false}
        ]
      },
      {
        'member': {'authority': 'legacy_replay_cache'}
      },
    ]) {
      final response = ApiResponse.fromJson(
          {'status': 'success', 'data': data}, 201,
          headers: {'idempotency-replayed': 'true'});
      final evidence = response.toOutboxEvidence(automaticAttemptCount: 1);
      expect(classifyOutboxResponse(evidence), OutboxDecision.accepted);
      expect(classifySyncErrorCategory(evidence),
          SyncErrorCategory.idempotencyReplay);
    }
  });

  test('session, authentication and scope outcomes retain precedence', () {
    final response = ApiResponse.fromJson({
      'status': 'success',
      'data': {'authorizes_settlement': false}
    }, 200);
    for (final entry in {
      AuthRefreshOutcome.transientFailure: OutboxDecision.needsAttention,
      AuthRefreshOutcome.superseded: OutboxDecision.supersededSession,
      AuthRefreshOutcome.rejected: OutboxDecision.pauseForAuthentication,
      AuthRefreshOutcome.scopeChanged:
          OutboxDecision.pauseForAuthorizationScope,
    }.entries) {
      final evidence = response
          .withRefreshOutcome(entry.key)
          .toOutboxEvidence(automaticAttemptCount: 1);
      expect(classifyOutboxResponse(evidence), entry.value);
    }
    for (final code in ['ACCOUNT_DISABLED', 'AUTH_SCOPE_CHANGED']) {
      final evidence = ApiResponse.fromJson({
        'status': 'error',
        'code': code,
        'lookup': {'authorizes_settlement': false}
      }, 401)
          .toOutboxEvidence(automaticAttemptCount: 1);
      expect(
          classifyOutboxResponse(evidence),
          code == 'ACCOUNT_DISABLED'
              ? OutboxDecision.pauseForAuthentication
              : OutboxDecision.pauseForAuthorizationScope);
      expect(
          classifySyncErrorCategory(evidence),
          code == 'ACCOUNT_DISABLED'
              ? SyncErrorCategory.authExpired
              : SyncErrorCategory.authScopeChanged);
    }
  });
}
