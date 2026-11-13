import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';

void main() {
  late Directory dir;
  late sqflite.Database raw;
  late LocalDb db;
  setUpAll(() async {
    sqfliteFfiInit();
    dir = await Directory.systemTemp.createTemp('mezmur-sections-');
    await databaseFactoryFfi.setDatabasesPath(dir.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    db = LocalDb();
    raw = await db.database;
    await db.persistLocalSession(
        state: SessionState.active,
        generation: 1,
        ownerUserId: 7,
        authorizationVersion: 2,
        ownerRole: 'mezmur_attendance_taker');
  });
  tearDownAll(() async {
    await raw.close();
    await dir.delete(recursive: true);
  });
  test('two same-day section submissions coexist in the durable outbox',
      () async {
    const date = '2026-10-25';
    final a = await db.saveMezmurLocal(
        date,
        'A',
        [
          {'member_id': 1, 'status': 'present', 'notes': 'section A'}
        ],
        packetKind: 'submitted',
        notBefore: DateTime.now().add(const Duration(minutes: 1)));
    final b = await db.saveMezmurLocal(
        date,
        'B',
        [
          {'member_id': 2, 'status': 'absent', 'notes': 'section B'}
        ],
        packetKind: 'submitted',
        notBefore: DateTime.now().add(const Duration(minutes: 1)));
    expect(a.clientOpId, isNot(b.clientOpId));
    final aRows = await db.getPendingMezmurRecords(date, 'A');
    final bRows = await db.getPendingMezmurRecords(date, 'B');
    expect(aRows.single['status'], 'present');
    expect(aRows.single['packet_kind'], 'submitted');
    expect(bRows.single['status'], 'absent');
    expect(bRows.single['packet_kind'], 'submitted');
    expect(await db.undoSubmittedLegacyOperation(a), SubmitUndoResult.applied);
    final afterB = await db.getPendingMezmurRecords(date, 'B');
    expect(afterB.single['client_op_id'], b.clientOpId);
    expect(afterB.single['packet_kind'], 'submitted');
  });
}
