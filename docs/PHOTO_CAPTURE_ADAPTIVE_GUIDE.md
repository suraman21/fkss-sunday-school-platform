# Adaptive Human-Guided Photo Capture — implementation notes

Implementation record for the "FKSS Adaptive Human-Guided Photo Capture"
specification v1.0 (member profile photo capture, Flutter Android, 3:4
portrait, fully offline). This doc maps the spec's requirements to the
code, lists the tunable constants for on-device calibration, and records
what remains manual acceptance (Stage D/E).

Stage A (exact 3:4 canvas, preview↔crop parity, review of the final
crop) landed earlier — see `PHOTO_MVP_PROGRESS.md` P2 and
`OFFLINE_PHOTO_SYNC_DESIGN.md`. Stages B/C (landmark-driven adaptive
guide, feedback engine, status chips, gallery import) are covered here.

## Architecture

Four modules keep the engineering contract testable and the plugin types
quarantined:

| File | Responsibility |
|---|---|
| `Mobile/wbws_flutter_app/lib/services/photo_framing_geometry.dart` | Pure Dart coordinate transforms: detection-image space → preview space → 3:4 frame-normalized space; front-camera mirroring at draw time only. No Flutter/plugin imports — fully unit-tested. |
| `Mobile/wbws_flutter_app/lib/services/photo_framing_engine.dart` | Pure feedback engine: per-check states (pass/warn/neutral), single-instruction priority, message switch delay, ready stability window. `allowCapture` is structurally always true (FR-09). |
| `Mobile/wbws_flutter_app/lib/services/photo_framing_detector.dart` | ML Kit wiring: face landmarks + head Euler Z, pose shoulders + per-landmark likelihood, latest-frame-only processing, instance reuse, explicit dispose. |
| `Mobile/wbws_flutter_app/lib/screens/members/photo_camera_screen.dart` | Screen (approved FKSS capture reference): crest header with on-device "Offline Ready" pill and settings gear, single-instruction card, preview showing only the saved 3:4 crop with `_AdaptiveGuidePainter` (static frame + adaptive landmark layer), three status cards, passport-size card, gallery/shutter/switch bar with Photo/Video tabs. Exposure, zoom, flash, haptics and "Close camera" live in the settings sheet. |
| `Mobile/wbws_flutter_app/lib/services/photo_gallery_import.dart` | Gallery path (spec §4.5): picks an image, bakes EXIF orientation via `FlutterImageCompress` (`autoCorrectionAngle: true`), returns a camera-equivalent `CapturedPhoto`; the picked file is never modified. |

Tests: `test/photo_framing_geometry_test.dart`,
`test/photo_framing_engine_test.dart` (round trips, mirroring, priority
order, smoothing, ready window, neutral-not-pass, confidence gate).

## Screen layout (spec §4)

The screen follows the approved FKSS capture reference and is built from
four regions:

- **Header** — gold-ring crest, `FKSS` / `Photo Capture`, the
  "Offline Ready" pill and a settings gear. The pill has three states and
  is driven by detector readiness only: green `Offline Ready` (detectors
  running, no last error), amber `Detection limited` (running with an
  error), neutral `Starting…`.
- **Instruction card** — exactly one instruction from the feedback
  engine, with a short static subtitle explaining the goal ("Keep the
  eyes on the guide line and shoulders visible"). When the engine reports
  ready the card turns green with "Perfect! Ready to capture". The member
  name is a small trailing label so the operator can confirm the subject.
- **Preview** — the dominant element and **only the saved 3:4 crop**
  (FR-03): the full-width `CameraPreview` is scaled into a rounded 3:4
  box and clipped vertically, so the cyan frame edge, the corner
  brackets, the dashed centerline and the 0.42 eye line all sit inside
  the exact pixels that `PhotoImageProcessor` keeps. The adaptive layer
  draws a head outline from the detected face box, a cyan segment through
  the real eye landmarks and a shoulder curve from both pose landmarks.
  With no reliable landmarks only the static guide is drawn (FR-18). A
  "Perfect!" badge appears next to the frame only when the verdict is
  ready.
- **Status rail + passport card** — three cards (Face Centered / Eyes On
  Line / Shoulders Visible) with an icon, label and state dot, so the
  state never depends on colour alone (spec §13); the right card states
  the output is `3:4 (Passport Size)`.
- **Bottom bar** — Gallery import, the large shutter and camera switch,
  with Photo (active) and Video tabs. Video is intentionally inactive: it
  shows a notice that video capture is not used for member photos
  (spec §4.5).

Exposure, zoom, flash, the ready haptic and "Close camera" moved into the
settings sheet so the main screen stays uncluttered and the shutter is
reachable one-handed. Tap-to-focus on the crop is projected back into
full-preview coordinates before being handed to the plugin, because the
crop is narrower than the preview on wide sensors.

If the detector errors or stops producing results for 2 s, the verdict
drops to neutral checks with "Adjust the lighting or framing" — a stale
green can never survive a stalled detector, and capture stays enabled
(FR-09, FR-18).

## Coordinate transformation (spec §9.4)

Spaces (defined in the geometry module's header comment):

1. **Detection space** — the upright camera image ML Kit ran on (raw
   sensor frame rotated by the camera's `sensorOrientation`). ML Kit is
   handed `InputImageRotation` derived from that sensor orientation.
2. **Preview space** — pixels of the upright camera image as displayed by
   `CameraPreview` (the widget's portrait aspect is `1 / value.aspectRatio`
   because the plugin reports the landscape `previewSize`, so the preview
   shows the full detection image).
3. **Frame space** — normalized 0..1 coordinates inside the exact 3:4
   crop that `PhotoImageProcessor` keeps (`frameRectInPreview` is the
   same centered 3:4 rectangle math, so what the operator sees is what
   gets saved, FR-03). The screen displays **only this crop**: the
   preview is scaled to the box width and clipped to the 3:4 box, so the
   drawn frame edge is the saved file edge and the guide paints in the
   same space.

`detectionPointToFrame` takes the **full preview size** explicitly: the
detection→preview scale is uniform over the whole preview and must not
be derived from the frame alone (the frame is narrower than the preview
whenever the capture aspect is wider than 3:4).

Front camera: the `camera` plugin mirrors the live preview texture but
not the captured file. Checks therefore run in the **unmirrored capture
space**; mirroring is applied only at draw time (`frameXToPreview(...,
mirror:)`, `FramingOverlayData.mirror`).

VERIFIED assumptions (from pub-cache plugin sources):
- `InputImageRotation.values.firstWhere((r) => r.rawValue == rotation)`
  construction, `InputImageFormat.yuv420`, `FaceDetectorMode.fast`,
  `face.headEulerAngleZ` (`double?`), `face.landmarks[type].position`
  (`Point<int>`), `pose.landmarks[type]` + `.likelihood` (`double`).

INFERENCE (must be confirmed on device, Stage D):
- The camera plugin's preview and picture share the sensor frame/FOV.
- ML Kit returns coordinates in the rotated (upright) image. The
  calibration flag `kMlKitReturnsUprightCoordinates` exists so a
  wrong convention is a one-line flip plus test re-run; on-device a
  wrong value shows up immediately as a sideways/flipped outline.

## Offline (spec §11)

- Detection is 100% on-device (ML Kit). No cloud API, no transmission.
- Models are bundled into the APK at build time via the
  `com.google.mlkit.vision.DEPENDENCIES` manifest entry (`face,pose`)
  in `Mobile/wbws_flutter_app/android/app/src/main/AndroidManifest.xml`,
  so first use in the field does not depend on a Play model download.
- "Offline Ready" chip reflects on-device readiness (detectors created,
  no last error) — not network state.

## Feedback engine (spec §8)

- Single instruction at a time; priority order: multiple faces →
  no face (person seen by pose: "Face the camera") → too large → too
  small → off-center → too low → head tilt → eyes misaligned →
  shoulders outside → shoulder tilt.
- Messages switch only after `kMessageSwitchDelay` (0.6 s) of stable
  condition; the session's first instruction shows immediately.
- "Ready to capture" requires the applicable checks to stay passing for
  `kReadyStabilityWindow` (1.2 s); optional haptic fires once per ready
  transition (toggle in capture settings; never affects capture).
- Missing/low-confidence detection maps to **neutral**, never pass
  (no face → "Position the person in front of the camera").

## Privacy (spec §12)

Detection is positioning-only: face landmarks/head angles and shoulder
landmarks/likelihoods are used to draw guides and compute verdicts, then
discarded. No identification, no demographic or emotion classification,
no identity templates, no beauty filters, no frame retention. Detector
instances are closed on leaving the screen (FR-15, FR-16).

## Calibration constants (Stage D tuning)

All in `photo_framing_engine.dart` — starting values from spec §7.3,
normalized against the 3:4 frame. They drive guidance only; nothing
rejects a capture (the shutter is never disabled):

| Constant | Value |
|---|---|
| `kFaceCenterXTarget` / tolerance | 0.50 / 0.08 |
| `kEyeLinePassMin..Max` (warn band wider) | 0.38–0.45 (0.33–0.50) |
| `kHeadTopMarginMin` / preferred / max | 0.02 / 0.05–0.10 / 0.18 |
| `kFaceWidthTooSmall` / warn band / too large | 0.24 / 0.28–0.42 / 0.48 |
| `kShoulderYMin..Max` | 0.70–0.88 |
| `kShoulderMarginPreferred` / warn | 0.06 / 0.03 |
| `kHeadTiltPass` / warn (degrees) | 10 / 15 |
| `kShoulderTiltPass` / warn (degrees) | 12 / 18 |
| `kShoulderMinConfidence` | 0.55 |
| `kMessageSwitchDelay` / `kReadyStabilityWindow` | 0.6 s / 1.2 s |

Design decision recorded here: `isReadyQuality` accepts a **neutral**
eye line (landmarks unavailable, e.g. unusual lighting or glasses) and a
neutral shoulder state, because demanding detector output the device
cannot produce would make "ready" unreachable on some hardware; the chip
still shows neutral, so uncertainty is never displayed as success.

## Functional requirements checklist

| FR | Status |
|---|---|
| FR-01 exact 3:4 | DONE (Stage A; `PhotoImageProcessor.targetAspect` = 3/4) |
| FR-02 no stretch/distort | DONE (Stage A; native 3:4 center crop) |
| FR-03 preview matches crop | DONE (the screen shows only the centered 3:4 crop; shared `frameRectInPreview` math; unit-tested) |
| FR-04 head not cropped | Guided (top-margin checks warn; operator decides) |
| FR-05 shoulders inside safe frame | Guided (pose landmarks when reliable) |
| FR-06 eye guide from landmarks | DONE (ML Kit eye landmarks + eye line) |
| FR-07 outline adapts to face geometry | DONE (detected bounding box outline, eye markers) |
| FR-08 shoulder guidance from body landmarks | DONE (pose shoulders + likelihood gate) |
| FR-09 missing detection never blocks capture | DONE (engine `allowCapture` always true; unit-tested) |
| FR-10 ages/proportions supported | Adaptive geometry, no fixed silhouette; Stage D acceptance pending |
| FR-11 no internet needed | DONE (on-device ML Kit; models bundled in APK) |
| FR-12 photo excludes overlays | DONE (paint overlay only; processor crops the camera file) |
| FR-13 review shows final photo | DONE (Stage A review screen) |
| FR-14 retake/save work | DONE (Stage A) |
| FR-15 resources released | DONE (`dispose`/lifecycle paths; detectors closed, stream stopped) |
| FR-16 no identification/demographics | DONE (positioning-only detection) |
| FR-17 no forced uncomfortable pose | By design (advisory guidance only) |
| FR-18 usable when detection uncertain | DONE (neutral states + static manual guide fallback) |

## Remaining manual acceptance

- **Stage D** — on-device calibration across the FKSS age/body matrix
  (spec §6, §15): tune the constants above; confirm the rotation
  convention (`kMlKitReturnsUprightCoordinates`) and the
  preview↔picture FOV assumption per device class; confirm ML Kit pose
  (Beta) runtime stability on low-end devices.
- **Stage E** — offline behavior, image quality, 3:4 output, device
  performance, storage/recovery, Android compatibility, future iOS.
