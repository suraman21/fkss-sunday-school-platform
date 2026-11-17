import 'dart:convert';
import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

import 'package:archive/archive.dart';
import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:path/path.dart' as p;
import 'package:share_plus/share_plus.dart';
import 'package:uuid/uuid.dart';

import 'local_db.dart';
import 'photo_outbox_models.dart';
import 'photo_outbox_service.dart';
import 'photo_public_downloads.dart';
import '../utils/config.dart';

/// Z1 — ZIP airlock export.
///
/// Bundles every queued/synced-but-not-delivered photo on the device
/// into signed ZIPs that the admin web UI can ingest:
///
///   photos/<member_code>__<member_id>__<client_op_id>.webp
///   manifest.json
///   manifest.sig         (HMAC-SHA256 hex over canonical manifest JSON,
///                         keyed by the server-issued device pairing
///                         secret — falls back to a random per-install
///                         secret when unpaired so admins can still
///                         verify the file wasn't tampered with after
///                         handoff)
///
/// Large queues are split into standalone chunks (≤100 photos / ≤150 MB
/// each, own manifest + signature) so a multi-thousand-photo intake
/// never produces an un-uploadable giant ZIP or OOMs the phone. The
/// server imports chunks in any order (each is a valid bundle).
///
/// The ZIP is store-only (no DEFLATE): WebP is already compressed and
/// re-compression just burns CPU/battery in the field.
///
/// Share sheet opens on completion so the operator can AirDrop / USB /
/// Gmail the bundle(s) to a PC. Exporting does NOT change outbox state —
/// synced upload and ZIP export are independent delivery channels and
/// either one completing marks the photo delivered (server reconciles
/// by client_op_id).
class PhotoZipExportService {
  PhotoZipExportService._();
  static final PhotoZipExportService instance = PhotoZipExportService._();

  static const int _zipVersion = 1;
  static const String _manifestVersion = 'fkss-photo-airlock-v1';

  /// Chunk budgets. The server caps imports at 200 MB; staying clearly
  /// under keeps USB handoff, PHP upload limits, and import time safe.
  static const int _maxPhotosPerZip = 100;
  static const int _maxBytesPerZip = 150 * 1024 * 1024;

  /// Export all non-discarded photos, split into standalone ZIP chunks.
  /// Set [onlyUnsynced]=true to exclude photos already marked done.
  /// Single-chunk exports keep the legacy `fkss-photos-<stamp>-<id>.zip`
  /// name; multi-chunk exports add a `-part1of3` suffix per chunk.
  Future<ExportedSet> exportBundles({bool onlyUnsynced = true}) async {
    await PhotoOutboxService.instance.ensureInitialized();
    final db = await LocalDb().database;

    final rows = await db.query(
      'photo_outbox',
      where: onlyUnsynced
          ? 'state != ? AND state != ?'
          : 'state != ?',
      whereArgs: onlyUnsynced
          ? [
              PhotoOutboxState.discarded.storageValue,
              PhotoOutboxState.done.storageValue,
            ]
          : [PhotoOutboxState.discarded.storageValue],
      orderBy: 'member_id, taken_at',
    );
    if (rows.isEmpty) {
      throw StateError('No photos to export. Take some first.');
    }

    final exportDirPath = await PhotoOutboxService.instance.exportsDirectory();
    final exportDir = Directory(exportDirPath);
    await exportDir.create(recursive: true);
    final deviceId = await PhotoOutboxService.instance.deviceId();

    // Pass 1 (metadata only): plan chunk boundaries without reading file
    // bytes, so a 4k-photo queue never holds gigabytes in RAM.
    final chunks = <List<_PlannedPhoto>>[[]];
    var chunkBytes = 0;
    var missingFiles = 0;
    for (final row in rows) {
      final entry = PhotoOutboxEntry.fromMap(row);
      final file = File(entry.localPath);
      if (!await file.exists()) {
        missingFiles++;
        continue;
      }
      final size = await file.length();
      final current = chunks.last;
      if (current.isNotEmpty &&
          (current.length >= _maxPhotosPerZip ||
              chunkBytes + size > _maxBytesPerZip)) {
        chunks.add([]);
        chunkBytes = 0;
      }
      chunks.last.add(_PlannedPhoto(entry: entry, byteSize: size));
      chunkBytes += size;
    }
    if (chunks.length == 1 && chunks.first.isEmpty) {
      throw StateError(
          'All queued photos reference missing files (storage cleaned?). '
          'Try capturing again.');
    }

    // HMAC key — prefer the server-paired per-(operator,device) secret
    // (returned after first successful upload and persisted via
    // saveHmacKey); fall back to a per-install secret stored in the
    // exports directory when we haven't yet paired with the server
    // (e.g., the operator is exporting without ever having synced).
    final pairedKeyHex = await PhotoOutboxService.instance.savedHmacKey();
    List<int> hmacKeyBytes;
    String hmacKeyType;
    if (pairedKeyHex != null && pairedKeyHex.length == 64) {
      final hex = pairedKeyHex;
      hmacKeyBytes = List<int>.generate(
          32, (i) => int.parse(hex.substring(i*2, i*2+2), radix: 16));
      hmacKeyType = 'server_paired';
    } else {
      hmacKeyBytes = await _fallbackHmacKey(exportDir);
      hmacKeyType = 'per_install';
    }

    // Pass 2: build one standalone bundle per chunk. Only one chunk's
    // bytes are ever in RAM at a time (bounded by _maxBytesPerZip).
    final zips = <File>[];
    var totalPhotos = 0;
    final chunkCount = chunks.where((c) => c.isNotEmpty).length;
    var chunkIndex = 0;
    for (final chunk in chunks) {
      if (chunk.isEmpty) continue;
      chunkIndex++;
      zips.add(await _sealChunk(
        exportDir: exportDir,
        deviceId: deviceId,
        chunk: chunk,
        chunkIndex: chunkIndex,
        chunkCount: chunkCount,
        skippedMissing: missingFiles,
        hmacKeyBytes: hmacKeyBytes,
        hmacKeyType: hmacKeyType,
      ));
      totalPhotos += chunk.length;
    }
    return ExportedSet(
        zips: zips, photoCount: totalPhotos, skippedMissing: missingFiles);
  }

  /// Build one standalone signed bundle for a planned chunk.
  Future<File> _sealChunk({
    required Directory exportDir,
    required String deviceId,
    required List<_PlannedPhoto> chunk,
    required int chunkIndex,
    required int chunkCount,
    required int skippedMissing,
    required List<int> hmacKeyBytes,
    required String hmacKeyType,
  }) async {
    final bundleId = const Uuid().v4();
    final startedAt = DateTime.now().toUtc();

    // Gather entries, read files, compute per-file SHA-256.
    final files = <_ZipPhotoEntry>[];
    var totalBytes = 0;
    for (final planned in chunk) {
      final entry = planned.entry;
      final file = File(entry.localPath);
      if (!await file.exists()) continue; // vanished between passes; skip.
      final bytes = await file.readAsBytes();
      totalBytes += bytes.length;
      final digest = sha256.convert(bytes);
      final ext = entry.format.extension;
      final code = entry.memberCode?.replaceAll(RegExp(r'[^A-Za-z0-9_-]'), '') ?? 'M';
      final arcName = 'photos/${code}__${entry.memberId}__${entry.clientOpId}.$ext';
      files.add(_ZipPhotoEntry(
        arcName: arcName,
        bytes: bytes,
        entry: entry,
        sha256: digest.toString(),
        byteSize: bytes.length,
      ));
    }
    if (files.isEmpty) {
      throw StateError(
          'Chunk has no readable photos (storage cleaned mid-export?).');
    }

    final manifest = <String, dynamic>{
      'manifest_version': _manifestVersion,
      'bundle_id': bundleId,
      'exported_at': startedAt.toIso8601String(),
      'device_id': deviceId,
      'app_version': AppConfig.appVersion,
      'zip_version': _zipVersion,
      'photo_count': files.length,
      'total_bytes': totalBytes,
      'chunk_index': chunkIndex,
      'chunk_count': chunkCount,
      'skipped_missing_files': skippedMissing,
      'hmac_key_type': hmacKeyType,
      'photos': files
          .map((f) => {
                'arc_path': f.arcName,
                'member_id': f.entry.memberId,
                'member_code': f.entry.memberCode,
                'field': f.entry.field.storageValue,
                'mime_type': f.entry.format.mime,
                'byte_size': f.byteSize,
                'width': f.entry.width,
                'height': f.entry.height,
                'sha256': f.sha256,
                'client_op_id': f.entry.clientOpId,
                'taken_at': f.entry.takenAt.toIso8601String(),
                'capture_device_id': f.entry.deviceId,
              })
          .toList(growable: false),
    };

    // Canonical JSON encoding (sorted keys, compact UTF-8) so HMAC is
    // reproducible across platforms.
    final manifestJson =
        const JsonEncoder.withIndent(null).convert(_sorted(manifest));
    final manifestBytes = Uint8List.fromList(utf8.encode(manifestJson));
    final sig = Hmac(sha256, hmacKeyBytes).convert(manifestBytes);

    final archive = Archive();
    // Store only (no DEFLATE) — WebP is already compressed.
    // ArchiveFile.noCompress was re-added in archive 4.0.5 and is the
    // stable cross-version helper for "store uncompressed" entries.
    for (final f in files) {
      archive.addFile(ArchiveFile.noCompress(f.arcName, f.bytes.length, f.bytes));
    }
    archive.addFile(ArchiveFile.noCompress('manifest.json', manifestBytes.length, manifestBytes));
    final sigBytes = Uint8List.fromList(utf8.encode(sig.toString()));
    archive.addFile(ArchiveFile.noCompress('manifest.sig', sigBytes.length, sigBytes));

    final zipBytes = ZipEncoder().encode(archive);
    final stamp = startedAt.toIso8601String().replaceAll(':', '').split('.').first;
    final suffix = chunkCount > 1 ? '-part${chunkIndex}of$chunkCount' : '';
    final zipPath = p.join(exportDir.path, 'fkss-photos-$stamp-$bundleId$suffix.zip');
    final out = File(zipPath);
    await out.writeAsBytes(zipBytes, flush: true);
    return out;
  }

  /// Build chunk(s), save copies to public Downloads (Android) for easy
  /// USB handoff, then hand the bundle(s) to the OS share sheet.
  ///
  /// A failed Downloads save never fails the export — sharing still
  /// works (e.g. old Android without the storage permission).
  Future<ExportedBundles> exportAndShare({bool onlyUnsynced = true}) async {
    final set = await exportBundles(onlyUnsynced: onlyUnsynced);
    final downloadsPaths = <String?>[];
    for (final zip in set.zips) {
      try {
        downloadsPaths.add(await PhotoPublicDownloads.saveZipToDownloads(zip));
      } catch (e) {
        debugPrint('Downloads copy failed (share still works): $e');
        downloadsPaths.add(null);
      }
    }
    await Share.shareXFiles(
      [for (final zip in set.zips) XFile(zip.path, mimeType: 'application/zip')],
      subject: set.zips.length == 1
          ? 'FKSS photos (${p.basename(set.zips.first.path)})'
          : 'FKSS photos (${set.zips.length} files)',
      text: 'Offline-captured photos for FKSS Sunday School — import via admin Photo Review.',
    );
    return ExportedBundles(
      zips: set.zips,
      downloadsPaths: downloadsPaths,
      photoCount: set.photoCount,
      skippedMissing: set.skippedMissing,
    );
  }

  /// Per-install fallback HMAC key (used when the device has never
  /// synced with the server and therefore doesn't have a paired key
  /// yet). This lets admins detect accidental corruption of a bundle
  /// that arrived by USB even before pairing completes.
  Future<List<int>> _fallbackHmacKey(Directory exportsDir) async {
    final keyFile = File(p.join(exportsDir.path, '.airlock_hmac_key'));
    if (await keyFile.exists()) {
      final hex = (await keyFile.readAsString()).trim();
      if (hex.length == 64) {
        return List<int>.generate(32, (i) => int.parse(hex.substring(i*2, i*2+2), radix: 16));
      }
    }
    final rnd = List<int>.generate(32, (_) => _secureByte());
    final hex = rnd.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
    await keyFile.writeAsString(hex, flush: true);
    return rnd;
  }

  int _secureByte() {
    try {
      return Random.secure().nextInt(256);
    } catch (_) {
      return Random().nextInt(256);
    }
  }

  /// Recursive sort of JSON objects for canonical encoding.
  dynamic _sorted(dynamic v) {
    if (v is Map) {
      final keys = v.keys.map((k) => k.toString()).toList()..sort();
      return Map<String, dynamic>.fromEntries(
          keys.map((k) => MapEntry<String, dynamic>(k, _sorted(v[k]))));
    }
    if (v is List) return v.map(_sorted).toList();
    return v;
  }
}

class _ZipPhotoEntry {
  final String arcName;
  final Uint8List bytes;
  final PhotoOutboxEntry entry;
  final String sha256;
  final int byteSize;
  _ZipPhotoEntry({
    required this.arcName,
    required this.bytes,
    required this.entry,
    required this.sha256,
    required this.byteSize,
  });
}

/// One photo selected for export (pass-1 plan; bytes are read in pass 2
/// so only one chunk is ever in RAM).
class _PlannedPhoto {
  final PhotoOutboxEntry entry;
  final int byteSize;
  const _PlannedPhoto({required this.entry, required this.byteSize});
}

/// Result of [PhotoZipExportService.exportBundles]: the chunk files plus
/// export-wide counts.
class ExportedSet {
  final List<File> zips;
  final int photoCount;
  final int skippedMissing;

  const ExportedSet(
      {required this.zips,
      required this.photoCount,
      required this.skippedMissing});
}

/// Result of [PhotoZipExportService.exportAndShare]: the private bundles
/// plus each public copy's display path (`Downloads/FKSS/…`, Android
/// only — null entries on iOS or when that Downloads save failed).
class ExportedBundles {
  final List<File> zips;
  final List<String?> downloadsPaths;
  final int photoCount;
  final int skippedMissing;

  const ExportedBundles(
      {required this.zips,
      required this.downloadsPaths,
      required this.photoCount,
      required this.skippedMissing});
}
