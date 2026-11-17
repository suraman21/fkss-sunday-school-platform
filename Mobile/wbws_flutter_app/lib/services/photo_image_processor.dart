import 'dart:io';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter_image_compress/flutter_image_compress.dart';

import 'device_tier_service.dart';
import 'photo_framing_geometry.dart';

class PhotoOutputSize {
  final int width;
  final int height;

  const PhotoOutputSize(this.width, this.height);

  static PhotoOutputSize forTier(DeviceTier tier) => switch (tier) {
        DeviceTier.low => const PhotoOutputSize(960, 1280),
        DeviceTier.mid => const PhotoOutputSize(1200, 1600),
        DeviceTier.high => const PhotoOutputSize(1536, 2048),
      };
}

class ProcessedPhoto {
  final String path;
  final int width;
  final int height;

  const ProcessedPhoto({
    required this.path,
    required this.width,
    required this.height,
  });
}

class PhotoImageProcessor {
  PhotoImageProcessor._();
  static final PhotoImageProcessor instance = PhotoImageProcessor._();

  static const int hardCapBytes = 2 * 1024 * 1024;
  static const double targetAspect = 3 / 4;

  Future<ProcessedPhoto> encode(
    String sourcePath,
    String tempPath,
    DeviceTier tier, {
    RectF? cropFraction,
  }) async {
    final sourceBytes = await File(sourcePath).readAsBytes();
    if (sourceBytes.isEmpty) {
      throw const FormatException('Camera produced an empty photo.');
    }

    final targetSize = PhotoOutputSize.forTier(tier);
    final codec = await ui.instantiateImageCodec(
      sourceBytes,
      targetWidth: targetSize.width,
      allowUpscaling: false,
    );
    late final ui.FrameInfo frame;
    try {
      frame = await codec.getNextFrame();
    } finally {
      codec.dispose();
    }
    final sourceImage = frame.image;
    try {
      // The displayed 3:4 frame (camera path) or the maximal centered
      // crop (gallery path, null fraction) — same pure math the screen
      // lays out, so preview and file agree by construction (FR-03).
      final cropPixels = cropRectPixels(
        SizeF(
          sourceImage.width.toDouble(),
          sourceImage.height.toDouble(),
        ),
        cropFraction,
      );
      final crop = ui.Rect.fromLTWH(
        cropPixels.left,
        cropPixels.top,
        cropPixels.width,
        cropPixels.height,
      );
      final outputWidth = crop.width.floor().clamp(1, targetSize.width).toInt();
      final outputHeight = (outputWidth / targetAspect).round();
      final croppedPng = await _renderCrop(
        sourceImage,
        crop,
        outputWidth,
        outputHeight,
      );
      final outputPath = '${tempPath.substring(0, tempPath.length - 4)}.jpg';

      for (final spec in _encodeSpecs(outputWidth)) {
        final encoded = await FlutterImageCompress.compressWithList(
          croppedPng,
          minWidth: spec.width,
          minHeight: spec.height,
          quality: spec.quality,
          autoCorrectionAngle: false,
          format: CompressFormat.jpeg,
          keepExif: false,
        );
        if (encoded.lengthInBytes > hardCapBytes) continue;

        await File(outputPath).writeAsBytes(encoded, flush: true);
        return ProcessedPhoto(
          path: outputPath,
          width: spec.width,
          height: spec.height,
        );
      }

      throw const FormatException(
        'Could not compress the photo under the 2 MB limit. '
        'Try again in better light.',
      );
    } finally {
      sourceImage.dispose();
    }
  }

  Future<Uint8List> _renderCrop(
    ui.Image source,
    ui.Rect crop,
    int width,
    int height,
  ) async {
    final recorder = ui.PictureRecorder();
    final canvas = ui.Canvas(recorder);
    canvas.drawImageRect(
      source,
      crop,
      ui.Rect.fromLTWH(0, 0, width.toDouble(), height.toDouble()),
      ui.Paint()..filterQuality = ui.FilterQuality.high,
    );
    final picture = recorder.endRecording();
    final ui.Image rendered;
    try {
      rendered = await picture.toImage(width, height);
    } finally {
      picture.dispose();
    }
    try {
      final data = await rendered.toByteData(format: ui.ImageByteFormat.png);
      if (data == null) {
        throw const FormatException('Could not prepare the captured photo.');
      }
      return data.buffer.asUint8List(data.offsetInBytes, data.lengthInBytes);
    } finally {
      rendered.dispose();
    }
  }

  List<_EncodeSpec> _encodeSpecs(int width) {
    final widths = <int>[width, width];
    for (final candidate in [1440, 1200, 1080, 1024, 960, 896, 800]) {
      if (candidate < width) widths.add(candidate);
    }
    return [
      for (var i = 0; i < widths.length; i++)
        _EncodeSpec(
          widths[i],
          (widths[i] / targetAspect).round(),
          i == 0 ? 90 : 88,
        ),
    ];
  }
}

class _EncodeSpec {
  final int width;
  final int height;
  final int quality;

  const _EncodeSpec(this.width, this.height, this.quality);
}
