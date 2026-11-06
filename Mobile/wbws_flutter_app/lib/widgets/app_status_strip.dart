import 'dart:async';
import 'package:flutter/material.dart';
import '../services/connectivity_service.dart';
import '../services/sync_service.dart';
import '../utils/theme.dart';

/// Single source of truth for offline / sync attention.
///
/// Priority (only one line shown, calm offline-first):
/// 1. rejected / blockedDependency / resolvedConflict → needs review (danger/warning)
/// 2. pausedAuth / pausedScope
/// 3. offline (+ pending count) — muted info, not shouting warning
/// 4. just came back online — transient success 2s
/// 5. idle online — nothing (no bar, no space taken)
///
/// Replaces the previous stack of OfflineBanner + SyncRecoveryBanner in AppShell
/// that stole 40-80px with duplicated orange warnings.
class AppStatusStrip extends StatefulWidget {
  const AppStatusStrip({super.key});
  @override
  State<AppStatusStrip> createState() => _AppStatusStripState();
}

class _AppStatusStripState extends State<AppStatusStrip> with SingleTickerProviderStateMixin {
  final _connectivity = ConnectivityService();
  final _sync = SyncService();
  late AnimationController _animCtrl;
  late Animation<double> _slide;
  StreamSubscription<bool>? _connSub;
  StreamSubscription<SyncStatus>? _syncSub;
  bool _waiting = false;
  bool _justOnline = false;
  SyncStatus _status = SyncService().lastStatus;
  int _pending = 0;

  @override
  void initState() {
    super.initState();
    _waiting = !_connectivity.hasLink;
    _status = _sync.lastStatus;
    _pending = _status.retryableDue + _status.retryableWaiting + _status.inFlight;
    _animCtrl = AnimationController(vsync: this, duration: const Duration(milliseconds: 220));
    _slide = CurvedAnimation(parent: _animCtrl, curve: Curves.easeOut);
    _updateAnim();
    _connSub = _connectivity.statusStream.listen((hasLink) {
      if (!mounted) return;
      final waiting = !hasLink;
      setState(() => _waiting = waiting);
      if (hasLink) {
        setState(() => _justOnline = true);
        _sync.syncAll();
        _updateAnim();
        Future.delayed(const Duration(seconds: 2), () {
          if (mounted) {
            setState(() => _justOnline = false);
            _updateAnim();
          }
        });
      } else {
        _updateAnim();
      }
    });
    _syncSub = _sync.syncStream.listen((s) {
      if (!mounted) return;
      setState(() {
        _status = s;
        _pending = s.retryableDue + s.retryableWaiting + s.inFlight;
      });
      _updateAnim();
    });
    _sync.emitCurrentStatus();
  }

  void _updateAnim() {
    final visible = _shouldShow;
    if (visible) {
      _animCtrl.forward();
    } else {
      _animCtrl.reverse();
    }
  }

  bool get _shouldShow {
    final attention = _status.rejected + _status.blockedDependency + _status.resolvedConflict;
    final paused = _status.pausedAuth + _status.pausedScope;
    if (attention + paused > 0) return true;
    if (_waiting) return true;
    if (_justOnline) return true;
    return false;
  }

  @override
  void dispose() {
    _connSub?.cancel();
    _syncSub?.cancel();
    _animCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final attention = _status.rejected + _status.blockedDependency + _status.resolvedConflict;
    final paused = _status.pausedAuth + _status.pausedScope;
    final totalAttention = attention + paused;

    String text;
    IconData icon;
    Color bg;
    Color fg;
    Color borderColor;
    VoidCallback? onTap;

    if (totalAttention > 0) {
      // Actionable attention — keep warning/danger but compact, not full banner
      final parts = <String>[];
      if (attention > 0) parts.add('$attention need review');
      if (paused > 0) parts.add('$paused paused');
      text = parts.join(' · ');
      icon = Icons.sync_problem_rounded;
      bg = AppTheme.warning.withOpacity(0.12);
      fg = AppTheme.warning;
      borderColor = AppTheme.warning.withOpacity(0.22);
      onTap = () => Navigator.of(context).pushNamed('/sync-center');
    } else if (_justOnline) {
      text = _pending > 0 ? 'Back online — sending…' : 'Back online';
      icon = Icons.cloud_done;
      bg = AppTheme.success.withOpacity(0.10);
      fg = AppTheme.success;
      borderColor = AppTheme.success.withOpacity(0.20);
      onTap = null;
    } else if (_waiting) {
      // Calm offline — muted, not shouting warning orange
      icon = Icons.cloud_off;
      bg = AppTheme.surfaceLight;
      fg = AppTheme.textSecondary;
      borderColor = AppTheme.borderLight;
      if (_pending > 0) {
        text = 'Offline — $_pending change${_pending == 1 ? '' : 's'} saved';
      } else {
        text = 'Offline — work saves on this phone';
      }
      onTap = null;
    } else {
      return const SizedBox.shrink();
    }

    return SizeTransition(
      sizeFactor: _slide,
      axisAlignment: -1,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
        decoration: BoxDecoration(
          color: bg,
          border: Border(bottom: BorderSide(color: borderColor)),
        ),
        child: SafeArea(
          bottom: false,
          child: InkWell(
            onTap: onTap,
            child: Row(
              children: [
                Icon(icon, size: 13, color: fg),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    text,
                    style: TextStyle(fontSize: 10.5, color: fg, fontWeight: FontWeight.w500),
                  ),
                ),
                if (onTap != null) ...[
                  const SizedBox(width: 8),
                  Text('Review', style: TextStyle(fontSize: 10, color: AppTheme.primary, fontWeight: FontWeight.w700)),
                  const Icon(Icons.chevron_right_rounded, size: 14, color: AppTheme.primary),
                ] else if (_waiting && _pending > 0) ...[
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(color: AppTheme.borderLight, borderRadius: BorderRadius.circular(4)),
                    child: const Text('View', style: TextStyle(fontSize: 9.5, color: AppTheme.textSecondary, fontWeight: FontWeight.w600)),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
