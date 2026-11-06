import 'dart:async';
import 'package:flutter/material.dart';
import '../services/connectivity_service.dart';
import '../services/sync_service.dart';
import '../utils/theme.dart';

/// Top bar — Telegram wording.
/// Shown only when the phone radio is off (airplane / data off / no SIM).
/// A slow 4G reply must never appear here as "No internet connection".
class OfflineBanner extends StatefulWidget {
  const OfflineBanner({super.key});

  @override
  State<OfflineBanner> createState() => _OfflineBannerState();
}

class _OfflineBannerState extends State<OfflineBanner>
    with SingleTickerProviderStateMixin {
  final _connectivity = ConnectivityService();
  final _sync = SyncService();

  late AnimationController _animCtrl;
  late Animation<double> _slideAnim;
  StreamSubscription<bool>? _connectSub;
  StreamSubscription<SyncStatus>? _syncSub;

  bool _waiting = false;
  int _pendingCount = 0;
  bool _syncing = false;
  bool _justCameOnline = false;

  @override
  void initState() {
    super.initState();
    _waiting = !_connectivity.hasLink;

    _animCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 300),
    );
    _slideAnim = CurvedAnimation(parent: _animCtrl, curve: Curves.easeOut);

    if (_waiting) _animCtrl.value = 1.0;

    _connectSub = _connectivity.statusStream.listen((hasLink) {
      if (!mounted) return;
      if (!hasLink && !_waiting) {
        setState(() => _waiting = true);
        _animCtrl.forward();
        _loadPendingCount();
      } else if (hasLink && _waiting) {
        setState(() {
          _waiting = false;
          _justCameOnline = true;
        });
        _sync.syncAll();
        Future.delayed(const Duration(seconds: 2), () {
          if (mounted) {
            _animCtrl.reverse();
            Future.delayed(const Duration(milliseconds: 300), () {
              if (mounted) setState(() => _justCameOnline = false);
            });
          }
        });
      }
    });

    _syncSub = _sync.syncStream.listen((status) {
      if (mounted) {
        setState(() {
          _pendingCount = status.retryableDue +
              status.retryableWaiting +
              status.inFlight;
          _syncing = status.syncing;
        });
      }
    });

    _loadPendingCount();
  }

  Future<void> _loadPendingCount() async {
    await _sync.emitCurrentStatus();
    final status = _sync.lastStatus;
    if (mounted) {
      setState(() {
        _pendingCount = status.retryableDue +
            status.retryableWaiting +
            status.inFlight;
        _syncing = status.syncing;
      });
    }
  }

  @override
  void dispose() {
    _connectSub?.cancel();
    _syncSub?.cancel();
    _animCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!_waiting && !_justCameOnline) return const SizedBox.shrink();

    return SizeTransition(
      sizeFactor: _slideAnim,
      axisAlignment: -1,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
        decoration: BoxDecoration(
          color: _justCameOnline
              ? AppTheme.success.withOpacity(0.12)
              : AppTheme.surfaceLight,
          border: Border(
            bottom: BorderSide(
              color: _justCameOnline
                  ? AppTheme.success.withOpacity(0.25)
                  : AppTheme.borderLight,
              width: 1,
            ),
          ),
        ),
        child: SafeArea(
          bottom: false,
          child: Row(
            children: [
              Icon(
                _justCameOnline ? Icons.cloud_done : Icons.cloud_off,
                size: 13,
                color: _justCameOnline ? AppTheme.success : AppTheme.textSecondary,
              ),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  _justCameOnline
                      ? 'Back online — sending…'
                      : _pendingCount > 0
                          ? 'Offline — $_pendingCount change${_pendingCount == 1 ? '' : 's'} saved, will send when online'
                          : 'Offline — work saves on this phone',
                  style: TextStyle(
                    fontSize: 10.5,
                    color:
                        _justCameOnline ? AppTheme.success : AppTheme.textSecondary,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ),
              if (!_justCameOnline && _pendingCount > 0)
                GestureDetector(
                  onTap: () =>
                      Navigator.of(context).pushNamed('/sync-center'),
                  child: Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                    decoration: BoxDecoration(
                      color: AppTheme.borderLight,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(
                      _syncing ? 'Sending…' : 'View',
                      style: TextStyle(
                        fontSize: 9.5,
                        color: AppTheme.textSecondary,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
