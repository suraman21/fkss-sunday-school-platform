import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/screens/attendance/attendance_screen.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';
import 'package:fkss_app/utils/theme.dart';

import 'attendance_calendar_screen_test.dart' show FakeApi, FakeSync;

// Runs the real screen, production LocalDb schema/transactions and native
// SQLite. Only network/sync collaborators are controlled. No camera/device
// or live server is claimed by this integration test.
void main() {
  late Directory directory;
  late LocalDb db;
  late FakeApi api;

  setUpAll(() async {
    sqfliteFfiInit();
    directory = await Directory.systemTemp.createTemp('attendance-class-day-');
    await databaseFactoryFfi.setDatabasesPath(directory.path);
    sqflite.databaseFactory = databaseFactoryFfi;
    db = LocalDb();
    await db.database;
  });
  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    final raw = await db.database;
    for (final table in [
      'pending_attendance',
      'cached_attendance',
      'cached_students'
    ]) {
      await raw.delete(table);
    }
    await db.persistLocalSession(
        state: SessionState.active,
        generation: 1,
        ownerUserId: 7,
        authorizationVersion: 2,
        ownerRole: 'teacher');
    api = FakeApi();
  });
  tearDownAll(() async {
    final raw = await db.database;
    await raw.close();
    await directory.delete(recursive: true);
  });

  Future<void> until(WidgetTester tester, bool Function() ready) async {
    for (var i = 0; i < 100; i++) {
      await tester.runAsync(
          () => Future<void>.delayed(const Duration(milliseconds: 20)));
      await tester.pump(const Duration(milliseconds: 10));
      if (ready()) return;
    }
    fail('Real SQLite screen operation did not finish');
  }

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
                  {'id': 4, 'class_name': 'Class 4'},
                ])));
    await until(
        tester, () => find.text('Student 3 Test').evaluate().isNotEmpty);
  }

  testWidgets(
      'real SQLite journey: rapid switch, leave, restart and off-day protection',
      (tester) async {
    api.code = 'o';
    await open(tester);
    await tester.tap(find.text('P'));
    await tester.tap(find.byType(DropdownButtonFormField<int>));
    await tester.pump(const Duration(milliseconds: 250));
    await tester.tap(find.text('Class 4').last);
    await until(
        tester, () => find.text('Student 4 Test').evaluate().isNotEmpty);
    var rows = await tester
        .runAsync(() => db.getPendingAttendanceRecords(3, api.date));
    expect(rows, hasLength(1));
    expect(rows!.single['status'], 'present');
    expect(rows.single['owner_user_id'], 7);
    expect(rows.single['packet_kind'], 'draft');
    await tester.pumpWidget(const SizedBox());
    await tester.pump();

    // Keep one widget-test zone for the production singleton's save-tail
    // futures. Open a fresh screen over the same database to model restart.
    await open(tester);
    final cached =
        await tester.runAsync(() => db.getCachedAttendanceSheet(3, api.date));
    expect(cached!['students'][0]['status'], 'present');
    await tester.tap(find.text('A'));
    await tester.pumpWidget(const SizedBox());
    for (var i = 0; i < 100; i++) {
      rows = await tester
          .runAsync(() => db.getPendingAttendanceRecords(3, api.date));
      if (rows!.single['status'] == 'absent') break;
      await tester.runAsync(
          () => Future<void>.delayed(const Duration(milliseconds: 20)));
      await tester.pump();
    }
    expect(rows!.single['status'], 'absent');
    expect(tester.takeException(), isNull);

    // A submitted offline operation must stay locked after a fresh screen
    // reads a server response that still says draft.
    await open(tester);
    await tester.tap(find.widgetWithText(OutlinedButton, 'Submit'));
    await until(tester,
        () => find.widgetWithText(OutlinedButton, 'Submit').evaluate().isEmpty);
    await tester.pumpWidget(const SizedBox());
    await open(tester);
    expect(find.text('Scan'), findsNothing);
    expect(find.widgetWithText(OutlinedButton, 'Submit'), findsNothing);
    rows = await tester
        .runAsync(() => db.getPendingAttendanceRecords(3, api.date));
    expect(rows!.single['packet_kind'], 'submitted');
    await tester.pumpWidget(const SizedBox());

    // Remove only this fixture's pending rows before the refusal probe.
    await tester.runAsync(() async {
      final raw = await db.database;
      await raw.delete('pending_attendance');
    });
    api.code = 'd';
    await open(tester);
    await tester.tap(find.text('P'));
    final state =
        tester.state<AttendanceScreenState>(find.byType(AttendanceScreen));
    expect((await state.handleQrScan('TEST-3')).isSuccess, false);
    await tester.pump(const Duration(seconds: 1));
    rows = await tester
        .runAsync(() => db.getPendingAttendanceRecords(3, api.date));
    expect(rows, isEmpty);
    expect(find.text('Scan'), findsNothing);
    expect(find.text('All Present'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });
}
