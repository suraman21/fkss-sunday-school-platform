import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:image/image.dart' as img;
import 'package:path/path.dart' as p;

import 'device_tier_service.dart';
import 'photo_framing_geometry.dart';
import 'photo_image_processor.dart';
import 'photo_outbox_models.dart';
import 'photo_outbox_service.dart';

class CapturedPhoto {
  final String path;
  final DateTime takenAt;

  /// Displayed 3:4 frame normalized to the upright image (in-app camera
  /// path — the processor crops exactly this window), or null for the
  /// legacy maximal center crop (gallery path).
  final RectF? crop;

  const CapturedPhoto({required this.path, required this.takenAt, this.crop});
}

/// Queues an in-app camera capture for ID-card and profile use.
///
/// Decode and crop use Flutter's native image codec and renderer; JPEG
/// compression uses a native plugin so high-resolution photos avoid slow
/// Dart image-processing loops.
class PhotoCaptureService {
  PhotoCaptureService._();
  static final PhotoCaptureService instance = PhotoCaptureService._();

  Future<PhotoOutboxEntry> processAndEnqueue({
    required CapturedPhoto capture,
    required int memberId,
    String? memberCode,
    PhotoField field = PhotoField.student,
    String? retakeOfClientOpId,
    int? operatorAdminId,
  }) async {
    final source = File(capture.path);
    String? encodedPath;
    try {
      if (!await source.exists()) {
        throw PhotoCaptureException(
            'The captured photo is no longer available.');
      }
      if (await source.length() == 0) {
        throw PhotoCaptureException('Camera produced an empty photo.');
      }

      final tempPath = p.join(
        await PhotoOutboxService.instance.queueDirectory(),
        'capture_${DateTime.now().microsecondsSinceEpoch}.tmp',
      );
      final encoded = await PhotoImageProcessor.instance.encode(
        source.path,
        tempPath,
        DeviceTierService.instance.tier,
        cropFraction: capture.crop,
      );
      encodedPath = encoded.path;

      return await PhotoOutboxService.instance.enqueueCapturedPhoto(
        memberId: memberId,
        memberCode: memberCode,
        field: field,
        absoluteFilePath: encoded.path,
        format: PhotoFormat.jpeg,
        width: encoded.width,
        height: encoded.height,
        takenAt: capture.takenAt,
        operatorAdminId: operatorAdminId,
        retakeOfClientOpId: retakeOfClientOpId,
      );
    } on PhotoCaptureException {
      rethrow;
    } catch (e) {
      throw PhotoCaptureException('Could not prepare the photo: $e');
    } finally {
      await _deleteTemporaryFile(source);
      if (encodedPath != null) {
        await _deleteTemporaryFile(File(encodedPath));
      }
    }
  }

  /// Decode a queued photo to raw RGBA bytes for thumbnail/preview display.
  /// Callers should run this method in an isolate on memory-constrained
  /// devices.
  img.Image? decodeQueuedImage(String path) {
    final f = File(path);
    if (!f.existsSync()) return null;
    try {
      return img.decodeImage(f.readAsBytesSync());
    } catch (_) {
      return null;
    }
  }

  Future<void> _deleteTemporaryFile(File file) async {
    try {
      if (await file.exists()) await file.delete();
    } catch (e) {
      debugPrint('Could not remove temporary photo: $e');
    }
  }
}

class PhotoCaptureException implements Exception {
  final String message;
  PhotoCaptureException(this.message);

  @override
  String toString() => 'PhotoCaptureException: $message';
}
