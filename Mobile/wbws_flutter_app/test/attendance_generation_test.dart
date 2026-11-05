import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/session_models.dart';
import 'package:fkss_app/services/sync_attempt_models.dart';
import 'package:fkss_app/services/sync_recovery_models.dart';

void main() {
  const session = LegacyWriteSession(
      ownerUserId: 101, authorizationVersion: 3, runtimeGeneration: 7);
  late Directory directory;
  late Database raw;
  late LocalDb db;
  late LegacyOperationRef original;
  late DateTime now;

  Future<void> activate(LegacyWriteSession s) => db.persistLocalSession(
      state: SessionState.active,
      generation: s.runtimeGeneration,
      ownerUserId: s.ownerUserId,
      authorizationVersion: s.authorizationVersion,
      ownerRole: 'teacher');
  Future<LegacyOperationRef> save(
          {String status = 'present',
          int classId = 3,
          String date = '2026-11-04',
          String packetKind = 'draft',
          LegacyWriteSession capture = session,
          List<int> members = const [30, 31]}) =>
      db.saveAttendanceLocal(
          classId,
          'Synthetic class',
          date,
          [
            for (final member in members)
              {
                'member_id': member,
                'student_name': 'Synthetic $member',
                'status': status,
                'notes': 'Synthetic note $status'
              }
          ],
          expectedSession: capture,
          packetKind: packetKind);
  Future<LegacyClaimSnapshot?> claim(
          {DateTime? at, LegacyWriteSession capture = session}) =>
      db.claimNextLegacyOperation(
          kind: LegacyOperationKind.attendance,
          ownerUserId: capture.ownerUserId,
          authorizationVersion: capture.authorizationVersion,
          runtimeGeneration: capture.runtimeGeneration,
          now: at ?? now);
  Future<List<Map<String, Object?>>> rows(String op) =>
      raw.query('pending_attendance',
          where: 'client_op_id = ?', whereArgs: [op], orderBy: 'id');
  Future<List<Map<String, Object?>>> ledger() =>
      raw.query('sync_attempts', orderBy: 'id');
  Future<LegacySettlementResult> settle(
          LegacyClaimSnapshot c, LegacySettlementKind kind,
          {DateTime? retryAt}) =>
      db.settleLegacyOperation(
          claim: c,
          settlement: LegacySettlement(kind: kind, nextAttemptAt: retryAt),
          currentOwnerUserId: 101,
          currentAuthorizationVersion: 3,
          currentRuntimeGeneration: 7,
          attemptClosure: SyncAttemptClosure(
              category: SyncErrorCategory.none,
              decision: kind == LegacySettlementKind.accepted
                  ? SyncRetryDecision.completed
                  : SyncRetryDecision.retryScheduled,
              nextAttemptAt: retryAt),
          now: now);
  List<Map<String, Object?>> payload(LegacyClaimSnapshot c) => [
        for (final r in c.records)
          {
            for (final k in [
              'class_id',
              'date',
              'member_id',
              'status',
              'notes',
              'student_name',
              'client_op_id',
              'owner_user_id',
              'created_authorization_version'
            ])
              k: r[k]
          }
      ];

  Future<LegacyOperationRef> acceptedAfterConflict() async {
    final a = (await claim())!;
    await settle(a, LegacySettlementKind.resolvedConflict);
    final b = await save();
    await settle((await claim())!, LegacySettlementKind.accepted);
    return b;
  }

  Future<void> ageAccepted() => raw
      .update('pending_attendance', {'synced_at': '2000-01-01T00:00:00.000Z'},
          where: 'synced = 1')
      .then((_) {});

  setUpAll(() async {
    sqfliteFfiInit();
    directory =
        await Directory.systemTemp.createTemp('attendance-generations-');
    await databaseFactoryFfi.setDatabasesPath(directory.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    db = LocalDb();
    raw = await db.database;
  });
  setUp(() async {
    await raw.delete('pending_attendance');
    await raw.delete('sync_attempts');
    await activate(session);
    original = await save(status: 'absent');
    now = DateTime.now().toUtc().add(const Duration(minutes: 1));
  });
  tearDownAll(() async {
    await raw.close();
    await directory.delete(recursive: true);
  });

  test(
      'new save keeps every claimed row and attempt and durably adds a new generation',
      () async {
    await claim();
    final old = await rows(original.clientOpId);
    final attempts = await ledger();
    final newer = await save();
    expect(await rows(original.clientOpId), old);
    expect(await ledger(), attempts);
    expect(await rows(newer.clientOpId), hasLength(2));
    expect(newer.clientOpId, isNot(original.clientOpId));
    expect(await db.getPendingAttendanceCount(), 2);
  });
  test(
      'overlay returns only the latest complete generation, not old removed members',
      () async {
    await claim();
    final newer = await save(members: [30]);
    final visible = await db.getPendingAttendanceRecords(3, '2026-11-04');
    expect(visible, hasLength(1));
    expect(visible.single['client_op_id'], newer.clientOpId);
    expect(visible.single['status'], 'present');
  });
  test('summary describes latest generation without counting older retry rows',
      () async {
    final a = (await claim())!;
    await settle(a, LegacySettlementKind.retryable,
        retryAt: now.add(const Duration(hours: 1)));
    final newer = await save(members: [30]);
    final summary = (await db.getPendingAttendance()).single;
    expect(summary['student_count'], 1);
    expect(summary['client_op_id'], newer.clientOpId);
  });
  test('another worker cannot overtake an in-flight generation', () async {
    await claim();
    await save();
    expect(await claim(), isNull);
  });
  test('backoff blocks the same key but allows another class to progress',
      () async {
    final a = (await claim())!;
    await settle(a, LegacySettlementKind.retryable,
        retryAt: now.add(const Duration(hours: 1)));
    await save();
    final other = await save(classId: 4);
    expect((await claim())!.operation.clientOpId, other.clientOpId);
  });
  test('same class different date can progress while earlier date is in flight',
      () async {
    await claim();
    await save();
    final other = await save(date: '2026-11-05');
    expect((await claim())!.operation.clientOpId, other.clientOpId);
  });
  test(
      'old acknowledgement and attempt closure cannot modify the new generation',
      () async {
    final a = (await claim())!;
    final newer = await save();
    final before = await rows(newer.clientOpId);
    expect(await settle(a, LegacySettlementKind.accepted),
        LegacySettlementResult.applied);
    expect(await rows(newer.clientOpId), before);
    expect((await ledger()).single['finished_at'], isNotNull);
    final b = (await claim())!;
    expect(b.operation.clientOpId, newer.clientOpId);
    final afterClaim = await rows(newer.clientOpId);
    final attempts = await ledger();
    expect(await settle(a, LegacySettlementKind.accepted),
        LegacySettlementResult.supersededLocal);
    expect(await rows(newer.clientOpId), afterClaim);
    expect(await ledger(), attempts);
  });
  test('retry reuses old id and payload before sending the newer intent',
      () async {
    final a = (await claim())!;
    final newer = await save();
    final before = await rows(newer.clientOpId);
    final due = now.add(const Duration(minutes: 5));
    expect(await settle(a, LegacySettlementKind.retryable, retryAt: due),
        LegacySettlementResult.applied);
    expect(await claim(), isNull);
    final retry = (await claim(at: due))!;
    expect(retry.operation.clientOpId, original.clientOpId);
    expect(payload(retry), payload(a));
    expect(retry.attemptCount, 2);
    expect(await rows(newer.clientOpId), before);
  });
  test('pristine drafts still coalesce without growing multiple operations',
      () async {
    final newer = await save();
    expect(await rows(original.clientOpId), isEmpty);
    expect(await db.getPendingAttendanceCount(), 1);
    expect((await claim())!.operation.clientOpId, newer.clientOpId);
  });
  test(
      'only the unattempted successor coalesces while attempted history stays intact',
      () async {
    await claim();
    final before = await rows(original.clientOpId);
    final b = await save();
    final c = await save(status: 'late', members: [30]);
    expect(await rows(original.clientOpId), before);
    expect(await rows(b.clientOpId), isEmpty);
    expect(await rows(c.clientOpId), hasLength(1));
    expect(await db.getPendingAttendanceCount(), 2);
  });
  test(
      'attempt ledger protects a generation even if mutable attempt metadata was reset',
      () async {
    await claim();
    await raw.update('pending_attendance',
        {'sync_state': 'pending', 'attempt_count': 0, 'last_attempt_at': null});
    final before = await rows(original.clientOpId);
    final attempts = await ledger();
    await save();
    expect(await rows(original.clientOpId), before);
    expect(await ledger(), attempts);
  });
  for (final state in [
    'needs_attention',
    'paused_auth',
    'paused_scope',
    'failed',
    'blocked_dependency',
    'unknown_state'
  ]) {
    test('$state generation is retained and blocks newer same-key sending',
        () async {
      await claim();
      await raw.update('pending_attendance', {'sync_state': state});
      final before = await rows(original.clientOpId);
      await save();
      expect(await rows(original.clientOpId), before);
      expect(await claim(), isNull);
    });
  }
  test('older unresolved authorization version blocks newer same-owner intent',
      () async {
    await claim();
    const newerScope = LegacyWriteSession(
        ownerUserId: 101, authorizationVersion: 4, runtimeGeneration: 8);
    await activate(newerScope);
    await save(capture: newerScope);
    expect(await claim(capture: newerScope), isNull);
    expect(await rows(original.clientOpId), hasLength(2));
  });
  test('row insertion order, not device clock order, controls same-key claims',
      () async {
    final a = (await claim())!;
    final b = await save();
    await raw.update('pending_attendance', {'created_at': '2099-01-01'},
        where: 'client_op_id = ?', whereArgs: [a.operation.clientOpId]);
    await raw.update('pending_attendance', {'created_at': '2000-01-01'},
        where: 'client_op_id = ?', whereArgs: [b.clientOpId]);
    expect(await claim(), isNull);
    expect(await settle(a, LegacySettlementKind.accepted),
        LegacySettlementResult.applied);
    expect((await claim())!.operation.clientOpId, b.clientOpId);
  });
  test(
      'undo of newest unattempted submit leaves an older attempted draft unchanged',
      () async {
    await claim();
    final before = await rows(original.clientOpId);
    final submit = await save(packetKind: 'submitted');
    expect(await db.undoSubmittedLegacyOperation(submit),
        SubmitUndoResult.applied);
    expect(await rows(original.clientOpId), before);
    final visible = await db.getPendingAttendanceRecords(3, '2026-11-04');
    expect(visible.every((r) => r['packet_kind'] == 'draft'), true);
    expect(visible.every((r) => r['client_op_id'] != submit.clientOpId), true);
  });
  test('an attempted submission cannot be undone after it enters retry wait',
      () async {
    final submit = await save(packetKind: 'submitted');
    final a = (await claim())!;
    await settle(a, LegacySettlementKind.retryable,
        retryAt: now.add(const Duration(hours: 1)));
    final before = await rows(submit.clientOpId);
    final attempts = await ledger();
    expect(await db.undoSubmittedLegacyOperation(submit),
        SubmitUndoResult.alreadyClaimed);
    expect(await rows(submit.clientOpId), before);
    expect(await ledger(), attempts);
  });
  test(
      'terminal conflict history never reappears as pending overlay after newer acknowledgement',
      () async {
    final a = (await claim())!;
    final b = await save();
    expect(await settle(a, LegacySettlementKind.resolvedConflict),
        LegacySettlementResult.applied);
    final next = (await claim())!;
    expect(next.operation.clientOpId, b.clientOpId);
    expect(await settle(next, LegacySettlementKind.accepted),
        LegacySettlementResult.applied);
    expect(await db.getPendingAttendanceRecords(3, '2026-11-04'), isEmpty);
    expect(await db.getPendingAttendance(), isEmpty);
    expect((await rows(original.clientOpId)).first['sync_state'],
        'resolved_conflict');
  });
  test(
      'failed replacement rolls back coalescence and preserves older work and newer draft',
      () async {
    await claim();
    await save();
    final before = await raw.query('pending_attendance', orderBy: 'id');
    final attempts = await ledger();
    await raw.execute(
        "CREATE TEMP TRIGGER refuse_new_generation BEFORE INSERT ON pending_attendance WHEN NEW.status = 'excused' BEGIN SELECT RAISE(ABORT, 'synthetic refusal'); END");
    try {
      await expectLater(save(status: 'excused'), throwsA(anything));
    } finally {
      await raw.execute('DROP TRIGGER refuse_new_generation');
    }
    expect(await raw.query('pending_attendance', orderBy: 'id'), before);
    expect(await ledger(), attempts);
  });
  test(
      'committed generations and attempt evidence are visible through a fresh SQLite handle',
      () async {
    await claim();
    final newer = await save();
    final connection = await databaseFactoryFfi.openDatabase(raw.path,
        options: OpenDatabaseOptions(singleInstance: false));
    try {
      final ids = (await connection
              .query('pending_attendance', columns: ['client_op_id']))
          .map((r) => r['client_op_id'])
          .toSet();
      expect(ids, {original.clientOpId, newer.clientOpId});
      expect(await connection.query('sync_attempts'), hasLength(1));
    } finally {
      await connection.close();
    }
  });
  test(
      'stale sheet capture still cannot append a generation under a later login',
      () async {
    await claim();
    final before = await raw.query('pending_attendance', orderBy: 'id');
    await activate(const LegacyWriteSession(
        ownerUserId: 202, authorizationVersion: 4, runtimeGeneration: 8));
    await expectLater(save(), throwsA(isA<StaleLegacyWriteSession>()));
    expect(await raw.query('pending_attendance', orderBy: 'id'), before);
  });
  test('partial undo cannot rewrite only part of an unattempted successor',
      () async {
    await claim();
    final b = await save(packetKind: 'submitted');
    final before = await raw.query('pending_attendance', orderBy: 'id');
    final attempts = await ledger();
    await raw.execute(
        "CREATE TEMP TRIGGER partial_undo BEFORE UPDATE OF packet_kind ON pending_attendance WHEN OLD.member_id = 31 AND NEW.packet_kind = 'draft' BEGIN SELECT RAISE(IGNORE); END");
    try {
      await expectLater(
          db.undoSubmittedLegacyOperation(b), throwsA(isA<StateError>()));
    } finally {
      await raw.execute('DROP TRIGGER partial_undo');
    }
    expect(await raw.query('pending_attendance', orderBy: 'id'), before);
    expect(await ledger(), attempts);
  });
  test('attempt evidence prevents undo even when row metadata was reset',
      () async {
    final b = await save(packetKind: 'submitted');
    await claim();
    await raw.update('pending_attendance',
        {'sync_state': 'pending', 'attempt_count': 0, 'last_attempt_at': null});
    final before = await rows(b.clientOpId);
    expect(await db.undoSubmittedLegacyOperation(b),
        SubmitUndoResult.alreadyClaimed);
    expect(await rows(b.clientOpId), before);
  });
  test('unknown older operation id is retained and blocks an unsafe overtake',
      () async {
    await claim();
    await raw.update('pending_attendance', {'client_op_id': null});
    await save();
    expect(await claim(), isNull);
    expect(await raw.query('pending_attendance', where: 'client_op_id IS NULL'),
        hasLength(2));
  });
  test(
      'concurrent claim and save preserve whichever snapshot was actually claimed',
      () async {
    final results = await Future.wait<Object?>([claim(), save()]);
    final claimed = results[0]! as LegacyClaimSnapshot;
    final saved = results[1]! as LegacyOperationRef;
    final claimedRows = await rows(claimed.operation.clientOpId);
    expect(claimedRows, hasLength(claimed.records.length));
    for (var i = 0; i < claimedRows.length; i++) {
      expect(claimedRows[i]['status'], claimed.records[i]['status']);
      expect(claimedRows[i]['notes'], claimed.records[i]['notes']);
      expect(claimedRows[i]['id'], claimed.records[i]['id']);
    }
    expect(await rows(saved.clientOpId), hasLength(2));
    expect(await ledger(), hasLength(1));
  });
  test('attempt evidence in one legacy row protects the whole generation',
      () async {
    await claim();
    await raw.delete('sync_attempts'); // simulate pre-ledger synthetic history
    await raw.update('pending_attendance',
        {'sync_state': 'pending', 'attempt_count': 0, 'last_attempt_at': null});
    await raw.update('pending_attendance', {'attempt_count': 1},
        where: 'member_id = ?', whereArgs: [30]);
    final before = await rows(original.clientOpId);
    await save();
    expect(await rows(original.clientOpId), before);
  });
  test(
      'unknown pristine operation identity is retained, not silently coalesced',
      () async {
    await raw.update('pending_attendance', {'client_op_id': null});
    final before = await raw.query('pending_attendance', orderBy: 'id');
    await save();
    expect(
        await raw.query('pending_attendance',
            where: 'client_op_id IS NULL', orderBy: 'id'),
        before);
    expect(await claim(), isNull);
  });
  test('attendance recovery retry keeps attempt numbering and old payload',
      () async {
    final first = (await claim())!;
    await settle(first, LegacySettlementKind.needsAttention);
    final b = await save();
    final before = await rows(original.clientOpId);
    expect(
        await db.retryRejectedOperation(
            'attendance', original.clientOpId, 'needs_attention'),
        SyncRecoveryActionResult.applied);
    final reset = await rows(original.clientOpId);
    expect(reset.first['attempt_count'], before.first['attempt_count']);
    expect(reset.first['last_attempt_at'], before.first['last_attempt_at']);
    final retry = (await claim())!;
    expect(retry.attemptCount, 2);
    expect(payload(retry), payload(first));
    expect((await ledger()).map((r) => r['attempt_number']), [1, 2]);
    await settle(retry, LegacySettlementKind.accepted);
    expect((await claim())!.operation.clientOpId, b.clientOpId);
  });
  test(
      'seven-day cleanup keeps accepted checkpoint above older conflict history',
      () async {
    final b = await acceptedAfterConflict();
    final old = await rows(original.clientOpId);
    await ageAccepted();
    await db.cleanupSynced();
    expect(await rows(b.clientOpId), hasLength(2));
    expect(await rows(original.clientOpId), old);
    expect(await db.getPendingAttendanceRecords(3, '2026-11-04'), isEmpty);
  });
  test(
      'cleanup keeps only newest accepted checkpoint and releases it when no longer needed',
      () async {
    final b = await acceptedAfterConflict();
    final c = await save();
    await settle((await claim())!, LegacySettlementKind.accepted);
    await ageAccepted();
    await db.cleanupSynced();
    expect(await rows(b.clientOpId), isEmpty);
    expect(await rows(c.clientOpId), hasLength(2));
    // Synthetic fixture transition, not a production resolution/discard path.
    await raw.update('pending_attendance',
        {'synced': 1, 'synced_at': '2000-01-01T00:00:00.000Z'},
        where: 'client_op_id = ?', whereArgs: [original.clientOpId]);
    await db.cleanupSynced();
    expect(await raw.query('pending_attendance'), isEmpty);
  });
  test(
      'cleanup retains accepted checkpoint even when newer unsynced edit exists',
      () async {
    final b = await acceptedAfterConflict();
    final c = await save();
    final newer = await rows(c.clientOpId);
    await ageAccepted();
    await db.cleanupSynced();
    expect(await rows(b.clientOpId), hasLength(2));
    expect(await rows(c.clientOpId), newer);
    expect(
        (await db.getPendingAttendanceRecords(3, '2026-11-04'))
            .first['client_op_id'],
        c.clientOpId);
  });
  test('blocked newer work is not due or a timer while older work is in flight',
      () async {
    await claim();
    final b = await save();
    expect(await db.hasDueLegacyOutbox(now: now), false);
    final inventory = await db.getOutboxInventory(now: now);
    expect(inventory.retryableDue, 0);
    expect(inventory.blockedDependency, 1);
    expect(inventory.inFlight, 1);
    await raw.update(
        'pending_attendance',
        {
          'next_attempt_at':
              now.add(const Duration(minutes: 1)).toIso8601String()
        },
        where: 'client_op_id = ?',
        whereArgs: [b.clientOpId]);
    expect(
        await db.nextOutboxAttemptAt(ownerUserId: 101, authorizationVersion: 3),
        isNull);
  });
  test(
      'retry timer and due inventory follow older backoff, not blocked newer edit',
      () async {
    final a = (await claim())!;
    final later = now.add(const Duration(hours: 1));
    await settle(a, LegacySettlementKind.retryable, retryAt: later);
    final b = await save();
    await raw.update(
        'pending_attendance',
        {
          'next_attempt_at':
              now.add(const Duration(minutes: 1)).toIso8601String()
        },
        where: 'client_op_id = ?',
        whereArgs: [b.clientOpId]);
    final checkAt = now.add(const Duration(minutes: 2));
    expect(await db.hasDueLegacyOutbox(now: checkAt), false);
    expect(
        await db.nextOutboxAttemptAt(ownerUserId: 101, authorizationVersion: 3),
        later);
    final inventory = await db.getOutboxInventory(now: checkAt);
    expect(inventory.retryableDue, 0);
    expect(inventory.retryableWaiting, 1);
    expect(inventory.blockedDependency, 1);
  });
  test(
      'older authorization generation blocks current due work without a rescan loop',
      () async {
    await claim();
    const next = LegacyWriteSession(
        ownerUserId: 101, authorizationVersion: 4, runtimeGeneration: 8);
    await activate(next);
    await save(capture: next);
    expect(await db.hasDueLegacyOutbox(now: now), false);
    final inventory = await db.getOutboxInventory(now: now);
    expect(inventory.retryableDue, 0);
    expect(inventory.blockedDependency, 1);
    expect(inventory.pausedScope, 1);
  });
}
