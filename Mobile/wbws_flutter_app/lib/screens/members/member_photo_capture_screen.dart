import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';

import '../../services/photo_capture_service.dart';
import '../../services/photo_outbox_models.dart';
import '../../services/photo_outbox_service.dart';
import 'photo_camera_screen.dart';

/// FKSS brand colors used by photo-capture UI (matches admin dashboard).
/// Mobile theme.dart is app-wide blue; photo screens use the school
/// maroon+gold so they visually cohere with the printed ID product.
class _AppThemePhoto {
  static const Color maroon = Color(0xFF600000);
  static const Color gold = Color(0xFFF0C000);
}

// Shorthand alias for the capture-screen widgets below.
typedef AppTheme = _AppThemePhoto;

/// Single-member photo capture and review screen.
///
/// Shows the existing photo (when available), opens an in-app camera with a
/// framing guide, then reviews the queued image with Accept / Retake actions.
///
/// Route args:
///   arguments = {'memberId': int, 'memberCode': String?, 'memberName': String,
///                'field': PhotoField?, 'retakeOfClientOpId': String?,
///                'photoUrl': String?, 'authorizationToken': String?}
class MemberPhotoCaptureScreen extends StatefulWidget {
  const MemberPhotoCaptureScreen({super.key});

  @override
  State<MemberPhotoCaptureScreen> createState() =>
      _MemberPhotoCaptureScreenState();
}

class _MemberPhotoCaptureScreenState extends State<MemberPhotoCaptureScreen> {
  bool _capturing = false;
  bool _processing = false;
  PhotoOutboxEntry? _reviewEntry;
  String? _error;
  bool _loadedPreviousPhoto = false;
  String? _previousPhotoPath;
  String? _previousPhotoError;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (!_loadedPreviousPhoto) {
      _loadedPreviousPhoto = true;
      unawaited(_loadPreviousPhoto());
    }
  }

  int get _memberId => (ModalRoute.of(context)?.settings.arguments
      as Map<String, dynamic>?)?['memberId'] as int;
  String? get _memberCode => (ModalRoute.of(context)?.settings.arguments
      as Map<String, dynamic>?)?['memberCode'] as String?;
  String get _memberName =>
      ((ModalRoute.of(context)?.settings.arguments
          as Map<String, dynamic>?)?['memberName'] as String?) ??
      'Member';
  PhotoField get _field =>
      ((ModalRoute.of(context)?.settings.arguments
          as Map<String, dynamic>?)?['field'] as PhotoField?) ??
      PhotoField.student;
  String? get _retakeOf => (ModalRoute.of(context)?.settings.arguments
      as Map<String, dynamic>?)?['retakeOfClientOpId'] as String?;
  String? get _photoUrl => (ModalRoute.of(context)?.settings.arguments
      as Map<String, dynamic>?)?['photoUrl'] as String?;
  String? get _authorizationToken => (ModalRoute.of(context)?.settings.arguments
      as Map<String, dynamic>?)?['authorizationToken'] as String?;

  Future<void> _loadPreviousPhoto() async {
    try {
      final previous = await PhotoOutboxService.instance.latestForMember(
        _memberId,
        field: _field,
      );
      if (previous == null || !await File(previous.localPath).exists()) return;
      if (mounted) setState(() => _previousPhotoPath = previous.localPath);
    } catch (e) {
      if (mounted) {
        setState(
            () => _previousPhotoError = 'Could not load the saved photo: $e');
      }
    }
  }

  Future<void> _takePhoto({String? retakeOfClientOpId}) async {
    if (_capturing) return;
    setState(() {
      _capturing = true;
      _processing = false;
      _error = null;
    });
    try {
      final capture = await Navigator.of(context).push<CapturedPhoto>(
        MaterialPageRoute(
          fullscreenDialog: true,
          builder: (_) => PhotoCameraScreen(memberName: _memberName),
        ),
      );
      if (!mounted) return;
      if (capture == null) {
        setState(() {
          _capturing = false;
          _processing = false;
        });
        return;
      }
      setState(() => _processing = true);
      final entry = await PhotoCaptureService.instance.processAndEnqueue(
        capture: capture,
        memberId: _memberId,
        memberCode: _memberCode,
        field: _field,
        retakeOfClientOpId: retakeOfClientOpId ?? _retakeOf,
      );
      if (!mounted) return;
      setState(() {
        _reviewEntry = entry;
        _capturing = false;
        _processing = false;
      });
    } on PhotoCaptureException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _capturing = false;
        _processing = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = 'Unexpected error: $e';
        _capturing = false;
        _processing = false;
      });
    }
  }

  void _retake() {
    // Retake the photo currently in review. We pass its clientOpId so
    // the outbox marks the prior capture as discarded (with a
    // replaced_by pointer) instead of leaving orphaned files.
    final entry = _reviewEntry;
    setState(() {
      _reviewEntry = null;
      _error = null;
    });
    _takePhoto(retakeOfClientOpId: entry?.clientOpId);
  }

  void _accept() {
    final entry = _reviewEntry;
    if (entry == null) return;
    // No confirmation toast: the operator approved the exact photo in
    // review, so return to the member screen immediately.
    Navigator.of(context).pop(entry);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_field == PhotoField.student
            ? 'Capture ID Photo'
            : 'Capture Guardian Photo'),
      ),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _MemberCard(name: _memberName, code: _memberCode),
              const SizedBox(height: 16),
              Expanded(child: _buildBody()),
              const SizedBox(height: 16),
              _buildActions(),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildBody() {
    if (_error != null) {
      return _ErrorPanel(message: _error!, onRetry: _takePhoto);
    }
    if (_capturing) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const CircularProgressIndicator(),
            const SizedBox(height: 12),
            Text(_processing ? 'Preparing photo…' : 'Opening camera…'),
          ],
        ),
      );
    }
    final entry = _reviewEntry;
    if (entry != null) {
      return _ReviewPanel(entry: entry);
    }
    return _IdlePanel(
      photoUrl: _photoUrl,
      authorizationToken: _authorizationToken,
      localPhotoPath: _previousPhotoPath,
      previousPhotoError: _previousPhotoError,
    );
  }

  Widget _buildActions() {
    if (_reviewEntry != null) {
      return Row(
        children: [
          Expanded(
            child: OutlinedButton.icon(
              onPressed: _retake,
              icon: const Icon(Icons.refresh),
              label: const Text('Retake'),
              style: OutlinedButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 14),
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: FilledButton.icon(
              onPressed: _accept,
              icon: const Icon(Icons.check),
              label: const Text('Accept'),
              style: FilledButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 14),
                backgroundColor: AppTheme.maroon,
              ),
            ),
          ),
        ],
      );
    }
    return FilledButton.icon(
      onPressed: _capturing ? null : _takePhoto,
      icon: const Icon(Icons.camera_alt),
      label: Text(_capturing
          ? (_processing ? 'Preparing…' : 'Opening…')
          : '📷 Take Photo'),
      style: FilledButton.styleFrom(
        padding: const EdgeInsets.symmetric(vertical: 16),
        backgroundColor: AppTheme.maroon,
        textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
      ),
    );
  }
}

class _MemberCard extends StatelessWidget {
  final String name;
  final String? code;
  const _MemberCard({required this.name, required this.code});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppTheme.maroon.withOpacity(0.06),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppTheme.maroon.withOpacity(0.2)),
      ),
      child: Row(
        children: [
          CircleAvatar(
            backgroundColor: AppTheme.maroon,
            child: Text(
              name.isNotEmpty ? name[0].toUpperCase() : '?',
              style: const TextStyle(color: Colors.white),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(name,
                    style: const TextStyle(
                        fontSize: 18, fontWeight: FontWeight.w700)),
                if (code != null)
                  Text(code!,
                      style:
                          TextStyle(color: Colors.grey.shade700, fontSize: 13)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _IdlePanel extends StatelessWidget {
  final String? photoUrl;
  final String? authorizationToken;
  final String? localPhotoPath;
  final String? previousPhotoError;

  const _IdlePanel({
    required this.photoUrl,
    required this.authorizationToken,
    required this.localPhotoPath,
    required this.previousPhotoError,
  });

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          _ExistingPhotoBox(
            photoUrl: photoUrl,
            authorizationToken: authorizationToken,
            localPhotoPath: localPhotoPath,
          ),
          const SizedBox(height: 16),
          const Text('Take a clear member portrait',
              style: TextStyle(fontSize: 16)),
          const SizedBox(height: 8),
          Text('Center the face · use even light · avoid shadows',
              style: TextStyle(color: Colors.grey.shade600)),
          if (previousPhotoError != null) ...[
            const SizedBox(height: 6),
            Text(
              previousPhotoError!,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.redAccent, fontSize: 12),
            ),
          ],
          const SizedBox(height: 24),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppTheme.gold.withOpacity(0.15),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              'Photos are saved privately on your device. They will NOT '
              'upload until you enable sync in the Sync Panel. You can '
              'also Export ZIP to hand in photos via USB.',
              style: TextStyle(
                color: Colors.brown.shade900,
                fontSize: 12,
              ),
              textAlign: TextAlign.center,
            ),
          ),
        ],
      ),
    );
  }
}

class _ExistingPhotoBox extends StatelessWidget {
  final String? photoUrl;
  final String? authorizationToken;
  final String? localPhotoPath;

  const _ExistingPhotoBox({
    required this.photoUrl,
    required this.authorizationToken,
    required this.localPhotoPath,
  });

  @override
  Widget build(BuildContext context) {
    final headers = authorizationToken == null
        ? null
        : {'Authorization': 'Bearer $authorizationToken'};
    final hasPhoto =
        localPhotoPath != null || (photoUrl != null && photoUrl!.isNotEmpty);
    final Widget image;
    if (localPhotoPath != null) {
      image = Image.file(
        File(localPhotoPath!),
        fit: BoxFit.cover,
        errorBuilder: (_, __, ___) => _emptyPhoto(),
      );
    } else if (photoUrl != null && photoUrl!.isNotEmpty) {
      image = Image.network(
        photoUrl!,
        headers: headers,
        fit: BoxFit.cover,
        errorBuilder: (_, __, ___) => _emptyPhoto(),
      );
    } else {
      image = _emptyPhoto();
    }

    return ClipRRect(
      borderRadius: BorderRadius.circular(14),
      child: Container(
        width: 184,
        height: 230,
        color: Colors.black12,
        child: Stack(
          fit: StackFit.expand,
          children: [
            image,
            if (!hasPhoto)
              const Center(
                child: Icon(Icons.person_pin_circle_outlined,
                    size: 72, color: Colors.black26),
              ),
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: Container(
                padding: const EdgeInsets.symmetric(vertical: 6),
                color: Colors.black54,
                child: Text(
                  hasPhoto ? 'Current photo' : 'No photo yet',
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white, fontSize: 12),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _emptyPhoto() => const Center(
        child: Icon(Icons.person_pin_circle_outlined,
            size: 72, color: Colors.black26),
      );
}

class _ReviewPanel extends StatelessWidget {
  final PhotoOutboxEntry entry;
  const _ReviewPanel({required this.entry});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Expanded(
          child: Center(
            child: AspectRatio(
              aspectRatio: 3 / 4,
              child: ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Container(
                  color: Colors.black,
                  alignment: Alignment.center,
                  child: Image.file(
                    File(entry.localPath),
                    fit: BoxFit.cover,
                    errorBuilder: (_, __, ___) => const Center(
                      child: Text('Could not preview.',
                          style: TextStyle(color: Colors.white)),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
        const SizedBox(height: 12),
        _InfoChip(
          icon: Icons.check_circle_outline,
          label:
              '${entry.format.extension.toUpperCase()} · ${entry.width}×${entry.height} · ${entry.humanSize}',
        ),
      ],
    );
  }
}

class _InfoChip extends StatelessWidget {
  final IconData icon;
  final String label;
  const _InfoChip({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.green.shade50,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Colors.green.shade200),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 16, color: Colors.green.shade700),
          const SizedBox(width: 6),
          Text(label,
              style: TextStyle(
                  color: Colors.green.shade800,
                  fontSize: 12,
                  fontWeight: FontWeight.w500)),
        ],
      ),
    );
  }
}

class _ErrorPanel extends StatelessWidget {
  final String message;
  final VoidCallback onRetry;
  const _ErrorPanel({required this.message, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.error_outline, size: 64, color: Colors.redAccent),
          const SizedBox(height: 12),
          Text(message,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.redAccent)),
          const SizedBox(height: 20),
          FilledButton.icon(
            onPressed: onRetry,
            icon: const Icon(Icons.refresh),
            label: const Text('Try Again'),
          ),
        ],
      ),
    );
  }
}
