import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:fkss_app/screens/mezmur/mezmur_attendance.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/sync_service.dart';
import 'package:fkss_app/utils/theme.dart';
import 'package:fkss_app/widgets/loading_skeleton.dart';

class TestApi implements ApiService {
  final statuses = <String, String>{};
  final requests = <String, Completer<ApiResponse>>{};
  bool delayed = false;
  bool offline = false;
  final date = '2026-10-25';
  @override
  int get userId => 7;
  @override
  int get authorizationVersion => 2;
  @override
  int Function()? get sessionGenerationProvider => () => 1;
  @override
  Future<ApiResponse> getMezmurSections() async => offline
      ? ApiResponse.error('offline')
      : ApiResponse(success: true, data: {
          'sections': [
            {'section': 'A', 'members': 1},
            {'section': 'B', 'members': 1}
          ],
        });
  ApiResponse sheet(String section) => ApiResponse(success: true, data: {
        'date': date,
        'section': section,
        'members': [
          {
            'id': section == 'A' ? 1 : 2,
            'student_name': 'Member $section',
            'father_name': 'Test',
            'member_code': 'M-$section',
            'mark': 'absent'
          }
        ],
        'submission_status': statuses[section] ?? '',
        'locked': const {'submitted', 'approved', 'rejected'}
            .contains(statuses[section]),
      });
  @override
  Future<ApiResponse> getMezmurSheet(String date, {String? section}) async {
    if (offline) return ApiResponse.error('offline');
    if (!delayed) return sheet(section!);
    final c = Completer<ApiResponse>();
    requests[section!] = c;
    return c.future;
  }

  @override
  dynamic noSuchMethod(Invocation i) => super.noSuchMethod(i);
}

class TestDb implements LocalDb {
  final sheets = <String, Map<String, dynamic>>{};
  final saved = <String, List<Map<String, dynamic>>>{};
  final writes = <String>[];
  bool refuseSave = false;
  List<Map<String, dynamic>>? cachedSections;
  Completer<void>? delayedSubmit;
  LegacyOperationRef? undone;
  @override
  Future<List<Map<String, dynamic>>?> getCachedMezmurSections() async =>
      cachedSections;
  @override
  Future<void> cacheMezmurSections(List<Map<String, dynamic>> sections) async {}
  @override
  Future<int> getPendingMezmurCount() async => 0;
  @override
  Future<List<Map<String, dynamic>>> getRejectedBatches() async => [];
  @override
  Future<Map<String, dynamic>?> getCachedMezmurSheet(
          String date, String section) async =>
      sheets['$date:$section'];
  @override
  Future<void> cacheMezmurSheet(
      String date, String section, Map<String, dynamic> payload) async {
    sheets['$date:$section'] =
        Map<String, dynamic>.from(jsonDecode(jsonEncode(payload)) as Map);
  }

  @override
  Future<List<Map<String, dynamic>>> getPendingMezmurRecords(
          String date, String section) async =>
      saved['$date:$section'] ?? [];
  @override
  Future<LegacyOperationRef> saveMezmurLocal(
      String date, String section, List<Map<String, dynamic>> records,
      {String packetKind = 'draft', DateTime? notBefore}) async {
    if (packetKind == 'submitted' && delayedSubmit != null) {
      await delayedSubmit!.future;
    }
    if (refuseSave) throw StateError('disk full');
    writes.add('$date:$section:$packetKind');
    saved['$date:$section'] = records
        .map((e) => {...e, 'packet_kind': packetKind, 'sync_state': 'pending'})
        .toList();
    return LegacyOperationRef(
        kind: LegacyOperationKind.mezmur,
        naturalKey: {'date': date, 'section': section},
        clientOpId: 'op-${writes.length}',
        packetKind: packetKind == 'submitted'
            ? LegacyPacketKind.submitted
            : LegacyPacketKind.draft,
        ownerUserId: 7,
        createdAuthorizationVersion: 2,
        runtimeGeneration: 1);
  }

  @override
  Future<SubmitUndoResult> undoSubmittedLegacyOperation(
      LegacyOperationRef op) async {
    undone = op;
    return SubmitUndoResult.applied;
  }

  @override
  dynamic noSuchMethod(Invocation i) => super.noSuchMethod(i);
}

class TestSync implements SyncService {
  @override
  Stream<SyncStatus> get syncStream => const Stream.empty();
  @override
  Future<SyncResult> syncAll({bool force = false}) async =>
      SyncResult(synced: 0, failed: 0, message: 'test');
  @override
  dynamic noSuchMethod(Invocation i) => super.noSuchMethod(i);
}

void main() {
  late TestApi api;
  late TestDb db;
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    api = TestApi();
    db = TestDb();
  });
  Future<void> open(WidgetTester t) async {
    t.view.physicalSize = const Size(1000, 1400);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.resetPhysicalSize);
    addTearDown(t.view.resetDevicePixelRatio);
    await t.pumpWidget(MaterialApp(
        theme: AppTheme.lightTheme,
        home: MezmurAttendanceScreen.withCollaborators(
            api: api,
            db: db,
            sync: TestSync(),
            initialDate: api.date,
            initialSection: 'A')));
    if (api.delayed) {
      await t.pump(const Duration(milliseconds: 100));
    } else {
      await t.pumpAndSettle();
    }
  }

  Future<void> switchTo(WidgetTester t, String section) async {
    await t.tap(find.byType(DropdownButtonFormField<String>));
    if (api.delayed) {
      await t.pump(const Duration(milliseconds: 350));
    } else {
      await t.pumpAndSettle();
    }
    await t.tap(find.text('$section · 1').last);
    if (api.delayed) {
      await t.pump(const Duration(milliseconds: 350));
    } else {
      await t.pumpAndSettle();
    }
  }

  testWidgets('submitted A remains view-only but B is selectable and editable',
      (t) async {
    api.statuses['A'] = 'submitted';
    await open(t);
    expect(
        t
            .widget<DropdownButtonFormField<String>>(
                find.byType(DropdownButtonFormField<String>))
            .onChanged,
        isNotNull);
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
    await switchTo(t, 'B');
    expect(find.text('Member B Test'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsOneWidget);
    await t.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await t.pump();
    expect(db.saved['${api.date}:B']?.single['packet_kind'], 'submitted');
    await switchTo(t, 'A');
    expect(find.text('Member A Test'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
  });
  testWidgets('fast section change saves a marked draft before leaving',
      (t) async {
    await open(t);
    await t.tap(find.text('P'));
    await switchTo(t, 'B');
    expect(db.saved['${api.date}:A']?.single['status'], 'present');
    expect(find.text('Member B Test'), findsOneWidget);
  });
  testWidgets('storage refusal keeps the unsaved section visible', (t) async {
    await open(t);
    db.refuseSave = true;
    await t.tap(find.text('P'));
    await switchTo(t, 'B');
    expect(find.text('Member A Test'), findsOneWidget);
    expect(find.text('A · 1'), findsOneWidget);
    expect(find.text('B · 1'), findsNothing);
    expect(find.textContaining('storage refused'), findsOneWidget);
  });
  testWidgets('late A response does not replace B', (t) async {
    api.delayed = true;
    await open(t);
    await switchTo(t, 'B');
    api.requests['B']!.complete(api.sheet('B'));
    await t.pumpAndSettle();
    api.requests['A']!.complete(api.sheet('A'));
    await t.pumpAndSettle();
    expect(find.text('Member B Test'), findsOneWidget);
    expect(find.text('Member A Test'), findsNothing);
  });
  testWidgets('a queued submission stays locked when server still says draft',
      (t) async {
    db.saved['${api.date}:A'] = [
      {
        'member_id': 1,
        'status': 'present',
        'packet_kind': 'submitted',
        'sync_state': 'pending'
      }
    ];
    await open(t);
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
    expect(find.text('Scan'), findsNothing);
  });
  for (final status in ['approved', 'rejected']) {
    testWidgets('$status section still allows navigation', (t) async {
      api.statuses['A'] = status;
      await open(t);
      await switchTo(t, 'B');
      expect(find.text('Member B Test'), findsOneWidget);
      expect(find.widgetWithText(OutlinedButton, 'Submit'), findsOneWidget);
    });
  }
  testWidgets('date picker is accessible for a submitted sheet', (t) async {
    api.statuses['A'] = 'submitted';
    await open(t);
    await t.tap(find.byIcon(Icons.calendar_today));
    await t.pumpAndSettle();
    expect(find.byType(Dialog), findsWidgets);
  });
  testWidgets('undo of A cannot unlock submitted B', (t) async {
    await open(t);
    await t.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await t.pump();
    api.statuses['B'] = 'submitted';
    await t.tap(find.byType(DropdownButtonFormField<String>));
    await t.pump(const Duration(milliseconds: 250));
    await t.tap(find.text('B · 1').last);
    await t.pump(const Duration(milliseconds: 300));
    await t.tap(find.text('UNDO'));
    await t.pumpAndSettle();
    expect(db.undone?.naturalKey['section'], 'A');
    expect(find.text('Member B Test'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
  });
  testWidgets('navigation is disabled only while a submission is being saved',
      (t) async {
    db.delayedSubmit = Completer<void>();
    await open(t);
    await t.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await t.pump();
    expect(
        t
            .widget<DropdownButtonFormField<String>>(
                find.byType(DropdownButtonFormField<String>))
            .onChanged,
        isNull);
    db.delayedSubmit!.complete();
    await t.pump();
    expect(
        t
            .widget<DropdownButtonFormField<String>>(
                find.byType(DropdownButtonFormField<String>))
            .onChanged,
        isNotNull);
  });
  testWidgets('note-only draft is saved before section change', (t) async {
    await open(t);
    await t.tap(find.text('Member A Test'));
    await t.pumpAndSettle();
    await t.enterText(find.byType(TextField), 'Section A note');
    await t.tap(find.text('Keep'));
    await t.pump();
    await switchTo(t, 'B');
    expect(db.saved['${api.date}:A']?.single['notes'], 'Section A note');
  });
  testWidgets('queued A submission stays locked after navigating away and back',
      (t) async {
    await open(t);
    await t.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await t.pump();
    await switchTo(t, 'B');
    await switchTo(t, 'A');
    expect(find.text('Member A Test'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
  });
  testWidgets('delayed server draft cannot unlock a locally submitted sheet',
      (t) async {
    db.sheets['${api.date}:A'] = api.sheet('A').data!;
    api.delayed = true;
    await open(t);
    // Allow the cached sheet to be presented while its request is in flight.
    // The screen must still be usable after a cached load.
    api.requests['A']!.complete(api.sheet('A'));
    await t.pumpAndSettle();
    api.delayed = true;
    final refresh =
        t.widget<RefreshIndicator>(find.byType(RefreshIndicator)).onRefresh();
    await t.pump();
    await t.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await t.pump();
    api.requests['A']!.complete(api.sheet('A'));
    await t.pumpAndSettle();
    await refresh;
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
  });
  testWidgets('offline cached sections remain independently usable', (t) async {
    api.statuses['A'] = 'submitted';
    db.sheets['${api.date}:A'] = api.sheet('A').data!;
    db.sheets['${api.date}:B'] = api.sheet('B').data!;
    db.cachedSections = [
      {'section': 'A', 'members': 1},
      {'section': 'B', 'members': 1}
    ];
    api.offline = true;
    await open(t);
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
    await switchTo(t, 'B');
    expect(find.text('Member B Test'), findsOneWidget);
    await t.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await t.pump();
    expect(db.saved['${api.date}:B']?.single['packet_kind'], 'submitted');
  });

  testWidgets('several sections of the same day can be taken in a row',
      (t) async {
    await open(t);
    // Section A: submit.
    await t.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await t.pump();
    expect(db.saved['${api.date}:A']?.single['packet_kind'], 'submitted');
    DropdownButtonFormField<String> picker() =>
        t.widget<DropdownButtonFormField<String>>(
            find.byType(DropdownButtonFormField<String>));
    expect(picker().onChanged, isNotNull,
        reason: 'submitting A must not disable the section picker');
    // Section B: still selectable, and it can be submitted too.
    await switchTo(t, 'B');
    expect(find.text('Member B Test'), findsOneWidget);
    expect(picker().onChanged, isNotNull);
    await t.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await t.pump();
    expect(db.saved['${api.date}:B']?.single['packet_kind'], 'submitted');
    // Both submitted sheets stay view-only, and both stay reachable.
    await switchTo(t, 'A');
    expect(find.text('Member A Test'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
    expect(picker().onChanged, isNotNull);
    await switchTo(t, 'B');
    expect(find.text('Member B Test'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
    expect(db.writes.where((w) => w.endsWith(':submitted')).length, 2);
  });
  testWidgets('a refused save leaves the roster on screen, never a skeleton',
      (t) async {
    await open(t);
    db.refuseSave = true;
    await t.tap(find.text('P'));
    await t.pump();
    final refresh =
        t.widget<RefreshIndicator>(find.byType(RefreshIndicator)).onRefresh();
    await t.pump();
    await refresh;
    await t.pumpAndSettle();
    expect(find.text('Member A Test'), findsOneWidget);
    expect(find.byType(StudentListSkeleton), findsNothing);
    expect(find.textContaining('storage refused'), findsOneWidget);
  });
  testWidgets('a failed submit re-enables the selector immediately', (t) async {
    db.refuseSave = true;
    await open(t);
    await t.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await t.pumpAndSettle();
    expect(
        t
            .widget<DropdownButtonFormField<String>>(
                find.byType(DropdownButtonFormField<String>))
            .onChanged,
        isNotNull);
    await switchTo(t, 'B');
    expect(find.text('Member B Test'), findsOneWidget);
  });
}
