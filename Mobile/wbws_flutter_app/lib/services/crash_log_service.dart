import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart' show getDatabasesPath;

import 'device_tier_service.dart';

/// P65 — the shared failure log reader/writer utilities.
///
/// ONE file holds the app's whole failure history:
/// `databases/fkss_bootstrap_error.log`
///   * the Dart bootstrap writer appends `=== <ISO-8601> ===` sections
///     (offline storage failures, main.dart);
///   * `FkssApplication.kt` appends `=== CRASH <epochMillis> ===`
///     sections for uncaught Java/Kotlin exceptions (the launch-crash
///     class: UnsatisfiedLinkError and friends).
///
/// This service reads/parses/clears it and builds the copyable
/// diagnostic report for the administrator (Profile → App →
/// Diagnostics). Parsing is a pure function, pinned by test. The copied report
/// includes the caller-supplied raw log tail without universal redaction;
/// users must review it before private sharing. Bounded telemetry signatures
/// below have a separate, narrower data contract.
const String kCrashLogFileName = 'fkss_bootstrap_error.log';

/// One `=== ... ===` section of the shared log.
class CrashLogEntry {
  /// When the entry was written; null when the header could not be
  /// parsed (the entry is still shown, just not time-filtered).
  final DateTime? at;

  /// True when written by the native crash trap (vs the Dart bootstrap).
  final bool nativeCrash;

  final String header;
  final String body;

  const CrashLogEntry({
    required this.at,
    required this.nativeCrash,
    required this.header,
    required this.body,
  });

  /// Stable, non-reversible identity for this exact diagnostic entry.
  ///
  /// The raw header/body remain local diagnostic material. Only this SHA-256
  /// key is eligible for telemetry, so a crash stack cannot cross the device
  /// trust boundary as an event payload.
  String get reportKey =>
      sha256.convert(utf8.encode('$header\n$body')).toString();

  bool within(Duration d) {
    final t = at;
    return t != null && DateTime.now().difference(t) <= d;
  }
}

/// Parses the shared log. Sections start with a line
/// `=== <header> ===`; everything until the next such line is the body.
/// Recognised headers:
///   * `CRASH 1757160000000`  — native trap, epoch milliseconds.
///   * `2026-09-06T09:30:00.123` — Dart bootstrap, ISO-8601.
/// Anything else still parses (header kept verbatim, `at` null).
List<CrashLogEntry> parseCrashLog(String raw) {
  final entries = <CrashLogEntry>[];
  final lines = raw.split('\n');
  final headerRe = RegExp(r'^===\s*(.+?)\s*===$');

  String? header;
  final body = <String>[];

  void flush() {
    if (header == null) return;
    final h = header!;
    header = null;
    var native = false;
    DateTime? at;
    final crash = RegExp(r'^CRASH\s+(\d+)$').firstMatch(h);
    if (crash != null) {
      native = true;
      final ms = int.tryParse(crash.group(1)!);
      if (ms != null) {
        at = DateTime.fromMillisecondsSinceEpoch(ms);
      }
    } else {
      at = DateTime.tryParse(h);
    }
    entries.add(
      CrashLogEntry(
        at: at,
        nativeCrash: native,
        header: h,
        body: body.join('\n').trim(),
      ),
    );
    body.clear();
  }

  for (final line in lines) {
    final m = headerRe.firstMatch(line.trim());
    if (m != null) {
      flush();
      header = m.group(1)!;
    } else if (header != null) {
      body.add(line);
    }
    // Lines before the first header (legacy noise) are dropped.
  }
  flush();
  return entries;
}

/// Bounded, privacy-safe location identity for a crash entry: the exception
/// class plus up to three first-party frame names (file + function only).
///
/// Hard contract, mirrored by the server route (api/v1/routes/telemetry.php):
///   * `signature_class` and each frame: allow-listed charset, ≤ 120 chars;
///   * at most 3 frames;
///   * total JSON payload ≤ 512 bytes;
///   * never messages, argument values, absolute paths, or user data —
///     only WHERE the failure happened, so a crash key becomes readable
///     without its stack crossing the device trust boundary as text.
class CrashSignature {
  const CrashSignature({required this.signatureClass, required this.frames});

  final String signatureClass;
  final List<String> frames;

  Map<String, dynamic> toTelemetryData(String crashKey) => {
        'crash_key': crashKey,
        'signature_class': signatureClass,
        'frames': frames,
      };
}

const int _kMaxSignaturePartLength = 120;
const int _kMaxSignatureFrames = 3;
const int _kMaxSignatureJsonBytes = 512;

String _sanitizeSignaturePart(String raw) {
  final cleaned =
      raw.replaceAll(RegExp(r'[^A-Za-z0-9 .:_/<>()$#-]'), '').trim();
  return cleaned.length > _kMaxSignaturePartLength
      ? cleaned.substring(0, _kMaxSignaturePartLength)
      : cleaned;
}

/// Builds the signature for [entry]; null when the body carries nothing
/// usable. Pure and deterministic: the same entry always yields the same
/// signature, so it matches whatever the server already holds for the
/// entry's [CrashLogEntry.reportKey].
CrashSignature? buildCrashSignature(CrashLogEntry entry) {
  final body = entry.body.trim();
  if (body.isEmpty) return null;

  // Exception class: the text before the first ':' on the first body line
  // ("java.lang.UnsatisfiedLinkError: …", "SqfliteException: …").
  final firstLine = body.split('\n').first;
  var signatureClass = _sanitizeSignaturePart(
    firstLine.contains(':') ? firstLine.split(':').first : firstLine,
  );
  if (signatureClass.isEmpty) signatureClass = 'Unknown';

  // First-party frames: Dart frames reference the app's own package;
  // native frames reference the app's own Kotlin/Java namespace. Anything
  // else (framework, engine, OS) is deliberately excluded.
  final frames = <String>[];
  final fileLocation = RegExp(r'([A-Za-z0-9_]+\.dart):(\d+)');
  final nativeLocation = RegExp(r'([A-Za-z0-9_]+\.(?:kt|java)):(\d+)');
  final nativeSymbol = RegExp(r'\.([A-Za-z0-9_]+)\(');
  final dartSymbol = RegExp(r'([A-Za-z0-9_.$]+)\s+\(');
  for (final line in body.split('\n').skip(1)) {
    if (frames.length >= _kMaxSignatureFrames) break;
    final isDart = line.contains('package:fkss_app/');
    final isNative = line.contains('com.arkeonethiopia');
    if (!isDart && !isNative) continue;
    String frame;
    final match = isDart
        ? fileLocation.firstMatch(line)
        : nativeLocation.firstMatch(line);
    if (match != null) {
      final location = '${match.group(1)}:${match.group(2)}';
      final symbol =
          (isNative ? nativeSymbol : dartSymbol).firstMatch(line)?.group(1) ?? '';
      frame = _sanitizeSignaturePart(
        symbol.isEmpty ? location : '$location $symbol',
      );
    } else {
      frame = _sanitizeSignaturePart(line.trim());
    }
    if (frame.isNotEmpty && !frames.contains(frame)) frames.add(frame);
  }

  // Enforce the total JSON budget by dropping frames from the end — the
  // class alone can never exceed it (≤ 120 of 512 bytes).
  var signature = CrashSignature(signatureClass: signatureClass, frames: frames);
  while (frames.isNotEmpty &&
      utf8.encode(jsonEncode(signature.toTelemetryData('0' * 64))).length >
          _kMaxSignatureJsonBytes) {
    frames.removeLast();
    signature = CrashSignature(signatureClass: signatureClass, frames: frames);
  }
  return signature;
}

class CrashLogService {
  CrashLogService._();

  static final CrashLogService instance = CrashLogService._();

  Future<File> _logFile() async {
    final dir = await getDatabasesPath();
    return File(p.join(dir, kCrashLogFileName));
  }

  /// The whole log; '' when missing or unreadable.
  Future<String> readRaw() async {
    try {
      final f = await _logFile();
      if (!await f.exists()) return '';
      return await f.readAsString();
    } catch (_) {
      return '';
    }
  }

  Future<List<CrashLogEntry>> readEntries() async =>
      parseCrashLog(await readRaw());

  /// The most recent native crash within [within] (default 7 days), if
  /// any — "the app closed itself recently" detection.
  Future<CrashLogEntry?> lastNativeCrash({
    Duration within = const Duration(days: 7),
  }) async {
    CrashLogEntry? best;
    for (final e in await readEntries()) {
      if (!e.nativeCrash) continue;
      if (!e.within(within)) continue;
      final b = best;
      if (b == null ||
          (e.at != null && (b.at == null || e.at!.isAfter(b.at!)))) {
        best = e;
      }
    }
    return best;
  }

  Future<void> clear() async {
    try {
      final f = await _logFile();
      if (await f.exists()) await f.delete();
    } catch (_) {
      // Clearing is best-effort.
    }
  }

  /// The copyable report for the administrator. Pure — no I/O, no PII:
  /// device/OS facts and stack traces only.
  static String buildReport({
    required String appVersion,
    required int appBuild,
    required String server,
    DeviceInfoSnapshot? device,
    required String crashLogTail,
  }) {
    final b = StringBuffer();
    b.writeln('FKSS diagnostic report');
    b.writeln('App: $appVersion ($appBuild)');
    b.writeln('Server: $server');
    if (device != null) {
      b.writeln('Device: ${device.manufacturer} ${device.model}');
      b.writeln('Android: ${device.release} (SDK ${device.sdkInt})');
      b.writeln(
        'ABI: ${device.primaryAbi}'
        '${device.abis.isEmpty ? '' : ' [${device.abis.join(', ')}]'}',
      );
      b.writeln(
        'RAM: ${device.totalRamMb > 0 ? '${device.totalRamMb} MB' : 'unknown'}'
        '${device.isLowRam ? ' (Android Go / low-RAM)' : ''}',
      );
    } else {
      b.writeln('Device: not available');
    }
    b.writeln('Generated: ${DateTime.now().toIso8601String()}');
    if (crashLogTail.trim().isNotEmpty) {
      b.writeln('--- error log (tail) ---');
      b.writeln(crashLogTail.trim());
    } else {
      b.writeln('No recorded errors.');
    }
    return b.toString();
  }
}
