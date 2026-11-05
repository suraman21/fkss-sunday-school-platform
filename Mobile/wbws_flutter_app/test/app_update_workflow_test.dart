import 'dart:async';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/app_update_service.dart';
import 'package:fkss_app/utils/config.dart';

const updater = MethodChannel('fkss.app/updater');
final payload = List<int>.generate(1024, (i) => i % 251);
String get digest => sha256.convert(payload).toString();

Map<String, dynamic> metadata({
  String version = '9.0.0',
  int build = 900,
  String? sha,
}) =>
    {
      'latest_version': version,
      'latest_build': build,
      'min_version': '0.0.0',
      'min_build': 0,
      'force_update': false,
      'download_available': true,
      'apk_sha256': sha ?? digest,
      'apk_size_bytes': payload.length,
    };

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  late Directory directory;
  late List<String> nativeCalls;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    directory = await Directory.systemTemp.createTemp('fkss-update-test-');
    nativeCalls = [];
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(updater, (call) async {
      nativeCalls.add(call.method);
      switch (call.method) {
        case 'updateDir':
          return directory.path;
        case 'canRequestPackageInstalls':
          return true;
        case 'installApk':
          return true;
        case 'openInstallPermissionSettings':
          return true;
      }
      return null;
    });
  });

  tearDown(() async {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(updater, null);
    await directory.delete(recursive: true);
  });

  AppUpdateService service({
    Future<ApiResponse> Function()? fetch,
    http.Client Function()? client,
  }) =>
      AppUpdateService.forTesting(
        fetchConfig:
            fetch ?? () async => ApiResponse(success: true, data: metadata()),
        clientFactory: client ??
            () => MockClient((_) async => http.Response.bytes(
                  payload,
                  200,
                  headers: {'x-app-sha256': digest},
                )),
      );

  test('unknown is not up to date; successful current check is', () async {
    final svc = service(
        fetch: () async => ApiResponse(
            success: true,
            data: metadata(
              version: AppConfig.appVersion,
              build: AppConfig.appBuild,
            )));
    expect(svc.statusLabel, 'Check for updates');
    await svc.check();
    expect(svc.statusLabel, 'Up to date');
    expect(svc.lastSuccessfulCheck, isNotNull);
    expect(svc.checkFailed, isFalse);
  });

  test('check failures never imply up to date or update freshness', () async {
    var fail = false;
    final svc = service(
        fetch: () async => fail
            ? ApiResponse(success: false)
            : ApiResponse(success: true, data: metadata()));
    await svc.check();
    final checked = svc.lastSuccessfulCheck;
    final cfg = svc.config;
    fail = true;
    await svc.check();
    expect(svc.checkFailed, isTrue);
    expect(svc.config, same(cfg));
    expect(svc.lastSuccessfulCheck, checked);
    expect(svc.statusLabel, 'Update available · Could not refresh status');
    expect(svc.isChecking, isFalse);
    expect(svc.backgroundDrainsEnabled, isTrue);
  });

  test('initial failure has no fabricated successful timestamp', () async {
    final svc =
        service(fetch: () async => throw const SocketException('private URL'));
    await svc.check();
    expect(svc.lastSuccessfulCheck, isNull);
    expect(svc.statusLabel, 'Could not check · Try again');
    expect(svc.revision.value, 0);
  });

  test(
      'simultaneous metadata checks coalesce and release gates before revision',
      () async {
    final response = Completer<ApiResponse>();
    var requests = 0;
    final svc = service(fetch: () {
      requests++;
      return response.future;
    });
    var gateAtRevision = false;
    svc.revision
        .addListener(() => gateAtRevision = svc.backgroundDrainsEnabled);
    final first = svc.check();
    final second = svc.check();
    expect(identical(first, second), isTrue);
    expect(svc.isChecking, isTrue);
    expect(svc.backgroundDrainsEnabled, isFalse);
    response.complete(ApiResponse(success: true, data: metadata()));
    await first;
    expect(requests, 1);
    expect(gateAtRevision, isTrue);
  });

  test('two entry points share one download and do not launch installer',
      () async {
    final response = Completer<http.Response>();
    var requests = 0;
    final svc = service(
        client: () => MockClient((_) {
              requests++;
              return response.future;
            }));
    await svc.check();
    final first = svc.downloadApk();
    final second = svc.downloadApk();
    expect(identical(first, second), isTrue);
    expect(svc.downloadPhase, AppDownloadPhase.downloading);
    response.complete(
        http.Response.bytes(payload, 200, headers: {'x-app-sha256': digest}));
    final path = await first;
    expect(requests, 1);
    expect(await File(path).readAsBytes(), payload);
    expect(svc.hasReadyDownload, isTrue);
    expect(svc.downloadProgress, 1.0);
    expect(nativeCalls, isNot(contains('installApk')));
    await svc.installReadyApk();
    expect(nativeCalls.where((c) => c == 'installApk').length, 1);
    expect(svc.hasReadyDownload, isTrue);
    expect(svc.installMessage, contains('Finish in Android’s installer'));
  });

  test('missing and wrong digests never produce a ready APK', () async {
    for (final sha in ['', '0' * 64]) {
      final svc = service(
        fetch: () async => ApiResponse(success: true, data: metadata(sha: sha)),
        client: () =>
            MockClient((_) async => http.Response.bytes(payload, 200)),
      );
      await svc.check();
      await expectLater(svc.downloadApk(), throwsA(isA<AppUpdateFailure>()));
      expect(svc.hasReadyDownload, isFalse);
      expect(svc.downloadPhase, AppDownloadPhase.failed);
      expect(await File('${directory.path}/fkss.apk').exists(), isFalse);
    }
  });

  test('served universal fallback uses its digest, not a different ABI size',
      () async {
    final svc = service(
      fetch: () async => ApiResponse(success: true, data: {
        ...metadata(sha: '0' * 64),
        'apk_size_bytes': 7,
      }),
    );
    await svc.check();
    await svc.downloadApk();
    expect(svc.hasReadyDownload, isTrue);
    expect(svc.receivedBytes, payload.length);
  });

  test('transport exception is safe and retry is explicit restart', () async {
    var fail = true;
    final svc = service(
        client: () => MockClient((_) async {
              if (fail) {
                throw const SocketException('https://secret.invalid/token');
              }
              return http.Response.bytes(payload, 200,
                  headers: {'x-app-sha256': digest});
            }));
    await svc.check();
    await expectLater(svc.downloadApk(), throwsA(isA<AppUpdateFailure>()));
    expect(svc.downloadError, contains('download again'));
    expect(svc.downloadError, isNot(contains('secret.invalid')));
    fail = false;
    await svc.downloadApk();
    expect(svc.hasReadyDownload, isTrue);
    expect(svc.downloadError, isNull);
  });

  test('changed or missing ready file disables installation', () async {
    final svc = service();
    await svc.check();
    final path = await svc.downloadApk();
    await File(path).writeAsBytes(List.filled(payload.length, 0));
    await svc.installReadyApk();
    expect(nativeCalls, isNot(contains('installApk')));
    expect(svc.hasReadyDownload, isFalse);
    expect(svc.downloadError, contains('verified'));
  });

  test('a different release cannot reuse an older ready artifact', () async {
    var build = 900;
    final svc = service(
        fetch: () async =>
            ApiResponse(success: true, data: metadata(build: build)));
    await svc.check();
    await svc.downloadApk();
    expect(svc.hasReadyDownload, isTrue);
    build = 901;
    await svc.check();
    expect(svc.hasReadyDownload, isFalse);
    await svc.installReadyApk();
    expect(nativeCalls, isNot(contains('installApk')));
  });

  test('permission is rechecked, not assumed granted after settings opens',
      () async {
    var permitted = false;
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(updater, (call) async {
      nativeCalls.add(call.method);
      switch (call.method) {
        case 'updateDir':
          return directory.path;
        case 'canRequestPackageInstalls':
          return permitted;
        default:
          return true;
      }
    });
    final svc = service();
    await svc.check();
    await svc.downloadApk();
    await svc.installReadyApk();
    expect(nativeCalls, contains('openInstallPermissionSettings'));
    expect(nativeCalls, isNot(contains('installApk')));
    expect(svc.hasReadyDownload, isTrue);
    permitted = true;
    await svc.installReadyApk();
    expect(nativeCalls, contains('installApk'));
  });

  test('release change during transfer retains identity and rejects old bytes',
      () async {
    var build = 900;
    final response = Completer<http.Response>();
    final svc = service(
      fetch: () async =>
          ApiResponse(success: true, data: metadata(build: build)),
      client: () => MockClient((_) => response.future),
    );
    await svc.check();
    final downloading = svc.downloadApk();
    build = 901;
    await svc.check();
    expect(svc.transferConfig!.latestBuild, 900);
    expect(svc.config!.latestBuild, 901);
    expect(svc.downloadTargetsCurrentRelease, isFalse);
    final rejected = expectLater(downloading, throwsA(isA<AppUpdateFailure>()));
    response.complete(
        http.Response.bytes(payload, 200, headers: {'x-app-sha256': digest}));
    await rejected;
    expect(svc.hasReadyDownload, isFalse);
    expect(svc.downloadError, contains('different release'));
    expect(await directory.list().toList(), isEmpty);
  });

  test('opening settings failure retains the verified APK', () async {
    final svc = service();
    await svc.check();
    await svc.downloadApk();
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(updater, (call) async {
      if (call.method == 'canRequestPackageInstalls') return false;
      throw PlatformException(code: 'settings_unavailable');
    });
    await svc.installReadyApk();
    expect(svc.hasReadyDownload, isTrue);
    expect(svc.installMessage,
        contains('Could not open Android install settings'));
  });

  test('connection closing after bytes arrive leaves no installable partial',
      () async {
    final svc = service(client: () => BrokenDownloadClient());
    await svc.check();
    await expectLater(svc.downloadApk(), throwsA(isA<AppUpdateFailure>()));
    expect(svc.hasReadyDownload, isFalse);
    expect(svc.downloadError, contains('Connection interrupted'));
    expect(await directory.list().toList(), isEmpty);
  });

  test('malformed version metadata is not shown as up to date', () async {
    final svc = service(
        fetch: () async => ApiResponse(
            success: true, data: metadata(version: 'not-a-version', build: 0)));
    await svc.check();
    expect(svc.statusLabel, 'Version information unavailable');
  });

  test('truncated response is rejected and partial file removed', () async {
    final svc = service(client: () => TruncatedDownloadClient());
    await svc.check();
    await expectLater(svc.downloadApk(), throwsA(isA<AppUpdateFailure>()));
    expect(svc.hasReadyDownload, isFalse);
    expect(svc.downloadPhase, AppDownloadPhase.failed);
    expect(await File('${directory.path}/fkss.apk').exists(), isFalse);
  });

  test('permission-channel failure is not treated as permission granted',
      () async {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(updater, (call) async {
      if (call.method == 'canRequestPackageInstalls') {
        throw PlatformException(code: 'unavailable');
      }
      return null;
    });
    expect(await service().canRequestPackageInstalls(), isFalse);
  });
}

class TruncatedDownloadClient extends http.BaseClient {
  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async =>
      http.StreamedResponse(Stream.value(payload.sublist(0, 10)), 200,
          contentLength: payload.length, headers: {'x-app-sha256': digest});
}

class BrokenDownloadClient extends http.BaseClient {
  Stream<List<int>> chunks() async* {
    yield payload.sublist(0, 10);
    throw const SocketException('connection closed with private diagnostic');
  }

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async =>
      http.StreamedResponse(chunks(), 200,
          contentLength: payload.length, headers: {'x-app-sha256': digest});
}
