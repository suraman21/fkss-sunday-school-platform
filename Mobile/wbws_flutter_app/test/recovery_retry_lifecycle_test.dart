import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/main.dart';

Future<void> Function() retryAction(WidgetTester tester) =>
    tester.widget<FilledButton>(find.byType(FilledButton)).onPressed!
        as Future<void> Function();

void main() {
  testWidgets('failed same-State bootstrap can be retried repeatedly',
      (tester) async {
    expect(
        const OfflineDataProtectionFailureApp(detail: 'fixture').retryBootstrap,
        same(runBootstrap));
    var calls = 0;
    late Future<void> Function() bootstrap;
    bootstrap = () async {
      calls++;
      // Reproduce runBootstrap's handled storage-failure path: same-type root
      // replacement, not a simulated successful login or a filesystem reset.
      runApp(OfflineDataProtectionFailureApp(
        detail: 'Synthetic failure $calls',
        migrationRecoveryRequired: true,
        retryBootstrap: bootstrap,
      ));
    };
    await tester.pumpWidget(OfflineDataProtectionFailureApp(
      detail: 'Initial synthetic failure',
      migrationRecoveryRequired: true,
      retryBootstrap: bootstrap,
    ));
    final state = tester.state(find.byType(OfflineDataProtectionFailureApp));
    for (var attempt = 1; attempt <= 3; attempt++) {
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();
      expect(tester.state(find.byType(OfflineDataProtectionFailureApp)),
          same(state));
      expect(find.text('Synthetic failure $attempt'), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
      expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
          isNotNull);
      expect(find.textContaining('Do not clear app data or reinstall'),
          findsOneWidget);
    }
    expect(calls, 3);
  });

  testWidgets('retry is single-flight even before the disabled button rebuilds',
      (tester) async {
    final pending = Completer<void>();
    var calls = 0;
    await tester.pumpWidget(OfflineDataProtectionFailureApp(
      detail: 'Synthetic pending bootstrap',
      retryBootstrap: () {
        calls++;
        return pending.future;
      },
    ));
    final action = retryAction(tester);
    final first = action();
    final second = action(); // captured callback, same frame
    try {
      expect(calls, 1);
      await tester.pump();
      expect(find.text('Retrying…'), findsOneWidget);
      expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
          isNull);
    } finally {
      pending.complete();
      await Future.wait([first, second]);
      await tester.pump();
    }
    expect(find.text('Retry'), findsOneWidget);
  });

  testWidgets('unexpected bootstrap errors propagate and restore Retry',
      (tester) async {
    await tester.pumpWidget(OfflineDataProtectionFailureApp(
      detail: 'Synthetic bootstrap error',
      retryBootstrap: () async =>
          throw StateError('Synthetic unexpected failure'),
    ));
    await expectLater(retryAction(tester)(), throwsA(isA<StateError>()));
    await tester.pump();
    expect(find.text('Retry'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNotNull);
  });

  testWidgets('successful bootstrap can replace the recovery root',
      (tester) async {
    await tester.pumpWidget(OfflineDataProtectionFailureApp(
      detail: 'Synthetic failure before success',
      retryBootstrap: () async {
        runApp(const MaterialApp(home: Text('Synthetic bootstrap ready')));
      },
    ));
    await tester.tap(find.text('Retry'));
    await tester.pumpAndSettle();
    expect(find.text('Synthetic bootstrap ready'), findsOneWidget);
    expect(find.byType(OfflineDataProtectionFailureApp), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('completion after disposal never updates the old State',
      (tester) async {
    final pending = Completer<void>();
    await tester.pumpWidget(OfflineDataProtectionFailureApp(
      detail: 'Synthetic pending bootstrap',
      retryBootstrap: () => pending.future,
    ));
    final task = retryAction(tester)();
    await tester.pumpWidget(const SizedBox.shrink());
    pending.complete();
    await task;
    await tester.pump();
    expect(tester.takeException(), isNull);
    expect(find.byType(OfflineDataProtectionFailureApp), findsNothing);
  });
}
