import 'dart:io';

import 'package:flutter/services.dart';
import 'package:path/path.dart' as p;

/// Public-Downloads copy for exported files (Android).
///
/// The ZIP also lives in app-private storage, which most file managers
/// cannot browse — so a copy goes to public `Downloads/FKSS/` where the
/// operator can find and copy it by USB. iOS has no equivalent step:
/// the share sheet's "Save to Files" covers it, so this is a no-op
/// there and returns null.
class PhotoPublicDownloads {
  PhotoPublicDownloads._();

  static const MethodChannel _channel = MethodChannel('fkss.app/photo_export');

  /// Copies [zip] to `Downloads/FKSS/` and returns the display path
  /// (e.g. `Downloads/FKSS/fkss-photos-….zip`), or null on non-Android.
  /// Throws [PlatformException] when the native save fails; callers must
  /// treat that as "share-only" rather than failing the export.
  static Future<String?> saveZipToDownloads(File zip) async {
    if (!Platform.isAndroid) return null;
    final saved = await _channel.invokeMethod<String>(
      'saveZipToDownloads',
      {'path': zip.path, 'fileName': p.basename(zip.path)},
    );
    return saved;
  }
}
