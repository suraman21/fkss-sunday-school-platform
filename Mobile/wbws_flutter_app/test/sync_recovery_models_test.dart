import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/sync_recovery_models.dart';

SyncRecoveryItem item(String state,
        {SyncRecoveryDomain domain = SyncRecoveryDomain.attendance}) =>
    SyncRecoveryItem(
      domain: domain,
      operationId: 'operation-1',
      state: state,
      title: 'Attendance',
      detail: '2026-09-24',
    );

void main() {
  test('recovery sections are mutually classified', () {
    expect(item('pending').isWaiting, isTrue);
    expect(item('in_flight').isWaiting, isTrue);
    expect(item('retry_wait').isRetryScheduled, isTrue);
    expect(item('paused_auth').isPaused, isTrue);
    expect(item('paused_scope').isPaused, isTrue);
    expect(item('resolved_conflict').isConflict, isTrue);
    expect(item('needs_attention').needsAttention, isTrue);
    expect(item('blocked_dependency').needsAttention, isTrue);
  });

  test('only retryable terminal states offer retry', () {
    expect(item('needs_attention').canRetry, isTrue);
    expect(item('failed').canRetry, isTrue);
    expect(item('blocked_dependency').canRetry, isFalse);
    expect(item('resolved_conflict').canRetry, isFalse);
  });

  test(
      'other-domain conflicts acknowledge while terminal attention can discard',
      () {
    expect(
        item('resolved_conflict', domain: SyncRecoveryDomain.grades)
            .canAcknowledge,
        isTrue);
    expect(
        item('resolved_conflict', domain: SyncRecoveryDomain.grades).canDiscard,
        isTrue);
    expect(
        item('needs_attention', domain: SyncRecoveryDomain.grades).canDiscard,
        isTrue);
    expect(item('pending').canDiscard, isFalse);
  });
}
