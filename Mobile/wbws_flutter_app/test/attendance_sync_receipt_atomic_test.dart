import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';

void main() {
  late Directory dir;
  late sqflite.Database raw;
  late LocalDb db;

  setUpAll(() async {
    sqfliteFfiInit();
    dir = await Directory.systemTemp.createTemp('receipt-atomic-');
    await databaseFactoryFfi.setDatabasesPath(dir.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    db = LocalDb();
    raw = await db.database;
  });

  setUp(() async {
    await raw.delete('pending_attendance');
    await raw.delete('attendance_sync_receipt');
    await db.persistLocalSession(state: SessionState.active, generation: 5, ownerUserId: 7, authorizationVersion: 3, ownerRole: 'teacher');
  });

  tearDownAll(() async {
    await raw.close();
    await dir.delete(recursive: true);
  });

  test('accepted attendance settlement inserts receipt atomically', () async {
    const session = LegacyWriteSession(ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 5);
    await db.saveAttendanceLocal(101, 'Class A', '2026-03-01', [
      {'member_id': 1, 'student_name': 'A', 'father_name': 'B', 'member_code': 'M1', 'status': 'present', 'notes': ''},
    ], expectedSession: session, packetKind: 'draft');

    final claim = await db.claimNextLegacyOperation(
      kind: LegacyOperationKind.attendance,
      ownerUserId: 7,
      authorizationVersion: 3,
      runtimeGeneration: 5,
    );
    expect(claim, isNotNull);
    final op = claim!.operation;

    final settlement = LegacySettlement(
      kind: LegacySettlementKind.accepted,
      receiptJson: '{"saved":1,"submission_id":99}',
      serverRequestId: 'req-abc-123',
      httpStatus: 201,
    );

    final result = await db.settleLegacyOperation(
      claim: claim,
      settlement: settlement,
      currentOwnerUserId: 7,
      currentAuthorizationVersion: 3,
      currentRuntimeGeneration: 5,
    );
    expect(result, LegacySettlementResult.applied);

    final pending = await raw.query('pending_attendance', where: 'client_op_id = ?', whereArgs: [op.clientOpId]);
    expect(pending.first['synced'], 1);
    expect(pending.first['sync_state'], 'synced');

    final receipts = await raw.query('attendance_sync_receipt', where: 'client_op_id = ?', whereArgs: [op.clientOpId]);
    expect(receipts.length, 1);
    expect(receipts.first['receipt_json'], '{"saved":1,"submission_id":99}');
    expect(receipts.first['server_request_id'], 'req-abc-123');
    expect(receipts.first['owner_user_id'], 7);
    expect(receipts.first['generation'], 5);
    expect(receipts.first['class_id'], 101);
    expect(receipts.first['date'], '2026-03-01');
  });

  test('retryable does not create receipt', () async {
    const session = LegacyWriteSession(ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 5);
    await db.saveAttendanceLocal(102, 'Class B', '2026-03-02', [
      {'member_id': 2, 'student_name': 'C', 'father_name': 'D', 'member_code': 'M2', 'status': 'absent', 'notes': ''},
    ], expectedSession: session, packetKind: 'draft');
    final claim = await db.claimNextLegacyOperation(
      kind: LegacyOperationKind.attendance,
      ownerUserId: 7,
      authorizationVersion: 3,
      runtimeGeneration: 5,
    );
    expect(claim, isNotNull);
    final settlement = LegacySettlement(
      kind: LegacySettlementKind.retryable,
      failureCode: 'NETWORK_ERROR',
      failureHttpStatus: 0,
      failureMessage: 'offline',
      nextAttemptAt: DateTime.now().toUtc().add(const Duration(seconds: 10)),
    );
    final result = await db.settleLegacyOperation(
      claim: claim!,
      settlement: settlement,
      currentOwnerUserId: 7,
      currentAuthorizationVersion: 3,
      currentRuntimeGeneration: 5,
    );
    expect(result, LegacySettlementResult.applied);
    final receipts = await raw.query('attendance_sync_receipt', where: 'client_op_id = ?', whereArgs: [claim.operation.clientOpId]);
    expect(receipts, isEmpty);
  });
}
