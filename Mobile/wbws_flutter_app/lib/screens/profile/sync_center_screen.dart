import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../services/api_service.dart';
import '../../services/app_nav.dart';
import '../../services/app_update_service.dart';
import '../../services/comm_outbox_service.dart';
import '../../services/comm_store.dart';
import '../../services/hymn_store.dart';
import '../../services/local_db.dart';
import '../../services/session_models.dart';
import '../../services/sync_recovery_models.dart';
import '../../services/sync_service.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import '../mezmur/mezmur_hymns.dart';
import '../notifications/messages_screen.dart';

/// Owner-safe global recovery surface for all durable mobile write queues.
///
/// Only active owner/scope rows become detail items. Older-scope private work
/// is represented by a count-only hold card so roster/member/message payloads
/// cannot cross an authorization-version boundary.
class SyncCenterScreen extends StatefulWidget {
  const SyncCenterScreen({super.key});

  @override
  State<SyncCenterScreen> createState() => _SyncCenterScreenState();
}

class _SyncCenterScreenState extends State<SyncCenterScreen> {
  static const _attendanceConfirmationMessage =
      'Attendance stays saved until its exact outcome is confirmed by the server.';

  final _db = LocalDb();
  OutboxInventory _inventory = const OutboxInventory();
  List<SyncRecoveryItem> _items = const [];
  StreamSubscription<SyncStatus>? _syncSub;
  bool _loading = true;
  bool _acting = false;
  int _loadGeneration = 0;
  String? _error;

  bool get _includeSharedHymns => HymnStore().canEdit;

  SyncRecoveryDomain? get _entryDomain {
    final arguments = ModalRoute.of(context)?.settings.arguments;
    return arguments is SyncRecoveryDomain ? arguments : null;
  }

  @override
  void initState() {
    super.initState();
    _syncSub = SyncService().syncStream.listen((_) => _load(silent: true));
    _load();
  }

  @override
  void dispose() {
    _syncSub?.cancel();
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    final generation = ++_loadGeneration;
    if (!silent && mounted) setState(() => _loading = true);
    try {
      final values = await Future.wait<Object>([
        _db.getOutboxInventory(),
        _db.getSyncRecoveryItems(
          includeSharedHymns: _includeSharedHymns,
        ),
      ]);
      if (!mounted || generation != _loadGeneration) return;
      setState(() {
        _inventory = values[0] as OutboxInventory;
        _items = values[1] as List<SyncRecoveryItem>;
        _loading = false;
        _error = null;
      });
    } catch (_) {
      if (!mounted || generation != _loadGeneration) return;
      setState(() {
        _loading = false;
        _error = 'Sync details are unavailable right now.';
      });
    }
  }

  String _legacyKind(SyncRecoveryDomain domain) => switch (domain) {
        SyncRecoveryDomain.attendance => 'attendance',
        SyncRecoveryDomain.grades => 'grades',
        SyncRecoveryDomain.mezmur => 'mezmur',
        SyncRecoveryDomain.hr => 'hr',
        _ => throw ArgumentError('Not a legacy operation'),
      };

  Future<void> _retry(SyncRecoveryItem item) async {
    if (_acting) return;
    setState(() => _acting = true);
    SyncRecoveryActionResult result;
    try {
      switch (item.domain) {
        case SyncRecoveryDomain.communication:
          result = await CommStore.instance.retryOutbox(
            item.operationId,
            expectedState: item.state,
          );
          if (result == SyncRecoveryActionResult.applied) {
            CommOutboxService.instance.kick();
          }
          break;
        case SyncRecoveryDomain.hymn:
          result = item.rowId == null
              ? SyncRecoveryActionResult.stale
              : await HymnStore().retryRecoveryOperation(
                  item.rowId!, item.operationId, item.state);
          if (result == SyncRecoveryActionResult.applied) {
            unawaited(SyncService().syncAll(force: true));
          }
          break;
        default:
          result = await _db.retryRejectedOperation(
            _legacyKind(item.domain),
            item.operationId,
            item.state,
          );
          if (result == SyncRecoveryActionResult.applied) {
            unawaited(SyncService().syncAll(force: true));
          }
      }
    } catch (_) {
      result = SyncRecoveryActionResult.stale;
    }
    if (!mounted) return;
    setState(() => _acting = false);
    await SyncService().emitCurrentStatus();
    await _load(silent: true);
    if (!mounted) return;
    _showActionResult(result,
        appliedMessage: 'Retry queued on this phone.');
  }

  Future<void> _acknowledge(SyncRecoveryItem item) async {
    if (_acting) return;
    final result = await _discardExact(item);
    if (!mounted) return;
    await SyncService().emitCurrentStatus();
    await _load(silent: true);
    if (!mounted) return;
    _showActionResult(result, appliedMessage: 'Conflict acknowledged.');
  }

  Future<void> _confirmDiscard(SyncRecoveryItem item) async {
    if (_acting) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Discard saved operation?'),
        content: const Text(
          'This removes only this exact queued operation from this phone. '
          'It cannot be undone and does not delete an accepted server record.',
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.of(ctx).pop(false),
              child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppTheme.danger),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Discard'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    final result = await _discardExact(item);
    if (!mounted) return;
    await SyncService().emitCurrentStatus();
    await _load(silent: true);
    if (!mounted) return;
    _showActionResult(result, appliedMessage: 'Queued operation discarded.');
  }

  Future<SyncRecoveryActionResult> _discardExact(
      SyncRecoveryItem item) async {
    if (_acting) return SyncRecoveryActionResult.stale;
    setState(() => _acting = true);
    try {
      switch (item.domain) {
        case SyncRecoveryDomain.communication:
          return await CommStore.instance.deleteOutbox(
            item.operationId,
            expectedState: item.state,
          );
        case SyncRecoveryDomain.hymn:
          return item.rowId == null
              ? SyncRecoveryActionResult.stale
              : await HymnStore().discardRecoveryOperation(
                  item.rowId!, item.operationId, item.state);
        default:
          return await _db.discardRejectedOperation(
            _legacyKind(item.domain),
            item.operationId,
            expectedState: item.state,
          );
      }
    } catch (_) {
      return SyncRecoveryActionResult.stale;
    } finally {
      if (mounted) setState(() => _acting = false);
    }
  }

  void _showActionResult(
    SyncRecoveryActionResult result, {
    required String appliedMessage,
  }) {
    final message = switch (result) {
      SyncRecoveryActionResult.applied => appliedMessage,
      SyncRecoveryActionResult.stale => 'This item changed. The list was reloaded.',
      SyncRecoveryActionResult.serverConfirmationRequired => _attendanceConfirmationMessage,
    };
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(message),
      duration: const Duration(seconds: 2),
    ));
  }

  Set<String> get _authorizedTabs {
    final config = AppUpdateService();
    return getTabsForRole(
      ApiService().userRole,
      attendanceEnabled: config.featureEnabled('attendance'),
      gradesEnabled: config.featureEnabled('grades'),
      mezmurEnabled: config.featureEnabled('mezmur'),
    ).map((tab) => tab.id).toSet();
  }

  String? _sourceTab(SyncRecoveryDomain domain) => switch (domain) {
        SyncRecoveryDomain.attendance => 'attendance',
        SyncRecoveryDomain.grades => 'grades',
        SyncRecoveryDomain.mezmur => 'mezmur_attendance',
        SyncRecoveryDomain.hr => 'hr_attendance',
        SyncRecoveryDomain.hymn => 'mezmur_hymns',
        SyncRecoveryDomain.communication => null,
      };

  bool _canOpenSource(SyncRecoveryItem item) {
    if (item.domain == SyncRecoveryDomain.communication) return true;
    if (item.domain == SyncRecoveryDomain.hymn) {
      return _includeSharedHymns && AppUpdateService().featureEnabled('mezmur');
    }
    final tab = _sourceTab(item.domain);
    return tab != null && _authorizedTabs.contains(tab);
  }

  void _openSource(SyncRecoveryItem item) {
    if (!_canOpenSource(item)) return;
    if (_entryDomain == item.domain) {
      Navigator.of(context).pop();
      return;
    }
    if (item.domain == SyncRecoveryDomain.communication) {
      Navigator.of(context)
          .push(MaterialPageRoute(builder: (_) => const MessagesScreen()));
      return;
    }
    if (item.domain == SyncRecoveryDomain.hymn) {
      Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => const MezmurHymnsScreen()));
      return;
    }
    final tab = _sourceTab(item.domain);
    if (tab == null) return;
    Navigator.of(context).pop();
    Future<void>.microtask(() => AppNav().openTab(tab));
  }

  void _inspectReason(SyncRecoveryItem item) {
    final reason = item.reason?.trim();
    if (reason == null || reason.isEmpty) return;
    showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Why this needs attention'),
        content: SelectableText(reason),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final waiting = _items.where((item) => item.isWaiting).toList();
    final scheduled = _items.where((item) => item.isRetryScheduled).toList();
    final attention = _items.where((item) => item.needsAttention).toList();
    final paused = _items.where((item) => item.isPaused).toList();
    final conflicts = _items.where((item) => item.isConflict).toList();
    final hiddenPaused = math.max(0, _inventory.paused - paused.length);
    final unresolved = _inventory.privateUnresolvedTotal +
        _inventory.sharedHymnUnresolvedTotal +
        _inventory.communicationDraftCount;

    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      appBar: AppBar(
        title: const Text('Sync Center'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            tooltip: 'Refresh',
            onPressed: _acting ? null : () => _load(),
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
                children: [
                  _summaryCard(unresolved),
                  if (_error != null) ...[
                    const SizedBox(height: 12),
                    _noticeCard(
                      icon: Icons.error_outline_rounded,
                      text: _error!,
                      color: AppTheme.danger,
                    ),
                  ],
                  if (_inventory.communicationDraftCount > 0) ...[
                    const SizedBox(height: 12),
                    _noticeCard(
                      icon: Icons.edit_note_rounded,
                      text:
                          '${_inventory.communicationDraftCount} saved message '
                          'draft${_inventory.communicationDraftCount == 1 ? '' : 's'} '
                          'remain on this phone.',
                      color: AppTheme.primary,
                    ),
                  ],
                  const SizedBox(height: 18),
                  _section(
                    title: 'Waiting to send',
                    subtitle: 'Saved locally or currently being sent',
                    icon: Icons.cloud_upload_outlined,
                    items: waiting,
                  ),
                  _section(
                    title: 'Retry scheduled',
                    subtitle: 'Will retry automatically after a delay',
                    icon: Icons.schedule_send_outlined,
                    items: scheduled,
                  ),
                  _section(
                    title: 'Needs attention',
                    subtitle: 'Inspect the reason, retry, open, or discard',
                    icon: Icons.error_outline_rounded,
                    items: attention,
                  ),
                  _section(
                    title: 'Paused after sign-in/access change',
                    subtitle: 'Held safely until compatible access is active',
                    icon: Icons.pause_circle_outline_rounded,
                    items: paused,
                    extraCount: hiddenPaused,
                    trailing: hiddenPaused > 0
                        ? _scopeHoldCard(hiddenPaused)
                        : null,
                  ),
                  _section(
                    title: 'Resolved conflicts',
                    subtitle: 'The server copy won; acknowledge after review',
                    icon: Icons.rule_folder_outlined,
                    items: conflicts,
                  ),
                  if (!_includeSharedHymns &&
                      _inventory.sharedHymnUnresolvedTotal > 0)
                    _noticeCard(
                      icon: Icons.library_music_outlined,
                      text:
                          '${_inventory.sharedHymnUnresolvedTotal} shared hymn '
                          'operation${_inventory.sharedHymnUnresolvedTotal == 1 ? '' : 's'} '
                          'will be available to an authorized curator.',
                      color: AppTheme.warning,
                    ),
                ],
              ),
            ),
    );
  }

  Widget _summaryCard(int unresolved) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        boxShadow: const [
          BoxShadow(color: Color(0x12000000), blurRadius: 16, offset: Offset(0, 6)),
        ],
      ),
      child: Row(children: [
        Container(
          width: 48,
          height: 48,
          decoration: BoxDecoration(
            color: AppTheme.primary.withOpacity(0.1),
            borderRadius: BorderRadius.circular(14),
          ),
          child: const Icon(Icons.sync_rounded, color: AppTheme.primary),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('$unresolved saved item${unresolved == 1 ? '' : 's'}',
                style: const TextStyle(
                    fontSize: 18, fontWeight: FontWeight.w800)),
            const SizedBox(height: 3),
            const Text(
              'Work stays on this device until it is sent, reviewed, or explicitly discarded.',
              style: TextStyle(color: AppTheme.textSecondary, height: 1.3),
            ),
          ]),
        ),
      ]),
    );
  }

  Widget _section({
    required String title,
    required String subtitle,
    required IconData icon,
    required List<SyncRecoveryItem> items,
    int extraCount = 0,
    Widget? trailing,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 18),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Icon(icon, size: 20, color: AppTheme.primary),
          const SizedBox(width: 8),
          Expanded(
            child: Text(title,
                style: const TextStyle(
                    fontSize: 16, fontWeight: FontWeight.w800)),
          ),
          Text('${items.length + extraCount}',
              style: const TextStyle(
                  color: AppTheme.textSecondary,
                  fontWeight: FontWeight.w700)),
        ]),
        Padding(
          padding: const EdgeInsets.only(left: 28, top: 2, bottom: 9),
          child: Text(subtitle,
              style: const TextStyle(
                  fontSize: 12, color: AppTheme.textSecondary)),
        ),
        if (items.isEmpty && trailing == null)
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(0.65),
              borderRadius: BorderRadius.circular(14),
            ),
            child: const Text('No items',
                style: TextStyle(color: AppTheme.textSecondary)),
          )
        else ...[
          for (final item in items) _itemCard(item),
          if (trailing != null) trailing,
        ],
      ]),
    );
  }

  Widget _itemCard(SyncRecoveryItem item) {
    final reason = item.reason?.trim();
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      elevation: 0,
      color: Colors.white,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 10, 8),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(item.title,
                    style: const TextStyle(fontWeight: FontWeight.w800)),
                const SizedBox(height: 2),
                Text(item.detail,
                    style: const TextStyle(
                        fontSize: 12, color: AppTheme.textSecondary)),
                if (item.nextAttemptAt != null && item.isRetryScheduled) ...[
                  const SizedBox(height: 4),
                  Text('Next try ${_timeLabel(item.nextAttemptAt!)}',
                      style: const TextStyle(
                          fontSize: 12, color: AppTheme.warning)),
                ],
              ]),
            ),
            if (_canOpenSource(item))
              IconButton(
                tooltip: 'Open source screen',
                onPressed: _acting ? null : () => _openSource(item),
                icon: const Icon(Icons.open_in_new_rounded, size: 19),
              ),
          ]),
          if (reason != null && reason.isNotEmpty)
            InkWell(
              onTap: () => _inspectReason(item),
              child: Padding(
                padding: const EdgeInsets.only(top: 8, bottom: 4),
                child: Row(children: [
                  const Icon(Icons.info_outline_rounded,
                      size: 16, color: AppTheme.warning),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(reason,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            fontSize: 12, color: AppTheme.textSecondary)),
                  ),
                  const Icon(Icons.chevron_right_rounded,
                      size: 18, color: AppTheme.textSecondary),
                ]),
              ),
            ),
          if (item.requiresServerConfirmation && (item.needsAttention || item.isConflict))
            const Padding(
              padding: EdgeInsets.only(top: 8),
              child: Text(_attendanceConfirmationMessage,
                  style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
            ),
          if (item.canRetry || item.canAcknowledge || item.canDiscard)
            Wrap(spacing: 4, children: [
              if (item.canRetry)
                TextButton.icon(
                  onPressed: _acting ? null : () => _retry(item),
                  icon: const Icon(Icons.refresh_rounded, size: 17),
                  label: const Text('Retry'),
                ),
              if (item.canAcknowledge)
                TextButton.icon(
                  onPressed: _acting ? null : () => _acknowledge(item),
                  icon: const Icon(Icons.check_rounded, size: 17),
                  label: const Text('Acknowledge'),
                ),
              if (item.canDiscard && !item.canAcknowledge)
                TextButton.icon(
                  onPressed: _acting ? null : () => _confirmDiscard(item),
                  icon: const Icon(Icons.delete_outline_rounded, size: 17),
                  label: const Text('Discard'),
                  style: TextButton.styleFrom(foregroundColor: AppTheme.danger),
                ),
            ]),
        ]),
      ),
    );
  }

  Widget _scopeHoldCard(int count) => _noticeCard(
        icon: Icons.admin_panel_settings_outlined,
        text: '$count saved operation${count == 1 ? '' : 's'} from an earlier '
            'access scope are held safely. Details stay hidden; ask an '
            'administrator to restore compatible access.',
        color: AppTheme.warning,
      );

  Widget _noticeCard({
    required IconData icon,
    required String text,
    required Color color,
  }) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: color.withOpacity(0.08),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: color.withOpacity(0.24)),
      ),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(icon, color: color, size: 20),
        const SizedBox(width: 10),
        Expanded(
          child: Text(text,
              style: const TextStyle(fontSize: 13, height: 1.35)),
        ),
      ]),
    );
  }

  String _timeLabel(DateTime value) {
    final local = value.toLocal();
    final hour = local.hour % 12 == 0 ? 12 : local.hour % 12;
    final minute = local.minute.toString().padLeft(2, '0');
    final suffix = local.hour >= 12 ? 'PM' : 'AM';
    return '$hour:$minute $suffix';
  }
}
