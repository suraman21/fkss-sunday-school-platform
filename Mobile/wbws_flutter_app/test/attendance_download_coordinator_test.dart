import 'dart:async';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/attendance_sync_coordinator.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/session_models.dart';

ApiResponse page({bool more = false}) => ApiResponse(success: true, data: {
      'items': [
        {'op': 'UPDATE', 'entity_id': 99}
      ],
      'records': <String, dynamic>{},
      'next_cursor': 11,
      'has_more': more,
    });

class DownloadApi implements ApiService {
  int owner = 101, version = 3, generation = 7, requests = 0;
  bool loggedIn = true;
  Completer<ApiResponse>? delayed;
  ApiResponse response = page();
  @override
  int get userId => owner;
  @override
  int get authorizationVersion => version;
  @override
  bool get isLoggedIn => loggedIn;
  @override
  int Function()? get sessionGenerationProvider => () => generation;
  @override
  Future<ApiResponse> getAttendanceChanges(
      {int cursor = 0, int limit = 200}) async {
    requests++;
    if (delayed == null) return response;
    return await delayed!.future;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class DownloadDb implements LocalDb {
  int owner = 101, version = 3, generation = 7, cursor = 10;
  bool active = true, failApply = false, failStatus = false;
  Completer<void>? applyWait, statusWait;
  final applyEntered = Completer<void>();
  final statusEntered = Completer<void>();
  void Function()? onReset;
  final events = <String>[];
  final captures = <LegacyWriteSession>[];
  bool matches(LegacyWriteSession s) =>
      active &&
      s.isValid &&
      s.ownerUserId == owner &&
      s.authorizationVersion == version &&
      s.runtimeGeneration == generation;
  void fence(LegacyWriteSession s) {
    captures.add(s);
    if (!matches(s)) throw const StaleAttendanceSyncSession();
  }

  @override
  Future<bool> activeSessionMatches(
          {required int runtimeGeneration,
          int? ownerUserId,
          int? authorizationVersion,
          DatabaseExecutor? executor}) async =>
      active &&
      runtimeGeneration == generation &&
      ownerUserId == owner &&
      authorizationVersion == version;
  @override
  Future<int> getSyncCursor(String domain) async => cursor;
  @override
  Future<void> applyAttendanceDelta(
      {required LegacyWriteSession expectedSession,
      required List<Map<String, dynamic>> items,
      required Map<String, dynamic> records,
      required int nextCursor,
      String domain = 'attendance'}) async {
    if (!applyEntered.isCompleted) applyEntered.complete();
    if (applyWait != null) await applyWait!.future;
    fence(expectedSession);
    if (failApply) throw StateError('Synthetic storage failure');
    cursor = nextCursor;
    events.add('apply');
  }

  @override
  Future<void> setSyncStatus(String domain, String status,
      {String? error, LegacyWriteSession? expectedSession}) async {
    if (!statusEntered.isCompleted) statusEntered.complete();
    if (statusWait != null) await statusWait!.future;
    fence(expectedSession!);
    if (failStatus) throw StateError('Synthetic status storage failure');
    events.add('status:$status');
  }

  @override
  Future<void> resetAttendanceSyncForBootstrap(
      {required LegacyWriteSession expectedSession}) async {
    fence(expectedSession);
    cursor = 0;
    events.add('reset');
    onReset?.call();
  }

  @override
  Future<void> completeAttendanceBootstrap(int cursor,
      {required LegacyWriteSession expectedSession}) async {
    fence(expectedSession);
    this.cursor = cursor;
    events.add('complete');
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  late DownloadApi api;
  late DownloadDb db;
  late AttendanceSyncCoordinator coordinator;
  setUp(() {
    api = DownloadApi();
    db = DownloadDb();
    coordinator = AttendanceSyncCoordinator.withCollaborators(api: api, db: db);
  });

  void change(String field) {
    if (field == 'owner') {
      api.owner = 202;
      db.owner = 202;
    }
    if (field == 'version') {
      api.version++;
      db.version++;
    }
    if (field == 'generation') {
      api.generation++;
      db.generation++;
    }
  }

  test('matching page propagates original context and advances once', () async {
    final result = await coordinator.pull();
    expect(result.failed, false);
    expect(result.applied, 1);
    expect(db.events, ['apply']);
    expect(db.cursor, 11);
    final s = db.captures.single;
    expect([s.ownerUserId, s.authorizationVersion, s.runtimeGeneration],
        [101, 3, 7]);
    expect(coordinator.isRunning, false);
  });
  for (final field in ['owner', 'version', 'generation']) {
    test(
        'response delayed across $field change cannot mutate or write failure status',
        () async {
      api.delayed = Completer<ApiResponse>();
      final pending = coordinator.pull();
      await pumpEventQueue();
      expect(api.requests, 1);
      change(field);
      api.delayed!.complete(page());
      final result = await pending;
      expect(result.failed, true);
      expect(db.events, isEmpty);
      expect(db.cursor, 10);
      expect(coordinator.isRunning, false);
    });
  }
  test(
      'queued apply rejects changed durable owner without writing failure status',
      () async {
    db.applyWait = Completer<void>();
    final pending = coordinator.pull();
    await db.applyEntered.future;
    change('owner');
    db.applyWait!.complete();
    expect((await pending).failed, true);
    expect(db.events, isEmpty);
    expect(db.captures.single.ownerUserId, 101);
    expect(coordinator.isRunning, false);
  });
  test('queued error status rejects newer account and never throws', () async {
    api.response = ApiResponse.error('Synthetic offline');
    db.statusWait = Completer<void>();
    final pending = coordinator.pull();
    await db.statusEntered.future;
    change('generation');
    db.statusWait!.complete();
    expect((await pending).failed, true);
    expect(db.events, isEmpty);
    expect(coordinator.isRunning, false);
  });
  test('session change after legitimate reset blocks bootstrap completion',
      () async {
    api.response = ApiResponse(
        success: true,
        data: {'bootstrap_required': true, 'bootstrap_cursor': 41});
    db.onReset = () => change('owner');
    final result = await coordinator.pull();
    expect(result.failed, true);
    expect(db.events, ['reset']);
    expect(db.cursor, 0);
    expect(db.captures.every((s) => s.ownerUserId == 101), true);
  });
  test('transport supersession flag cannot become an error/status write',
      () async {
    api.response = ApiResponse(success: false, sessionSuperseded: true);
    expect((await coordinator.pull()).failed, true);
    expect(db.events, isEmpty);
  });
  test(
      'apply and status storage failures never escape and running gate releases',
      () async {
    db.failApply = true;
    db.failStatus = true;
    expect((await coordinator.pull()).failed, true);
    expect(coordinator.isRunning, false);
    expect(db.events, isEmpty);
    db.failApply = false;
    db.failStatus = false;
    expect((await coordinator.pull()).failed, false);
    expect(db.events, ['apply']);
  });
  test('inactive durable session cannot issue a request', () async {
    db.active = false;
    expect((await coordinator.pull()).failed, true);
    expect(api.requests, 0);
    expect(db.events, isEmpty);
  });
  test('overlap skips and a new session may pull after obsolete run exits',
      () async {
    api.delayed = Completer<ApiResponse>();
    final pending = coordinator.pull();
    await pumpEventQueue();
    expect(coordinator.isRunning, true);
    expect((await coordinator.pull()).message, 'Skipped');
    change('generation');
    api.delayed!.complete(page());
    await pending;
    api.delayed = null;
    expect((await coordinator.pull()).failed, false);
    expect(db.captures.single.runtimeGeneration, 8);
  });
  test('matching bootstrap uses the original capture for reset and completion',
      () async {
    api.response = ApiResponse(
        success: true,
        data: {'bootstrap_required': true, 'bootstrap_cursor': 41});
    expect((await coordinator.pull()).bootstrapped, true);
    expect(db.events, ['reset', 'complete']);
    expect(db.cursor, 41);
    expect(identical(db.captures[0], db.captures[1]), true);
  });
  test('empty page writes scoped success without advancing cursor', () async {
    api.response =
        ApiResponse(success: true, data: {'items': [], 'records': []});
    expect((await coordinator.pull()).failed, false);
    expect(db.events, ['status:ok']);
    expect(db.cursor, 10);
    expect(db.captures.single.ownerUserId, 101);
  });
  test('paging checks session before issuing another request', () async {
    api.response = page(more: true);
    db.applyWait = Completer<void>();
    final pending = coordinator.pull();
    await db.applyEntered.future;
    change('version');
    db.applyWait!.complete();
    expect((await pending).failed, true);
    expect(api.requests, 1);
    expect(db.events, isEmpty);
  });
}
