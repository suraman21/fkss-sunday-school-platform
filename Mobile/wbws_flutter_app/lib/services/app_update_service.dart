import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../utils/config.dart';
import '../utils/version.dart';
import 'api_service.dart';
import 'device_tier_service.dart';
import 'telemetry_service.dart';

/// P65 — metadata of one downloadable APK artifact
/// (universal, arm64-v8a or armeabi-v7a).
class ApkArtifact {
  final int sizeBytes;
  final String sha256;

  const ApkArtifact({required this.sizeBytes, required this.sha256});
}

class AppRemoteConfig {
  final String latestVersion;
  final int latestBuild;
  final String minVersion;
  final int minBuild;
  final bool forceFlag;
  final String releaseNotes;
  final bool downloadAvailable;
  final int apkSizeBytes;
  final String apkSha256;

  /// P65: per-ABI artifacts advertised by the server. Keys: `universal`,
  /// `arm64-v8a`, `armeabi-v7a`. Empty on servers that only publish the
  /// universal build — the legacy [apkSha256] / [apkSizeBytes] fields
  /// then remain the source of truth.
  final Map<String, ApkArtifact> apkArtifacts;
  final String? bannerText;
  final String? bannerKind;
  final Map<String, bool> features;
  final Map<String, List<String>> tiles;

  const AppRemoteConfig({
    required this.latestVersion,
    required this.latestBuild,
    required this.minVersion,
    required this.minBuild,
    required this.forceFlag,
    required this.releaseNotes,
    required this.downloadAvailable,
    required this.apkSizeBytes,
    required this.apkSha256,
    this.apkArtifacts = const {},
    this.bannerText,
    this.bannerKind,
    this.features = const {},
    this.tiles = const {},
  });

  factory AppRemoteConfig.fromJson(Map<String, dynamic> json) {
    final banner = json['banner'];
    final featureRaw = json['features'];
    final features = <String, bool>{};
    if (featureRaw is Map) {
      featureRaw.forEach((k, v) {
        if (v is bool) features[k.toString()] = v;
      });
    }
    final tilesRaw = json['tiles'];
    final tiles = <String, List<String>>{};
    if (tilesRaw is Map) {
      tilesRaw.forEach((k, v) {
        if (v is List) {
          tiles[k.toString()] = v.map((e) => e.toString()).toList();
        }
      });
    }
    // P65: optional per-ABI artifact map from the server
    // {"apk_artifacts": {"arm64-v8a": {"size_bytes": n, "sha256": s}}}.
    // Unknown/absent → empty map; the legacy fields stay authoritative.
    final artifactsRaw = json['apk_artifacts'];
    final artifacts = <String, ApkArtifact>{};
    if (artifactsRaw is Map) {
      artifactsRaw.forEach((k, v) {
        if (v is Map) {
          final sha = '${v['sha256'] ?? ''}'.toLowerCase();
          final size = int.tryParse('${v['size_bytes'] ?? 0}') ?? 0;
          if (sha.isNotEmpty) {
            artifacts[k.toString()] = ApkArtifact(sizeBytes: size, sha256: sha);
          }
        }
      });
    }
    return AppRemoteConfig(
      latestVersion: '${json['latest_version'] ?? ''}',
      latestBuild: int.tryParse('${json['latest_build'] ?? 0}') ?? 0,
      minVersion: '${json['min_version'] ?? ''}',
      minBuild: int.tryParse('${json['min_build'] ?? 0}') ?? 0,
      forceFlag: json['force_update'] == true,
      releaseNotes: '${json['release_notes'] ?? ''}',
      downloadAvailable: json['download_available'] == true,
      apkSizeBytes: int.tryParse('${json['apk_size_bytes'] ?? 0}') ?? 0,
      apkSha256: '${json['apk_sha256'] ?? ''}'.toLowerCase(),
      apkArtifacts: artifacts,
      bannerText: banner is Map ? '${banner['text'] ?? ''}' : null,
      bannerKind: banner is Map ? '${banner['kind'] ?? 'info'}' : null,
      features: features,
      tiles: tiles,
    );
  }
}

/// Checks the server for a newer app. Downloads live on Android only.
enum AppDownloadPhase { idle, downloading, verifying, ready, failed }

/// Safe user-facing failure; never display raw transport paths or URLs.
class AppUpdateFailure implements Exception {
  final String message;
  const AppUpdateFailure(this.message);
  @override
  String toString() => message;
}

class AppUpdateService extends ChangeNotifier {
  static final AppUpdateService _instance = AppUpdateService._internal();
  factory AppUpdateService() => _instance;
  AppUpdateService._internal({
    Future<ApiResponse> Function()? fetchConfig,
    http.Client Function()? clientFactory,
    bool? android,
  })  : _fetchConfig = fetchConfig,
        _clientFactory = clientFactory,
        _android = android;

  @visibleForTesting
  factory AppUpdateService.forTesting({
    required Future<ApiResponse> Function() fetchConfig,
    required http.Client Function() clientFactory,
  }) =>
      AppUpdateService._internal(
        fetchConfig: fetchConfig,
        clientFactory: clientFactory,
        android: true,
      );

  final Future<ApiResponse> Function()? _fetchConfig;
  final http.Client Function()? _clientFactory;
  final bool? _android;
  bool isChecking = false;
  bool checkFailed = false;
  DateTime? lastSuccessfulCheck;
  AppDownloadPhase downloadPhase = AppDownloadPhase.idle;
  int receivedBytes = 0;
  int totalBytes = 0;
  String? downloadError;
  String? installMessage;
  bool isOpeningInstaller = false;
  String? _readyPath;
  String? _readySha;
  int? _readySize;
  String? _downloadReleaseKey;
  AppRemoteConfig? _transferConfig;
  AppRemoteConfig? get transferConfig => _transferConfig;
  bool get downloadTargetsCurrentRelease => _downloadReleaseKey == releaseKey;
  Future<String>? _downloadFuture;
  Future<void>? _installFuture;

  String? get releaseKey {
    final value = config;
    if (value == null) return null;
    final keys = value.apkArtifacts.keys.toList()..sort();
    return '${value.latestVersion}|${value.latestBuild}|${value.apkSha256}|'
        '${keys.map((key) => '$key:${value.apkArtifacts[key]!.sha256}').join('|')}';
  }

  bool get hasVersionInformation =>
      config != null &&
      RegExp(r'^\d+\.\d+\.\d+(?:\+\d+)?$')
          .hasMatch(config!.latestVersion.trim()) &&
      !AppVersion.parse(config!.latestVersion, config!.latestBuild).isZero;

  bool get isDownloading =>
      downloadPhase == AppDownloadPhase.downloading ||
      downloadPhase == AppDownloadPhase.verifying;
  bool get hasReadyDownload =>
      downloadPhase == AppDownloadPhase.ready &&
      _readyPath != null &&
      _downloadReleaseKey == releaseKey;
  double? get downloadProgress => totalBytes > 0
      ? (receivedBytes / totalBytes).clamp(0.0, 1.0).toDouble()
      : null;

  String get statusLabel {
    if (isDownloading) {
      if (downloadPhase == AppDownloadPhase.verifying) {
        return 'Checking downloaded file…';
      }
      final p = downloadProgress;
      return p == null
          ? 'Downloading update…'
          : 'Downloading · ${(p * 100).floor()}%';
    }
    if (hasReadyDownload) return 'Ready to install';
    if (isChecking) return 'Checking for updates…';
    if (decision.force) return 'Update required · View details';
    if (downloadPhase == AppDownloadPhase.failed && decision.any) {
      return 'Download failed · Try again';
    }
    if (decision.optional) {
      return checkFailed
          ? 'Update available · Could not refresh status'
          : 'Version ${config!.latestVersion} available';
    }
    if (checkFailed) return 'Could not check · Try again';
    if (lastSuccessfulCheck == null) return 'Check for updates';
    if (!hasVersionInformation) return 'Version information unavailable';
    return 'Up to date';
  }

  static const _channel = MethodChannel('fkss.app/updater');

  static const _featureCacheKey = 'fkss_remote_features_v1';
  static const _tileCacheKey = 'fkss_remote_tiles_v1';
  static const backgroundDrainFeature = 'background_outbox_drain';

  AppRemoteConfig? config;
  UpdateDecision decision = const UpdateDecision(force: false, optional: false);

  /// Bumped after successful checks, once release gates can be reconciled. Lets small widgets (the
  /// optional-update banner) rebuild on fresh results the moment a check
  /// lands — without the whole shell rebuilding (that visibly hitched
  /// the kept-alive tabs).
  final ValueNotifier<int> revision = ValueNotifier<int>(0);
  Map<String, bool> _cachedFeatures = const {};
  Map<String, List<String>> _cachedTiles = const {};
  Future<void>? _cacheFuture;
  Future<void>? _checkFuture;
  bool _releaseCheckCompleted = false;

  final _progress = StreamController<double>.broadcast();
  Stream<double> get progressStream => _progress.stream;

  /// Loads release metadata once at a time. Outbound drains remain held until
  /// the first launch check finishes, so an already-published emergency pause
  /// is observed before account-scoped workers claim more rows. On an outage,
  /// the durable cached setting wins; a device with no cache keeps the legacy
  /// enabled default so an old server cannot permanently strand its queue.
  Future<void> check() {
    final existing = _checkFuture;
    if (existing != null) return existing;
    // Every launch/resume check creates a short no-new-claims window. Existing
    // in-flight requests still settle by exact identity; workers resume only
    // after fresh config (or the durable fallback) has been selected.
    _releaseCheckCompleted = false;
    isChecking = true;
    late final Future<void> future;
    future = _checkOnce().whenComplete(() {
      if (identical(_checkFuture, future)) _checkFuture = null;
    });
    _checkFuture = future;
    notifyListeners();
    return future;
  }

  Future<void> _checkOnce() async {
    var succeeded = false;
    try {
      await _loadCapabilityCache();
      final res = await (_fetchConfig?.call() ??
          ApiService().get('/app/config', auth: false));
      if (!res.success || res.data is! Map) return;
      final data = res.data is Map<String, dynamic>
          ? res.data as Map<String, dynamic>
          : Map<String, dynamic>.from(res.data);
      config = AppRemoteConfig.fromJson(data);
      await _saveCapabilityCache(config!);
      decision = decideUpdate(
        currentVersion: AppConfig.appVersion,
        currentBuild: AppConfig.appBuild,
        latestVersion: config!.latestVersion,
        latestBuild: config!.latestBuild,
        minVersion: config!.minVersion,
        minBuild: config!.minBuild,
        forceFlag: config!.forceFlag,
      );
      lastSuccessfulCheck = DateTime.now();
      succeeded = true;
    } catch (_) {
      // Stay on the current app if the check fails — do not lock teachers out.
    } finally {
      _releaseCheckCompleted = true;
      isChecking = false;
      checkFailed = !succeeded;
      if (succeeded) revision.value++;
      notifyListeners();
    }
  }

  /// False during the cold-start release check. Afterwards the server value,
  /// then its durable cache, then the old-server-compatible default is used.
  bool get backgroundDrainsEnabled =>
      _releaseCheckCompleted && featureEnabled(backgroundDrainFeature);

  Future<void> _loadCapabilityCache() {
    return _cacheFuture ??= _readCapabilityCache();
  }

  Future<void> _readCapabilityCache() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final featureJson = prefs.getString(_featureCacheKey);
      final tileJson = prefs.getString(_tileCacheKey);
      if (featureJson != null) {
        final raw = jsonDecode(featureJson);
        if (raw is Map) {
          _cachedFeatures = {
            for (final entry in raw.entries)
              if (entry.value is bool)
                entry.key.toString(): entry.value as bool,
          };
        }
      }
      if (tileJson != null) {
        final raw = jsonDecode(tileJson);
        if (raw is Map) {
          _cachedTiles = {
            for (final entry in raw.entries)
              if (entry.value is List)
                entry.key.toString(): (entry.value as List)
                    .map((item) => item.toString())
                    .toList(growable: false),
          };
        }
      }
    } catch (_) {
      _cachedFeatures = const {};
      _cachedTiles = const {};
    }
  }

  Future<void> _saveCapabilityCache(AppRemoteConfig value) async {
    _cachedFeatures = Map<String, bool>.from(value.features);
    _cachedTiles = {
      for (final entry in value.tiles.entries)
        entry.key: List<String>.from(entry.value),
    };
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_featureCacheKey, jsonEncode(_cachedFeatures));
      await prefs.setString(_tileCacheKey, jsonEncode(_cachedTiles));
    } catch (_) {
      // Server enforcement remains authoritative if local preferences fail.
    }
  }

  bool featureEnabled(String feature, {bool fallback = true}) {
    return config?.features[feature] ?? _cachedFeatures[feature] ?? fallback;
  }

  List<String> tilesFor(String area, List<String> fallback) {
    final list = config?.tiles[area] ?? _cachedTiles[area];
    if (list == null) return fallback;
    return list;
  }

  bool get canDownload =>
      (_android ?? (!kIsWeb && Platform.isAndroid)) &&
      (config?.downloadAvailable ?? false) &&
      decision.any;

  /// P65 — which artifact the config promises for [abi]:
  /// the per-ABI entry when published, else `universal`, else the legacy
  /// single-file fields. Pure; pinned by test.
  static ApkArtifact? artifactFor(AppRemoteConfig? config, String? abi) {
    if (config == null) return null;
    if (abi != null && abi.isNotEmpty) {
      final hit = config.apkArtifacts[abi];
      if (hit != null) return hit;
    }
    final universal = config.apkArtifacts['universal'];
    if (universal != null) return universal;
    if (config.apkSha256.isNotEmpty) {
      return ApkArtifact(
        sizeBytes: config.apkSizeBytes,
        sha256: config.apkSha256,
      );
    }
    return null;
  }

  static bool _isSha256(String s) =>
      s.length == 64 && RegExp(r'^[0-9a-f]{64}$').hasMatch(s);

  /// All entry points share one operation. Leaving a route does not cancel it.
  /// This batch retries from zero; it does NOT claim durable/background resume.
  Future<String> downloadApk({void Function(double p)? onProgress}) {
    final existing = _downloadFuture;
    if (existing != null) return existing;
    if (isOpeningInstaller) {
      return Future.error(const AppUpdateFailure(
          'Finish opening installation before starting another download.'));
    }
    if (!canDownload) {
      return Future.error(
          const AppUpdateFailure('No Android update download is available.'));
    }
    downloadPhase = AppDownloadPhase.downloading;
    receivedBytes = 0;
    totalBytes = 0;
    downloadError = null;
    installMessage = null;
    _readyPath = null;
    _readySha = null;
    _readySize = null;
    final snapshot = config!;
    _transferConfig = snapshot;
    _downloadReleaseKey = releaseKey;
    late final Future<String> future;
    future = _download(snapshot, onProgress).whenComplete(() {
      if (identical(_downloadFuture, future)) _downloadFuture = null;
    });
    _downloadFuture = future;
    notifyListeners();
    return future;
  }

  void _requireCurrentDownload() {
    if (!downloadTargetsCurrentRelease) {
      throw const AppUpdateFailure(
          'A different release is now available. Download the latest update.');
    }
  }

  Future<void> _verifyFile(File file, String expected, int? size) async {
    if (!await file.exists() || (size != null && await file.length() != size)) {
      throw const AppUpdateFailure(
          'The downloaded file is incomplete. Download it again.');
    }
    // Streaming verification keeps large APKs out of the Dart heap.
    final digest = await sha256.bind(file.openRead()).first;
    if (digest.toString() != expected) {
      throw const AppUpdateFailure(
          'The downloaded file could not be verified. Download it again.');
    }
  }

  Future<String> _download(
      AppRemoteConfig snapshot, void Function(double)? onProgress) async {
    File? file;
    final client = _clientFactory?.call() ?? http.Client();
    try {
      final dir = await _channel.invokeMethod<String>('updateDir');
      if (dir == null || dir.isEmpty) {
        throw const AppUpdateFailure(
            'Could not prepare the download folder. Try again.');
      }
      file = File('$dir/fkss.apk');
      if (await file.exists()) await file.delete();
      final abi = DeviceTierService.instance.info?.primaryAbi;
      final artifact = artifactFor(snapshot, abi);
      var uri = Uri.parse('${AppConfig.apiBaseUrl}/app/download');
      if (abi != null && abi.isNotEmpty) {
        uri = uri.replace(queryParameters: {'abi': abi});
      }
      final response = await client
          .send(http.Request('GET', uri))
          .timeout(const Duration(seconds: 45));
      if (response.statusCode != 200) {
        throw const AppUpdateFailure(
            'The update could not be downloaded. Try again later.');
      }
      // Preserve served-artifact fallback, but never install without a digest.
      final header =
          (response.headers['x-app-sha256'] ?? '').trim().toLowerCase();
      final expected = _isSha256(header) ? header : artifact?.sha256 ?? '';
      if (!_isSha256(expected)) {
        throw const AppUpdateFailure(
            'Update verification information is missing. Contact the administrator.');
      }
      final expectedSize = response.contentLength ??
          (artifact?.sha256 == expected && (artifact?.sizeBytes ?? 0) > 0
              ? artifact!.sizeBytes
              : null);
      totalBytes = expectedSize ?? 0;
      var lastNotification = DateTime.now();
      final sink = await file.open(mode: FileMode.write);
      try {
        await for (final chunk
            in response.stream.timeout(const Duration(seconds: 45))) {
          _requireCurrentDownload();
          receivedBytes += chunk.length;
          if (expectedSize != null && receivedBytes > expectedSize) {
            throw const AppUpdateFailure(
                'The downloaded file has an unexpected size. Try again.');
          }
          await sink.writeFrom(chunk);
          final p = downloadProgress ?? 0.0;
          _progress.add(p);
          onProgress?.call(p);
          final now = DateTime.now();
          if (now.difference(lastNotification).inMilliseconds >= 200) {
            lastNotification = now;
            notifyListeners();
          }
        }
      } finally {
        await sink.close();
      }
      _requireCurrentDownload();
      downloadPhase = AppDownloadPhase.verifying;
      notifyListeners();
      await _verifyFile(file, expected, expectedSize);
      _requireCurrentDownload();
      _readyPath = file.path;
      _readySha = expected;
      _readySize = await file.length();
      downloadPhase = AppDownloadPhase.ready;
      notifyListeners();
      if (_android == null) {
        try {
          TelemetryService.instance.recordUpdateDownloaded(
            version: snapshot.latestVersion,
            build: snapshot.latestBuild,
          );
        } catch (_) {}
      }
      return file.path;
    } catch (error) {
      if (file != null) {
        try {
          if (await file.exists()) await file.delete();
        } catch (_) {}
      }
      downloadPhase = AppDownloadPhase.failed;
      downloadError = error is AppUpdateFailure
          ? error.message
          : error is FileSystemException
              ? 'Could not save the update. Check free storage and try again.'
              : 'Connection interrupted. Check your connection and download again.';
      notifyListeners();
      throw AppUpdateFailure(downloadError!);
    } finally {
      client.close();
    }
  }

  /// Opening the Android installer is not a successful APK replacement.
  Future<void> installReadyApk() {
    final existing = _installFuture;
    if (existing != null) return existing;
    late final Future<void> future;
    future = _installReady().whenComplete(() {
      if (identical(_installFuture, future)) _installFuture = null;
    });
    _installFuture = future;
    return future;
  }

  Future<void> _installReady() async {
    var fileVerified = false;
    isOpeningInstaller = true;
    installMessage = null;
    notifyListeners();
    try {
      if (!hasReadyDownload || _readySha == null) {
        throw const AppUpdateFailure(
            'Check for the latest update and download it again.');
      }
      await _verifyFile(File(_readyPath!), _readySha!, _readySize);
      fileVerified = true;
      if (!await canRequestPackageInstalls()) {
        await openInstallPermissionSettings();
        installMessage =
            'Allow installs from FKSS in Android settings, then return and tap Install update again.';
        return;
      }
      // Metadata might have changed while permission/file checks were awaited.
      if (!hasReadyDownload) {
        fileVerified = false;
        throw const AppUpdateFailure(
            'A different release is now available. Download the latest update.');
      }
      await installApk(_readyPath!);
      installMessage =
          'Finish in Android’s installer. If installation was canceled, the verified file is still ready.';
    } on AppUpdateFailure catch (error) {
      if (!fileVerified) {
        // Invalid/missing/stale artifacts must not retain an Install action.
        _readyPath = null;
        downloadPhase = AppDownloadPhase.failed;
        downloadError = error.message;
      } else {
        // A settings/permission failure must not discard a verified APK.
        installMessage = error.message;
      }
    } catch (_) {
      if (!fileVerified) {
        _readyPath = null;
        downloadPhase = AppDownloadPhase.failed;
        downloadError =
            'Could not read the downloaded file. Download it again.';
      } else {
        installMessage =
            'Could not open installation. Check Android install permission and try again.';
      }
    } finally {
      isOpeningInstaller = false;
      notifyListeners();
    }
  }

  Future<bool> canRequestPackageInstalls() async {
    if (!(_android ?? (!kIsWeb && Platform.isAndroid))) return true;
    try {
      final res =
          await _channel.invokeMethod<bool>('canRequestPackageInstalls');
      return res ?? false;
    } catch (_) {
      return false;
    }
  }

  Future<void> openInstallPermissionSettings() async {
    if (!(_android ?? (!kIsWeb && Platform.isAndroid))) return;
    try {
      await _channel.invokeMethod('openInstallPermissionSettings');
    } catch (_) {
      throw const AppUpdateFailure(
          'Could not open Android install settings. Check device settings and try again.');
    }
  }

  Future<void> installApk(String path) async {
    await _channel.invokeMethod('installApk', {'path': path});
  }
}
