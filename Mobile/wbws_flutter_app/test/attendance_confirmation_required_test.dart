import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';
import 'package:fkss_app/services/sync_recovery_models.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';

void main() {
  late Directory directory;
  late Database raw;
  late LocalDb db;
  late String original;
  const session = LegacyWriteSession(
      ownerUserId: 101, authorizationVersion: 3, runtimeGeneration: 7);
  const states = [
    'needs_attention',
    'blocked_dependency',
    'failed',
    'resolved_conflict',
    'pending',
    'retry_wait',
    'in_flight',
    'paused_auth',
    'paused_scope',
    'unknown'
  ];
  Future<List<Map<String, Object?>>> attendance() =>
      raw.query('pending_attendance', orderBy: 'id');
  Future<List<Map<String, Object?>>> attempts() =>
      raw.query('sync_attempts', orderBy: 'id');
  Future<LegacyOperationRef> save(String status) => db.saveAttendanceLocal(
      3,
      'Synthetic class',
      '2026-11-04',
      [
        for (final id in [30, 31])
          {'member_id': id, 'status': status, 'notes': 'Synthetic note'}
      ],
      expectedSession: session);
  setUpAll(() async {
    sqfliteFfiInit();
    directory =
        await Directory.systemTemp.createTemp('attendance-confirmation-');
    await databaseFactoryFfi.setDatabasesPath(directory.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    db = LocalDb();
    raw = await db.database;
  });
  setUp(() async {
    await raw.delete('pending_attendance');
    await raw.delete('sync_attempts');
    await db.persistLocalSession(
        state: SessionState.active,
        generation: 7,
        ownerUserId: 101,
        authorizationVersion: 3,
        ownerRole: 'teacher');
    original = (await save('absent')).clientOpId;
    await db.claimNextLegacyOperation(
        kind: LegacyOperationKind.attendance,
        ownerUserId: 101,
        authorizationVersion: 3,
        runtimeGeneration: 7);
    await save('present');
  });
  tearDownAll(() async {
    await raw.close();
    await directory.delete(recursive: true);
  });

  for (final state in states) {
    for (final exactState in [true, false]) {
      test(
          'manual recovery preserves $state attendance, expectedState=$exactState',
          () async {
        await raw.update('pending_attendance', {'sync_state': state},
            where: 'client_op_id = ?', whereArgs: [original]);
        final before = await attendance();
        final ledger = await attempts();
        final result = await db.discardRejectedOperation('attendance', original,
            expectedState: exactState ? state : null);
        expect(await attendance(), before);
        expect(await attempts(), ledger);
        expect(result.name, 'serverConfirmationRequired');
      });
    }
    test('deprecated lock drop cannot remove $state attendance', () async {
      await raw.update('pending_attendance', {'sync_state': state},
          where: 'client_op_id = ?', whereArgs: [original]);
      final before = await attendance();
      final ledger = await attempts();
      // ignore: deprecated_member_use_from_same_package
      await expectLater(
          db.dropPendingAttendance(3, '2026-11-04'), throwsStateError);
      expect(await attendance(), before);
      expect(await attempts(), ledger);
    });
    test('attendance model offers no destructive action for $state', () {
      final item = SyncRecoveryItem(
          domain: SyncRecoveryDomain.attendance,
          operationId: 'synthetic',
          state: state,
          title: 'Synthetic',
          detail: 'Synthetic');
      expect(item.canDiscard, false);
      expect(item.canAcknowledge, false);
    });
  }
  test('a stale owner session cannot turn manual discard into cleanup',
      () async {
    final before = await attendance();
    final ledger = await attempts();
    await db.persistLocalSession(
        state: SessionState.reauthRequired,
        generation: 8,
        ownerUserId: 101,
        authorizationVersion: 3,
        ownerRole: 'teacher');
    final result = await db.discardRejectedOperation('attendance', original);
    expect(result.name, 'serverConfirmationRequired');
    expect(await attendance(), before);
    expect(await attempts(), ledger);
  });
  test('other recovery domains retain their existing action contract', () {
    for (final domain in SyncRecoveryDomain.values
        .where((d) => d != SyncRecoveryDomain.attendance)) {
      final conflict = SyncRecoveryItem(
          domain: domain,
          operationId: 'synthetic',
          state: 'resolved_conflict',
          title: 'Synthetic',
          detail: 'Synthetic');
      expect(conflict.canAcknowledge, true);
      expect(conflict.canDiscard, true);
    }
  });
  test('grade recovery storage keeps its existing exact-delete behavior',
      () async {
    await raw.delete('pending_grades');
    final grade = await db.saveGradesLocal(5, 'Synthetic assessment', 3,
        'Synthetic class', 2, 'Synthetic subject', 10, [
      {'member_id': 30, 'score': 4}
    ]);
    await raw.update('pending_grades', {'sync_state': 'needs_attention'},
        where: 'client_op_id = ?', whereArgs: [grade.clientOpId]);
    final before = await attendance();
    final ledger = await attempts();
    expect(
        await db.discardRejectedOperation('grades', grade.clientOpId,
            expectedState: 'needs_attention'),
        SyncRecoveryActionResult.applied);
    expect(await raw.query('pending_grades'), isEmpty);
    expect(await attendance(), before);
    expect(await attempts(), ledger);
  });
}
