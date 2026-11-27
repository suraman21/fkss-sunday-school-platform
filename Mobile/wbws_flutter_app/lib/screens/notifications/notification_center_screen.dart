import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/inbox_view_model.dart';
import '../../services/local_db.dart';
import '../../utils/notification_icons.dart';
import '../../services/notification_service.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/loading_skeleton.dart';
import '../members/member_detail_screen.dart';
import '../attendance_warnings/attendance_warning_detail_screen.dart';
import '../attendance/attendance_screen.dart';
import '../mezmur/mezmur_attendance.dart';
import 'messages_screen.dart';

/// P72 — the mobile Notification Center: Alerts + Announcements in
/// one screen (the web bell's two content tabs), plus a Messages
/// entry point and the announcement composer for permitted roles.
///
/// The server is authoritative: read state lives in
/// notification_reads; permissions (can_announce / can_message) come
/// with every /notifications/summary response.
class NotificationCenterScreen extends StatefulWidget {
  const NotificationCenterScreen({super.key});

  @override
  State<NotificationCenterScreen> createState() =>
      _NotificationCenterScreenState();
}

class _NotificationCenterScreenState extends State<NotificationCenterScreen>
    with SingleTickerProviderStateMixin, WidgetsBindingObserver {
  final _api = ApiService();
  final _db = LocalDb();
  late final TabController _tabs = TabController(length: 2, vsync: this);

  final _alerts = <Map<String, dynamic>>[];
  final _announcements = <Map<String, dynamic>>[];
  bool _loadingAlerts = true;
  bool _loadingAnn = true;
  bool _canAnnounce = false;
  bool _canMessage = false;
  String? _error;

  // P74 Phase 3 — All/Unread filter + "Load older" cursor state.
  bool _unreadOnly = false;
  bool _loadingOlderAlerts = false;
  bool _loadingOlderAnn = false;
  bool _alertsHasMore = false;
  int? _alertsNextBefore;
  bool _annHasMore = false;
  int? _annNextBefore;
  int? _annNextPin;
  String? _annError;

  // P1-B local-first state: background-refresh flag + per-tab cache
  // freshness ('· updated HH:MM' for the stale banner).
  bool _refreshingAll = false;
  String? _alertsFresh;
  String? _annFresh;
  StreamSubscription<bool>? _radioSub;

  @override
  void initState() {
    super.initState();
    _tabs.addListener(() => setState(() {}));
    _loadAll();
    // P1-B: radio-return + app-resume refresh (P1-A screen-local
    // pattern — the center is a pushed route, so the shell's
    // tab-refresh never reaches it). Rows stay visible throughout.
    WidgetsBinding.instance.addObserver(this);
    _radioSub = ConnectivityService().statusStream.listen((online) {
      if (online) {
        Future.delayed(const Duration(seconds: 1), () {
          if (mounted) _loadAll();
        });
      }
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _radioSub?.cancel();
    _tabs.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // App return: refresh the local store from the server.
    if (state == AppLifecycleState.resumed) _loadAll();
  }

  Future<void> _loadAll() async {
    if (_refreshingAll) return; // single-flight: pull/radio/resume overlap
    _refreshingAll = true;
    try {
      final sum = await NotificationService.instance.refresh();
      if (!mounted) return;
      if (sum != null) {
        _canAnnounce = sum['can_announce'] == true;
        _canMessage = sum['can_message'] == true;
      }
      await Future.wait([_loadAlerts(), _loadAnnouncements()]);
    } finally {
      _refreshingAll = false;
      if (mounted) setState(() {});
    }
  }

  /// P0 audit B5: [silent] reloads keep the rows visible — skeletons
  /// are reserved for the first load and explicit retries.
  /// P1-B: the local cache renders FIRST (offline-capable); the API
  /// refresh runs independently afterwards, persists its page, and
  /// the list re-renders from the refreshed local store.
  Future<void> _loadAlerts({bool silent = false}) async {
    // ── 1. LOCAL READ (instant; offline-capable) ─────────────────
    if (!silent) {
      final cached =
          await _db.getCachedNotifications(unreadOnly: _unreadOnly, limit: 40);
      if (!mounted) return;
      if (cached.isNotEmpty) {
        setState(() {
          _alerts
            ..clear()
            ..addAll(cached);
          _loadingAlerts = false;
          _alertsFresh = _freshest(cached);
        });
      }
    }
    // ── 2. SERVER REFRESH (independent, non-blocking) ────────────
    if (!silent) setState(() => _loadingAlerts = _alerts.isEmpty);
    final res = await _api.getNotificationFeed(
        limit: 40, unreadOnly: _unreadOnly);
    if (!mounted) return;
    final data = res.data is Map<String, dynamic>
        ? res.data as Map<String, dynamic>
        : null;
    final okRows = (data != null && data['rows'] is List)
        ? List<Map<String, dynamic>>.from(
            (data['rows'] as List).whereType<Map<String, dynamic>>())
        : null;
    // P1-B: persist the successful page (merge-upsert by server id —
    // repeated refreshes can never duplicate).
    if (okRows != null && okRows.isNotEmpty) {
      await _db.cacheNotificationRows(okRows);
    }
    // Re-render from the local store so UI == SQLite.
    final localNow = okRows != null
        ? await _db.getCachedNotifications(unreadOnly: _unreadOnly, limit: 40)
        : null;
    if (!mounted) return;
    setState(() {
      _loadingAlerts = false;
      _error = res.isNetworkError ? 'You appear to be offline.' : null;
      // P1 audit C2: only a successful load may replace the rows —
      // a failed refresh keeps what's on screen under the stale
      // banner instead of destroying the user's content.
      if (localNow != null || _alerts.isEmpty) {
        _alerts
          ..clear()
          ..addAll(localNow ?? []);
        if (localNow != null && localNow.isNotEmpty) {
          _alertsFresh = _freshest(localNow);
        }
      }
      _alertsHasMore = hasMore(data);
      _alertsNextBefore = nextCursor(data, 'next_before');
    });
  }

  /// P1-B freshness: newest cache-write stamp among the rendered
  /// rows, as 'HH:MM' (same day) or 'YYYY-MM-DD'.
  String? _freshest(List<Map<String, dynamic>> rows) {
    String? maxIso;
    for (final r in rows) {
      final iso = r['local_fetched_at'];
      if (iso is String && (maxIso == null || iso.compareTo(maxIso) > 0)) {
        maxIso = iso;
      }
    }
    if (maxIso == null) return null;
    final dt = DateTime.tryParse(maxIso);
    if (dt == null) return null;
    final local = dt.toLocal();
    final now = DateTime.now();
    String p2(int v) => v.toString().padLeft(2, '0');
    final sameDay = local.year == now.year &&
        local.month == now.month &&
        local.day == now.day;
    return sameDay
        ? '${p2(local.hour)}:${p2(local.minute)}'
        : '${local.year}-${p2(local.month)}-${p2(local.day)}';
  }

  /// P74 Phase 3 — "Load older": page backwards by the server's
  /// stable before_id cursor; the older page appends (newest-first
  /// order), de-duplicated at the window edge.
  /// P1-B: cached pages serve first (offline-capable, id < oldest
  /// displayed); the server cursor continues when the local set is
  /// exhausted.
  Future<void> _loadOlderAlerts() async {
    if (_loadingOlderAlerts || !_alertsHasMore || _alertsNextBefore == null) {
      return;
    }
    setState(() => _loadingOlderAlerts = true);
    // ── 1. LOCAL page first: the server's before_id semantics on
    //     SQLite (id < oldest displayed id, id DESC).
    if (_alerts.isNotEmpty) {
      final oldest = (_alerts.last['id'] as num?)?.toInt() ?? 0;
      if (oldest > 0) {
        final localPage = await _db.getCachedNotifications(
            unreadOnly: _unreadOnly, beforeId: oldest, limit: 40);
        if (!mounted) return;
        if (localPage.isNotEmpty) {
          // Compute BEFORE clearing — the merge reads the current rows.
          final merged = mergeOlderRows(_alerts, localPage);
          setState(() {
            _alerts
              ..clear()
              ..addAll(merged);
            _loadingOlderAlerts = false;
          });
          return;
        }
      }
    }
    // ── 2. Local set exhausted → the server's cursor.
    final res = await _api.getNotificationFeed(
        limit: 40, unreadOnly: _unreadOnly, beforeId: _alertsNextBefore);
    if (!mounted) return;
    final data = res.data is Map<String, dynamic>
        ? res.data as Map<String, dynamic>
        : null;
    final serverPage = (data != null && data['rows'] is List)
        ? List<Map<String, dynamic>>.from(
            (data['rows'] as List).whereType<Map<String, dynamic>>())
        : null;
    if (serverPage != null && serverPage.isNotEmpty) {
      await _db.cacheNotificationRows(serverPage);
    }
    if (!mounted) return;
    setState(() {
      if (serverPage != null) {
        // Compute BEFORE clearing — the merge reads the current rows.
        final merged = mergeOlderRows(_alerts, serverPage);
        _alerts
          ..clear()
          ..addAll(merged);
      }
      _alertsHasMore = hasMore(data);
      _alertsNextBefore = nextCursor(data, 'next_before');
      _loadingOlderAlerts = false;
    });
  }

  Future<void> _setUnreadOnly(bool value) async {
    if (_unreadOnly == value) return;
    _unreadOnly = value;
    await _loadAlerts();
  }

  /// P1-B: the local cache renders FIRST (offline-capable, expiry
  /// filtered); the API refresh runs independently, persists its
  /// page, and the tab re-renders from the refreshed local store.
  Future<void> _loadAnnouncements({bool silent = false}) async {
    // ── 1. LOCAL READ (instant; offline-capable) ─────────────────
    if (!silent) {
      final cached = await _db.getCachedAnnouncements(limit: 40);
      if (!mounted) return;
      if (cached.isNotEmpty) {
        setState(() {
          _announcements
            ..clear()
            ..addAll(cached);
          _loadingAnn = false;
          _annFresh = _freshest(cached);
        });
      }
    }
    // ── 2. SERVER REFRESH (independent, non-blocking) ────────────
    if (!silent) setState(() => _loadingAnn = _announcements.isEmpty);
    final res = await _api.getAnnouncements(limit: 40);
    if (!mounted) return;
    final data = res.data is Map<String, dynamic>
        ? res.data as Map<String, dynamic>
        : null;
    final okRows = (data != null && data['announcements'] is List)
        ? List<Map<String, dynamic>>.from((data['announcements'] as List)
            .whereType<Map<String, dynamic>>())
        : null;
    // P1-B: persist the successful page (merge-upsert by server id).
    if (okRows != null && okRows.isNotEmpty) {
      await _db.cacheAnnouncementRows(okRows);
    }
    // Re-render from the local store so UI == SQLite.
    final localNow =
        okRows != null ? await _db.getCachedAnnouncements(limit: 40) : null;
    if (!mounted) return;
    setState(() {
      _loadingAnn = false;
      // P74 Phase 4 offline review: a failed load must not masquerade
      // as "No announcements" — mirror the alerts tab's error state.
      _annError = res.isNetworkError ? 'You appear to be offline.' : null;
      // P1 audit C2: only a successful load may replace the rows.
      if (localNow != null || _announcements.isEmpty) {
        _announcements
          ..clear()
          ..addAll(localNow ?? []);
        if (localNow != null && localNow.isNotEmpty) {
          _annFresh = _freshest(localNow);
        }
      }
      _annHasMore = hasMore(data);
      _annNextBefore = nextCursor(data, 'next_before');
      _annNextPin = nextCursor(data, 'next_pin');
    });
  }

  /// P74 Phase 3 — "Load older" on announcements: the (before_pin,
  /// before_id) tuple cursor keeps pinned/unpinned ordering stable
  /// across pages.
  /// P1-B: cached pages serve first (offline-capable, same tuple
  /// semantics); the server cursor continues when the local set is
  /// exhausted.
  Future<void> _loadOlderAnnouncements() async {
    if (_loadingOlderAnn || !_annHasMore || _annNextBefore == null) return;
    setState(() => _loadingOlderAnn = true);
    // ── 1. LOCAL page first: the server's tuple cursor on SQLite —
    //     (is_pinned, id) < the oldest displayed row's own tuple.
    if (_announcements.isNotEmpty) {
      final lastRow = _announcements.last;
      final oldestId = (lastRow['id'] as num?)?.toInt() ?? 0;
      final oldestPin = lastRow['is_pinned']?.toString() == '1' ? 1 : 0;
      if (oldestId > 0) {
        final localPage = await _db.getCachedAnnouncements(
            beforePin: oldestPin, beforeId: oldestId, limit: 40);
        if (!mounted) return;
        if (localPage.isNotEmpty) {
          final merged = mergeOlderRows(_announcements, localPage);
          setState(() {
            _announcements
              ..clear()
              ..addAll(merged);
            _loadingOlderAnn = false;
          });
          return;
        }
      }
    }
    // ── 2. Local set exhausted → the server's tuple cursor.
    final res = await _api.getAnnouncements(
        limit: 40, beforeId: _annNextBefore, beforePin: _annNextPin);
    if (!mounted) return;
    final data = res.data is Map<String, dynamic>
        ? res.data as Map<String, dynamic>
        : null;
    final serverPage = (data != null && data['announcements'] is List)
        ? List<Map<String, dynamic>>.from((data['announcements'] as List)
            .whereType<Map<String, dynamic>>())
        : null;
    if (serverPage != null && serverPage.isNotEmpty) {
      await _db.cacheAnnouncementRows(serverPage);
    }
    if (!mounted) return;
    setState(() {
      if (serverPage != null) {
        final merged = mergeOlderRows(_announcements, serverPage);
        _announcements
          ..clear()
          ..addAll(merged);
      }
      _annHasMore = hasMore(data);
      _annNextBefore = nextCursor(data, 'next_before');
      _annNextPin = nextCursor(data, 'next_pin');
      _loadingOlderAnn = false;
    });
  }

  /// P1 audit C1 — alerts are actionable: tapping marks the row read
  /// (if it isn't already — unchanged optimistic flow) AND routes to
  /// the alert's subject when the server sent a target. 'member'
  /// targets open that member's profile — the Outlook/Teams
  /// contract; rows without a target behave exactly as before.
  void _openAlert(Map<String, dynamic> n) {
    _markRead(n); // fire-and-forget; it owns its own error handling
    // Attendance alerting Phase 2: a warning bell opens the warning
    // itself (reason, risk, close-with-outcome); every other member
    // target still opens the profile.
    final warningId = warningTargetId(n);
    if (warningId != null) {
      Navigator.of(context).push(MaterialPageRoute(
          builder: (_) => AttendanceWarningDetailScreen(warningId: warningId)));
      return;
    }
    // Phase 3 (Q-2): a returned / reopened / rejected sheet opens that
    // sheet for the teacher (class + date) or taker (section + date).
    final sheet = sheetTarget(n);
    if (sheet != null) {
      final date = sheet['date'] as String;
      if (sheet['department'] == 'mezmur') {
        Navigator.of(context).push(MaterialPageRoute(
            builder: (_) => MezmurAttendanceScreen(
                initialDate: date.isEmpty ? null : date,
                initialSection: (sheet['section'] as String).isEmpty ? null : sheet['section'] as String)));
      } else {
        final classId = sheet['classId'] as int;
        Navigator.of(context).push(MaterialPageRoute(
            builder: (_) => AttendanceScreen(
                initialClassId: classId > 0 ? classId : null,
                initialDate: date.isEmpty ? null : date)));
      }
      return;
    }
    final memberId = memberTargetId(n);
    if (memberId == null) return;
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => MemberDetailScreen(memberId: memberId)));
  }

  Future<void> _markRead(Map<String, dynamic> n) async {
    final id = (n['id'] as num?)?.toInt() ?? 0;
    if (id <= 0 || n['is_unread'] != 1) return;
    // P74 Phase 3, web parity: the unread state clears INSTANTLY, the
    // badge decrements locally (floored at zero), the write confirms
    // in the background; a failure reverts by refetching.
    final optimistic = applyReadOptimistic(_alerts, id);
    if (!optimistic.changed) return;
    setState(() {
      _alerts
        ..clear()
        ..addAll(optimistic.rows);
    });
    NotificationService.instance.decrement('alerts');
    // P1-B: persist the optimistic read state so it survives restart
    // and offline browsing; the server reconciles on the next
    // successful refresh (and reverts there if this write below
    // never landed — the existing revert-by-refetch semantics).
    await _db.markCachedNotificationRead(id);
    final res = await _api.markNotificationRead(id);
    if (!mounted) return;
    if (res.success) {
      await NotificationService.instance.refresh();
    } else {
      await _loadAlerts();
      await NotificationService.instance.refresh();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Could not mark as read.')));
      }
    }
  }


  /// Announcements are broadcast, not per-user, so there is no per-row server
  /// call: the read happens locally and the badge updates here. They used to
  /// be untappable entirely -- a row showed a NEW badge and nothing could ever
  /// clear it, which reads as a broken badge rather than an unread item.
  Future<void> _markAnnouncementRead(Map<String, dynamic> a) async {
    final id = (a['id'] as num?)?.toInt() ?? 0;
    if (id <= 0 || a['is_unread'] != 1) return;
    final optimistic = applyReadOptimistic(_announcements, id);
    if (!optimistic.changed) return;
    setState(() {
      _announcements
        ..clear()
        ..addAll(optimistic.rows);
    });
    NotificationService.instance.decrement('announcements');
    // P1-B: persist so the cleared state survives a restart and offline
    // browsing; the next successful refresh reconciles from the server.
    await _db.markCachedAnnouncementRead(id);
  }

  Future<void> _markAll() async {
    final scope = _tabs.index == 1 ? 'announcements' : 'alerts';
    final res = await _api.markAllNotificationsRead(scope: scope);
    if (!mounted) return;
    if (!res.success) {
      // P1-B: this used to fail silently offline — say it honestly.
      // The rows stay exactly as they are; the next successful
      // refresh reconciles them.
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Could not mark all as read.')));
    }
    // B5: no skeleton flash after a bulk action — rows update in place.
    await Future.wait([_loadAlerts(silent: true), _loadAnnouncements(silent: true)]);
    await NotificationService.instance.refresh();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      appBar: AppBar(
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        title: const Text('Notifications',
            style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
        actions: [
          if (_canMessage)
            IconButton(
              icon: const Icon(Icons.chat_bubble_outline_rounded, size: 22),
              tooltip: 'Messages',
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => const MessagesScreen())),
            ),
          IconButton(
            icon: const Icon(Icons.done_all_rounded, size: 21),
            tooltip: 'Mark all read',
            onPressed: _markAll,
          ),
        ],
        bottom: TabBar(
          controller: _tabs,
          indicatorColor: AppTheme.accent,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          tabs: [
            Tab(
                text: NotificationService.instance.count('alerts') > 0
                    ? 'Alerts (${NotificationService.instance.count('alerts')})'
                    : 'Alerts'),
            Tab(
                text: NotificationService.instance.count('announcements') > 0
                    ? 'Announcements (${NotificationService.instance.count('announcements')})'
                    : 'Announcements'),
          ],
        ),
      ),
      floatingActionButton: (_canAnnounce && _tabs.index == 1)
          ? FloatingActionButton.extended(
              backgroundColor: AppTheme.success,
              foregroundColor: Colors.white,
              icon: const Icon(Icons.campaign_rounded, size: 22),
              label: const Text('New announcement',
                  style: TextStyle(fontWeight: FontWeight.w700)),
              onPressed: () => _openComposer(context),
            )
          : null,
      body: Column(
        children: [
          // P1-B: a background refresh (open/radio-return/resume) is
          // in flight — the rows stay visible underneath.
          if (_refreshingAll)
            const LinearProgressIndicator(
                minHeight: 3, color: AppTheme.primary),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _loadAll,
              child: TabBarView(
                controller: _tabs,
                children: [_alertsTab(), _announcementsTab()],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _alertsTab() {
    if (_loadingAlerts) {
      return ListView(
          padding: const EdgeInsets.all(14),
          children: List.generate(
              6,
              (_) => const ShimmerBox(
                  width: double.infinity, height: 74, radius: 14)));
    }
    if (_error != null && _alerts.isEmpty) {
      return ListView(children: [
        EmptyState(
            icon: Icons.wifi_off_rounded,
            title: 'Could not load',
            subtitle: _error,
            action: TextButton(onPressed: _loadAlerts, child: const Text('Retry')))
      ]);
    }
    if (_alerts.isEmpty) {
      // NOTE: ListView's default constructor is NOT const — the const
      // belongs on the children list, not on Expanded/ListView (this
      // exact mistake broke the user's 1.2.0 release build once).
      return Column(
        children: [
          _filterChips(),
          Expanded(
              child: ListView(children: const [
            EmptyState(
                icon: Icons.notifications_none_rounded,
                title: 'You are all caught up',
                subtitle: 'New alerts will appear here.')
          ])),
        ],
      );
    }
    return Column(
      children: [
        _filterChips(),
        Expanded(
          child: ListView.builder(
            padding: const EdgeInsets.all(14),
            itemCount:
                _alerts.length + (_alertsHasMore ? 1 : 0) + (_error != null ? 1 : 0),
            itemBuilder: (_, i) {
              // P1 audit C2 — stale banner over kept rows.
              if (_error != null && i == 0) {
                return _staleBanner(_error!, 'alerts', updated: _alertsFresh);
              }
              final j = i - (_error != null ? 1 : 0);
              if (_alertsHasMore && j == _alerts.length) {
                return _loadOlderControl(_loadingOlderAlerts, _loadOlderAlerts);
              }
              return _alertTile(_alerts[j]);
            },
          ),
        ),
      ],
    );
  }

  /// P74 Phase 3 — All / Unread filter (the web inbox's unreadOnly
  /// toggle; the server applies the filter, this is not a client sieve).
  Widget _filterChips() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 0),
      child: Row(
        children: [
          ChoiceChip(
            label: const Text('All',
                style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700)),
            selected: !_unreadOnly,
            onSelected: (_) => _setUnreadOnly(false),
            selectedColor: AppTheme.primary,
            labelStyle: const TextStyle(
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
                color: Colors.white),
            backgroundColor: Colors.white,
            showCheckmark: false,
            side: BorderSide(color: AppTheme.borderLight),
          ),
          const SizedBox(width: 8),
          ChoiceChip(
            label: Text(
                'Unread${NotificationService.instance.count('alerts') > 0 ? ' (${NotificationService.instance.count('alerts')})' : ''}',
                style: const TextStyle(
                    fontSize: 12.5, fontWeight: FontWeight.w700)),
            selected: _unreadOnly,
            onSelected: (_) => _setUnreadOnly(true),
            selectedColor: AppTheme.primary,
            labelStyle: const TextStyle(
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
                color: Colors.white),
            backgroundColor: Colors.white,
            showCheckmark: false,
            side: BorderSide(color: AppTheme.borderLight),
          ),
        ],
      ),
    );
  }

  /// P74 Phase 3 — trailing "Load older" control (web parity: shown
  /// only while the server says an older page exists).
  Widget _loadOlderControl(bool busy, Future<void> Function() onLoad) {
    return Padding(
      padding: const EdgeInsets.only(top: 4, bottom: 8),
      child: Center(
        child: OutlinedButton.icon(
          style: OutlinedButton.styleFrom(
            backgroundColor: Colors.white,
            foregroundColor: AppTheme.primary,
            side: BorderSide(color: AppTheme.borderLight),
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(13)),
          ),
          onPressed: busy ? null : onLoad,
          icon: busy
              ? const SizedBox(
                  width: 14,
                  height: 14,
                  child: CircularProgressIndicator(strokeWidth: 2))
              : const Icon(Icons.expand_more_rounded, size: 18),
          label: Text(busy ? 'Loading…' : 'Load older',
              style:
                  const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700)),
        ),
      ),
    );
  }

  /// P1 audit C2 — Google's rule: a failed refresh never destroys
  /// content; a slim banner says what happened instead. Text
  /// #92400E on #FEF3C7 = 6.37:1. P1-B: appends the cache's last
  /// write time when known ('· updated HH:MM').
  Widget _staleBanner(String message, String what, {String? updated}) {
    final suffix = updated == null ? '' : ' · updated $updated';
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        color: const Color(0xFFFEF3C7),
        borderRadius: BorderRadius.circular(11),
      ),
      child: Row(
        children: [
          const Icon(Icons.wifi_off_rounded,
              size: 15, color: Color(0xFF92400E)),
          const SizedBox(width: 7),
          Expanded(
            child: Text('$message — showing recent $what$suffix.',
                style: const TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                    color: Color(0xFF92400E))),
          ),
        ],
      ),
    );
  }

  Widget _alertTile(Map<String, dynamic> n) {
    final unread = n['is_unread'] == 1;
    final priority = (n['priority'] ?? 'normal') as String;
    final urgent = priority == 'urgent';
    final high = priority == 'high';
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(
        color: unread ? const Color(0xFFF0FDF4) : Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
            color: unread
                ? const Color(0xFFA7F3D0)
                : AppTheme.borderLight),
      ),
      child: ListTile(
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        leading: CircleAvatar(
          radius: 19,
          backgroundColor: urgent
              ? const Color(0xFFFEE2E2)
              : high
                  ? const Color(0xFFFEF3C7)
                  : const Color(0xFFDBEAFE),
          // P1 audit C7 — the web's type→icon table, ported: every
          // domain gets its own glyph; tint still follows priority.
          child: Icon(
              notificationTypeIcon(n['type']?.toString()),
              size: 19,
              color: urgent
                  ? AppTheme.danger
                  : high
                      ? AppTheme.warning
                      : AppTheme.info),
        ),
        title: Text(n['title']?.toString() ?? '',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
                fontSize: 14,
                fontWeight: unread ? FontWeight.w800 : FontWeight.w600,
                color: AppTheme.textPrimary)),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 3),
          child: Text(n['message']?.toString() ?? '',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                  fontSize: 12.5, color: AppTheme.textSecondary)),
        ),
        trailing: unread
            ? Semantics(
                label: 'Unread',
                // NOTE: Semantics' constructor is NOT const (unlike its
                // children here) — this exact const mistake has broken
                // two release builds; keep const off the wrapper.
                child: const SizedBox(
                  width: 9,
                  height: 9,
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                        color: AppTheme.success, shape: BoxShape.circle),
                  ),
                ),
              )
            : null,
        onTap: () => _openAlert(n),
      ),
    );
  }

  Widget _announcementsTab() {
    if (_loadingAnn) {
      return ListView(
          padding: const EdgeInsets.all(14),
          children: List.generate(
              4,
              (_) => const ShimmerBox(
                  width: double.infinity, height: 120, radius: 14)));
    }
    if (_annError != null && _announcements.isEmpty) {
      return ListView(children: [
        EmptyState(
            icon: Icons.wifi_off_rounded,
            title: 'Could not load',
            subtitle: _annError,
            action: TextButton(
                onPressed: _loadAnnouncements,
                child: const Text('Retry')))
      ]);
    }
    if (_announcements.isEmpty) {
      return ListView(children: const [
        EmptyState(
            icon: Icons.campaign_outlined,
            title: 'No announcements',
            subtitle: 'Department announcements will appear here.')
      ]);
    }
    return ListView.builder(
      padding: const EdgeInsets.all(14),
      itemCount: _announcements.length +
          (_annHasMore ? 1 : 0) +
          (_annError != null ? 1 : 0),
      itemBuilder: (_, i) {
        // P1 audit C2 — stale banner over kept rows.
        if (_annError != null && i == 0) {
          return _staleBanner(_annError!, 'announcements', updated: _annFresh);
        }
        final j = i - (_annError != null ? 1 : 0);
        if (_annHasMore && j == _announcements.length) {
          return _loadOlderControl(_loadingOlderAnn, _loadOlderAnnouncements);
        }
        return _announcementCard(_announcements[j]);
      },
    );
  }

  Widget _announcementCard(Map<String, dynamic> a) {
    final unread = a['is_unread'] == 1;
    final priority = (a['priority'] ?? 'normal') as String;
    final pinned = a['is_pinned'] == 1;
    final card = Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: unread ? const Color(0xFFF6FEF9) : Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
            color: unread ? const Color(0xFFA7F3D0) : AppTheme.borderLight),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              CircleAvatar(
                radius: 17,
                backgroundColor: priority == 'urgent'
                    ? const Color(0xFFFEE2E2)
                    : priority == 'high'
                        ? const Color(0xFFFEF3C7)
                        : const Color(0xFFD1FAE5),
                child: Icon(Icons.campaign_rounded,
                    size: 18,
                    color: priority == 'urgent'
                        ? AppTheme.danger
                        : priority == 'high'
                            ? AppTheme.warning
                            : AppTheme.success),
              ),
              const SizedBox(width: 11),
              Expanded(
                child: Text(
                  (pinned ? '📌 ' : '') + (a['title']?.toString() ?? ''),
                  style: TextStyle(
                      fontSize: 14.5,
                      fontWeight:
                          unread ? FontWeight.w800 : FontWeight.w700,
                      color: AppTheme.textPrimary),
                ),
              ),
              if (unread)
                Semantics(
                  label: 'New announcement',
                  child: const DecoratedBox(
                    decoration: BoxDecoration(
                        color: Color(0xFFD1FAE5),
                        borderRadius: BorderRadius.all(Radius.circular(9))),
                    child: Padding(
                      padding:
                          EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      child: Text('NEW',
                          style: TextStyle(
                              fontSize: 10.5,
                              fontWeight: FontWeight.w800,
                              color: Color(0xFF065F46))),
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 10),
          Text(a['body']?.toString() ?? '',
              style: const TextStyle(
                  fontSize: 13.5,
                  height: 1.55,
                  color: AppTheme.textPrimary)),
          const SizedBox(height: 10),
          Text(
            '${a['author_label'] ?? ''}${a['author_name'] != null ? ' · ${a['author_name']}' : ''}',
            style: const TextStyle(
                fontSize: 11.5, color: AppTheme.textSecondary),
          ),
        ],
      ),
    );
    // Tapping clears the unread badge; a read announcement is not a link,
    // so it stays inert rather than opening something that does not exist.
    if (!unread) return card;
    return InkWell(
      onTap: () => _markAnnouncementRead(a),
      borderRadius: BorderRadius.circular(16),
      child: card,
    );
  }

  Future<void> _openComposer(BuildContext context) async {
    final res = await _api.getAnnounceTargets();
    if (!mounted) return;
    if (!res.success ||
        res.data is! Map ||
        (res.data as Map)['roles'] is! Map) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Could not load announcement recipients.')));
      return;
    }
    final data = res.data as Map;
    final roles = Map<String, dynamic>.from(data['roles'] as Map);
    final users = List<Map<String, dynamic>>.from(
        ((data['users'] ?? []) as List).whereType<Map<String, dynamic>>());

    final title = TextEditingController();
    final body = TextEditingController();
    var priority = 'normal';
    var audienceRoles = true;
    final selectedRoles = <String>{};
    final selectedUsers = <int>{};

    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(22))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Padding(
          padding: EdgeInsets.only(
              left: 18, right: 18, top: 16,
              bottom: MediaQuery.of(ctx).viewInsets.bottom + 18),
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('New announcement',
                    style: TextStyle(
                        fontSize: 16, fontWeight: FontWeight.w800)),
                const SizedBox(height: 14),
                TextField(
                    controller: title,
                    maxLength: 200,
                    decoration: const InputDecoration(
                        labelText: 'Title',
                        border: OutlineInputBorder())),
                const SizedBox(height: 10),
                TextField(
                    controller: body,
                    maxLines: 4,
                    maxLength: 5000,
                    decoration: const InputDecoration(
                        labelText: 'Message',
                        border: OutlineInputBorder())),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: priority,
                  decoration: const InputDecoration(labelText: 'Priority'),
                  items: const [
                    DropdownMenuItem(value: 'normal', child: Text('Normal')),
                    DropdownMenuItem(value: 'high', child: Text('High — important')),
                    DropdownMenuItem(
                        value: 'urgent', child: Text('Urgent — needs attention now')),
                  ],
                  onChanged: (v) => setSheet(() => priority = v ?? 'normal'),
                ),
                const SizedBox(height: 14),
                SegmentedButton<bool>(
                  segments: const [
                    ButtonSegment(value: true, label: Text('Whole groups')),
                    ButtonSegment(value: false, label: Text('People')),
                  ],
                  selected: {audienceRoles},
                  onSelectionChanged: (s) =>
                      setSheet(() => audienceRoles = s.first),
                ),
                const SizedBox(height: 12),
                if (audienceRoles)
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: roles.entries.map((e) {
                      final on = selectedRoles.contains(e.key);
                      return FilterChip(
                        label: Text(e.value.toString()),
                        selected: on,
                        onSelected: (v) => setSheet(() =>
                            v ? selectedRoles.add(e.key) : selectedRoles.remove(e.key)),
                      );
                    }).toList(),
                  )
                else
                  SizedBox(
                    height: 160,
                    child: ListView(
                      children: users.map((u) {
                        final id = (u['id'] as num).toInt();
                        return CheckboxListTile(
                          dense: true,
                          value: selectedUsers.contains(id),
                          title: Text(u['label']?.toString() ?? ''),
                          onChanged: (v) => setSheet(() =>
                              v == true ? selectedUsers.add(id) : selectedUsers.remove(id)),
                        );
                      }).toList(),
                    ),
                  ),
                const SizedBox(height: 16),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton(
                    style: FilledButton.styleFrom(
                        backgroundColor: AppTheme.success),
                    onPressed: () async {
                      final empty = audienceRoles
                          ? selectedRoles.isEmpty
                          : selectedUsers.isEmpty;
                      if (title.text.trim().isEmpty ||
                          body.text.trim().isEmpty ||
                          empty) {
                        ScaffoldMessenger.of(ctx).showSnackBar(const SnackBar(
                            content: Text(
                                'Title, message and audience are required.')));
                        return;
                      }
                      final send = await _api.composeAnnouncement(
                        title: title.text.trim(),
                        body: body.text.trim(),
                        priority: priority,
                        audience: audienceRoles ? 'roles' : 'users',
                        roles: selectedRoles.toList(),
                        userIds: selectedUsers.toList(),
                      );
                      if (!ctx.mounted) return;
                      Navigator.of(ctx).pop();
                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(send.success
                              ? 'Announcement published ✓'
                              : (send.message ?? 'Could not publish.'))));
                      if (send.success) {
                        HapticFeedback.lightImpact();
                        _loadAll();
                      }
                    },
                    child: const Text('Publish',
                        style: TextStyle(fontWeight: FontWeight.w700)),
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
