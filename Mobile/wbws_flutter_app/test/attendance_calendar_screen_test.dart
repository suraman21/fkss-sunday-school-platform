import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:fkss_app/screens/attendance/attendance_screen.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';
import 'package:fkss_app/services/sync_service.dart';
import 'package:fkss_app/services/attendance_calendar_cache.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/utils/attendance_calendar.dart';
import 'package:fkss_app/utils/config.dart';
import 'package:fkss_app/utils/theme.dart';
import 'package:fkss_app/widgets/action_bar.dart';

class FakeApi implements ApiService {
  String code = 'd';
  Map<String, dynamic>? onlineStatus;
  String packet = '';
  bool networkFailure = false;
  bool authFailure = false;
  bool unavailable = false;
  int generation = 1;
  bool notModified = false;
  String? sentEtag;
  final requests = <String, Completer<ApiResponse>>{};
  bool delayed = false;
  final date =
      AttendanceCalendar.ymd(AttendanceCalendar.addisToday(DateTime.now()));
  @override
  int get userId => 7;
  @override
  int get authorizationVersion => 2;
  @override
  int Function()? get sessionGenerationProvider => () => generation;
  Map<String, dynamic> calendar() => {
        'available': !unavailable,
        'enforced': true,
        'backdate_days': 45,
        'from': date,
        'to': date,
        'etag': '"v1"',
        'classes': [
          {'class_id': 3, 'days': code},
          {'class_id': 4, 'days': code}
        ],
        'school': {date: 'Day Off: training'},
      };
  ApiResponse sheet(int id) => ApiResponse(success: true, data: {
        'students': [
          {
            'member_id': id * 10,
            'student_name': 'Student $id',
            'father_name': 'Test',
            'member_code': 'TEST-$id',
            'att_status': 'absent'
          }
        ],
        'submission_status': packet,
        'locked': packet == 'submitted',
        if (onlineStatus != null)
          'date_status': {...onlineStatus!, 'date': date},
      });
  @override
  Future<ApiResponse> getAttendanceCalendar({String? etag}) async {
    sentEtag = etag;
    if (networkFailure) return ApiResponse.error('offline');
    if (notModified) return ApiResponse(success: true, statusCode: 304);
    return ApiResponse(success: true, data: calendar());
  }

  @override
  Future<ApiResponse> getAttendance(int id, {String? date}) async {
    if (delayed) {
      final c = Completer<ApiResponse>();
      requests['$id:$date'] = c;
      return c.future;
    }
    if (authFailure) return ApiResponse.error('Forbidden', 403);
    return networkFailure ? ApiResponse.error('offline') : sheet(id);
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class FakeDb implements LocalDb {
  int saves = 0;
  int lockCleanupCalls = 0;
  LegacyOperationRef? undone;
  int localEdits = 0;
  Object? snapshotFailure;
  Completer<void>? delayedSnapshot;
  final cacheSessions = <LegacyWriteSession>[];
  bool refuseSave = false;
  Object? saveFailure;
  Completer<void>? delayedSave;
  final capturedSessions = <LegacyWriteSession>[];
  final saved = <String, List<Map<String, dynamic>>>{};
  final sheets = <String, Map<String, dynamic>>{};
  @override
  Future<int> getPendingAttendanceCount() async => 0;
  @override
  Future<List<Map<String, dynamic>>> getRejectedBatches() async => [];
  @override
  Future<Map<String, dynamic>?> getCachedAttendanceSheet(
          int id, String date) async =>
      sheets['$id:$date'];
  @override
  Future<List<Map<String, dynamic>>> getPendingAttendanceRecords(
          int id, String date) async =>
      saved['$id:$date'] ?? [];
  @override
  Future<List<Map<String, dynamic>>> getCachedStudents(int id) async => [
        {
          'member_id': id * 10,
          'student_name': 'Student $id',
          'father_name': 'Test',
          'member_code': 'TEST-$id'
        },
      ];
  @override
  Future<SubmitUndoResult> undoSubmittedLegacyOperation(
      LegacyOperationRef operation) async {
    undone = operation;
    return SubmitUndoResult.applied;
  }

  @override
  Future<void> dropPendingAttendance(int id, String date) async {
    lockCleanupCalls++;
    // Model the unsafe production helper so the test detects data loss,
    // rather than hiding the caller behind a no-op mock.
    saved.remove('$id:$date');
  }
  @override
  Future<void> cacheAttendanceSnapshot(
      int id, String date, List<Map<String, dynamic>> students,
      {required LegacyWriteSession expectedSession,
      String? submissionStatus, bool locked = false}) async {
    cacheSessions.add(expectedSession);
    final captured = students.map((s) => Map<String, dynamic>.from(s)).toList();
    if (delayedSnapshot != null) await delayedSnapshot!.future;
    if (snapshotFailure != null) throw snapshotFailure!;
    await cacheAttendanceResponse(id, date, captured,
        submissionStatus: submissionStatus, locked: locked);
  }

  @override
  Future<void> cacheStudents(int id, List<dynamic> rows) async {}
  @override
  Future<void> cacheAttendanceResponse(
      int id, String date, List<Map<String, dynamic>> students,
      {String? submissionStatus, bool locked = false}) async {
    localEdits++;
    sheets['$id:$date'] = {
      'students': students.map((s) => Map<String, dynamic>.from(s)).toList(),
      'submission_status': submissionStatus ?? '',
      'locked': locked
    };
  }

  @override
  Future<LegacyOperationRef> saveAttendanceLocal(
      int id, String name, String date, List<Map<String, dynamic>> records,
      {required LegacyWriteSession expectedSession,
      int? originalAcademicYearId,
      String packetKind = 'draft', DateTime? notBefore}) async {
    capturedSessions.add(expectedSession);
    if (delayedSave != null) await delayedSave!.future;
    if (saveFailure != null) throw saveFailure!;
    if (refuseSave) throw StateError('test disk full');
    saves++;
    saved['$id:$date'] = records
        .map((r) => {...r, 'packet_kind': packetKind, 'sync_state': 'pending'})
        .toList();
    return LegacyOperationRef(
        kind: LegacyOperationKind.attendance,
        originalAcademicYearId: originalAcademicYearId,
        naturalKey: {'class_id': id, 'date': date},
        clientOpId: 'op-$saves',
        packetKind: packetKind == 'submitted'
            ? LegacyPacketKind.submitted
            : LegacyPacketKind.draft,
        ownerUserId: 7,
        createdAuthorizationVersion: 2,
        runtimeGeneration: 1);
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class FakeSync implements SyncService {
  @override
  Stream<SyncStatus> get syncStream => const Stream.empty();
  @override
  Future<SyncResult> syncAll({bool force = false}) async =>
      SyncResult(synced: 0, failed: 0, message: 'test');
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  late FakeApi api;
  late FakeDb db;
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    api = FakeApi();
    db = FakeDb();
  });
  Future<void> open(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1000, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(
        theme: AppTheme.lightTheme,
        home: AttendanceScreen.withCollaborators(
            api: api,
            db: db,
            sync: FakeSync(),
            initialClassId: 3,
            initialDate: api.date,
            classes: () async => [
                  {'id': 3, 'class_name': 'Class 3'},
                  {'id': 4, 'class_name': 'Class 4'}
                ])));
    await tester.pumpAndSettle();
  }

  Future<void> seed() async {
    await AttendanceCalendarCache().save(
        AttendanceCalendarCache.key(AppConfig.apiBaseUrl, 7, 2),
        AttendanceCalendar.parse(api.calendar())!,
        isCurrent: () => true);
  }

  bool submitEnabled(WidgetTester tester) =>
      tester
          .widget<OutlinedButton>(find.widgetWithText(OutlinedButton, 'Submit'))
          .onPressed !=
      null;

  for (final code in ['n', 'c', 'd', 'h', 'x']) {
    testWidgets(
        '$code blocks individual, bulk, QR, note and submit entry points',
        (tester) async {
      api.code = code;
      await open(tester);
      expect(submitEnabled(tester), false);
      expect(find.text('All Present'), findsNothing);
      expect(find.text('Scan'), findsNothing);
      await tester.tap(find.text('P'));
      await tester.tap(find.text('Student 3 Test'));
      await tester.pump(const Duration(seconds: 1));
      expect(find.text('Note'), findsNothing);
      expect(db.saves, 0);
      expect(tester.takeException(), isNull);
    });
  }
  for (final code in ['o', 'm']) {
    testWidgets('$code preserves individual autosave and submit availability',
        (tester) async {
      api.code = code;
      await open(tester);
      expect(submitEnabled(tester), true);
      expect(find.text('Scan'), findsOneWidget);
      await tester.tap(find.text('P'));
      await tester.pump(const Duration(milliseconds: 800));
      expect(db.saves, 1);
      if (code == 'm') {
        expect(find.text('Makeup class day — attendance can be recorded.'),
            findsOneWidget);
      }
      await tester.pumpWidget(const SizedBox());
    });
  }
  testWidgets('offline restart uses persistent off-day calendar',
      (tester) async {
    await seed();
    api.networkFailure = true;
    await open(tester);
    expect(find.text('Day Off: training'), findsOneWidget);
    expect(submitEnabled(tester), false);
    expect(db.saves, 0);
  });
  testWidgets('unknown calendar accepts an explicit online date verdict',
      (tester) async {
    api.code = 'x';
    api.onlineStatus = {'ok': true, 'code': 'OK', 'is_makeup': false};
    await open(tester);
    expect(submitEnabled(tester), true);
  });
  testWidgets('online off-day overrides a cached allowed day', (tester) async {
    api.code = 'o';
    await seed();
    api.onlineStatus = {
      'ok': false,
      'code': 'DAY_OFF',
      'detail': 'Day Off: newly closed'
    };
    await open(tester);
    expect(submitEnabled(tester), false);
    expect(find.text('Day Off: newly closed'), findsOneWidget);
  });
  testWidgets('304 keeps persisted calendar and sends its ETag',
      (tester) async {
    await seed();
    api.notModified = true;
    await open(tester);
    expect(api.sentEtag, '"v1"');
    expect(submitEnabled(tester), false);
  });
  for (final packet in ['submitted', 'approved']) {
    testWidgets("$packet sheet lock preserves this phone's pending intent",
        (tester) async {
      api.code = 'o';
      api.packet = packet;
      final key = '3:${api.date}';
      final original = <String, dynamic>{
        'member_id': 30,
        'status': 'present',
        'notes': 'Original unsynchronized note',
        'client_op_id': 'original-offline-operation',
        'owner_user_id': 7,
        'created_authorization_version': 2,
        'packet_kind': 'draft',
        'sync_state': 'pending',
        'attempt_count': 0,
      };
      db.saved[key] = [Map<String, dynamic>.from(original)];

      await open(tester);

      expect(db.lockCleanupCalls, 0);
      expect(db.saved[key], [original]);
      expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
      expect(find.text('Scan'), findsNothing);
      await tester.tap(find.text('P'));
      await tester.pump(const Duration(seconds: 1));
      expect(db.saves, 0);
      expect(db.saved[key], [original]);
    });
  }

  testWidgets('submitted packet stays locked even on an allowed day',
      (tester) async {
    api.code = 'o';
    api.packet = 'submitted';
    await open(tester);
    expect(find.text('Scan'), findsNothing);
    await tester.tap(find.text('P'));
    await tester.pump(const Duration(seconds: 1));
    expect(db.saves, 0);
  });
  testWidgets(
      'missing offline calendar fails closed without inventing weekdays',
      (tester) async {
    api.networkFailure = true;
    await open(tester);
    expect(submitEnabled(tester), false);
    expect(find.textContaining('Class-day information is unavailable'),
        findsOneWidget);
    await tester.tap(find.text('P'));
    await tester.pump(const Duration(seconds: 1));
    expect(db.saves, 0);
  });
  testWidgets(
      'unavailable successful refresh removes an old allowed disk calendar',
      (tester) async {
    api.code = 'o';
    await seed();
    api.unavailable = true;
    await open(tester);
    expect(submitEnabled(tester), false);
    expect(
        await AttendanceCalendarCache()
            .read(AttendanceCalendarCache.key(AppConfig.apiBaseUrl, 7, 2)),
        isNull);
  });
  testWidgets('permission denial cannot unlock a cached allowed class day',
      (tester) async {
    api.code = 'o';
    await seed();
    api.authFailure = true;
    await open(tester);
    expect(submitEnabled(tester), false);
    await tester.tap(find.text('P'));
    await tester.pump(const Duration(seconds: 1));
    expect(db.saves, 0);
  });
  testWidgets('session generation change fences stale marking callbacks',
      (tester) async {
    api.code = 'o';
    await open(tester);
    expect(submitEnabled(tester), true);
    final editsBefore = db.localEdits;
    api.generation++;
    await tester.tap(find.text('P'));
    await tester.pump(const Duration(seconds: 1));
    expect(db.saves, 0);
    expect(db.localEdits, editsBefore);
  });
  Future<void> switchTo(WidgetTester tester, int id) async {
    await tester.tap(find.byType(DropdownButtonFormField<int>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Class $id').last);
    await tester.pumpAndSettle();
  }

  testWidgets('a submitted class does not block a second assigned class on the same day',
      (tester) async {
    api.code = 'o';
    await open(tester);
    await tester.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await tester.pump();
    expect(db.saved['3:${api.date}']?.single['packet_kind'], 'submitted');
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
    await switchTo(tester, 4);
    expect(find.text('Student 4 Test'), findsOneWidget);
    expect(submitEnabled(tester), isTrue);
    await tester.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await tester.pump();
    expect(db.saved['4:${api.date}']?.single['packet_kind'], 'submitted');
    expect(db.saved['3:${api.date}']?.single['packet_kind'], 'submitted');
  });

  testWidgets('fast class switch flushes marked draft before debounce fires',
      (tester) async {
    api.code = 'o';
    await open(tester);
    await tester.tap(find.text('P'));
    await switchTo(tester, 4);
    expect(db.saved['3:${api.date}']?.single['status'], 'present');
    expect(find.text('Student 4 Test'), findsOneWidget);
  });
  testWidgets(
      'refresh flushes a pending marked draft before replacing the roster',
      (tester) async {
    api.code = 'o';
    await open(tester);
    await tester.tap(find.text('P'));
    final state =
        tester.state<AttendanceScreenState>(find.byType(AttendanceScreen));
    state.didChangeAppLifecycleState(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(db.saved['3:${api.date}']?.single['status'], 'present');
  });
  testWidgets('slow sheet response preserves a mark made after request start',
      (tester) async {
    api.code = 'o';
    api.delayed = true;
    await open(tester);
    await tester.tap(find.text('P'));
    api.requests['3:${api.date}']!.complete(api.sheet(3));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 1));
    expect(db.saved['3:${api.date}']?.single['status'], 'present');
    await tester.pumpWidget(const SizedBox());
  });
  testWidgets('disk refusal prevents losing pending marks on a class switch',
      (tester) async {
    api.code = 'o';
    await open(tester);
    db.refuseSave = true;
    await tester.tap(find.text('P'));
    await switchTo(tester, 4);
    expect(find.text('Student 3 Test'), findsOneWidget);
    expect(find.textContaining('storage refused'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });
  testWidgets('note-only change enters autosave without another status tap',
      (tester) async {
    api.code = 'o';
    await open(tester);
    await tester.tap(find.text('Student 3 Test'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'Audit note');
    await tester.tap(find.text('Keep'));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 1));
    expect(db.saved['3:${api.date}']?.single['notes'], 'Audit note');
    await tester.pumpWidget(const SizedBox());
  });
  testWidgets('QR storage failure never reports successful durable attendance',
      (tester) async {
    api.code = 'o';
    await open(tester);
    db.refuseSave = true;
    final state =
        tester.state<AttendanceScreenState>(find.byType(AttendanceScreen));
    final result = await state.handleQrScan('TEST-3');
    expect(result.isSuccess, false);
    db.refuseSave = false;
    final retry = await state.handleQrScan('TEST-3');
    expect(retry.isSuccess, true);
    expect(db.saved['3:${api.date}']?.single['status'], 'present');
    await tester.pumpWidget(const SizedBox());
  });
  testWidgets('QR mark survives a sheet response that began before the scan',
      (tester) async {
    api.code = 'o';
    api.delayed = true;
    await open(tester);
    final state =
        tester.state<AttendanceScreenState>(find.byType(AttendanceScreen));
    expect((await state.handleQrScan('TEST-3')).isSuccess, true);
    api.requests['3:${api.date}']!.complete(api.sheet(3));
    await tester.pumpAndSettle();
    expect(db.sheets['3:${api.date}']?['students'][0]['status'], 'present');
  });
  testWidgets('late draft response cannot reopen a locally submitted sheet',
      (tester) async {
    api.code = 'o';
    api.delayed = true;
    await open(tester);
    await tester.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await tester.pump();
    api.requests['3:${api.date}']!.complete(api.sheet(3));
    await tester.pumpAndSettle();
    expect(find.text('Scan'), findsNothing);
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });
  testWidgets('undo from class 3 never unlocks a submitted class 4 sheet',
      (tester) async {
    api.code = 'o';
    await open(tester);
    await tester.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    api.packet = 'submitted';
    await tester.tap(find.byType(DropdownButtonFormField<int>));
    await tester.pump(const Duration(milliseconds: 250));
    await tester.tap(find.text('Class 4').last);
    await tester.pump(const Duration(milliseconds: 300));
    await tester.tap(find.text('UNDO'));
    await tester.pumpAndSettle();
    expect(db.undone?.naturalKey['class_id'], 3);
    expect(find.text('Student 4 Test'), findsOneWidget);
    expect(find.text('Scan'), findsNothing);
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });
  testWidgets(
      'queued submitted packet remains locked across restart and a stale server draft',
      (tester) async {
    api.code = 'o';
    db.saved['3:${api.date}'] = [
      {
        'member_id': 30,
        'status': 'present',
        'packet_kind': 'submitted',
        'sync_state': 'pending'
      }
    ];
    await open(tester);
    expect(find.text('Scan'), findsNothing);
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
    expect(db.saves, 0);
  });
  testWidgets('late class-3 response cannot replace class-4 roster or verdict',
      (tester) async {
    api.code = 'x';
    api.delayed = true;
    await open(tester);
    await tester.tap(find.byType(DropdownButtonFormField<int>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Class 4').last);
    await tester.pumpAndSettle();
    api.onlineStatus = {
      'ok': false,
      'code': 'DAY_OFF',
      'detail': 'Class 4 closed'
    };
    api.requests['4:${api.date}']!.complete(api.sheet(4));
    await tester.pumpAndSettle();
    api.onlineStatus = {'ok': true, 'code': 'OK'};
    api.requests['3:${api.date}']!.complete(api.sheet(3));
    await tester.pumpAndSettle();
    expect(find.text('Student 4 Test'), findsOneWidget);
    expect(find.text('Student 3 Test'), findsNothing);
    expect(find.text('Class 4 closed'), findsOneWidget);
    expect(submitEnabled(tester), false);
  });
  Future<void> saveThrough(WidgetTester tester, String path) async {
    await tester.tap(find.text('P'));
    await tester.pump();
    if (path == 'manual') {
      await tester.tap(find.text('Save'));
    } else if (path == 'submit') {
      await tester.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    } else {
      await tester.pump(const Duration(milliseconds: 800));
    }
    await tester.pumpAndSettle();
  }

  for (final path in ['manual', 'submit', 'autosave']) {
    testWidgets('$path passes original sheet session into the save', (tester) async {
      api.code = 'o';
      await open(tester);
      await saveThrough(tester, path);
      expect(db.capturedSessions, isNotEmpty);
      for (final capture in db.capturedSessions) {
        expect(capture.ownerUserId, 7);
        expect(capture.authorizationVersion, 2);
        expect(capture.runtimeGeneration, 1);
      }
      expect(db.saved['3:${api.date}']?.single['status'], 'present');
      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('$path stale capture reports not saved and keeps dirty marks', (tester) async {
      api.code = 'o';
      await open(tester);
      db.saveFailure = const StaleLegacyWriteSession();
      await saveThrough(tester, path);
      expect(db.saves, 0);
      expect(db.saved, isEmpty);
      expect(find.textContaining('These edits are not saved'), findsOneWidget);
      expect(find.textContaining('Phone storage refused'), findsNothing);
      expect(find.text('Saved'), findsNothing);
      expect(find.text('Submitted'), findsNothing);
      expect(find.text('Student 3 Test'), findsOneWidget);
      expect(tester.widget<TeacherActionBar>(find.byType(TeacherActionBar))
          .saveEnabled!.value, isTrue);
      await tester.pumpWidget(const SizedBox());
    });
  }

  testWidgets('delayed save retains original generation when session changes', (tester) async {
    api.code = 'o';
    await open(tester);
    db.delayedSave = Completer<void>();
    db.saveFailure = const StaleLegacyWriteSession();
    await tester.tap(find.text('P'));
    await tester.pump();
    await tester.tap(find.text('Save'));
    await tester.pump();
    expect(db.capturedSessions, hasLength(1));
    api.generation++;
    db.delayedSave!.complete();
    await tester.pumpAndSettle();
    final capture = db.capturedSessions.single;
    expect(capture.ownerUserId, 7);
    expect(capture.authorizationVersion, 2);
    expect(capture.runtimeGeneration, 1);
    expect(db.saves, 0);
    expect(find.textContaining('These edits are not saved'), findsOneWidget);
    expect(find.text('Saved'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('stale capture blocks class switch without masking its cause', (tester) async {
    api.code = 'o';
    await open(tester);
    db.saveFailure = const StaleLegacyWriteSession();
    await tester.tap(find.text('P'));
    await switchTo(tester, 4);
    expect(find.text('Student 3 Test'), findsOneWidget);
    expect(find.text('Student 4 Test'), findsNothing);
    expect(find.textContaining('These edits are not saved'), findsOneWidget);
    expect(find.textContaining('Phone storage refused'), findsNothing);
    expect(db.saves, 0);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('online refresh publishes with its original captured session', (tester) async {
    api.code = 'o';
    await open(tester);
    final capture = db.cacheSessions.single;
    expect(capture.ownerUserId, 7);
    expect(capture.authorizationVersion, 2);
    expect(capture.runtimeGeneration, 1);
    expect(db.sheets['3:${api.date}'], isNotNull);
  });

  testWidgets('cache storage failure explains retention without erasing pending work', (tester) async {
    api.code = 'o';
    db.saved['3:${api.date}'] = [{'member_id': 30, 'status': 'late', 'notes': 'Unsent', 'packet_kind': 'draft', 'sync_state': 'pending'}];
    final before = db.saved['3:${api.date}'];
    db.snapshotFailure = StateError('synthetic disk full');
    await open(tester);
    expect(find.textContaining('Pending work was not cleared'), findsOneWidget);
    expect(db.saved['3:${api.date}'], same(before));
    expect(db.lockCleanupCalls, 0);
    expect(find.text('Saved'), findsNothing);
  });

  testWidgets('durable cache-session refusal blocks editing without clearing work', (tester) async {
    api.code = 'o';
    db.snapshotFailure = const StaleAttendanceSyncSession();
    await open(tester);
    expect(submitEnabled(tester), false);
    expect(find.textContaining('Your session changed'), findsOneWidget);
    await tester.tap(find.text('P'));
    await tester.pump(const Duration(seconds: 1));
    expect(db.saves, 0);
    expect(db.lockCleanupCalls, 0);
  });

  testWidgets('late cache refusal from an older generation cannot update the current view', (tester) async {
    api.code = 'o';
    db.delayedSnapshot = Completer<void>();
    db.snapshotFailure = const StaleAttendanceSyncSession();
    await open(tester);
    api.generation++;
    db.delayedSnapshot!.complete();
    await tester.pumpAndSettle();
    expect(db.cacheSessions.single.runtimeGeneration, 1);
    expect(find.textContaining('Your session changed'), findsNothing);
    expect(db.lockCleanupCalls, 0);
  });

  for (final fails in [false, true]) {
    testWidgets('delayed cache ${fails ? "failure" : "completion"} cannot erase a newer mark', (tester) async {
      api.code = 'o';
      db.delayedSnapshot = Completer<void>();
      if (fails) db.snapshotFailure = StateError('synthetic disk full');
      await open(tester);
      await tester.tap(find.text('P'));
      await tester.pump(const Duration(seconds: 1));
      expect(db.saved['3:${api.date}']!.single['status'], 'present');
      db.delayedSnapshot!.complete();
      await tester.pumpAndSettle();
      expect(db.saved['3:${api.date}']!.single['status'], 'present');
      expect(db.lockCleanupCalls, 0);
      if (fails) expect(find.textContaining('Pending work was not cleared'), findsOneWidget);
      // Existing UI hides the progress strip while an error is displayed.
      // A fresh manual save reads current UI records, proving the late cache
      // callback did not silently replace them with its older absent mark.
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
      expect(db.saved['3:${api.date}']!.single['status'], 'present');
      expect(find.text('1 / 1 present'), findsOneWidget);
      await tester.pumpWidget(const SizedBox());
    });
  }

}
