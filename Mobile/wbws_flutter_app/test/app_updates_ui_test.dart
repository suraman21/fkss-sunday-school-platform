import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:fkss_app/screens/update/app_updates_tile.dart';
import 'package:fkss_app/screens/update/update_banner.dart';
import 'package:fkss_app/screens/update/update_screen.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/app_update_service.dart';
import 'package:fkss_app/utils/config.dart';
import 'package:fkss_app/utils/theme.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  Map<String, dynamic> config(
          {int build = 900, bool required = false, bool current = false}) =>
      {
        'latest_version': current ? AppConfig.appVersion : '9.0.0',
        'latest_build': current ? AppConfig.appBuild : build,
        'min_version': required ? '9.0.0' : '0.0.0',
        'min_build': 0,
        'download_available': false,
      };

  AppUpdateService service(Future<ApiResponse> Function() fetch) =>
      AppUpdateService.forTesting(
        fetchConfig: fetch,
        clientFactory: () => MockClient((_) async => http.Response('', 503)),
      );

  Widget home(AppUpdateService svc) => MaterialApp(
        theme: AppTheme.lightTheme,
        home: Scaffold(
            body: Column(children: [
          UpdateBanner(service: svc),
          AppUpdatesTile(service: svc),
        ])),
      );

  testWidgets('X hides the reminder, never the permanent update entry',
      (tester) async {
    final svc = service(() async => ApiResponse(success: true, data: config()));
    await svc.check();
    await tester.pumpWidget(home(svc));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Dismiss update reminder'));
    await tester.pumpAndSettle();
    expect(find.byType(UpdateBanner), findsOneWidget);
    expect(find.textContaining('New FKSS version'), findsNothing);
    expect(find.text('App updates'), findsOneWidget);
    expect(find.text('Version 9.0.0 available'), findsOneWidget);
    final context = tester.element(find.byType(AppUpdatesTile));
    await tester.tap(find.text('App updates'));
    // Exercise the navigation guard directly: the first route now covers the row.
    await AppUpdatesNavigation.open(context, service: svc);
    await tester.pumpAndSettle();
    expect(find.byType(UpdateScreen), findsOneWidget);
    expect(find.text('Update available'), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();
  });

  testWidgets('dismissal is release-aware, not reset by same-release checks',
      (tester) async {
    var build = 900;
    final svc = service(
        () async => ApiResponse(success: true, data: config(build: build)));
    await svc.check();
    await tester.pumpWidget(home(svc));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Dismiss update reminder'));
    await tester.pumpAndSettle();
    await svc.check();
    await tester.pumpAndSettle();
    expect(find.byTooltip('Dismiss update reminder'), findsNothing);
    build = 901;
    await svc.check();
    await tester.pumpAndSettle();
    expect(find.byTooltip('Dismiss update reminder'), findsOneWidget);
  });

  testWidgets('unknown, failed and current are distinct neutral-page states',
      (tester) async {
    var fail = true;
    final svc = service(() async => fail
        ? ApiResponse(success: false)
        : ApiResponse(success: true, data: config(current: true)));
    await tester.pumpWidget(MaterialApp(home: UpdateScreen(service: svc)));
    expect(find.text('Check for FKSS updates'), findsOneWidget);
    expect(find.text('You’re up to date'), findsNothing);
    await svc.check();
    await tester.pump();
    expect(find.text('Couldn’t check for updates'), findsOneWidget);
    expect(find.text('You’re up to date'), findsNothing);
    fail = false;
    await svc.check();
    await tester.pump();
    expect(find.text('You’re up to date'), findsOneWidget);
  });

  testWidgets('required policy removes back action and prevents route popping',
      (tester) async {
    final svc = service(
        () async => ApiResponse(success: true, data: config(required: true)));
    await svc.check();
    await tester.pumpWidget(MaterialApp(home: UpdateScreen(service: svc)));
    expect(find.text('Update required to continue'), findsOneWidget);
    expect(find.byType(BackButton), findsNothing);
    expect(tester.widget<PopScope>(find.byType(PopScope)).canPop, isFalse);
  });

  testWidgets('banner and permanent row have labeled Android-sized tap targets',
      (tester) async {
    final svc = service(() async => ApiResponse(success: true, data: config()));
    await svc.check();
    final handle = tester.ensureSemantics();
    await tester.pumpWidget(home(svc));
    await tester.pumpAndSettle();
    await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
    await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
    handle.dispose();
  });
  testWidgets('detail page tolerates a small screen and 200 percent text',
      (tester) async {
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final svc = service(() async => ApiResponse(success: true, data: config()));
    await svc.check();
    await tester.pumpWidget(MaterialApp(
      theme: AppTheme.lightTheme,
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(context)
            .copyWith(textScaler: const TextScaler.linear(2)),
        child: child!,
      ),
      home: UpdateScreen(service: svc),
    ));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.text('Update available'), findsOneWidget);
  });
}
