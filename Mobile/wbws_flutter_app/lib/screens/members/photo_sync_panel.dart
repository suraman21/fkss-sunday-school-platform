import 'dart:async';
import 'package:flutter/material.dart';

import '../../services/connectivity_service.dart';
import '../../services/photo_outbox_models.dart';
import '../../services/photo_outbox_service.dart';
import '../../services/photo_sync_worker.dart';
import '../../services/photo_zip_export_service.dart';

/// P5 minimal Sync Panel screen.
///
/// This is the control surface the field staff sees: master sync toggle,
/// cellular opt-in, queue counters, Start/Pause/Resume/Cancel, Export
/// ZIP button (visible, stubbed until Z1 ships), and a live event log.
///
/// Defaults are the safe "sync OFF" values the user mandated; the only
/// way to upload is to (1) flip Enable, (2) tap Start Sync.
class PhotoSyncPanelScreen extends StatefulWidget {
  const PhotoSyncPanelScreen({super.key});

  @override
  State<PhotoSyncPanelScreen> createState() => _PhotoSyncPanelScreenState();
}

class _PhotoSyncPanelScreenState extends State<PhotoSyncPanelScreen> {
  final _outbox = PhotoOutboxService.instance;
  final _worker = PhotoSyncWorker.instance;
  final _connectivity = ConnectivityService();

  PhotoSyncPolicyState _policy = PhotoSyncPolicyState.seeded();
  PhotoOutboxCounters _counters = PhotoOutboxCounters.zero;
  StreamSubscription<PhotoSyncEvent>? _events;
  StreamSubscription<bool>? _radio;
  Timer? _tick;
  DateTime? _startedAt;

  @override
  void initState() {
    super.initState();
    _load();
    _events = _worker.progressStream.listen((_) => _refresh());
    _radio = _connectivity.statusStream.listen((_) => _refresh());
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (_worker.isRunning) setState(() {});
    });
    _worker.addListener(_refresh);
  }

  @override
  void dispose() {
    _events?.cancel();
    _radio?.cancel();
    _tick?.cancel();
    _worker.removeListener(_refresh);
    super.dispose();
  }

  Future<void> _load() async {
    _policy = await _outbox.readPolicy();
    _counters = await _outbox.counters();
    if (mounted) setState(() {});
  }

  void _refresh() {
    if (mounted) setState(() {});
  }

  Future<void> _toggleSync(bool enabled) async {
    await _outbox.setSyncEnabled(enabled);
    _policy = await _outbox.readPolicy();
    setState(() {});
  }

  Future<void> _toggleCellular(bool allow) async {
    final accepted = allow == false || await _confirmCellular();
    if (!accepted) return;
    await _outbox.setAllowCellular(allow);
    _policy = await _outbox.readPolicy();
    setState(() {});
  }

  Future<bool> _confirmCellular() async {
    final size = _counters.totalBytesQueued;
    final mb = (size / (1024 * 1024)).toStringAsFixed(1);
    return await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('Sync over cellular?'),
            content: Text(
              'You have ${_counters.unsynced} photos queued (about $mb MB). '
              'Uploading over mobile data may use your data plan and could '
              'incur charges. Recommended: connect to Wi-Fi or use Export ZIP '
              'to transfer via USB.',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx, false),
                child: const Text('Cancel'),
              ),
              FilledButton(
                onPressed: () => Navigator.pop(ctx, true),
                style: FilledButton.styleFrom(backgroundColor: _Colors.maroon),
                child: const Text('Allow cellular'),
              ),
            ],
          ),
        ) ??
        false;
  }

  Future<void> _startSync() async {
    final check = await _worker.canStart();
    if (!check.ok) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(check.reason ?? 'Cannot start sync.'),
        backgroundColor: _Colors.maroon,
      ));
      return;
    }
    _startedAt = DateTime.now();
    await _worker.start();
  }

  bool _exportingZip = false;

  Future<void> _exportZip() async {
    if (_exportingZip) return;
    setState(() => _exportingZip = true);
    try {
      final bundle =
          await PhotoZipExportService.instance.exportAndShare();
      if (!mounted) return;
      final kb = (await bundle.zip.length()) ~/ 1024;
      final where = bundle.downloadsPath == null
          ? 'choose a share target (AirDrop, email, Save to Files…).'
          : 'saved to ${bundle.downloadsPath} — copy it by USB or choose a share target.';
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('ZIP ready ($kb KB) — $where'),
        duration: const Duration(seconds: 5),
        backgroundColor: const Color(0xFF600000),
      ));
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('ZIP export failed: $e'),
        backgroundColor: Colors.red,
        duration: const Duration(seconds: 5),
      ));
    } finally {
      if (mounted) setState(() => _exportingZip = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final networkBanner = _buildNetworkBanner();
    final hasPhotos = _counters.total > 0;
    final running = _worker.isRunning;
    final progress = _worker.progress;
    final eta = _startedAt != null
        ? _worker.estimateEta(DateTime.now().difference(_startedAt!))
        : '';

    return Scaffold(
      appBar: AppBar(title: const Text('Photo Sync')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (networkBanner != null) networkBanner,
          const SizedBox(height: 12),
          _PolicyCard(
            policy: _policy,
            onToggleSync: _toggleSync,
            onToggleCellular: _toggleCellular,
          ),
          const SizedBox(height: 12),
          _CountersCard(counters: _counters),
          const SizedBox(height: 12),
          _ActionsCard(
            running: running,
            paused: _worker.isPaused,
            hasPhotos: hasPhotos,
            canStart: _policy.syncEnabled && _connectivity.hasLink,
            onStart: _startSync,
            onPause: () => _worker.pause(),
            onResume: () => _worker.resume(),
            onCancel: () => _worker.cancel(),
            onExportZip: _exportZip,
          ),
          if (running || _worker.done > 0) ...[
            const SizedBox(height: 12),
            _ProgressCard(
              progress: progress,
              done: _worker.done,
              total: _worker.total,
              failed: _worker.failed,
              conflicts: _worker.conflicts,
              bytesUploaded: _worker.bytesUploaded,
              bytesTotal: _worker.bytesTotal,
              eta: eta,
              currentLabel: _worker.currentMemberLabel,
            ),
          ],
          const SizedBox(height: 12),
          _EventLog(events: _worker.recentEvents.take(20).toList()),
          const SizedBox(height: 24),
          const _PrivacyFooter(),
        ],
      ),
    );
  }

  Widget? _buildNetworkBanner() {
    if (_worker.isRunning) return null;
    if (!_connectivity.hasLink) {
      return _Banner(
        icon: Icons.cloud_off,
        color: Colors.red.shade100,
        iconColor: Colors.red.shade700,
        text: 'No network connection. Use Export ZIP or wait for Wi-Fi.',
      );
    }
    if (!_policy.syncEnabled) {
      return _Banner(
        icon: Icons.cloud_done_outlined,
        color: _Colors.gold.withOpacity(0.25),
        iconColor: Colors.brown.shade800,
        text: 'Sync is OFF. Photos are saved locally only. '
            'Enable sync below to upload, or use Export ZIP.',
      );
    }
    if (!_policy.allowCellular && !_connectivity.isUnmetered) {
      return _Banner(
        icon: Icons.signal_cellular_alt,
        color: Colors.orange.shade100,
        iconColor: Colors.orange.shade800,
        text: 'Connected to cellular data. '
            'Enable "Sync over cellular" to upload now, or connect to Wi-Fi.',
      );
    }
    if (_policy.syncEnabled && _connectivity.isUnmetered && _counters.unsynced > 0) {
      return _Banner(
        icon: Icons.wifi,
        color: Colors.green.shade50,
        iconColor: Colors.green.shade700,
        text: 'On Wi-Fi. Tap Start Sync when ready.',
      );
    }
    return null;
  }
}

// ---------- Widgets ----------

class _Colors {
  static const maroon = Color(0xFF600000);
  static const gold = Color(0xFFF0C000);
}

class _Banner extends StatelessWidget {
  final IconData icon;
  final Color color;
  final Color iconColor;
  final String text;
  const _Banner({
    required this.icon,
    required this.color,
    required this.iconColor,
    required this.text,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: iconColor, size: 20),
          const SizedBox(width: 10),
          Expanded(child: Text(text, style: TextStyle(color: iconColor))),
        ],
      ),
    );
  }
}

class _PolicyCard extends StatelessWidget {
  final PhotoSyncPolicyState policy;
  final ValueChanged<bool> onToggleSync;
  final ValueChanged<bool> onToggleCellular;
  const _PolicyCard({
    required this.policy,
    required this.onToggleSync,
    required this.onToggleCellular,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Sync settings',
                style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 12),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Enable photo sync'),
              subtitle: const Text(
                  'Off by default. Photos never upload until you turn this on and tap Start.'),
              value: policy.syncEnabled,
              activeColor: _Colors.maroon,
              onChanged: onToggleSync,
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Sync over cellular'),
              subtitle: const Text('May use your data plan. Wi-Fi only when off.'),
              value: policy.allowCellular,
              activeColor: _Colors.maroon,
              onChanged: policy.syncEnabled ? onToggleCellular : null,
            ),
          ],
        ),
      ),
    );
  }
}

class _CountersCard extends StatelessWidget {
  final PhotoOutboxCounters counters;
  const _CountersCard({required this.counters});

  @override
  Widget build(BuildContext context) {
    String mb(int bytes) =>
        (bytes / (1024 * 1024)).toStringAsFixed(1);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Queue', style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 12),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                _Stat(label: 'Queued', value: '${counters.queued}'),
                _Stat(label: 'Synced', value: '${counters.done}', color: Colors.green.shade700),
                _Stat(label: 'Review', value: '${counters.conflictPending}', color: Colors.orange.shade800),
                _Stat(label: 'Failed', value: '${counters.failed}', color: Colors.red.shade700),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              '${counters.unsynced} unsynced · ${mb(counters.totalBytesQueued)} MB queued',
              style: TextStyle(color: Colors.grey.shade700, fontSize: 12),
            ),
          ],
        ),
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  final String label;
  final String value;
  final Color? color;
  const _Stat({required this.label, required this.value, this.color});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text(value,
            style: TextStyle(
                fontSize: 22,
                fontWeight: FontWeight.w700,
                color: color ?? Colors.black87)),
        Text(label, style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
      ],
    );
  }
}

class _ActionsCard extends StatelessWidget {
  final bool running;
  final bool paused;
  final bool hasPhotos;
  final bool canStart;
  final VoidCallback onStart;
  final VoidCallback onPause;
  final VoidCallback onResume;
  final VoidCallback onCancel;
  final VoidCallback onExportZip;
  const _ActionsCard({
    required this.running,
    required this.paused,
    required this.hasPhotos,
    required this.canStart,
    required this.onStart,
    required this.onPause,
    required this.onResume,
    required this.onCancel,
    required this.onExportZip,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (!running) ...[
              FilledButton.icon(
                onPressed: canStart && hasPhotos ? onStart : null,
                icon: const Icon(Icons.sync),
                label: Text(hasPhotos ? '▶ Start Sync' : 'No photos to sync'),
                style: FilledButton.styleFrom(
                  backgroundColor: _Colors.maroon,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
              ),
              const SizedBox(height: 10),
              OutlinedButton.icon(
                onPressed: onExportZip,
                icon: const Icon(Icons.folder_zip_outlined),
                label: const Text('📦 Export ZIP file (to USB)'),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  foregroundColor: _Colors.maroon,
                  side: const BorderSide(color: _Colors.maroon),
                ),
              ),
            ] else ...[
              Row(
                children: [
                  if (!paused)
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: onPause,
                        icon: const Icon(Icons.pause),
                        label: const Text('Pause'),
                      ),
                    )
                  else
                    Expanded(
                      child: FilledButton.icon(
                        onPressed: onResume,
                        icon: const Icon(Icons.play_arrow),
                        label: const Text('Resume'),
                        style: FilledButton.styleFrom(
                            backgroundColor: _Colors.maroon),
                      ),
                    ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: onCancel,
                      icon: const Icon(Icons.stop),
                      label: const Text('Cancel'),
                      style: OutlinedButton.styleFrom(
                          foregroundColor: Colors.red),
                    ),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _ProgressCard extends StatelessWidget {
  final double progress;
  final int done;
  final int total;
  final int failed;
  final int conflicts;
  final int bytesUploaded;
  final int bytesTotal;
  final String eta;
  final String? currentLabel;
  const _ProgressCard({
    required this.progress,
    required this.done,
    required this.total,
    required this.failed,
    required this.conflicts,
    required this.bytesUploaded,
    required this.bytesTotal,
    required this.eta,
    required this.currentLabel,
  });

  @override
  Widget build(BuildContext context) {
    String mb(int b) => (b / (1024 * 1024)).toStringAsFixed(1);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Progress', style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 12),
            LinearProgressIndicator(
              value: progress.clamp(0, 1),
              minHeight: 8,
              backgroundColor: Colors.grey.shade200,
              valueColor: const AlwaysStoppedAnimation(_Colors.maroon),
              borderRadius: BorderRadius.circular(4),
            ),
            const SizedBox(height: 10),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('$done / $total photos'),
                Text('${mb(bytesUploaded)} / ${mb(bytesTotal)} MB'),
              ],
            ),
            if (currentLabel != null || eta.isNotEmpty) ...[
              const SizedBox(height: 6),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  if (currentLabel != null)
                    Text('Now: $currentLabel',
                        style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
                  if (eta.isNotEmpty)
                    Text(eta,
                        style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
                ],
              ),
            ],
            if (failed > 0 || conflicts > 0) ...[
              const SizedBox(height: 8),
              Text(
                [
                  if (failed > 0) '$failed failed',
                  if (conflicts > 0) '$conflicts pending admin review',
                ].join(' · '),
                style: TextStyle(color: Colors.red.shade700, fontSize: 12),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _EventLog extends StatelessWidget {
  final List<PhotoSyncEvent> events;
  const _EventLog({required this.events});

  @override
  Widget build(BuildContext context) {
    if (events.isEmpty) {
      return const SizedBox.shrink();
    }
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Recent activity',
                style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: 8),
            ...events.take(8).map((e) => Padding(
                  padding: const EdgeInsets.symmetric(vertical: 2),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(_iconFor(e.type), size: 14, color: _colorFor(e.type)),
                      const SizedBox(width: 6),
                      Expanded(
                        child: Text(e.message,
                            style: const TextStyle(fontSize: 12)),
                      ),
                    ],
                  ),
                )),
          ],
        ),
      ),
    );
  }

  IconData _iconFor(PhotoSyncEventType t) => switch (t) {
        PhotoSyncEventType.uploaded => Icons.check_circle,
        PhotoSyncEventType.uploadedConflict => Icons.info_outline,
        PhotoSyncEventType.failed => Icons.error_outline,
        PhotoSyncEventType.retrying => Icons.refresh,
        PhotoSyncEventType.waiting => Icons.hourglass_empty,
        _ => Icons.circle,
      };

  Color _colorFor(PhotoSyncEventType t) => switch (t) {
        PhotoSyncEventType.uploaded => Colors.green.shade700,
        PhotoSyncEventType.uploadedConflict => Colors.orange.shade800,
        PhotoSyncEventType.failed => Colors.red.shade700,
        PhotoSyncEventType.retrying => Colors.orange.shade700,
        PhotoSyncEventType.waiting => Colors.grey.shade700,
        _ => Colors.grey.shade500,
      };
}

class _PrivacyFooter extends StatelessWidget {
  const _PrivacyFooter();

  @override
  Widget build(BuildContext context) {
    return Text(
      'Photos stay in private app storage until sync or ZIP export. '
      'They are never saved to your gallery by default. Sync is OFF until you '
      'turn it on.',
      textAlign: TextAlign.center,
      style: TextStyle(color: Colors.grey.shade600, fontSize: 11),
    );
  }
}
