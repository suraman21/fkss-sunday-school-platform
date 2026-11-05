import 'package:flutter/foundation.dart';
import 'package:flutter/painting.dart';
import 'package:flutter/services.dart';

/// P65 — the device capability tier (the Telegram model: the hardware
/// class is a first-class input to how the app behaves).
///
/// One tiny method channel (`fkss.app/device`, read-only) supplies the
/// ABI, RAM and OS snapshot; the tier then scales heavyweight behaviour
/// to the hardware. Today's consumer is the image cache (a 1GB Android Go
/// phone must not let a 100MB default bitmap cache push it into
/// low-memory kills). Future consumers (animation weight, prefetch
/// counts, artwork resolution) read [tier] without knowing how it was
/// computed — the thresholds live in exactly one pure function,
/// [tierFor], pinned by test.
///
/// Best-effort read-only capability probe: starts at MID with null info.
/// Web skips the probe. Missing/failed channels preserve the last successful
/// snapshot and cache settings; before first success, defaults are unchanged.
enum DeviceTier { low, mid, high }

class DeviceInfoSnapshot {
  /// Primary ABI, e.g. `arm64-v8a` or `armeabi-v7a`. Empty when unknown.
  final String primaryAbi;

  /// Every ABI the device reports, best first.
  final List<String> abis;

  /// Total RAM in whole MB (0 when unknown).
  final int totalRamMb;

  /// The OS's own "1GB-class phone" flag (Android Go devices).
  final bool isLowRam;

  final int sdkInt;
  final String release;
  final String model;
  final String manufacturer;

  const DeviceInfoSnapshot({
    required this.primaryAbi,
    required this.abis,
    required this.totalRamMb,
    required this.isLowRam,
    required this.sdkInt,
    required this.release,
    required this.model,
    required this.manufacturer,
  });
}

class DeviceTierService {
  DeviceTierService._();

  static final DeviceTierService instance = DeviceTierService._();

  static const MethodChannel _channel = MethodChannel('fkss.app/device');

  /// Null until [boot] succeeds (or forever on web/unknown platforms).
  DeviceInfoSnapshot? info;

  DeviceTier _tier = DeviceTier.mid;
  int? _defaultCacheSize;
  int? _defaultCacheBytes;
  DeviceTier get tier => _tier;

  bool get isLowEnd => _tier == DeviceTier.low;

  /// Fetches the snapshot and applies tier-driven budgets. Safe to call
  /// more than once; safe when the channel is absent.
  Future<void> boot() async {
    if (kIsWeb) return;
    try {
      final raw = await _channel.invokeMethod<Object?>('info');
      if (raw is! Map) {
        return; // Keep a previous valid snapshot on probe failure.
      }
      final map = Map<Object?, Object?>.from(raw);
      final rawAbis = map['abis'];
      final abis = rawAbis is List
          ? rawAbis.whereType<String>().where((abi) => abi.isNotEmpty).toList()
          : const <String>[];
      int positiveInt(Object? value) =>
          value is num && value.isFinite && value > 0 && value <= 0x7fffffff
              ? value.toInt()
              : 0;
      String text(Object? value) => value is String ? value : '';
      final snap = DeviceInfoSnapshot(
        primaryAbi: text(map['abi']),
        abis: abis,
        totalRamMb: positiveInt(map['totalRamMb']),
        isLowRam: map['isLowRam'] == true,
        sdkInt: positiveInt(map['sdkInt']),
        release: text(map['release']),
        model: text(map['model']),
        manufacturer: text(map['manufacturer']),
      );
      info = snap;
      _tier = tierFor(totalRamMb: snap.totalRamMb, isLowRam: snap.isLowRam);
      _applyImageCache();
    } on PlatformException {
      // Read-only capability probe; failing open is correct.
    } on MissingPluginException {
      // Channel unavailable — retain any previous successful snapshot.
    }
  }

  /// The single source of truth for tier thresholds.
  ///
  ///   * OS says low-RAM (Android Go)  → LOW, whatever the marketing RAM.
  ///   * ≤ 2 GB                        → LOW  (the Samsung A10 / 1–2GB
  ///                                      fleet this app must serve).
  ///   * ≤ 4 GB                        → MID.
  ///   * > 4 GB                        → HIGH.
  ///   * unknown RAM, not low-RAM      → MID (fail open to the middle).
  static DeviceTier tierFor({
    required int? totalRamMb,
    required bool isLowRam,
  }) {
    if (isLowRam) return DeviceTier.low;
    final ram = totalRamMb;
    if (ram == null || ram <= 0) return DeviceTier.mid;
    if (ram <= 2048) return DeviceTier.low;
    if (ram <= 4096) return DeviceTier.mid;
    return DeviceTier.high;
  }

  void _applyImageCache() {
    // Limit retained images on lower-memory devices. These are cache ceilings,
    // not total process limits or a guarantee against OS low-memory kills.
    // HIGH restores settings captured before this service first adjusted them.
    final cache = PaintingBinding.instance.imageCache;
    _defaultCacheSize ??= cache.maximumSize;
    _defaultCacheBytes ??= cache.maximumSizeBytes;
    switch (_tier) {
      case DeviceTier.low:
        cache.maximumSize = 250;
        cache.maximumSizeBytes = 32 << 20; // 32 MB
      case DeviceTier.mid:
        cache.maximumSize = 500;
        cache.maximumSizeBytes = 64 << 20; // 64 MB
      case DeviceTier.high:
        cache.maximumSize = _defaultCacheSize!;
        cache.maximumSizeBytes = _defaultCacheBytes!;
    }
  }
}
