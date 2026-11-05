import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';

void main() {
  const session = LegacyWriteSession(ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 5);
  late Directory dir; late sqflite.Database raw; late LocalDb db;
  setUpAll(() async { sqfliteFfiInit(); dir = await Directory.systemTemp.createTemp('grades-receipt-atomic-'); await databaseFactoryFfi.setDatabasesPath(dir.path); sqflite.databaseFactory = databaseFactoryFfi; db = LocalDb(); raw = await db.database; });
  setUp(() async { await raw.delete('pending_grades'); await raw.delete('grades_sync_receipt'); await db.persistLocalSession(state: SessionState.active, generation: 5, ownerUserId: 7, authorizationVersion: 3, ownerRole: 'teacher'); });
  tearDownAll(() async { await raw.close(); await dir.delete(recursive: true); });

  test('accepted grades inserts receipt atomically', () async {
    await db.saveGradesLocal(1001, 'Quiz 1', 101, 'Class A', 10, 'Math', 20, [{'member_id':1,'student_name':'A','member_code':'M1','score':15,'remark':''}]);
    final claim = await db.claimNextLegacyOperation(kind: LegacyOperationKind.grades, ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 5);
    expect(claim, isNotNull);
    await db.settleLegacyOperation(claim: claim!, settlement: LegacySettlement(kind: LegacySettlementKind.accepted, receiptJson: '{"saved":1}', serverRequestId: 'req-g1', httpStatus: 201), currentOwnerUserId: 7, currentAuthorizationVersion: 3, currentRuntimeGeneration: 5);
    final rows = await raw.query('grades_sync_receipt', where: 'client_op_id = ?', whereArgs: [claim.operation.clientOpId]);
    expect(rows, hasLength(1)); expect(rows.first['receipt_json'], '{"saved":1}'); expect(rows.first['assessment_id'], 1001);
  });

  test('retryable grades creates no receipt', () async {
    await db.saveGradesLocal(1002, 'Quiz 2', 101, 'Class A', 10, 'Math', 20, [{'member_id':1,'student_name':'A','member_code':'M1','score':10,'remark':''}]);
    final claim = await db.claimNextLegacyOperation(kind: LegacyOperationKind.grades, ownerUserId: 7, authorizationVersion: 3, runtimeGeneration: 5);
    expect(claim, isNotNull);
    await db.settleLegacyOperation(claim: claim!, settlement: LegacySettlement(kind: LegacySettlementKind.retryable, failureCode: 'TIMEOUT'), currentOwnerUserId: 7, currentAuthorizationVersion: 3, currentRuntimeGeneration: 5);
    final rows = await raw.query('grades_sync_receipt');
    expect(rows, isEmpty);
  });
}
