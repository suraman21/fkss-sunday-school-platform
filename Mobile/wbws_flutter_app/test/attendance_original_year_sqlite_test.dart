import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/session_models.dart';

void main() {
  const session = LegacyWriteSession(
      ownerUserId: 101, authorizationVersion: 3, runtimeGeneration: 7);
  late Directory directory;
  late Database raw;
  late LocalDb db;
  Future<LegacyOperationRef> save(int? year,
          {String packet = 'draft', LegacyWriteSession capture = session}) =>
      db.saveAttendanceLocal(
          3,
          'Synthetic class',
          '2026-11-05',
          [
            for (final id in [30, 31])
              {
                'member_id': id,
                'status': 'present',
                'notes': 'Synthetic note $id'
              }
          ],
          expectedSession: capture,
          originalAcademicYearId: year,
          packetKind: packet);
  Future<LegacyClaimSnapshot?> claim() => db.claimNextLegacyOperation(
      kind: LegacyOperationKind.attendance,
      ownerUserId: 101,
      authorizationVersion: 3,
      runtimeGeneration: 7,
      now: DateTime.now().toUtc().add(const Duration(minutes: 1)));
  Future<List<Map<String, Object?>>> rows(String op) =>
      raw.query('pending_attendance',
          where: 'client_op_id = ?', whereArgs: [op], orderBy: 'id');
  setUpAll(() async {
    sqfliteFfiInit();
    directory = await Directory.systemTemp.createTemp('attendance-year-local-');
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
  });
  tearDownAll(() async {
    await raw.close();
    await directory.delete(recursive: true);
  });
  test(
      'explicit original year is committed with every mark and immutable claim',
      () async {
    final op = await save(5);
    expect(op.originalAcademicYearId, 5);
    final c = (await claim())!;
    expect(c.operation.originalAcademicYearId, 5);
    expect(
        c.records.map((r) => r['original_academic_year_id']), everyElement(5));
    final reader = await databaseFactoryFfi.openDatabase(raw.path,
        options: OpenDatabaseOptions(readOnly: true, singleInstance: false));
    try {
      expect(
          (await reader.query('pending_attendance'))
              .map((r) => r['original_academic_year_id']),
          everyElement(5));
    } finally {
      await reader.close();
    }
    await save(6);
    expect(c.operation.originalAcademicYearId, 5);
    expect(
        (await rows(op.clientOpId)).map((r) => r['original_academic_year_id']),
        everyElement(5));
    expect(c.records.first['notes'], 'Synthetic note 30');
  });
  test('legacy caller remains unknown through save and claim', () async {
    final op = await save(null);
    expect(op.originalAcademicYearId, isNull);
    final c = (await claim())!;
    expect(c.operation.originalAcademicYearId, isNull);
    expect(c.records.map((r) => r['original_academic_year_id']),
        everyElement(isNull));
  });
  for (final years in [
    [null, 5],
    [5, null],
    [5, 6]
  ]) {
    test('different/unknown year cannot delete an unattempted original: $years',
        () async {
      final op = await save(years[0]);
      final before = await rows(op.clientOpId);
      final newer = await save(years[1]);
      expect(await rows(op.clientOpId), before);
      expect(await rows(newer.clientOpId), hasLength(2));
    });
  }
  for (final year in [null, 5]) {
    test('same provenance still coalesces only never-attempted drafts: $year',
        () async {
      final op = await save(year);
      final newer = await save(year);
      expect(await rows(op.clientOpId), isEmpty);
      expect(await rows(newer.clientOpId), hasLength(2));
    });
  }
  test(
      'mixed sibling years are retained whole, claim exposes unknown not a guess',
      () async {
    final op = await save(5);
    await raw.update('pending_attendance', {'original_academic_year_id': 6},
        where: 'member_id = 31');
    final before = await rows(op.clientOpId);
    await save(5);
    expect(await rows(op.clientOpId), before);
    final c = (await claim())!;
    expect(c.operation.clientOpId, op.clientOpId);
    expect(c.operation.originalAcademicYearId, isNull);
  });
  test(
      'failed member insert rolls replacement and all provenance back atomically',
      () async {
    final op = await save(5);
    final before = await rows(op.clientOpId);
    await raw.execute(
        "CREATE TRIGGER synthetic_year_abort BEFORE INSERT ON pending_attendance WHEN NEW.member_id = 31 BEGIN SELECT RAISE(ABORT,'synthetic insert failure'); END");
    try {
      await expectLater(save(5), throwsA(isA<DatabaseException>()));
    } finally {
      await raw.execute('DROP TRIGGER synthetic_year_abort');
    }
    expect(await rows(op.clientOpId), before);
    expect(await raw.query('pending_attendance'), before);
  });
  for (final year in [0, -1, 9007199254740992]) {
    test('invalid explicit year $year cannot mutate existing marks', () async {
      final op = await save(5);
      final before = await rows(op.clientOpId);
      await expectLater(save(year), throwsArgumentError);
      expect(await rows(op.clientOpId), before);
    });
  }
  test('stale session cannot relabel original year or replace saved work',
      () async {
    final op = await save(5);
    final before = await rows(op.clientOpId);
    await expectLater(
        save(6,
            capture: const LegacyWriteSession(
                ownerUserId: 102,
                authorizationVersion: 3,
                runtimeGeneration: 8)),
        throwsA(isA<StaleLegacyWriteSession>()));
    expect(await rows(op.clientOpId), before);
  });
  test(
      'preclaim undo retains original year while generating fresh draft identity',
      () async {
    final op = await save(5, packet: 'submitted');
    expect(await db.undoSubmittedLegacyOperation(op), SubmitUndoResult.applied);
    final c = (await claim())!;
    expect(c.operation.clientOpId, isNot(op.clientOpId));
    expect(c.operation.packetKind, LegacyPacketKind.draft);
    expect(c.operation.originalAcademicYearId, 5);
  });
  test(
      'orphan retry preserves original year, identity and recorded original snapshot',
      () async {
    final op = await save(5);
    final first = (await claim())!;
    await db.recoverOrphanedInFlightOperations();
    final retry = (await claim())!;
    expect(retry.operation.clientOpId, op.clientOpId);
    expect(retry.operation.originalAcademicYearId, 5);
    expect(retry.attemptCount, 2);
    expect(first.operation.originalAcademicYearId, 5);
    expect(first.attemptCount, 1);
  });
}
