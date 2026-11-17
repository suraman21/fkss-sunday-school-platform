import 'dart:async';
import 'dart:io';
import 'dart:math' as math;

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../services/device_tier_service.dart';
import '../../services/photo_capture_service.dart';
import '../../services/photo_framing_detector.dart';
import '../../services/photo_framing_engine.dart';
import '../../services/photo_framing_geometry.dart';
import '../../services/photo_gallery_import.dart';

// ---------------------------------------------------------------------------
// Palette — dark professional capture UI with controlled green/amber status
// and cyan framing accents (approved FKSS camera reference).
// ---------------------------------------------------------------------------
const Color _kBg = Color(0xFF05070C);
const Color _kPanel = Color(0xFF101725);
const Color _kPanelBorder = Color(0xFF1E293B);
const Color _kControl = Color(0xFF0B111B);
const Color _kButton = Color(0xFF131C2B);
const Color _kBlue = Color(0xFF3B82F6);
const Color _kCyan = Color(0xFF38BDF8);
const Color _kGreen = Color(0xFF22C55E);
const Color _kAmber = Color(0xFFF59E0B);
const Color _kTextDim = Color(0xFF94A3B8);
const Color _kGold = Color(0xFFD4AF37);
const Color _kDim = Color(0x73000000);

/// FKSS adaptive portrait capture (spec: Adaptive Human-Guided Photo
/// Capture v1.0).
///
/// Layout (spec §4): crest header with offline-readiness pill and
/// settings; instruction card above the preview; the live preview scaled
/// to EXACTLY the 3:4 region that will be saved (FR-03), with the
/// adaptive landmark-driven guide; three status cards (face centered /
/// eyes on line / shoulders visible, green-amber-neutral); dark control
/// bar with gallery, shutter and camera switch.
///
/// Detection is on-device (ML Kit, bundled models — no internet) and is
/// advisory only: the shutter is never disabled by detection (FR-09).
/// When detection is unavailable the screen falls back to the static
/// manual guide (FR-18).
class PhotoCameraScreen extends StatefulWidget {
  final String memberName;

  const PhotoCameraScreen({super.key, required this.memberName});

  @override
  State<PhotoCameraScreen> createState() => _PhotoCameraScreenState();
}

class _PhotoCameraScreenState extends State<PhotoCameraScreen>
    with WidgetsBindingObserver {
  CameraController? _controller;
  List<CameraDescription> _cameras = const [];
  CameraDescription? _selectedCamera;
  bool _initializing = false;
  bool _takingPicture = false;
  bool _appResumed = true;
  String? _error;
  String? _controlsNotice;
  FlashMode _flashMode = FlashMode.off;
  double _minExposure = 0;
  double _maxExposure = 0;
  double _exposure = 0;
  double _minZoom = 1;
  double _maxZoom = 1;
  double _zoom = 1;

  // Adaptive framing (spec §5, §8).
  final PhotoFramingDetector _detector = PhotoFramingDetector();
  final PhotoFramingEngine _engine = PhotoFramingEngine();
  final ValueNotifier<FramingOverlayData?> _overlay =
      ValueNotifier<FramingOverlayData?>(null);
  Timer? _detectionTimer;
  FramingVerdict? _verdict;
  RawDetection? _lastProcessed;
  DateTime? _lastFrameAt;
  bool _detectionStalled = false;
  bool _hapticsReady = true; // vibrate once when framing becomes ready
  // Latest displayed 3:4 frame, normalized to the upright image. Written
  // every layout; read by detection mapping (same window for checks and
  // overlay) and by capture (the processor crops exactly this window).
  RectF? _lastFrameInImage;

  /// Shown when the detector stops producing results: every check is
  /// neutral and capture stays available (spec §8 "poor detection
  /// confidence", FR-18). A stale green must never survive a stalled
  /// detector.
  static const FramingVerdict _stalledVerdict = FramingVerdict(
    faceCentered: CheckState.neutral,
    eyesAligned: CheckState.neutral,
    shouldersVisible: CheckState.neutral,
    message: 'Adjust the lighting or framing',
    ready: false,
    personDetected: false,
  );

  ResolutionPreset get _resolutionPreset =>
      switch (DeviceTierService.instance.tier) {
        DeviceTier.low => ResolutionPreset.high,
        DeviceTier.mid => ResolutionPreset.veryHigh,
        DeviceTier.high => ResolutionPreset.max,
      };

  bool get _isFrontCamera =>
      _selectedCamera?.lensDirection == CameraLensDirection.front;

  /// Aspect ratio (width / height) of the upright camera image — the
  /// landscape stream aspect inverted for the portrait app. This matches
  /// what `CameraPreview` displays and what the 3:4 processor crop is
  /// taken from.
  double get _uprightAspect {
    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) {
      return kPhotoFrameAspect;
    }
    final raw = controller.value.aspectRatio;
    return raw > 1 ? 1 / raw : raw;
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _loadHaptics();
    unawaited(_initializeCamera());
  }

  Future<void> _loadHaptics() async {
    final prefs = await SharedPreferences.getInstance();
    if (mounted) {
      setState(() =>
          _hapticsReady = prefs.getBool('photo_capture_haptics') ?? true);
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.hidden ||
        state == AppLifecycleState.inactive ||
        state == AppLifecycleState.paused) {
      _appResumed = false;
      _detectionTimer?.cancel();
      unawaited(_releaseCamera());
    } else if (state == AppLifecycleState.resumed) {
      _appResumed = true;
      if (_takingPicture) return;
      unawaited(_initializeCamera());
    }
  }

  Future<void> _initializeCamera({CameraDescription? preferred}) async {
    if (_initializing || !mounted) return;
    _initializing = true;
    setState(() {
      _error = null;
      _controlsNotice = null;
    });

    try {
      if (_cameras.isEmpty) {
        _cameras = await availableCameras();
      }
      if (_cameras.isEmpty) {
        throw StateError('No camera is available on this device.');
      }
      final selected = preferred ??
          _selectedCamera ??
          _cameras.firstWhere(
            (camera) => camera.lensDirection == CameraLensDirection.back,
            orElse: () => _cameras.first,
          );
      final controller = CameraController(
        selected,
        _resolutionPreset,
        enableAudio: false,
      );
      _controller = controller;
      await controller.initialize();
      if (!mounted || !_appResumed) {
        if (identical(_controller, controller)) _controller = null;
        await controller.dispose();
        return;
      }
      _selectedCamera = selected;
      await _loadCameraControls(controller);
      // Adaptive guide: consume frames from the live controller. The
      // detector degrades to no-ops if the stream cannot start.
      _engine.reset();
      _lastProcessed = null;
      _lastFrameAt = null;
      _detectionStalled = false;
      await _detector.attach(controller);
      if (mounted) setState(() {});
      _detectionTimer?.cancel();
      _detectionTimer = Timer.periodic(const Duration(milliseconds: 66), (_) {
        if (!mounted || !_appResumed) return;
        _processLatestDetection();
      });
    } on CameraException catch (e) {
      final failedController = _controller;
      _controller = null;
      await failedController?.dispose();
      if (mounted) {
        setState(() => _error = 'Camera unavailable: ${e.description}');
      }
    } catch (e) {
      final failedController = _controller;
      _controller = null;
      await failedController?.dispose();
      if (mounted) setState(() => _error = 'Could not open the camera: $e');
    } finally {
      _initializing = false;
      if (mounted) setState(() {});
    }
  }

  /// Maps the detector's latest raw result into 3:4 frame space, feeds
  /// the feedback engine and refreshes the overlay + instruction panel.
  void _processLatestDetection() {
    final raw = _detector.latest;
    if (raw == null || identical(raw, _lastProcessed)) {
      _checkDetectionStall();
      return;
    }
    _lastProcessed = raw;
    _lastFrameAt = DateTime.now();
    _detectionStalled = false;

    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) return;

    // Virtual preview with the upright image aspect: frame-normalized
    // coordinates are invariant to the displayed size. Checks run in the
    // DISPLAYED 3:4 frame — the exact window the overlay draws and the
    // processor saves — so all three agree by construction (FR-03).
    final preview = SizeF(1000, 1000 / _uprightAspect);
    final frame = _displayedFrameIn(preview);
    final mirror = _isFrontCamera;

    final faces = raw.faces;
    RectF? faceBoxFrame;
    OffsetF? leftEyeFrame;
    OffsetF? rightEyeFrame;
    if (faces.isNotEmpty) {
      final f = faces.first;
      final tl = detectionPointToFrame(
        OffsetF(f.box.left, f.box.top), raw.uprightImageSize, frame,
        preview: preview);
      final br = detectionPointToFrame(
        OffsetF(f.box.right, f.box.bottom), raw.uprightImageSize, frame,
        preview: preview);
      faceBoxFrame = RectF(
        left: tl.x,
        top: tl.y,
        width: (br.x - tl.x).abs(),
        height: (br.y - tl.y).abs(),
      );
      leftEyeFrame = f.leftEye == null
          ? null
          : detectionPointToFrame(f.leftEye!, raw.uprightImageSize, frame,
              preview: preview);
      rightEyeFrame = f.rightEye == null
          ? null
          : detectionPointToFrame(f.rightEye!, raw.uprightImageSize, frame,
              preview: preview);
    }

    PoseObservation? poseObs;
    if (raw.pose != null) {
      final p = raw.pose!;
      poseObs = PoseObservation(
        leftShoulder: p.leftShoulder == null
            ? null
            : detectionPointToFrame(p.leftShoulder!, raw.uprightImageSize,
                frame, preview: preview),
        rightShoulder: p.rightShoulder == null
            ? null
            : detectionPointToFrame(p.rightShoulder!, raw.uprightImageSize,
                frame, preview: preview),
        leftConfidence: p.leftConfidence,
        rightConfidence: p.rightConfidence,
      );
    }

    final verdict = _engine.evaluate(
      FrameObservation(
        faceCount: faces.length,
        faceBox: faceBoxFrame,
        leftEye: leftEyeFrame,
        rightEye: rightEyeFrame,
        headTiltDegrees: faces.isNotEmpty ? faces.first.headTiltDegrees : null,
        pose: poseObs,
      ),
      DateTime.now(),
    );

    _overlay.value = FramingOverlayData(
      faceBox: faceBoxFrame,
      leftEye: leftEyeFrame,
      rightEye: rightEyeFrame,
      pose: poseObs,
      mirror: mirror,
    );

    final changed = _verdict == null ||
        _verdict!.message != verdict.message ||
        _verdict!.ready != verdict.ready ||
        _verdict!.faceCentered != verdict.faceCentered ||
        _verdict!.eyesAligned != verdict.eyesAligned ||
        _verdict!.shouldersVisible != verdict.shouldersVisible;
    if (changed && mounted) setState(() => _verdict = verdict);

    if (verdict.ready && _hapticsReady) {
      _hapticsReady = false;
      HapticFeedback.mediumImpact();
    } else if (!verdict.ready) {
      _hapticsReady = true;
    }
  }

  /// Displayed 3:4 frame expressed in the given full-image (virtual
  /// preview) coordinates. Before the first layout there is no displayed
  /// frame yet, so the maximal centered crop stands in for a frame or two
  /// (no overlay is drawn from it — landmarks need a verdict first).
  RectF _displayedFrameIn(SizeF preview) {
    final n = _lastFrameInImage;
    if (n == null) return frameRectInPreview(preview);
    return RectF(
      left: n.left * preview.width,
      top: n.top * preview.height,
      width: n.width * preview.width,
      height: n.height * preview.height,
    );
  }

  /// A detector that errors or stops producing results must not leave a
  /// stale green verdict on screen: every check drops to neutral and the
  /// operator is told to adjust lighting or framing (spec §8, FR-18).
  /// Capture stays available throughout (FR-09).
  void _checkDetectionStall() {
    if (_detectionStalled) return;
    final lastFrameAt = _lastFrameAt;
    final stalled = _detector.lastError != null ||
        (lastFrameAt != null &&
            DateTime.now().difference(lastFrameAt) >
                const Duration(milliseconds: 2000));
    if (!stalled) return;
    _detectionStalled = true;
    _engine.reset();
    _overlay.value = null;
    _hapticsReady = true;
    if (mounted) setState(() => _verdict = _stalledVerdict);
  }

  Future<void> _loadCameraControls(CameraController controller) async {
    try {
      await controller.setFlashMode(FlashMode.off);
      _flashMode = FlashMode.off;
    } on CameraException catch (e) {
      _controlsNotice = 'Flash control unavailable: ${e.description}';
    }
    try {
      _minExposure = await controller.getMinExposureOffset();
      _maxExposure = await controller.getMaxExposureOffset();
      _exposure = 0.clamp(_minExposure, _maxExposure).toDouble();
    } on CameraException catch (e) {
      _controlsNotice = 'Exposure adjustment unavailable: ${e.description}';
      _minExposure = 0;
      _maxExposure = 0;
    }
    try {
      _minZoom = await controller.getMinZoomLevel();
      _maxZoom = await controller.getMaxZoomLevel();
      _zoom = _minZoom;
    } on CameraException catch (e) {
      _controlsNotice ??= 'Zoom control unavailable: ${e.description}';
      _minZoom = 1;
      _maxZoom = 1;
    }
  }

  Future<void> _releaseCamera() async {
    final controller = _controller;
    _controller = null;
    _detectionTimer?.cancel();
    _overlay.value = null;
    _engine.reset();
    _lastProcessed = null;
    _lastFrameAt = null;
    _detectionStalled = false;
    _verdict = null;
    await _detector.detach();
    if (mounted) setState(() {});
    try {
      await controller?.dispose();
    } on CameraException catch (e) {
      if (mounted) {
        setState(() => _error = 'Could not release camera: ${e.description}');
      }
    }
  }

  Future<void> _switchCamera() async {
    final current = _selectedCamera;
    if (current == null || _cameras.length < 2 || _takingPicture) return;
    final next = _cameras.firstWhere(
      (camera) => camera.lensDirection != current.lensDirection,
      orElse: () => _cameras.first,
    );
    final previous = _controller;
    _controller = null;
    await previous?.dispose();
    if (!mounted) return;
    await _initializeCamera(preferred: next);
  }

  Future<void> _setExposure(double value) async {
    final controller = _controller;
    if (controller == null || _takingPicture) return;
    try {
      final applied = await controller.setExposureOffset(value);
      if (mounted) setState(() => _exposure = applied);
    } on CameraException catch (e) {
      if (mounted) {
        setState(() =>
            _controlsNotice = 'Could not adjust exposure: ${e.description}');
      }
    }
  }

  Future<void> _setZoom(double value) async {
    final controller = _controller;
    if (controller == null || _takingPicture) return;
    try {
      await controller.setZoomLevel(value);
      if (mounted) setState(() => _zoom = value);
    } on CameraException catch (e) {
      if (mounted) {
        setState(
            () => _controlsNotice = 'Could not adjust zoom: ${e.description}');
      }
    }
  }

  /// Tap-to-focus. The tap arrives as fractions of the full upright
  /// image ([tapToFocusPoint] — correct even where the image overflows
  /// the visible area), which is exactly what the plugin expects.
  Future<void> _focusAt(OffsetF point) async {
    final controller = _controller;
    if (controller == null || _takingPicture) return;
    final focus = Offset(point.x, point.y);
    try {
      await controller.setFocusPoint(focus);
      await controller.setExposurePoint(focus);
    } on CameraException catch (e) {
      if (mounted) {
        setState(() =>
            _controlsNotice = 'Tap-to-focus unavailable: ${e.description}');
      }
    }
  }

  Future<void> _importFromGallery() async {
    if (_takingPicture) return;
    try {
      final capture = await PhotoGalleryImport.pickAndNormalize();
      if (!mounted) return;
      if (capture == null) return; // operator cancelled the picker
      if (!await File(capture.path).exists()) {
        if (mounted) {
          setState(
              () => _controlsNotice = 'Could not read the selected photo.');
        }
        return;
      }
      if (mounted) Navigator.of(context).pop(capture);
    } catch (e) {
      if (mounted) {
        setState(() => _controlsNotice = 'Import failed: $e');
      }
    }
  }

  Future<void> _takePicture() async {
    final controller = _controller;
    if (controller == null ||
        _takingPicture ||
        !_appResumed ||
        !controller.value.isInitialized) {
      return;
    }
    setState(() {
      _takingPicture = true;
      _error = null;
    });
    try {
      final image = await controller.takePicture();
      if (!mounted) {
        try {
          await File(image.path).delete();
        } on FileSystemException catch (e) {
          debugPrint('Could not remove abandoned camera image: $e');
        }
        return;
      }
      Navigator.of(context).pop(
        CapturedPhoto(
          path: image.path,
          takenAt: DateTime.now().toUtc(),
          // The processor crops exactly the frame the operator saw.
          crop: _lastFrameInImage,
        ),
      );
    } on CameraException catch (e) {
      if (mounted) {
        setState(() {
          _takingPicture = false;
          _error = 'Could not take the photo: ${e.description}';
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _takingPicture = false;
          _error = 'Could not take the photo: $e';
        });
      }
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _detectionTimer?.cancel();
    unawaited(_detector.dispose());
    _overlay.dispose();
    unawaited(_controller?.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final controller = _controller;
    final ready = controller != null && controller.value.isInitialized;
    final verdict = _verdict;
    return Scaffold(
      backgroundColor: _kBg,
      body: SafeArea(
        child: Column(
          children: [
            _header(ready),
            if (ready) ...[
              _instructionCard(verdict),
              if (_controlsNotice != null) _noticeLine(),
              Expanded(child: _previewArea(controller)),
              _bottomBar(ready),
            ] else
              Expanded(
                child: Center(
                  child: _error == null
                      ? const CircularProgressIndicator(color: _kGreen)
                      : _CameraError(
                          message: _error!,
                          onRetry: () => _initializeCamera(
                            preferred: _selectedCamera,
                          ),
                        ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  // --- Header (spec §4.1) ----------------------------------------------

  Widget _header(bool ready) => Padding(
        padding: const EdgeInsets.fromLTRB(14, 8, 12, 8),
        child: Row(
          children: [
            Container(
              width: 56,
              height: 56,
              padding: const EdgeInsets.all(2),
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                border: Border.all(color: _kGold, width: 2),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.45),
                    blurRadius: 10,
                    offset: const Offset(0, 2),
                  ),
                ],
              ),
              child: ClipOval(
                child: Image.asset(
                  'assets/logo_school.png',
                  fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) => Container(
                    color: const Color(0xFF600000),
                    alignment: Alignment.center,
                    child: const Text(
                      'F',
                      style: TextStyle(
                        color: Color(0xFFF0C000),
                        fontWeight: FontWeight.w800,
                        fontSize: 22,
                      ),
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(width: 12),
            const Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    'FKSS',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 19,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 0.4,
                      height: 1.1,
                    ),
                  ),
                  SizedBox(height: 2),
                  Text(
                    'Photo Capture',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: _kTextDim,
                      fontSize: 12.5,
                      fontWeight: FontWeight.w500,
                      height: 1.1,
                    ),
                  ),
                ],
              ),
            ),
            _OfflinePill(state: _offlineState()),
            const SizedBox(width: 8),
            _RoundIconButton(
              icon: Icons.settings,
              tooltip: 'Capture settings',
              onTap: _openSettings,
            ),
          ],
        ),
      );

  /// "Offline Ready" reflects on-device capture readiness — detection
  /// running with no errors — not network state (spec §4.1).
  _ChipState _offlineState() {
    if (_detector.isReady && _detector.lastError == null) {
      return _ChipState.good;
    }
    if (_detector.isReady && _detector.lastError != null) {
      return _ChipState.warn;
    }
    return _ChipState.neutral;
  }

  Widget _noticeLine() => Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 6),
        child: Text(
          _controlsNotice!,
          textAlign: TextAlign.center,
          style: const TextStyle(color: _kAmber, fontSize: 11.5),
        ),
      );

  void _openSettings() {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: const Color(0xFF121826),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => SafeArea(
        child: StatefulBuilder(
          builder: (ctx, setSheet) {
            final hasExposure = _maxExposure > _minExposure;
            final hasZoom = _maxZoom > _minZoom;
            return Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Capture settings',
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 16,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 10),
                  SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    value: _hapticsReady,
                    onChanged: (v) async {
                      final prefs = await SharedPreferences.getInstance();
                      await prefs.setBool('photo_capture_haptics', v);
                      if (mounted) setState(() => _hapticsReady = v);
                      setSheet(() {});
                    },
                    title: const Text(
                      'Vibrate when framing is ready',
                      style: TextStyle(color: Colors.white, fontSize: 14),
                    ),
                    subtitle: const Text(
                      'Off does not affect capture',
                      style: TextStyle(color: Colors.white54, fontSize: 12),
                    ),
                    activeThumbColor: _kGreen,
                  ),
                  const Divider(color: Colors.white12),
                  const Text(
                    'Flash',
                    style: TextStyle(color: Colors.white70, fontSize: 13),
                  ),
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 8,
                    children: [
                      for (final mode in [
                        FlashMode.off,
                        FlashMode.auto,
                        FlashMode.torch,
                      ])
                        ChoiceChip(
                          label: Text(switch (mode) {
                            FlashMode.off => 'Off',
                            FlashMode.auto => 'Auto',
                            FlashMode.torch || FlashMode.always => 'Torch',
                          }),
                          selected: _flashMode == mode,
                          onSelected: (_) async {
                            await _setFlash(mode);
                            setSheet(() {});
                          },
                          selectedColor: _kGreen,
                        ),
                    ],
                  ),
                  if (hasExposure) ...[
                    const Divider(color: Colors.white12),
                    _SheetSlider(
                      label: 'Brightness',
                      icon: Icons.brightness_6_outlined,
                      value: _exposure.clamp(_minExposure, _maxExposure),
                      min: _minExposure,
                      max: _maxExposure,
                      onChanged: (v) =>
                          setSheet(() => _exposure = v.clamp(_minExposure, _maxExposure)),
                      onChangeEnd: (v) async {
                        await _setExposure(v);
                        setSheet(() {});
                      },
                    ),
                  ],
                  if (hasZoom) ...[
                    const Divider(color: Colors.white12),
                    _SheetSlider(
                      label: 'Zoom',
                      icon: Icons.zoom_in,
                      value: _zoom.clamp(_minZoom, _maxZoom),
                      min: _minZoom,
                      max: _maxZoom,
                      onChanged: (v) =>
                          setSheet(() => _zoom = v.clamp(_minZoom, _maxZoom)),
                      onChangeEnd: (v) async {
                        await _setZoom(v);
                        setSheet(() {});
                      },
                    ),
                  ],
                  const Divider(color: Colors.white12),
                  Row(
                    children: [
                      TextButton.icon(
                        onPressed: () async {
                          await _setZoom(_minZoom);
                          if (_maxExposure > _minExposure) {
                            await _setExposure(
                                0.clamp(_minExposure, _maxExposure).toDouble());
                          }
                          setSheet(() {});
                        },
                        icon: const Icon(Icons.restart_alt, size: 18),
                        label: const Text('Reset zoom & brightness'),
                      ),
                      const Spacer(),
                      TextButton.icon(
                        onPressed: () {
                          Navigator.pop(ctx);
                          Navigator.of(context).pop();
                        },
                        icon: const Icon(Icons.close, size: 18),
                        label: const Text('Close camera'),
                      ),
                    ],
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Future<void> _setFlash(FlashMode next) async {
    final controller = _controller;
    if (controller == null) return;
    try {
      await controller.setFlashMode(next);
      if (mounted) setState(() => _flashMode = next);
    } on CameraException catch (e) {
      if (mounted) {
        setState(() =>
            _controlsNotice = 'Flash control unavailable: ${e.description}');
      }
    }
  }

  // --- Instruction card (spec §4.2) ------------------------------------

  Widget _instructionCard(FramingVerdict? verdict) {
    final ready = verdict?.ready ?? false;
    var message = verdict == null
        ? 'Place the student inside the frame'
        : (verdict.message ?? 'Place the student inside the frame');
    // UI wording for the engine's generic idle state.
    if (message == 'Position the person in front of the camera') {
      message = 'Place the student inside the frame';
    }
    if (ready) message = 'Perfect! Ready to capture';
    final subtitle = ready
        ? 'Good framing — hold still and tap the shutter'
        : 'Keep the eyes on the guide line and shoulders visible';
    final accent = ready ? _kGreen : _kBlue;
    return Container(
      margin: const EdgeInsets.fromLTRB(14, 2, 14, 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: _kPanel,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: _kPanelBorder),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(color: accent, shape: BoxShape.circle),
            alignment: Alignment.center,
            child: Icon(
              ready ? Icons.check : Icons.info_outline,
              color: Colors.white,
              size: 21,
            ),
          ),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  message,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: ready ? _kGreen : Colors.white,
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 3),
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        subtitle,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: _kTextDim,
                          fontSize: 11.5,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ),
                    if (widget.memberName.isNotEmpty) ...[
                      const SizedBox(width: 8),
                      ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 110),
                        child: Text(
                          widget.memberName,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          textAlign: TextAlign.right,
                          style: const TextStyle(
                            color: Colors.white38,
                            fontSize: 11,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // --- Preview (spec §4.3) ----------------------------------------------

  /// Full-bleed preview (approved FKSS reference): the whole upright
  /// camera image at its TRUE aspect — the box handed to `CameraPreview`
  /// is exactly [_uprightAspect], so the texture can never stretch
  /// (FR-02) — with the centered 3:4 crop frame drawn as a window
  /// inside it. The dimmed surround is outside the saved photo; the
  /// frame edge on screen IS the saved-file edge (FR-03), because the
  /// processor crops exactly [PreviewLayout.frameInImage].
  Widget _previewArea(CameraController controller) {
    final aspect = _uprightAspect;
    return LayoutBuilder(
      builder: (context, constraints) {
        final areaW = constraints.maxWidth;
        final areaH = constraints.maxHeight;
        if (areaW <= 0 || areaH <= 0) return const SizedBox.shrink();
        final layout = previewLayout(
          area: SizeF(areaW, areaH),
          imageAspect: aspect,
        );
        _lastFrameInImage = layout.frameInImage;
        final img = layout.image;
        final frame = layout.frame;
        assert(
          (img.width / img.height - aspect).abs() < 1e-9,
          'preview box must carry the true camera aspect (no stretch)',
        );
        return Stack(
          children: [
            // Full camera image, true aspect. Cover-fit: it may overflow
            // the area vertically (tall sensors) or horizontally (wide
            // sensors); the Stack clips the overflow.
            Positioned(
              left: img.left,
              top: img.top,
              width: img.width,
              height: img.height,
              child: CameraPreview(controller),
            ),
            // Tap-to-focus anywhere on the live image.
            Positioned.fill(
              child: GestureDetector(
                behavior: HitTestBehavior.translucent,
                onTapDown: (details) => _focusAt(tapToFocusPoint(
                  tap: OffsetF(
                    details.localPosition.dx,
                    details.localPosition.dy,
                  ),
                  image: img,
                )),
              ),
            ),
            // Dimmed surround: four bands around the frame showing what
            // falls OUTSIDE the saved photo.
            Positioned(
              left: 0,
              right: 0,
              top: 0,
              height: frame.top,
              child: Container(color: _kDim),
            ),
            Positioned(
              left: 0,
              right: 0,
              top: frame.bottom,
              bottom: 0,
              child: Container(color: _kDim),
            ),
            Positioned(
              left: 0,
              top: frame.top,
              width: frame.left,
              height: frame.height,
              child: Container(color: _kDim),
            ),
            Positioned(
              left: frame.right,
              right: 0,
              top: frame.top,
              height: frame.height,
              child: Container(color: _kDim),
            ),
            // The 3:4 crop frame: static guides + adaptive landmark
            // layer, with the Perfect badge at its top-right corner.
            Positioned(
              left: frame.left,
              top: frame.top,
              width: frame.width,
              height: frame.height,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  CustomPaint(
                    painter: _AdaptiveGuidePainter(_overlay),
                  ),
                  if (_verdict?.ready ?? false)
                    const Align(
                      alignment: Alignment.topRight,
                      child: Padding(
                        padding: EdgeInsets.all(12),
                        child: _PerfectBadge(),
                      ),
                    ),
                ],
              ),
            ),
            Align(
              alignment: Alignment.centerLeft,
              child: Padding(
                padding: const EdgeInsets.only(left: 8),
                child: _StatusRail(verdict: _verdict),
              ),
            ),
            const Align(
              alignment: Alignment(1.0, -0.25),
              child: Padding(
                padding: EdgeInsets.only(right: 8),
                child: _PassportCard(),
              ),
            ),
          ],
        );
      },
    );
  }

  // --- Bottom controls (spec §4.5) --------------------------------------

  Widget _bottomBar(bool ready) {
    return Container(
      width: double.infinity,
      decoration: const BoxDecoration(
        color: _kControl,
        border: Border(top: BorderSide(color: _kPanelBorder)),
      ),
      padding: const EdgeInsets.fromLTRB(20, 14, 20, 10),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              _ActionButton(
                icon: Icons.photo_library_outlined,
                label: 'Gallery',
                onTap: _takingPicture ? null : _importFromGallery,
              ),
              _ShutterButton(
                busy: _takingPicture,
                enabled: ready && _appResumed,
                onTap: _takePicture,
              ),
              _ActionButton(
                icon: Icons.cameraswitch_outlined,
                label: 'Switch',
                onTap:
                    _cameras.length > 1 && !_takingPicture ? _switchCamera : null,
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const _ModeTab(label: 'Photo', active: true),
              const SizedBox(width: 36),
              _ModeTab(
                label: 'Video',
                active: false,
                onTap: () {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(
                      content: Text(
                          'Video capture is not used for member photos.'),
                      duration: Duration(seconds: 2),
                    ),
                  );
                },
              ),
            ],
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Overlay data + adaptive guide painter (spec §4.3, §5)
// ---------------------------------------------------------------------------

/// Landmarks mapped into 3:4 frame space, plus the front-camera mirror
/// flag used only when drawing (checks run in capture space).
class FramingOverlayData {
  final RectF? faceBox;
  final OffsetF? leftEye;
  final OffsetF? rightEye;
  final PoseObservation? pose;
  final bool mirror;

  const FramingOverlayData({
    required this.faceBox,
    required this.leftEye,
    required this.rightEye,
    required this.pose,
    required this.mirror,
  });
}

/// Adaptive guide painter. Static layer: cyan 3:4 frame (the exact saved
/// crop) with corner brackets, centerline and eye-line guide. Dynamic
/// layer (when landmarks are available): a head outline from the
/// detected face box, a landmark eye line and a shoulder curve from pose
/// landmarks — the guide adapts to the person instead of forcing one
/// silhouette (spec §5.3). Without landmarks only the static guide is
/// drawn (FR-18).
class _AdaptiveGuidePainter extends CustomPainter {
  _AdaptiveGuidePainter(this._data) : super(repaint: _data);

  final ValueNotifier<FramingOverlayData?> _data;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    _drawFrame(canvas, rect);
    _drawGuideLines(canvas, rect);
    _drawBrackets(canvas, rect);
    _drawAdaptiveOutline(canvas, rect);
  }

  void _drawFrame(Canvas canvas, Rect rect) {
    final frame = RRect.fromRectAndRadius(
      rect.deflate(1.5),
      const Radius.circular(21),
    );
    // Soft glow, then the crisp cyan edge.
    canvas.drawRRect(
      frame,
      Paint()
        ..color = _kCyan.withValues(alpha: 0.30)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 9
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 8),
    );
    canvas.drawRRect(
      frame,
      Paint()
        ..color = _kCyan.withValues(alpha: 0.95)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2.6,
    );
  }

  void _drawGuideLines(Canvas canvas, Rect rect) {
    // Vertical centerline.
    _dashedLine(
      canvas,
      Offset(rect.center.dx, rect.top + 2),
      Offset(rect.center.dx, rect.bottom - 2),
      Paint()
        ..color = Colors.white.withValues(alpha: 0.80)
        ..strokeWidth = 1.2,
      dash: 7,
      gap: 6,
    );
    // Eye-alignment line.
    final eyeY = rect.top + rect.height * 0.42;
    _dashedLine(
      canvas,
      Offset(rect.left + 2, eyeY),
      Offset(rect.right - 2, eyeY),
      Paint()
        ..color = Colors.white.withValues(alpha: 0.88)
        ..strokeWidth = 1.4,
      dash: 8,
      gap: 6,
    );
  }

  void _drawBrackets(Canvas canvas, Rect rect) {
    final paint = Paint()
      ..color = Colors.white.withValues(alpha: 0.92)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 4.2
      ..strokeCap = StrokeCap.round;
    const inset = 16.0;
    final len = math.min(rect.width, rect.height) * 0.075;
    final l = rect.left + inset;
    final r = rect.right - inset;
    final t = rect.top + inset;
    final b = rect.bottom - inset;
    // Top-left
    canvas.drawLine(Offset(l, t), Offset(l + len, t), paint);
    canvas.drawLine(Offset(l, t), Offset(l, t + len), paint);
    // Top-right
    canvas.drawLine(Offset(r, t), Offset(r - len, t), paint);
    canvas.drawLine(Offset(r, t), Offset(r, t + len), paint);
    // Bottom-left
    canvas.drawLine(Offset(l, b), Offset(l + len, b), paint);
    canvas.drawLine(Offset(l, b), Offset(l, b - len), paint);
    // Bottom-right
    canvas.drawLine(Offset(r, b), Offset(r - len, b), paint);
    canvas.drawLine(Offset(r, b), Offset(r, b - len), paint);
  }

  void _drawAdaptiveOutline(Canvas canvas, Rect rect) {
    final data = _data.value;
    if (data == null) return;
    final mirror = data.mirror;
    Offset map(OffsetF n) => Offset(
          mirror ? rect.right - n.x * rect.width : rect.left + n.x * rect.width,
          rect.top + n.y * rect.height,
        );

    final outline = Paint()
      ..color = _kCyan.withValues(alpha: 0.55)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round;

    // Head contour from the detected face box — adapts to head size and
    // head coverings rather than a fixed oval.
    final faceBox = data.faceBox;
    if (faceBox != null) {
      final left = math.min(faceBox.left, faceBox.right);
      final top = math.min(faceBox.top, faceBox.bottom);
      final headRect = Rect.fromLTRB(
        map(OffsetF(left, top)).dx,
        rect.top + top * rect.height,
        map(OffsetF(left + faceBox.width, top)).dx,
        rect.top + (top + faceBox.height) * rect.height,
      );
      canvas.drawOval(
        headRect.inflate(4),
        Paint()
          ..color = _kCyan.withValues(alpha: 0.30)
          ..style = PaintingStyle.stroke
          ..strokeWidth = 6
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 5),
      );
      canvas.drawOval(headRect, outline);
    }

    // Detected eye line (FR-06) — a short cyan segment through the real
    // eye landmarks, drawn over the static alignment guide.
    final leftEye = data.leftEye;
    final rightEye = data.rightEye;
    if (leftEye != null && rightEye != null) {
      final a = map(leftEye);
      final b = map(rightEye);
      canvas.drawLine(
        a,
        b,
        Paint()
          ..color = _kCyan.withValues(alpha: 0.85)
          ..strokeWidth = 2.2
          ..strokeCap = StrokeCap.round,
      );
    }

    // Shoulder curve from pose landmarks (FR-08), only when both are
    // present — a partial or low-confidence pose leaves the neutral
    // static guide in charge.
    final pose = data.pose;
    if (pose != null &&
        pose.leftShoulder != null &&
        pose.rightShoulder != null) {
      final l = map(pose.leftShoulder!);
      final r = map(pose.rightShoulder!);
      final neckY = math.min(l.dy, r.dy) - rect.height * 0.045;
      final centerX = (l.dx + r.dx) / 2;
      final path = Path()
        ..moveTo(l.dx - rect.width * 0.03, l.dy + rect.height * 0.03)
        ..quadraticBezierTo(l.dx, l.dy, l.dx + (r.dx - l.dx) * 0.16,
            l.dy - rect.height * 0.004)
        ..quadraticBezierTo(centerX, neckY,
            r.dx - (r.dx - l.dx) * 0.16, r.dy - rect.height * 0.004)
        ..quadraticBezierTo(r.dx, r.dy,
            r.dx + rect.width * 0.03, r.dy + rect.height * 0.03);
      canvas.drawPath(
        path,
        Paint()
          ..color = _kCyan.withValues(alpha: 0.25)
          ..style = PaintingStyle.stroke
          ..strokeWidth = 6
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 5),
      );
      canvas.drawPath(path, outline);
    }
  }

  void _dashedLine(
    Canvas canvas,
    Offset a,
    Offset b,
    Paint paint, {
    double dash = 10,
    double gap = 7,
  }) {
    final dx = b.dx - a.dx;
    final dy = b.dy - a.dy;
    final total = math.sqrt(dx * dx + dy * dy);
    if (total <= 0) return;
    final ux = dx / total;
    final uy = dy / total;
    var travelled = 0.0;
    while (travelled < total) {
      final end = math.min(travelled + dash, total);
      canvas.drawLine(
        a + Offset(ux * travelled, uy * travelled),
        a + Offset(ux * end, uy * end),
        paint,
      );
      travelled = end + gap;
    }
  }

  @override
  bool shouldRepaint(covariant _AdaptiveGuidePainter oldDelegate) =>
      oldDelegate._data != _data;
}

// ---------------------------------------------------------------------------
// Overlay widgets
// ---------------------------------------------------------------------------

enum _ChipState { good, warn, neutral }

class _OfflinePill extends StatelessWidget {
  final _ChipState state;

  const _OfflinePill({required this.state});

  @override
  Widget build(BuildContext context) {
    final (color, text) = switch (state) {
      _ChipState.good => (_kGreen, 'Offline Ready'),
      _ChipState.warn => (_kAmber, 'Detection limited'),
      _ChipState.neutral => (_kTextDim, 'Starting…'),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          ),
          const SizedBox(width: 6),
          Text(
            text,
            style: TextStyle(
              color: color,
              fontSize: 11.5,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}

class _RoundIconButton extends StatelessWidget {
  final IconData icon;
  final String tooltip;
  final VoidCallback onTap;

  const _RoundIconButton({
    required this.icon,
    required this.tooltip,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) => Tooltip(
        message: tooltip,
        child: Material(
          color: const Color(0xFF141B29),
          shape: const CircleBorder(
            side: BorderSide(color: _kPanelBorder),
          ),
          child: InkWell(
            customBorder: const CircleBorder(),
            onTap: onTap,
            child: SizedBox(
              width: 44,
              height: 44,
              child: Icon(icon, color: Colors.white, size: 21),
            ),
          ),
        ),
      );
}

/// One status card in the left rail — icon + text + state dot, so the
/// meaning never relies on colour alone (spec §13).
class _StatusRail extends StatelessWidget {
  final FramingVerdict? verdict;

  const _StatusRail({required this.verdict});

  static _ChipState _map(CheckState? state) => switch (state) {
        CheckState.pass => _ChipState.good,
        CheckState.warn => _ChipState.warn,
        _ => _ChipState.neutral,
      };

  @override
  Widget build(BuildContext context) => Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          _StatusCard(
            icon: Icons.face_outlined,
            label: 'Face\nCentered',
            state: _map(verdict?.faceCentered),
          ),
          const SizedBox(height: 10),
          _StatusCard(
            icon: Icons.visibility_outlined,
            label: 'Eyes\nOn Line',
            state: _map(verdict?.eyesAligned),
          ),
          const SizedBox(height: 10),
          _StatusCard(
            icon: Icons.accessibility_new,
            label: 'Shoulders\nVisible',
            state: _map(verdict?.shouldersVisible),
          ),
        ],
      );
}

class _StatusCard extends StatelessWidget {
  final IconData icon;
  final String label;
  final _ChipState state;

  const _StatusCard({
    required this.icon,
    required this.label,
    required this.state,
  });

  @override
  Widget build(BuildContext context) {
    final (color, dotIcon) = switch (state) {
      _ChipState.good => (_kGreen, Icons.check_circle),
      _ChipState.warn => (_kAmber, Icons.error_outline),
      _ChipState.neutral => (_kTextDim, Icons.help_outline),
    };
    return Container(
      width: 62,
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 7),
      decoration: BoxDecoration(
        color: const Color(0xFF0B1018).withValues(alpha: 0.88),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.white12),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, color: Colors.white70, size: 16),
              const Spacer(),
              Icon(dotIcon, color: color, size: 13),
            ],
          ),
          const SizedBox(height: 5),
          Text(
            label,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 9.5,
              fontWeight: FontWeight.w600,
              height: 1.15,
            ),
          ),
        ],
      ),
    );
  }
}

class _PassportCard extends StatelessWidget {
  const _PassportCard();

  @override
  Widget build(BuildContext context) => Container(
        width: 62,
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 8),
        decoration: BoxDecoration(
          color: const Color(0xFF0B1018).withValues(alpha: 0.88),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: _kCyan.withValues(alpha: 0.35)),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.crop_portrait, color: _kCyan, size: 26),
            const SizedBox(height: 4),
            const Text(
              '3:4',
              style: TextStyle(
                color: Colors.white,
                fontSize: 12,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 1),
            Text(
              '(Passport\nSize)',
              textAlign: TextAlign.center,
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.55),
                fontSize: 8.5,
                height: 1.15,
              ),
            ),
          ],
        ),
      );
}

class _PerfectBadge extends StatelessWidget {
  const _PerfectBadge();

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.fromLTRB(8, 5, 11, 5),
        decoration: BoxDecoration(
          color: _kGreen,
          borderRadius: BorderRadius.circular(999),
          boxShadow: [
            BoxShadow(
              color: _kGreen.withValues(alpha: 0.45),
              blurRadius: 12,
            ),
          ],
        ),
        child: const Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.check_circle, color: Colors.white, size: 15),
            SizedBox(width: 5),
            Text(
              'Perfect!',
              style: TextStyle(
                color: Colors.white,
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
      );
}

// ---------------------------------------------------------------------------
// Bottom bar widgets
// ---------------------------------------------------------------------------

class _ActionButton extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback? onTap;

  const _ActionButton({
    required this.icon,
    required this.label,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final enabled = onTap != null;
    return Opacity(
      opacity: enabled ? 1 : 0.45,
      child: Material(
        color: _kButton,
        borderRadius: BorderRadius.circular(999),
        child: InkWell(
          borderRadius: BorderRadius.circular(999),
          onTap: onTap,
          child: SizedBox(
            width: 104,
            height: 62,
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(icon, color: Colors.white, size: 22),
                const SizedBox(height: 4),
                Text(
                  label,
                  style: const TextStyle(
                    color: Colors.white70,
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _ShutterButton extends StatelessWidget {
  final bool busy;
  final bool enabled;
  final VoidCallback onTap;

  const _ShutterButton({
    required this.busy,
    required this.enabled,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: busy || !enabled ? null : onTap,
      child: Container(
        width: 84,
        height: 84,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          border: Border.all(
            color: _kCyan.withValues(alpha: 0.55),
            width: 2,
          ),
        ),
        alignment: Alignment.center,
        child: Container(
          width: 70,
          height: 70,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: busy ? Colors.grey.shade400 : Colors.white,
            boxShadow: [
              BoxShadow(
                color: _kCyan.withValues(alpha: 0.25),
                blurRadius: 14,
              ),
            ],
          ),
          alignment: Alignment.center,
          child: busy
              ? const SizedBox(
                  width: 26,
                  height: 26,
                  child: CircularProgressIndicator(
                    strokeWidth: 3,
                    color: Color(0xFF0B111B),
                  ),
                )
              : const Icon(
                  Icons.camera_alt,
                  color: Color(0xFF0B111B),
                  size: 32,
                ),
        ),
      ),
    );
  }
}

class _ModeTab extends StatelessWidget {
  final String label;
  final bool active;
  final VoidCallback? onTap;

  const _ModeTab({required this.label, required this.active, this.onTap});

  @override
  Widget build(BuildContext context) {
    final color = active ? _kBlue : Colors.white38;
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            label,
            style: TextStyle(
              color: color,
              fontSize: 13,
              fontWeight: active ? FontWeight.w700 : FontWeight.w600,
            ),
          ),
          const SizedBox(height: 4),
          Container(
            height: 2.5,
            width: 34,
            decoration: BoxDecoration(
              color: active ? _kBlue : Colors.transparent,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
        ],
      ),
    );
  }
}

class _SheetSlider extends StatelessWidget {
  final String label;
  final IconData icon;
  final double value;
  final double min;
  final double max;
  final ValueChanged<double> onChanged;
  final ValueChanged<double> onChangeEnd;

  const _SheetSlider({
    required this.label,
    required this.icon,
    required this.value,
    required this.min,
    required this.max,
    required this.onChanged,
    required this.onChangeEnd,
  });

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Icon(icon, color: Colors.white, size: 18),
          const SizedBox(width: 8),
          SizedBox(
            width: 82,
            child: Text(
              label,
              style: const TextStyle(color: Colors.white70, fontSize: 12.5),
            ),
          ),
          Expanded(
            child: Slider(
              value: value.clamp(min, max),
              min: min,
              max: max,
              activeColor: _kCyan,
              onChanged: onChanged,
              onChangeEnd: onChangeEnd,
            ),
          ),
        ],
      );
}

class _CameraError extends StatelessWidget {
  final String message;
  final VoidCallback onRetry;

  const _CameraError({required this.message, required this.onRetry});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.camera_alt_outlined,
                color: Colors.white70, size: 48),
            const SizedBox(height: 12),
            Text(
              message,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.white),
            ),
            const SizedBox(height: 16),
            FilledButton(onPressed: onRetry, child: const Text('Try again')),
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Close camera'),
            ),
          ],
        ),
      );
}
