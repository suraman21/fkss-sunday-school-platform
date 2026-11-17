/// Pure geometry for the adaptive 3:4 photo-capture guide.
///
/// Everything in this file is unit-testable Dart with no Flutter or
/// plugin dependencies. It answers the two questions that must never
/// drift apart (FR-03):
///
/// 1. Where is the 3:4 frame on the camera preview?
/// 2. Given a detection result from ML Kit, where does that landmark
///    sit inside the frame (and on the preview)?
///
/// Coordinate spaces used here:
///
/// * **Detection space** — the upright camera image that ML Kit ran on
///   (sensor frame rotated to portrait). Shares aspect ratio and field
///   of view with the captured photo (INFERENCE: the Flutter `camera`
///   plugin's picture and preview expose the same sensor frame; this is
///   the same assumption the previous static guide used and must be
///   confirmed per-device in Stage D acceptance).
/// * **Preview space** — pixels of the on-screen `CameraPreview` widget.
/// * **Frame space** — normalized (0..1, 0..1) coordinates inside the
///   final 3:4 crop. Target regions (spec §7.3) are defined here.
/// * **Area space** — pixels of the on-screen preview area in the
///   full-bleed redesign: the whole upright image drawn at its true
///   aspect (cover-fit, possibly overflowing), with the 3:4 crop frame
///   as a centered window inside it.
library;

import 'dart:math' as math;

/// Calibration switch for ML Kit coordinate output.
///
/// ML Kit face/pose detection applies the supplied rotation when
/// detecting and returns coordinates relative to the rotated (upright)
/// image. If on-device Stage D calibration shows coordinates arriving in
/// the raw sensor frame instead, flip this flag and re-run the geometry
/// tests (they cover both conventions).
const bool kMlKitReturnsUprightCoordinates = true;

/// True final-output aspect ratio (width / height).
/// Mirrors [PhotoImageProcessor.targetAspect]; keep in sync.
const double kPhotoFrameAspect = 3 / 4;

/// The largest centered 3:4 rectangle inside the preview — exactly the
/// region [PhotoImageProcessor] keeps after its 3:4 center crop.
RectF frameRectInPreview(SizeF preview) {
  final double width = preview.width < preview.height * kPhotoFrameAspect
      ? preview.width
      : preview.height * kPhotoFrameAspect;
  final double height = width / kPhotoFrameAspect;
  return RectF(
    left: (preview.width - width) / 2,
    top: (preview.height - height) / 2,
    width: width,
    height: height,
  );
}

/// Size of the upright detection image given the raw camera buffer and
/// the rotation ML Kit was told to apply.
SizeF uprightImageSize(SizeF raw, int rotationDegrees) {
  final bool swap = rotationDegrees == 90 || rotationDegrees == 270;
  return swap ? SizeF(raw.height, raw.width) : SizeF(raw.width, raw.height);
}

/// Uniform scale factor from detection pixels to preview pixels.
double detectionToPreviewScale(SizeF uprightImage, SizeF preview) =>
    preview.width / uprightImage.width;

/// Detection-space point → preview-space point, with front-camera
/// mirroring applied at draw time only.
OffsetF detectionPointToPreview(
  OffsetF p,
  SizeF uprightImage,
  SizeF preview, {
  bool mirror = false,
}) {
  final double scale = detectionToPreviewScale(uprightImage, preview);
  final double x = mirror ? preview.width - p.x * scale : p.x * scale;
  return OffsetF(x, p.y * scale);
}

/// Detection-space point → frame-normalized point (unmirrored capture
/// space — this is what the framing checks use).
///
/// [preview] is the on-screen preview size the frame sits inside; the
/// detection→preview scale is uniform over the WHOLE preview (the
/// preview shows the full upright image), so it must not be derived
/// from the frame alone — the frame is narrower than the preview
/// whenever the capture aspect is wider than 3:4.
///
/// Points may fall outside 0..1 when the subject is outside the crop;
/// callers clamp only where a bounded value is required.
OffsetF detectionPointToFrame(
  OffsetF p,
  SizeF uprightImage,
  RectF frame, {
  required SizeF preview,
}) {
  final double scale = detectionToPreviewScale(uprightImage, preview);
  final double x = (p.x * scale - frame.left) / frame.width;
  final double y = (p.y * scale - frame.top) / frame.height;
  return OffsetF(x, y);
}

/// Frame-normalized point → preview-space point (for drawing).
OffsetF framePointToPreview(
  OffsetF n,
  RectF frame, {
  bool mirror = false,
}) {
  return OffsetF(
    frameXToPreview(n.x, frame, mirror: mirror),
    frame.top + n.y * frame.height,
  );
}

/// Frame-normalized x → preview-space x (mirroring aware).
double frameXToPreview(double nx, RectF frame, {bool mirror = false}) =>
    mirror ? frame.right - nx * frame.width : frame.left + nx * frame.width;

/// Layout of the full-bleed capture preview (redesign).
///
/// The camera image is drawn at its TRUE aspect — the box handed to
/// `CameraPreview` always has exactly the `imageAspect` ratio, so the
/// texture can never stretch (the old `FittedBox` + fixed-size `SizedBox`
/// clamped the preview into a 3:4 box and squeezed the image).
/// The 3:4 crop frame is a centered window inside the image; the
/// processor saves exactly this window ([frameInImage]), so the frame
/// edge on screen IS the saved-file edge (FR-02, FR-03).
class PreviewLayout {
  /// Full upright image rect in area coords (cover-fit; may overflow).
  final RectF image;

  /// Displayed 3:4 crop frame in area coords. Always ⊆ image ∩ area.
  final RectF frame;

  /// [frame] normalized 0..1 to the IMAGE (what the processor crops).
  final RectF frameInImage;

  const PreviewLayout({
    required this.image,
    required this.frame,
    required this.frameInImage,
  });
}

/// Computes the full-bleed layout for a preview [area] and an upright
/// camera-image aspect ([imageAspect] = width / height, ≤ 1 on phones).
///
/// The image cover-fits the area (uniform scale, centered, no letterbox,
/// no stretch); the frame is the largest centered 3:4 rect inside the
/// area with at least [gutter] clearance on every side.
PreviewLayout previewLayout({
  required SizeF area,
  required double imageAspect,
  double gutter = 14.0,
}) {
  assert(area.width > 0 && area.height > 0);
  var a = imageAspect;
  if (a.isNaN || a <= 0) a = kPhotoFrameAspect;

  // Cover-fit, centered: uniform scale, so width/height is exactly `a`.
  double imgW;
  double imgH;
  if (a <= 1) {
    imgW = area.width;
    imgH = imgW / a;
    if (imgH < area.height) {
      imgH = area.height;
      imgW = imgH * a;
    }
  } else {
    imgH = area.height;
    imgW = imgH * a;
    if (imgW < area.width) {
      imgW = area.width;
      imgH = imgW / a;
    }
  }
  final image = RectF(
    left: (area.width - imgW) / 2,
    top: (area.height - imgH) / 2,
    width: imgW,
    height: imgH,
  );

  // Cover ⇒ image ⊇ area, so constraining the frame to the area (with a
  // gutter that survives tiny areas) also keeps it inside the image.
  final g = math.min(
      gutter, math.min(area.width / 4, area.height / 4));
  var fw = math.min(
      area.width - 2 * g, (area.height - 2 * g) * kPhotoFrameAspect);
  fw = math.max(fw, 1);
  final fh = fw / kPhotoFrameAspect;
  final frame = RectF(
    left: (area.width - fw) / 2,
    top: (area.height - fh) / 2,
    width: fw,
    height: fh,
  );
  final frameInImage = RectF(
    left: (frame.left - image.left) / image.width,
    top: (frame.top - image.top) / image.height,
    width: frame.width / image.width,
    height: frame.height / image.height,
  );
  return PreviewLayout(image: image, frame: frame, frameInImage: frameInImage);
}

/// Tap in area coords → focus point as fractions of the FULL upright
/// image (0..1, clamped), ready for the camera plugin's focus/exposure
/// point. Correct even where the image overflows the visible area.
OffsetF tapToFocusPoint({required OffsetF tap, required RectF image}) {
  final fx = image.width > 0 ? (tap.x - image.left) / image.width : 0.5;
  final fy = image.height > 0 ? (tap.y - image.top) / image.height : 0.5;
  return OffsetF(
    fx.clamp(0.0, 1.0).toDouble(),
    fy.clamp(0.0, 1.0).toDouble(),
  );
}

/// Pixel crop rect for the image processor.
///
/// A null [frameInImage] keeps the legacy maximal centered 3:4 crop
/// (gallery path — arbitrary source aspects). Otherwise the crop is the
/// exact displayed frame, so preview and file agree by construction.
/// The result always stays inside the image with a uniform scale, so the
/// 3:4 ratio survives even corrupt input (no stretch downstream).
RectF cropRectPixels(SizeF image, RectF? frameInImage) {
  final w = image.width;
  final h = image.height;
  if (frameInImage == null) {
    final sourceAspect = w / h;
    if ((sourceAspect - kPhotoFrameAspect).abs() < 0.01) {
      return RectF(left: 0, top: 0, width: w, height: h);
    }
    if (sourceAspect > kPhotoFrameAspect) {
      final cw = h * kPhotoFrameAspect;
      return RectF(left: (w - cw) / 2, top: 0, width: cw, height: h);
    }
    final ch = w / kPhotoFrameAspect;
    return RectF(left: 0, top: (h - ch) / 2, width: w, height: ch);
  }
  final left =
      (frameInImage.left * w).clamp(0.0, math.max(0.0, w - 1.0)).toDouble();
  final top =
      (frameInImage.top * h).clamp(0.0, math.max(0.0, h - 1.0)).toDouble();
  var cw = frameInImage.width * w;
  var ch = frameInImage.height * h;
  final maxW = math.max(1.0, w - left);
  final maxH = math.max(1.0, h - top);
  var scale = 1.0;
  if (cw > maxW) scale = math.min(scale, maxW / cw);
  if (ch > maxH) scale = math.min(scale, maxH / ch);
  cw = math.max(1.0, cw * scale);
  ch = math.max(1.0, ch * scale);
  return RectF(left: left, top: top, width: cw, height: ch);
}

/// Minimal rectangle primitives (unit-testable without dart:ui).
class SizeF {
  final double width;
  final double height;
  const SizeF(this.width, this.height);
}

class OffsetF {
  final double x;
  final double y;
  const OffsetF(this.x, this.y);
}

class RectF {
  final double left;
  final double top;
  final double width;
  final double height;
  const RectF({
    required this.left,
    required this.top,
    required this.width,
    required this.height,
  });

  double get right => left + width;
  double get bottom => top + height;
  SizeF get size => SizeF(width, height);

  OffsetF get center => OffsetF(left + width / 2, top + height / 2);

  bool contains(OffsetF p) =>
      p.x >= left && p.x <= right && p.y >= top && p.y <= bottom;
}
