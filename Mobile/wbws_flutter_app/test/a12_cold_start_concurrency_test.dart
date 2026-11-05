import 'dart:async';
import 'dart:io';
import 'dart:isolate';

import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';
import 'package:fkss_app/services/sync_attempt_models.dart';
import 'package:fkss_app/services/sync_execution.dart';

/// A.12 Level-2 concurrency evidence: two production LocalDb instances in
/// separate Dart isolates use the same real SQLite file. This models the
/// foreground isolate plus the temporary cold-start isolate without pretending
/// that an Android device was present.
Future<Map<Object?, Object?>> _spawnWorker(
  Map<String, Object?> payload,
) async {
  final replies = ReceivePort();
  final worker = await Isolate.spawn<Map<String, Object?>>(
    _workerMain,
    <String, Object?>{...payload, 'reply': replies.sendPort},
  );
  final result = await replies.first.timeout(const Duration(seconds: 30));
  replies.close();
  worker.kill(priority: Isolate.immediate);
  return Map<Object?, Object?>.from(result as Map);
}

Future<void> _workerMain(Map<String, Object?> message) async {
  final reply = message['reply'] as SendPort;
  final directory = '${message['directory']}';
  final mode = '${message['mode']}';
  try {
    sqfliteFfiInit();
    await databaseFactoryFfi.setDatabasesPath(directory);
    sqflite.databaseFactory = databaseFactoryFfi;
    final db = LocalDb();

    if (mode == 'hold') {
      await db.persistLocalSession(
        state: SessionState.active,
        generation: 41,
        ownerUserId: 701,
        authorizationVersion: 8,
        ownerRole: 'teacher',
      );
      final operation = await db.saveAttendanceLocal(
        77,
        'A.12 concurrency class',
        '2026-10-05',
        [
          {'member_id': 8801, 'status': 'present'},
          {'member_id': 8802, 'status': 'late'},
        ],
        expectedSession: const LegacyWriteSession(
          ownerUserId: 701,
          authorizationVersion: 8,
          runtimeGeneration: 41,
        ),
      );
      final claim = await db.claimNextLegacyOperation(
        kind: LegacyOperationKind.attendance,
        ownerUserId: 701,
        authorizationVersion: 8,
        runtimeGeneration: 41,
        executionSource: SyncExecutionSource.foreground,
      );
      if (claim == null) {
        reply.send(
            <String, Object?>{'ok': false, 'error': 'holder did not claim'});
        return;
      }
      final commands = ReceivePort();
      reply.send(<String, Object?>{
        'ok': true,
        'phase': 'claimed',
        'clientOpId': operation.clientOpId,
        'attempt': claim.attemptCount,
        'source': claim.attemptUid,
        'control': commands.sendPort,
      });
      final command = await commands.first.timeout(const Duration(seconds: 30));
      commands.close();
      if (command == 'settle') {
        final stale = await db.settleLegacyOperation(
          claim: claim,
          settlement: const LegacySettlement(
            kind: LegacySettlementKind.accepted,
          ),
          currentOwnerUserId: 701,
          currentAuthorizationVersion: 8,
          currentRuntimeGeneration: 41,
          attemptClosure: const SyncAttemptClosure(
            category: SyncErrorCategory.none,
            decision: SyncRetryDecision.completed,
            httpStatus: 200,
          ),
          now: DateTime.now().toUtc(),
        );
        reply.send(<String, Object?>{
          'ok': true,
          'phase': 'stale_settlement',
          'result': stale.name,
        });
      }
      return;
    }

    final claim = await db.claimNextLegacyOperation(
      kind: LegacyOperationKind.attendance,
      ownerUserId: 701,
      authorizationVersion: 8,
      runtimeGeneration: 41,
      executionSource: SyncExecutionSource.background,
    );
    if (claim == null) {
      final raw = await db.database;
      final rows = await raw.query('pending_attendance');
      final attempts = await raw.query('sync_attempts');
      reply.send(<String, Object?>{
        'ok': false,
        'error': 'competitor did not claim',
        'rows': rows,
        'attempts': attempts,
      });
      return;
    }
    final settled = await db.settleLegacyOperation(
      claim: claim,
      settlement: const LegacySettlement(kind: LegacySettlementKind.accepted),
      currentOwnerUserId: 701,
      currentAuthorizationVersion: 8,
      currentRuntimeGeneration: 41,
      attemptClosure: const SyncAttemptClosure(
        category: SyncErrorCategory.none,
        decision: SyncRetryDecision.completed,
        httpStatus: 200,
      ),
      now: DateTime.now().toUtc(),
    );
    await (await db.database).close();
    reply.send(<String, Object?>{
      'ok': true,
      'phase': 'competitor_settlement',
      'attempt': claim.attemptCount,
      'result': settled.name,
      'source': SyncExecutionSource.background.storageValue,
    });
  } catch (error, stack) {
    reply.send(<String, Object?>{
      'ok': false,
      'error': '$error\n$stack',
    });
  }
}

Future<Map<Object?, Object?>> _inspectDatabase(String directory) async {
  final replies = ReceivePort();
  final worker = await Isolate.spawn<Map<String, Object?>>(
    _inspectWorker,
    <String, Object?>{'directory': directory, 'reply': replies.sendPort},
  );
  final result = await replies.first.timeout(const Duration(seconds: 30));
  replies.close();
  worker.kill(priority: Isolate.immediate);
  return Map<Object?, Object?>.from(result as Map);
}

Future<void> _inspectWorker(Map<String, Object?> message) async {
  final reply = message['reply'] as SendPort;
  try {
    sqfliteFfiInit();
    await databaseFactoryFfi.setDatabasesPath('${message['directory']}');
    sqflite.databaseFactory = databaseFactoryFfi;
    final db = LocalDb();
    final raw = await db.database;
    final rows = await raw.query(
      'pending_attendance',
      columns: ['sync_state', 'synced', 'attempt_count'],
    );
    final attempts = await raw.query(
      'sync_attempts',
      columns: [
        'attempt_number',
        'attempt_uid',
        'client_op_id',
        'execution_source',
        'owner_user_id',
        'created_authorization_version',
        'entity_ref',
        'retry_decision',
      ],
      orderBy: 'attempt_number',
    );
    final integrity = await raw.rawQuery('PRAGMA integrity_check');
    reply.send(<String, Object?>{
      'ok': true,
      'rows': rows,
      'attempts': attempts,
      'integrity': integrity.single.values.single,
    });
  } catch (error, stack) {
    reply.send(<String, Object?>{'ok': false, 'error': '$error\n$stack'});
  }
}

void main() {
  test('production LocalDb safely arbitrates foreground and cold-start claims',
      () async {
    final directory = await Directory.systemTemp.createTemp('a12-concurrency-');
    try {
      final holderReplies = ReceivePort();
      final holderMessages = StreamIterator<dynamic>(holderReplies);
      final holderIsolate = await Isolate.spawn<Map<String, Object?>>(
        _workerMain,
        <String, Object?>{
          'directory': p.normalize(directory.path),
          'mode': 'hold',
          'reply': holderReplies.sendPort,
        },
      );
      expect(
        await holderMessages.moveNext().timeout(const Duration(seconds: 30)),
        isTrue,
      );
      final holder = Map<Object?, Object?>.from(holderMessages.current as Map);
      expect(holder['ok'], true, reason: '${holder['error']}');
      expect(holder['phase'], 'claimed');
      expect(holder['attempt'], 1);

      // The holder remains alive with its real SQLite connection open and its
      // operation in flight. The competitor opens a second production
      // LocalDb connection now, deterministically exercising onOpen recovery,
      // claim arbitration, attempt lineage and settlement.
      final competitor = await _spawnWorker(<String, Object?>{
        'directory': p.normalize(directory.path),
        'mode': 'competitor',
      });
      expect(
        competitor['ok'],
        true,
        reason:
            '${competitor['error']} rows=${competitor['rows']} attempts=${competitor['attempts']}',
      );
      expect(competitor['phase'], 'competitor_settlement');
      expect(competitor['result'], 'applied');
      expect(competitor['source'], 'background');
      expect(competitor['attempt'], 2);

      final control = holder['control'] as SendPort;
      control.send('settle');
      expect(
        await holderMessages.moveNext().timeout(const Duration(seconds: 30)),
        isTrue,
      );
      final staleResult =
          Map<Object?, Object?>.from(holderMessages.current as Map);
      expect(staleResult['ok'], true, reason: '${staleResult['error']}');
      expect(staleResult['result'], 'supersededLocal');
      await holderMessages.cancel();
      holderReplies.close();
      holderIsolate.kill(priority: Isolate.immediate);

      // The inspector is a third production LocalDb instance. It proves the
      // durable result after both isolate-level owners have completed.
      final inspected = await _inspectDatabase(p.normalize(directory.path));
      final rows = (inspected['rows'] as List).cast<Map>();
      expect(rows, hasLength(2));
      expect(rows.every((row) => row['sync_state'] == 'synced'), isTrue);
      expect(rows.every((row) => row['synced'] == 1), isTrue);
      expect(rows.every((row) => row['attempt_count'] == 2), isTrue);

      final attempts = (inspected['attempts'] as List).cast<Map>();
      expect(attempts, hasLength(2));
      expect(attempts[0]['attempt_number'], 1);
      expect(attempts[0]['attempt_uid'], isNotEmpty);
      expect(attempts[0]['execution_source'], 'foreground');
      expect(attempts[0]['owner_user_id'], 701);
      expect(attempts[0]['created_authorization_version'], 8);
      expect(attempts[0]['retry_decision'], 'INTERRUPTED');
      expect(attempts[1]['attempt_number'], 2);
      expect(attempts[1]['attempt_uid'], isNotEmpty);
      expect(attempts[1]['attempt_uid'], isNot(attempts[0]['attempt_uid']));
      expect(attempts[1]['client_op_id'], attempts[0]['client_op_id']);
      expect(attempts[1]['entity_ref'], attempts[0]['entity_ref']);
      expect(attempts[1]['execution_source'], 'background');
      expect(attempts[1]['owner_user_id'], 701);
      expect(attempts[1]['created_authorization_version'], 8);
      expect(attempts[1]['retry_decision'], 'COMPLETED');
      expect(inspected['integrity'], 'ok');
    } finally {
      await directory.delete(recursive: true).catchError((_) {});
    }
  });
}
