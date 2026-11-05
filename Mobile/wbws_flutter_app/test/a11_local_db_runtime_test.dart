import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'package:fkss_app/services/hymn_outbox_models.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';
import 'package:fkss_app/services/sync_attempt_models.dart';
import 'package:fkss_app/services/sync_execution.dart';

/// A.11 executes the production LocalDb against a real SQLite library.
///
/// The fixture is deliberately only the v36 tables needed by this focused
/// runtime proof. It is not a replacement schema or a test LocalDb: the
/// database is opened and migrated by LocalDb itself, and every claim,
/// settlement, session predicate, and attempt-ledger write below is production
/// code. The fixture's sync_attempts table omits execution_source so the real
/// v36 -> v37 onUpgrade branch must add it.
void main() {
  late Directory fixtureDirectory;
  late Database rawDb;
  late LocalDb db;

  final t0 = DateTime.utc(2026, 10, 5, 8, 0, 0);
  final t1 = t0.add(const Duration(minutes: 5));
  const ownerUserId = 101;
  const authorizationVersion = 3;
  const runtimeGeneration = 7;

  setUpAll(() async {
    sqfliteFfiInit();
    fixtureDirectory = await Directory.systemTemp.createTemp('a11-local-db-');
    await databaseFactoryFfi.setDatabasesPath(fixtureDirectory.path);
    // LocalDb imports package:sqflite, so replace that production factory
    // with the FFI implementation before its singleton opens the database.
    sqflite.databaseFactory = databaseFactoryFfi;

    final path = p.join(fixtureDirectory.path, 'wbws_offline_v4.db');
    final v36 = await databaseFactoryFfi.openDatabase(
      path,
      options: OpenDatabaseOptions(
        version: 36,
        onCreate: (database, _) async {
          await database.execute('''
          CREATE TABLE local_session_state (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            owner_user_id INTEGER,
            owner_username TEXT,
            owner_display_name TEXT,
            owner_role TEXT,
            owner_authorization_version INTEGER,
            state TEXT NOT NULL,
            reason TEXT,
            generation INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL
          )
        ''');
          await database.execute('''
          CREATE TABLE pending_attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            class_name TEXT,
            date TEXT NOT NULL,
            member_id INTEGER NOT NULL,
            student_name TEXT,
            father_name TEXT,
            member_code TEXT,
            status TEXT NOT NULL,
            notes TEXT,
            packet_kind TEXT NOT NULL DEFAULT 'draft',
            client_op_id TEXT,
            synced INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            synced_at TEXT,
            sync_error TEXT,
            sync_state TEXT NOT NULL DEFAULT 'pending',
            attempt_count INTEGER NOT NULL DEFAULT 0,
            next_attempt_at TEXT,
            last_attempt_at TEXT,
            failure_code TEXT,
            failure_http_status INTEGER,
            failed_at TEXT,
            created_authorization_version INTEGER,
            owner_user_id INTEGER
          )
        ''');
          await database.execute('''
          CREATE TABLE pending_hymn_ops (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            op TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            client_op_id TEXT,
            created_at TEXT NOT NULL,
            synced INTEGER NOT NULL DEFAULT 0,
            synced_at TEXT,
            sync_error TEXT,
            sync_state TEXT NOT NULL DEFAULT 'pending',
            attempt_count INTEGER NOT NULL DEFAULT 0,
            next_attempt_at TEXT,
            last_attempt_at TEXT,
            failure_code TEXT,
            failure_http_status INTEGER,
            failed_at TEXT,
            created_authorization_version INTEGER,
            created_by_user_id INTEGER,
            entity_key TEXT,
            depends_on INTEGER
          )
        ''');
          // Deliberately v36-shaped: execution_source is added by LocalDb's
          // production onUpgrade callback when it opens version 37.
          await database.execute('''
          CREATE TABLE sync_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_op_id TEXT NOT NULL,
            attempt_number INTEGER NOT NULL,
            attempt_uid TEXT NOT NULL,
            domain TEXT NOT NULL,
            entity_ref TEXT,
            owner_user_id INTEGER,
            created_authorization_version INTEGER,
            started_at TEXT NOT NULL,
            finished_at TEXT,
            duration_ms INTEGER,
            http_status INTEGER,
            error_category TEXT,
            retry_decision TEXT NOT NULL DEFAULT 'PENDING',
            failure_message TEXT,
            next_attempt_at TEXT,
            server_ref TEXT
          )
        ''');
          await database.execute('''
          CREATE UNIQUE INDEX uq_sync_attempt_identity
          ON sync_attempts(client_op_id, attempt_number)
        ''');
          await database.execute('''
          CREATE UNIQUE INDEX uq_sync_attempt_uid
          ON sync_attempts(attempt_uid)
        ''');
        },
      ),
    );
    await v36.close();

    db = LocalDb();
    rawDb = await db.database;
    await db.persistLocalSession(
      state: SessionState.active,
      generation: runtimeGeneration,
      ownerUserId: ownerUserId,
      authorizationVersion: authorizationVersion,
      ownerRole: 'teacher',
      ownerUsername: 'a11-owner',
      ownerDisplayName: 'A.11 Owner',
    );
  });

  tearDownAll(() async {
    // LocalDb intentionally has no public close/reset API. The process owns
    // this isolated fixture; remove the un-opened directory entry if possible.
    await fixtureDirectory.delete(recursive: true).catchError((_) {});
  });

  test('runs production migration, claims, settlement, lineage, and integrity',
      () async {
    final columns = await rawDb.rawQuery('PRAGMA table_info(sync_attempts)');
    final columnNames = columns.map((row) => '${row['name']}').toSet();
    expect(columnNames, contains('execution_source'));

    final userVersion = await rawDb.rawQuery('PRAGMA user_version');
    expect(userVersion.single.values.single, 42);

    final attendanceRef = await db.saveAttendanceLocal(
      42,
      'A.11 Class',
      '2026-10-05',
      [
        {
          'member_id': 7001,
          'student_name': 'Student One',
          'status': 'present',
        },
        {
          'member_id': 7002,
          'student_name': 'Student Two',
          'status': 'late',
        },
      ],
      expectedSession: const LegacyWriteSession(
        ownerUserId: 101,
        authorizationVersion: 3,
        runtimeGeneration: 7,
      ),
    );
    expect(attendanceRef.clientOpId, isNotEmpty);
    expect(attendanceRef.ownerUserId, ownerUserId);
    expect(attendanceRef.createdAuthorizationVersion, authorizationVersion);

    final firstClaim = await db.claimNextLegacyOperation(
      kind: LegacyOperationKind.attendance,
      ownerUserId: ownerUserId,
      authorizationVersion: authorizationVersion,
      runtimeGeneration: runtimeGeneration,
      now: t0,
      executionSource: SyncExecutionSource.foreground,
    );
    expect(firstClaim, isNotNull);
    expect(firstClaim!.records, hasLength(2));
    expect(firstClaim.attemptCount, 1);

    var attendanceRows = await rawDb.query(
      'pending_attendance',
      where: 'client_op_id = ?',
      whereArgs: [attendanceRef.clientOpId],
    );
    expect(attendanceRows, hasLength(2));
    expect(attendanceRows.every((row) => row['sync_state'] == 'in_flight'),
        isTrue);

    var attempts = await rawDb.query(
      'sync_attempts',
      where: 'client_op_id = ?',
      whereArgs: [attendanceRef.clientOpId],
      orderBy: 'attempt_number',
    );
    expect(attempts, hasLength(1));
    expect(attempts.single['execution_source'], 'foreground');
    expect(attempts.single['owner_user_id'], ownerUserId);
    expect(
        attempts.single['created_authorization_version'], authorizationVersion);
    expect(attempts.single['finished_at'], isNull);

    final retryResult = await db.settleLegacyOperation(
      claim: firstClaim,
      settlement: LegacySettlement(
        kind: LegacySettlementKind.retryable,
        failureCode: 'NETWORK_UNAVAILABLE',
        failureMessage: 'A.11 retry evidence',
        nextAttemptAt: t1,
      ),
      currentOwnerUserId: ownerUserId,
      currentAuthorizationVersion: authorizationVersion,
      currentRuntimeGeneration: runtimeGeneration,
      attemptClosure: SyncAttemptClosure(
        category: SyncErrorCategory.networkUnavailable,
        decision: SyncRetryDecision.retryScheduled,
        failureMessage: 'A.11 retry evidence',
        nextAttemptAt: t1,
      ),
      now: t0.add(const Duration(seconds: 1)),
    );
    expect(retryResult, LegacySettlementResult.applied);

    attendanceRows = await rawDb.query(
      'pending_attendance',
      where: 'client_op_id = ?',
      whereArgs: [attendanceRef.clientOpId],
    );
    expect(attendanceRows.every((row) => row['sync_state'] == 'retry_wait'),
        isTrue);
    expect(
        attendanceRows
            .every((row) => row['next_attempt_at'] == t1.toIso8601String()),
        isTrue);

    attempts = await rawDb.query(
      'sync_attempts',
      where: 'client_op_id = ?',
      whereArgs: [attendanceRef.clientOpId],
      orderBy: 'attempt_number',
    );
    expect(attempts.single['retry_decision'], 'RETRY_SCHEDULED');
    expect(attempts.single['error_category'], 'NETWORK_UNAVAILABLE');
    expect(attempts.single['finished_at'], isNotNull);

    final secondClaim = await db.claimNextLegacyOperation(
      kind: LegacyOperationKind.attendance,
      ownerUserId: ownerUserId,
      authorizationVersion: authorizationVersion,
      runtimeGeneration: runtimeGeneration,
      now: t1,
      executionSource: SyncExecutionSource.foreground,
    );
    expect(secondClaim, isNotNull);
    expect(secondClaim!.attemptCount, 2);
    expect(secondClaim.operation.clientOpId, attendanceRef.clientOpId);

    final acceptedResult = await db.settleLegacyOperation(
      claim: secondClaim,
      settlement: const LegacySettlement(kind: LegacySettlementKind.accepted),
      currentOwnerUserId: ownerUserId,
      currentAuthorizationVersion: authorizationVersion,
      currentRuntimeGeneration: runtimeGeneration,
      attemptClosure: const SyncAttemptClosure(
        category: SyncErrorCategory.none,
        decision: SyncRetryDecision.completed,
        httpStatus: 200,
        serverRef: 'a11-accepted',
      ),
      now: t1.add(const Duration(seconds: 1)),
    );
    expect(acceptedResult, LegacySettlementResult.applied);

    attendanceRows = await rawDb.query(
      'pending_attendance',
      where: 'client_op_id = ?',
      whereArgs: [attendanceRef.clientOpId],
    );
    expect(
        attendanceRows.every((row) => row['sync_state'] == 'synced'), isTrue);
    expect(attendanceRows.every((row) => row['synced'] == 1), isTrue);

    attempts = await rawDb.query(
      'sync_attempts',
      where: 'client_op_id = ?',
      whereArgs: [attendanceRef.clientOpId],
      orderBy: 'attempt_number',
    );
    expect(attempts, hasLength(2));
    expect(attempts.map((row) => row['attempt_number']), [1, 2]);
    expect(attempts.map((row) => row['execution_source']),
        ['foreground', 'foreground']);
    expect(attempts.every((row) => row['finished_at'] != null), isTrue);
    expect(attempts.last['retry_decision'], 'COMPLETED');

    // A changed active owner/scope cannot claim the first owner's pending
    // operation. Restore the original session afterward and prove the exact
    // operation can still be claimed by its owner.
    final isolatedRef = await db.saveAttendanceLocal(
      43,
      'Isolation Class',
      '2026-10-06',
      [
        {'member_id': 7003, 'status': 'present'},
      ],
      expectedSession: const LegacyWriteSession(
        ownerUserId: 101,
        authorizationVersion: 3,
        runtimeGeneration: 7,
      ),
    );
    await db.persistLocalSession(
      state: SessionState.active,
      generation: 8,
      ownerUserId: 202,
      authorizationVersion: 4,
      ownerRole: 'teacher',
    );
    final wrongOwnerClaim = await db.claimNextLegacyOperation(
      kind: LegacyOperationKind.attendance,
      ownerUserId: 202,
      authorizationVersion: 4,
      runtimeGeneration: 8,
      now: t0,
    );
    expect(wrongOwnerClaim, isNull);
    var isolatedRows = await rawDb.query(
      'pending_attendance',
      where: 'client_op_id = ?',
      whereArgs: [isolatedRef.clientOpId],
    );
    expect(isolatedRows.single['sync_state'], 'pending');
    expect(isolatedRows.single['attempt_count'], 0);

    await db.persistLocalSession(
      state: SessionState.active,
      generation: runtimeGeneration,
      ownerUserId: ownerUserId,
      authorizationVersion: authorizationVersion,
      ownerRole: 'teacher',
    );
    final restoredClaim = await db.claimNextLegacyOperation(
      kind: LegacyOperationKind.attendance,
      ownerUserId: ownerUserId,
      authorizationVersion: authorizationVersion,
      runtimeGeneration: runtimeGeneration,
      now: t0,
    );
    expect(restoredClaim, isNotNull);
    expect(restoredClaim!.operation.clientOpId, isolatedRef.clientOpId);

    // Hymn work uses the same real ledger and records the explicit background
    // source while retaining the operation creator's owner/scope provenance.
    final hymnRowId = await db.enqueueHymnOp('hymn_save', {
      'id': 501,
      'title': 'A.11 Hymn',
      'categories': <Object>[],
      'zemarians': <Object>[],
    });
    expect(hymnRowId, greaterThan(0));

    final hymnClaim = await db.claimNextHymnOperation(
      runtimeGeneration: runtimeGeneration,
      ownerUserId: ownerUserId,
      authorizationVersion: authorizationVersion,
      now: t1.add(const Duration(minutes: 1)),
      executionSource: SyncExecutionSource.background,
    );
    expect(hymnClaim, isNotNull);
    expect(hymnClaim!.rowId, hymnRowId);
    expect(hymnClaim.attemptCount, 1);

    var hymnAttempts = await rawDb.query(
      'sync_attempts',
      where: 'client_op_id = ?',
      whereArgs: [hymnClaim.clientOpId],
    );
    expect(hymnAttempts, hasLength(1));
    expect(hymnAttempts.single['domain'], 'pending_hymn_ops');
    expect(hymnAttempts.single['execution_source'], 'background');
    expect(hymnAttempts.single['owner_user_id'], ownerUserId);
    expect(hymnAttempts.single['created_authorization_version'],
        authorizationVersion);

    final hymnSettlement = await db.settleHymnOperation(
      claim: hymnClaim,
      settlement: const HymnSettlement(kind: HymnSettlementKind.accepted),
      currentRuntimeGeneration: runtimeGeneration,
      now: t1.add(const Duration(minutes: 1, seconds: 1)),
      attemptClosure: const SyncAttemptClosure(
        category: SyncErrorCategory.idempotencyReplay,
        decision: SyncRetryDecision.completed,
        httpStatus: 200,
        serverRef: 'a11-hymn-replay',
      ),
    );
    expect(hymnSettlement, HymnSettlementResult.applied);

    final hymnRows = await rawDb.query(
      'pending_hymn_ops',
      where: 'id = ?',
      whereArgs: [hymnRowId],
    );
    expect(hymnRows.single['sync_state'], 'synced');
    expect(hymnRows.single['synced'], 1);
    hymnAttempts = await rawDb.query(
      'sync_attempts',
      where: 'client_op_id = ?',
      whereArgs: [hymnClaim.clientOpId],
    );
    expect(hymnAttempts.single['finished_at'], isNotNull);
    expect(hymnAttempts.single['error_category'], 'IDEMPOTENCY_REPLAY');

    // A production settlement is atomic across the outbox row and its ledger
    // close: the two rows above are the observable commit boundary. The
    // stale-session path is also checked as a no-mutation transaction.
    await db.persistLocalSession(
      state: SessionState.active,
      generation: 99,
      ownerUserId: 999,
      authorizationVersion: 99,
      ownerRole: 'teacher',
    );
    final staleSettlement = await db.settleHymnOperation(
      claim: hymnClaim,
      settlement: const HymnSettlement(kind: HymnSettlementKind.accepted),
      currentRuntimeGeneration: runtimeGeneration,
      attemptClosure: const SyncAttemptClosure(
        category: SyncErrorCategory.none,
        decision: SyncRetryDecision.completed,
      ),
      now: t1,
    );
    expect(staleSettlement, HymnSettlementResult.supersededSession);
    final unchangedHymn = await rawDb.query(
      'pending_hymn_ops',
      where: 'id = ?',
      whereArgs: [hymnRowId],
    );
    expect(unchangedHymn.single['synced'], 1);

    final integrity = await rawDb.rawQuery('PRAGMA integrity_check');
    expect(integrity.single.values.single, 'ok');
    final foreignKeys = await rawDb.rawQuery('PRAGMA foreign_key_check');
    expect(foreignKeys, isEmpty);
  });
}
