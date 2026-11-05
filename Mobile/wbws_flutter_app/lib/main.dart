import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:just_audio_background/just_audio_background.dart';
import 'package:path/path.dart';
import 'package:sqflite/sqflite.dart' show getDatabasesPath;
import 'services/app_lock_service.dart';
import 'services/local_db.dart';
import 'services/interrupted_database_migration.dart';
import 'services/session_models.dart';
import 'services/session_service.dart';
import 'screens/lock/lock_screen.dart';
import 'services/background_sync_bridge.dart';
import 'services/android_background_sync_scheduler.dart';
import 'services/connectivity_service.dart';
import 'services/app_update_service.dart';
import 'services/device_tier_service.dart';
import 'services/telemetry_service.dart';
import 'services/crash_log_service.dart';
import 'services/app_navigator.dart';
import 'services/mezmur_download_manager.dart';
import 'services/lyrics_reader_settings.dart';
import 'utils/config.dart';
import 'utils/scrolling.dart';
import 'utils/theme.dart';
import 'screens/auth/login_screen.dart';
import 'screens/auth/session_recovery_screen.dart';
import 'screens/shell/app_shell.dart';
import 'screens/update/update_screen.dart';
import 'screens/mezmur/mezmur_mini_player_host.dart';
import 'screens/profile/sync_center_screen.dart';

// ---------------------------------------------------------------------------
// Startup watchdog (2026-10-20, "stuck on splash" fix)
//
// Everything `main()` awaits BEFORE `runApp` runs behind the native launch
// splash (`@drawable/launch_background`). If any of those awaits never
// completes — a plugin platform-channel reply that never arrives, a service
// binding that never connects, a storage open that blocks — Flutter never
// draws a first frame, nothing is thrown, nothing is logged, and the user sees
// the logo forever. The rules below make that impossible:
//
//   * every pre-runApp await is a named, time-boxed step;
//   * optional steps (background audio, orientation) are skipped on timeout;
//   * required steps (database, session, app lock) fall through to the
//     existing recovery screen with the step name in the detail line;
//   * an independent watchdog timer puts a diagnostic screen on screen if the
//     first `runApp` has still not happened — whatever the reason;
//   * every stall is appended to `fkss_bootstrap_error.log`, the same file the
//     native crash trap and Profile → App → Diagnostics use.
// ---------------------------------------------------------------------------

/// Name of the startup step currently in flight (for the stall screen/log).
String _startupStep = 'engine';

/// True once the first `runApp` of a real UI happened.
bool _startupUiShown = false;

Timer? _startupWatchdog;

/// Hard ceiling before the watchdog surfaces the stall screen. Generous
/// enough for first-launch database creation on a slow phone.
const Duration _startupWatchdogLimit = Duration(seconds: 40);

class StartupStallException implements Exception {
  final String step;
  final Duration limit;
  const StartupStallException(this.step, this.limit);
  @override
  String toString() =>
      'Startup step "$step" did not finish within ${limit.inSeconds}s';
}

/// Runs one named startup step with a time limit.
///
/// [tolerate] steps are optional: a timeout or error is logged and startup
/// continues. Required steps rethrow (a timeout as [StartupStallException]) so
/// `runBootstrap` shows the recovery screen with the step name.
Future<void> _startupStage(
  String name,
  Future<void> Function() body, {
  required Duration timeout,
  bool tolerate = false,
}) async {
  _startupStep = name;
  try {
    await body().timeout(timeout);
  } on TimeoutException {
    final error = StartupStallException(name, timeout);
    await _writeBootstrapLog(error, StackTrace.current);
    if (!tolerate) throw error;
  } catch (error, stack) {
    if (!tolerate) rethrow;
    await _writeBootstrapLog(error, stack);
  }
}

void _armStartupWatchdog() {
  _startupWatchdog?.cancel();
  _startupWatchdog = Timer(_startupWatchdogLimit, () {
    if (_startupUiShown) return;
    final step = _startupStep;
    _writeBootstrapLog(
      StartupStallException(step, _startupWatchdogLimit),
      StackTrace.current,
    );
    runApp(StartupStallApp(step: step));
  });
}

void _markStartupUiShown() {
  _startupUiShown = true;
  _startupWatchdog?.cancel();
  _startupWatchdog = null;
}

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  _armStartupWatchdog();

  // P0 background mezmur playback: register the single auto-managed
  // player channel BEFORE runApp so audio_service can connect. Kept
  // tolerant — on a platform without the plugin, or if the service binding
  // never answers, the app still starts (background audio is then
  // unavailable until the next launch).
  await _startupStage(
    'audio_service',
    () => JustAudioBackground.init(
      androidNotificationChannelId: 'com.arkeonethiopia.fkss.channel.audio',
      androidNotificationChannelName: 'መዝሙር · FKSS Mezmur',
      androidNotificationOngoing: true,
    ),
    timeout: const Duration(seconds: 10),
    tolerate: true,
  );

  await _startupStage(
    'orientation',
    () => SystemChrome.setPreferredOrientations([
      DeviceOrientation.portraitUp,
      DeviceOrientation.portraitDown,
    ]),
    timeout: const Duration(seconds: 5),
    tolerate: true,
  );

  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.dark,
      systemNavigationBarColor: AppTheme.bgLight,
      systemNavigationBarIconBrightness: Brightness.dark,
    ),
  );

  await runBootstrap();
}

/// Runs the startup sequence. Extracted so the recovery screen can retry
/// without restarting the process.
Future<void> runBootstrap() async {
  try {
    // SQLite opens/migrates before credentials are interpreted. The session
    // coordinator then reconciles the durable owner marker, protected
    // credentials and a single-snapshot local inventory. Each step is
    // time-boxed: a step that never answers becomes a visible
    // StartupStallException instead of an endless splash.
    await _startupStage(
      'local_database',
      () async {
        await LocalDb().database;
      },
      timeout: const Duration(seconds: 20),
    );
    await _startupStage(
      'session_bootstrap',
      () async {
        await SessionCoordinator().bootstrap();
      },
      timeout: const Duration(seconds: 25),
    );
    // App Lock protects active AND recoverable private state. The PIN is not
    // cleared by auth expiry or preserve-for-reauth logout.
    await _startupStage(
      'app_lock',
      () => AppLockService().lockAtColdStartIfConfigured(),
      timeout: const Duration(seconds: 10),
    );
  } catch (error, stack) {
    final bool isStalled = error is StartupStallException;
    final bool isRecovery = error is OfflineDatabaseRecoveryRequired;
    final detail = isStalled ? '$error' : await _writeBootstrapLog(error, stack);
    _markStartupUiShown();
    runApp(OfflineDataProtectionFailureApp(
      detail: detail,
      stalled: isStalled,
      migrationRecoveryRequired: isRecovery,
    ));
    return;
  }

  // S3 A.8 — install the Android background sync producer. This is the first
  // real producer of SyncExecutionSource.background: it replaces the
  // NoopBackgroundSyncScheduler with the AlarmManager-backed one and registers
  // the Dart entry point the OS calls back into. Deliberately AFTER the
  // bootstrap above, so the session is already reconciled before any wake-up
  // can be granted, and deliberately outside its try/catch: this must never be
  // able to stop the app from starting. It is a no-op on every non-Android
  // host.
  _startupStep = 'background_sync_producer';
  await installAndroidBackgroundSyncProducer();

  _startupStep = 'first_frame';
  _markStartupUiShown();
  runApp(const FKSSApp());

  WidgetsBinding.instance.addPostFrameCallback((_) {
    // Device-global services may start independently. Account-scoped workers
    // are started only by SessionCoordinator after active owner reconciliation.
    ConnectivityService().startMonitoring();
    LyricsReaderSettings.instance.boot();
    DeviceTierService.instance.boot().then((_) async {
      await TelemetryService.instance.boot();
      try {
        final recentCrash = await CrashLogService.instance.lastNativeCrash(
          within: const Duration(hours: 24),
        );
        if (recentCrash != null) {
          await TelemetryService.instance.recordCrash(
            crashKey: recentCrash.reportKey,
            nativeCrash: recentCrash.nativeCrash,
          );
          // The readable companion (class + first-party frames), sent
          // ungated: the server's exact-once key makes repeats no-ops and
          // backfills signatures for keys older builds already reported.
          await TelemetryService.instance.recordCrashSignature(recentCrash);
        }
      } catch (_) {}
    });
    if (SessionCoordinator().isActive) {
      Future<void>.delayed(const Duration(seconds: 2), () {
        if (SessionCoordinator().isActive) {
          MezmurDownloadManager.instance.boot();
        }
      });
    }
  });
}

/// Appends the real error to a log inside the app sandbox and returns a
/// short, screen-safe summary the user can send to the school administrator.
Future<String> _writeBootstrapLog(Object error, StackTrace stack) async {
  var summary = '$error';
  try {
    final dbPath = await getDatabasesPath();
    final logFile = File(join(dbPath, 'fkss_bootstrap_error.log'));
    await logFile.writeAsString(
      '=== ${DateTime.now().toIso8601String()} ===\n$error\n$stack\n',
      mode: FileMode.append,
      flush: true,
    );
  } catch (_) {}
  summary = summary.replaceAll('\n', ' ').trim();
  if (summary.length > 160) summary = '${summary.substring(0, 160)}…';
  return summary;
}

class OfflineDataProtectionFailureApp extends StatefulWidget {
  final String detail;

  /// True when a required startup step timed out (see [StartupStallException])
  /// rather than failed — the copy then talks about "starting" instead of
  /// "opening offline storage", because storage may be perfectly healthy.
  final bool stalled;
  // Kept constructor name also covers orphaned ordinary SQLite sidecars.
  final bool migrationRecoveryRequired;
  /// Defaults to the real bootstrap; injectable for isolated lifecycle tests.
  final Future<void> Function() retryBootstrap;
  const OfflineDataProtectionFailureApp({
    super.key,
    required this.detail,
    this.stalled = false,
    this.migrationRecoveryRequired = false,
    this.retryBootstrap = runBootstrap,
  });

  @override
  State<OfflineDataProtectionFailureApp> createState() =>
      _OfflineDataProtectionFailureAppState();
}

class _OfflineDataProtectionFailureAppState
    extends State<OfflineDataProtectionFailureApp> {
  bool _retrying = false;

  Future<void> _retry() async {
    if (_retrying) return;
    setState(() => _retrying = true);
    try {
      await widget.retryBootstrap(); // replaces this app on success
    } finally {
      // A handled failure can update this same State with a new error detail.
      // Success may instead dispose it; never update an unmounted screen.
      if (mounted) setState(() => _retrying = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'FKSS',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme,
      scrollBehavior: const SmoothScrollBehavior(),
      home: Scaffold(
        body: SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(32),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(
                    Icons.security_rounded,
                    size: 56,
                    color: AppTheme.primary,
                  ),
                  const SizedBox(height: 20),
                  Text(
                    widget.stalled
                        ? 'The app could not finish starting'
                        : 'Offline storage could not be opened',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    widget.migrationRecoveryRequired
                        ? 'Offline database files need review. '
                            'This attempt kept the database files unchanged. '
                            'Do not clear app data or reinstall. Send the detail '
                            'line below to the school administrator before '
                            'retrying recovery.'
                        : widget.stalled
                            ? 'One startup step on this phone did not answer in '
                                'time. Your data has NOT been deleted. Tap Retry; '
                                'if it continues, restart the phone and send the '
                                'detail line below to the school administrator '
                                '(Profile → App → Diagnostics also keeps it).'
                            : 'The app could not open its offline storage on this phone. '
                                'Your data has NOT been deleted. Free up some phone storage '
                                'and tap Retry. If it continues, send the detail line below '
                                'to the school administrator.',
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    onPressed: _retrying ? null : _retry,
                    icon: const Icon(Icons.refresh),
                    label: Text(_retrying ? 'Retrying…' : 'Retry'),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    widget.detail,
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Shown by the startup watchdog when `runApp` has still not happened after
/// [_startupWatchdogLimit]. It names the step that never answered so the
/// report from the field is actionable, and lets the user retry without
/// force-stopping the app. If the stuck step later completes, the normal
/// `runApp(FKSSApp)` simply replaces this screen.
class StartupStallApp extends StatefulWidget {
  final String step;
  const StartupStallApp({super.key, required this.step});

  @override
  State<StartupStallApp> createState() => _StartupStallAppState();
}

class _StartupStallAppState extends State<StartupStallApp> {
  bool _retrying = false;

  Future<void> _retry() async {
    setState(() => _retrying = true);
    // Skip the optional pre-bootstrap stages (audio/orientation): if one of
    // them is what hung, retrying it would hang again. runBootstrap owns the
    // required steps and their own timeouts.
    await runBootstrap(); // replaces this app on success
    if (mounted) setState(() => _retrying = false);
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme,
      scrollBehavior: const SmoothScrollBehavior(),
      home: Scaffold(
        body: SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(32),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.hourglass_bottom_rounded,
                      size: 56, color: AppTheme.primary),
                  const SizedBox(height: 20),
                  const Text(
                    'The app is taking too long to start',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 12),
                  const Text(
                    'A startup step on this phone did not answer. Your data '
                    'has NOT been deleted. Tap Retry; if it continues, '
                    'restart the phone and send the detail line below to the '
                    'school administrator.',
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    onPressed: _retrying ? null : _retry,
                    icon: const Icon(Icons.refresh),
                    label: Text(_retrying ? 'Retrying…' : 'Retry'),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    'Stuck at startup step: ${widget.step} · '
                    '${AppConfig.appName} ${AppConfig.appVersion}',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// A.12 — temporary Dart entry point used only by the Android cold-start
/// FlutterEngine. It intentionally does not call [runApp] or install the
/// foreground scheduler. The engine gets the same MethodChannel bridge, the
/// session coordinator reconciles durable credentials/generation, and the
/// native side then invokes the existing background route.
@pragma('vm:entry-point')
Future<void> backgroundSyncMain() async {
  WidgetsFlutterBinding.ensureInitialized();
  const channel = MethodChannel(BackgroundSyncChannel.name);
  BackgroundSyncBridge(channel: channel).install();

  var ready = false;
  try {
    await SessionCoordinator().bootstrap();
    ready = SessionCoordinator().isActive;
  } catch (_) {
    // A cold-start bootstrap failure is a failed opportunity, never evidence
    // that durable work succeeded. Native destroys the temporary engine
    // without invoking the sync path, leaving the outbox retryable.
    ready = false;
  }

  try {
    await channel.invokeMethod<void>(
      BackgroundSyncChannel.methodBackgroundReady,
      <String, Object?>{'ready': ready},
    );
  } catch (_) {
    // Native timeout/teardown owns the final lifecycle if the handshake cannot
    // be delivered. Do not create a second retry or scheduler here.
  }
}

class FKSSApp extends StatefulWidget {
  const FKSSApp({super.key});

  @override
  State<FKSSApp> createState() => _FKSSAppState();
}

class _FKSSAppState extends State<FKSSApp> {
  final _appLock = AppLockService();
  final _session = SessionCoordinator();
  bool _lastUpdateForce = AppUpdateService().decision.force;

  void _onReleaseChanged() {
    _session.reconcileReleaseGates();
    final force = AppUpdateService().decision.force;
    // Metadata/progress must not rebuild the entire kept-alive shell. Only a
    // change to the mandatory root policy requires this root rebuild.
    if (force != _lastUpdateForce) {
      _lastUpdateForce = force;
      if (mounted) setState(() {});
    }
  }

  @override
  void initState() {
    super.initState();
    _appLock.addListener(_onRootStateChanged);
    _session.addListener(_onRootStateChanged);
    AppUpdateService().revision.addListener(_onReleaseChanged);
    AppUpdateService().check().then((_) {
      _session.reconcileReleaseGates();
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _appLock.removeListener(_onRootStateChanged);
    _session.removeListener(_onRootStateChanged);
    AppUpdateService().revision.removeListener(_onReleaseChanged);
    super.dispose();
  }

  void _onRootStateChanged() {
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final update = AppUpdateService();

    Widget home;
    if (_appLock.isLocked && _session.protectsPrivateState) {
      // Passcode gate sits in front of active and recoverable private data.
      home = const LockScreen();
    } else if (update.decision.force) {
      home = const UpdateScreen(blocking: true);
    } else {
      switch (_session.root) {
        case SessionRoot.active:
          home = AppShell(key: ValueKey(_session.generation));
          break;
        case SessionRoot.reauthentication:
          home = const SessionRecoveryScreen();
          break;
        case SessionRoot.orphanRecovery:
          home = const OrphanedDataRecoveryScreen();
          break;
        case SessionRoot.scopeReconciling:
          home = const ScopeReconciliationScreen();
          break;
        case SessionRoot.purging:
          home = const PurgingSessionScreen();
          break;
        case SessionRoot.protectionFailure:
          home = const SessionProtectionFailureScreen();
          break;
        case SessionRoot.cleanLogin:
          home = const LoginScreen();
          break;
      }
    }

    return MaterialApp(
      title: 'FKSS',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme,
      darkTheme: AppTheme.darkTheme,
      themeMode: ThemeMode.light,
      scrollBehavior: const SmoothScrollBehavior(),
      // P35: the mini player is mounted above the Navigator, so
      // Navigator.of(its context) finds no ancestor. This key lets it
      // push the full player on the root navigator.
      navigatorKey: AppNavigator.key,
      // P34: the now-playing bar is mounted ABOVE the Navigator so it
      // follows the user across every pushed route and consumes real
      // layout height (never covers FABs or save bars).
      builder: (context, navigator) =>
          MezmurMiniPlayerHost(child: navigator ?? const SizedBox.shrink()),
      routes: {
        '/sync-center': (_) => const SyncCenterScreen(),
      },
      home: home,
    );
  }
}
