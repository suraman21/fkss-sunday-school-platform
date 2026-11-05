import 'package:flutter/painting.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/device_tier_service.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  const channel = MethodChannel('fkss.app/device');
  final messenger =
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
  final service = DeviceTierService.instance;
  final cache = PaintingBinding.instance.imageCache;
  final originalSize = cache.maximumSize;
  final originalBytes = cache.maximumSizeBytes;

  void respond(Object? value) =>
      messenger.setMockMethodCallHandler(channel, (call) async {
        expect(call.method, 'info');
        return value;
      });

  setUp(() async {
    respond({'totalRamMb': 8192});
    await service.boot();
  });
  tearDown(() {
    messenger.setMockMethodCallHandler(channel, null);
    cache.maximumSize = originalSize;
    cache.maximumSizeBytes = originalBytes;
  });

  test('LOW then MID then HIGH restores original cache, including repeat boot',
      () async {
    respond({
      'totalRamMb': 1024,
      'abis': ['armeabi-v7a'],
      'sdkInt': 28
    });
    await service.boot();
    expect(service.tier, DeviceTier.low);
    expect(cache.maximumSize, 250);
    expect(cache.maximumSizeBytes, 32 << 20);
    expect(service.info!.abis, ['armeabi-v7a']);
    respond({'totalRamMb': 4096});
    await service.boot();
    expect(service.tier, DeviceTier.mid);
    expect(cache.maximumSize, 500);
    expect(cache.maximumSizeBytes, 64 << 20);
    respond({'totalRamMb': 8192});
    await service.boot();
    await service.boot();
    expect(service.tier, DeviceTier.high);
    expect(cache.maximumSize, originalSize);
    expect(cache.maximumSizeBytes, originalBytes);
  });

  test('OS low-RAM flag overrides capacity', () async {
    respond({'totalRamMb': 8192, 'isLowRam': true});
    await service.boot();
    expect(service.isLowEnd, isTrue);
    expect(cache.maximumSizeBytes, 32 << 20);
  });

  test('unknown RAM uses MID without invalid diagnostics', () async {
    respond({});
    await service.boot();
    expect(service.tier, DeviceTier.mid);
    expect(service.info!.totalRamMb, 0);
    expect(cache.maximumSizeBytes, 64 << 20);
  });

  test('missing channel preserves last valid capability/cache', () async {
    final previous = service.info;
    messenger.setMockMethodCallHandler(channel, null);
    await service.boot();
    expect(service.info, same(previous));
    expect(cache.maximumSizeBytes, originalBytes);
  });

  test('native exception preserves last valid capability/cache', () async {
    final previous = service.info;
    messenger.setMockMethodCallHandler(channel,
        (_) async => throw PlatformException(code: 'device_info_failed'));
    await service.boot();
    expect(service.info, same(previous));
    expect(cache.maximumSizeBytes, originalBytes);
  });

  test('non-map response preserves last valid snapshot without throwing',
      () async {
    final previous = service.info;
    for (final value in [null, 123, 'unknown', <Object>[]]) {
      respond(value);
      await service.boot();
      expect(service.info, same(previous));
    }
  });

  test('malformed values do not crash boot or invent ABI strings', () async {
    for (final ram in ['2048', double.nan, double.infinity, -1, 1e30]) {
      respond({
        'totalRamMb': ram,
        'sdkInt': '28',
        'abis': [null, 3, '', 'arm64-v8a'],
        'abi': 17,
        'model': false
      });
      await service.boot();
      expect(service.tier, DeviceTier.mid);
      expect(service.info!.totalRamMb, 0);
      expect(service.info!.sdkInt, 0);
      expect(service.info!.abis, ['arm64-v8a']);
      expect(service.info!.primaryAbi, '');
      expect(service.info!.model, '');
    }
    respond({'abis': 'arm64-v8a'});
    await service.boot();
    expect(service.info!.abis, isEmpty);
  });
}
