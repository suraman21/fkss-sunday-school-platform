import 'dart:async';

import 'package:flutter/material.dart';
import '../../widgets/loading_skeleton.dart';
import '../../utils/transitions.dart';
import '../../utils/config.dart';
import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../utils/scrolling.dart';
import '../../utils/theme.dart';
import '../../widgets/fast_list.dart';
import '../../widgets/paged_list_footer.dart';
import 'member_detail_screen.dart';
import '../../widgets/qr_scan_sheet.dart';
import '../../services/qr_attendance.dart';
import '../../services/photo_outbox_service.dart';
import '../../services/photo_outbox_models.dart';
import '../../services/photo_sync_worker.dart';
import 'photo_sync_panel.dart';
import 'photo_batch_capture_screen.dart';

class MemberListScreen extends StatefulWidget {
  const MemberListScreen({super.key});

  @override
  State<MemberListScreen> createState() => _MemberListScreenState();
}

class _MemberListScreenState extends State<MemberListScreen>
    with WidgetsBindingObserver {
  final _api = ApiService();
  final _db = LocalDb();
  final _searchController = TextEditingController();
  final _scrollController = ScrollController();

  static const int _pageSize = 20;

  // P1-A local-first state: SQLite is the read source; the server
  // refresh runs independently and the list re-renders from the cache.
  List<Map<String, dynamic>> _members = [];
  int _localPagesLoaded = 0; // SQLite pages opened for the current query
  int _serverPage = 0; // deepest server page fetched for the query
  int _totalPages = 1; // server-reported page count for the query
  int? _serverTotal; // Phase 3: server-reported member count for the query
  bool _initialLoading = false; // empty cache + first fetch in flight
  bool _refreshing = false; // background refresh in flight
  bool _loadingMore = false;
  bool _refreshFailed = false; // last refresh failed — cached list stays up
  bool _isOffline = false; // OS radio down / network error
  String? _error; // set only when nothing can be rendered
  String _statusFilter = '';
  String? _lastSynced; // MAX(cached_members.updated_at)

  StreamSubscription<bool>? _radioSub;
  StreamSubscription<PhotoSyncEvent>? _photoEvents;
  Timer? _photoCounterTimer;
  PhotoOutboxCounters _photoCounters = PhotoOutboxCounters.zero;
  bool _photoServiceReady = false;

  bool get _photoOnly => UserRoles.canManageMemberPhotos(_api.userRole);
  bool get _canUploadPhotos => UserRoles.canUploadMemberPhotos(_api.userRole);

  void _refreshPhotoCounters() async {
    if (!_photoServiceReady) {
      await PhotoOutboxService.instance.ensureInitialized();
      _photoServiceReady = true;
    }
    final c = await PhotoOutboxService.instance.counters();
    if (mounted) setState(() => _photoCounters = c);
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this); // app-return refresh
    _loadMembers(newQuery: true);
    _scrollController.addListener(_onScroll);
    // Radio return: the shell's radio-refresh has no 'members' case,
    // so this screen listens for itself — settle 1 s first (same
    // pattern as app_shell: don't pile requests on a waking 4G radio).
    _radioSub = ConnectivityService().statusStream.listen((online) {
      if (online) {
        Future.delayed(const Duration(seconds: 1), () {
          if (mounted) _loadMembers();
        });
      }
    });
    // Photo-outbox badge refresh every 5s and on any sync event.
    _refreshPhotoCounters();
    _photoEvents = PhotoSyncWorker.instance.progressStream.listen((_) {
      _refreshPhotoCounters();
    });
    _photoCounterTimer = Timer.periodic(
        const Duration(seconds: 5), (_) => _refreshPhotoCounters());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _radioSub?.cancel();
    _photoEvents?.cancel();
    _photoCounterTimer?.cancel();
    _searchController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // App return: same trigger the shell gives every other tab.
    if (state == AppLifecycleState.resumed) _loadMembers();
  }

  void _onScroll() {
    // Local pagination works offline too; the server continuation
    // inside _loadMore checks the radio itself.
    if (_scrollController.position.pixels >=
        _scrollController.position.maxScrollExtent - 200) {
      _loadMore();
    }
  }

  /// Phase 9 QR lookup: scan a member card, resolve locally first
  /// (offline parity), then server; opens the member file directly.
  void _openLookup() {
    QrScanSheet.open(
      context,
      header: 'Scan member card',
      onScan: (raw) async {
        final code = QrAttendance.extractMemberCode(raw);
        if (code == null) return QrFeedback.invalid();
        final db = LocalDb();
        var member = await db.findCachedMemberByCode(code);
        int? id = member != null
            ? (member['id'] is int
                ? member['id'] as int
                : int.tryParse('${member['id']}'))
            : null;
        String name = member != null
            ? '${member['student_name'] ?? ''} ${member['father_name'] ?? ''}'
            : '';
        if (id == null) {
          final res = await _api.getMembers(search: code, limit: 5);
          if (res.success && res.data != null) {
            final items = (res.data!['items'] as List? ?? [])
                .whereType<Map>()
                .map((e) => Map<String, dynamic>.from(e))
                .toList();
            for (final m in items) {
              if ('${m['member_code'] ?? ''}' == code) {
                id = m['id'] is int
                    ? m['id'] as int
                    : int.tryParse('${m['id']}');
                name = '${m['student_name'] ?? ''} ${m['father_name'] ?? ''}';
                break;
              }
            }
          }
        }
        if (id != null && mounted) {
          final mid = id;
          Future.delayed(const Duration(milliseconds: 350), () {
            if (!mounted) return;
            Navigator.of(context).pop(); // close the sheet
            Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => MemberDetailScreen(memberId: mid)));
          });
          return QrFeedback.memberFound(name: name.trim());
        }
        return QrFeedback.notFound();
      },
    );
  }

  /// The SQLite window for the current query: every local page opened
  /// so far (at least one page), so a refresh re-reads the same depth
  /// instead of shrinking the visible list back to page 1.
  Future<List<Map<String, dynamic>>> _readLocalWindow(
      String search, String? status) async {
    final rows = await _db.getCachedMembers(
      search: search.isEmpty ? null : search,
      status: status,
      limit: (_localPagesLoaded <= 0 ? 1 : _localPagesLoaded) * _pageSize,
      offset: 0,
    );
    _localPagesLoaded =
        rows.isEmpty ? 0 : (rows.length + _pageSize - 1) ~/ _pageSize;
    return rows;
  }

  /// Server matches the local predicate cannot see (full_name_am /
  /// phone search for PII roles) are appended after the local window.
  List<Map<String, dynamic>> _mergeLocalAndServer(
      List<Map<String, dynamic>> local, List<Map<String, dynamic>> server) {
    if (server.isEmpty) return local;
    final ids = local.map((m) => m['id']).toSet();
    return [...local, ...server.where((m) => !ids.contains(m['id']))];
  }

  /// P1-A local-first load:
  ///   SQLite read → render immediately → server refresh independently
  ///   → cache upsert → re-render from the local DB.
  /// The network is never the prerequisite for the first useful render
  /// once anything is cached; nothing is fabricated when offline.
  Future<void> _loadMembers({bool newQuery = false}) async {
    if (_refreshing) return; // single-flight: pull/radio/resume overlap
    if (newQuery) {
      _localPagesLoaded = 0;
      _serverPage = 0;
      _totalPages = 1;
      _serverTotal = null;
    }
    final search = _searchController.text.trim();
    final status = _statusFilter.isEmpty ? null : _statusFilter;

    // ── 1. LOCAL READ (instant; offline-capable) ───────────────────
    final window = await _readLocalWindow(search, status);
    final lastSynced = await _db.getCachedMembersLastSynced();
    if (!mounted) return;
    setState(() {
      _lastSynced = lastSynced;
      _error = null;
      if (window.isNotEmpty) {
        _members = window;
        _initialLoading = false;
      } else if (newQuery) {
        // Genuine empty local result for this query — shown now, never
        // fabricated; a refresh may still fill it in.
        _members = [];
      }
    });

    // ── 2. SERVER REFRESH (independent, non-blocking) ──────────────
    if (!ConnectivityService().hasLink) {
      setState(() {
        _isOffline = true;
        _refreshing = false;
        if (_members.isEmpty) {
          _initialLoading = false;
          _error = 'Waiting for network and no members saved on this phone';
        }
      });
      return;
    }
    setState(() {
      _refreshing = true;
      _refreshFailed = false;
      _isOffline = false;
      if (_members.isEmpty) _initialLoading = true;
    });

    final depth = _localPagesLoaded <= 0 ? 1 : _localPagesLoaded;
    final serverRows = <Map<String, dynamic>>[];
    var ok = true;
    String? failMessage;
    for (var p = 1; p <= depth && p <= _totalPages; p++) {
      final res = await _api.getMembers(
        page: p,
        search: search.isEmpty ? null : search,
        status: status,
      );
      if (!mounted) return;
      if (res.success && res.data != null) {
        final items = (res.data!['items'] as List? ?? [])
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .toList();
        if (items.isNotEmpty) {
          // Existing merge/upsert semantics — unchanged (no F10 work).
          await _db.cacheMembers(items);
        }
        serverRows.addAll(items);
        final pagination = res.data!['pagination'] as Map? ?? {};
        _totalPages = (pagination['pages'] as num?)?.toInt() ?? 1;
        _serverTotal = (pagination['total'] as num?)?.toInt() ?? _serverTotal;
        _serverPage = p;
      } else {
        ok = false;
        _isOffline = res.isNetworkError;
        failMessage = res.message;
        break;
      }
    }
    if (!mounted) return;
    if (ok) {
      // Re-render from the refreshed local cache.
      final refreshed = await _readLocalWindow(search, status);
      if (!mounted) return;
      setState(() {
        _members = _mergeLocalAndServer(refreshed, serverRows);
        _refreshing = false;
        _initialLoading = false;
        _refreshFailed = false;
      });
    } else {
      // Failure with data on screen: keep the cached list visible and
      // surface the failure honestly — never a fake "no members".
      setState(() {
        _refreshing = false;
        _initialLoading = false;
        _refreshFailed = true;
        if (_members.isEmpty) {
          _error = _isOffline
              ? 'Waiting for network and no members saved on this phone'
              : (failMessage ?? "Couldn't load members right now");
        }
      });
    }
  }

  Future<void> _loadMore() async {
    if (_loadingMore || _refreshing || _initialLoading) return;
    _loadingMore = true;
    if (mounted) setState(() {}); // Phase 3: footer shows Loading…
    try {
      final search = _searchController.text.trim();
      final status = _statusFilter.isEmpty ? null : _statusFilter;

      // 1. Open the next LOCAL page first — works fully offline.
      final next = await _db.getCachedMembers(
        search: search.isEmpty ? null : search,
        status: status,
        limit: _pageSize,
        offset: _localPagesLoaded * _pageSize,
      );
      if (next.isNotEmpty && mounted) {
        final ids = _members.map((m) => m['id']).toSet();
        final added = next.where((m) => !ids.contains(m['id'])).toList();
        if (added.isNotEmpty) {
          setState(() => _members = [..._members, ...added]);
        }
        _localPagesLoaded++;
      }

      // 2. Local set exhausted → continue from the server while online
      //    (the pagination this screen always had; every fetched page
      //    upserts the cache, so it becomes locally available too).
      if (next.length < _pageSize &&
          ConnectivityService().hasLink &&
          _serverPage < _totalPages) {
        final res = await _api.getMembers(
          page: _serverPage + 1,
          search: search.isEmpty ? null : search,
          status: status,
        );
        if (!mounted) return;
        if (res.success && res.data != null) {
          final items = (res.data!['items'] as List? ?? [])
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList();
          if (items.isNotEmpty) await _db.cacheMembers(items);
          _serverPage++;
          final pagination = res.data!['pagination'] as Map? ?? {};
          _totalPages = (pagination['pages'] as num?)?.toInt() ?? 1;
          _serverTotal = (pagination['total'] as num?)?.toInt() ?? _serverTotal;
          final ids = _members.map((m) => m['id']).toSet();
          final added = items.where((m) => !ids.contains(m['id'])).toList();
          if (added.isNotEmpty && mounted) {
            setState(() => _members = [..._members, ...added]);
          }
        } else if (mounted) {
          setState(() {
            _refreshFailed = true;
            _isOffline = res.isNetworkError;
          });
        }
      }
    } finally {
      _loadingMore = false;
      if (mounted) setState(() {});
    }
  }

  /// Phase 3: more members exist (server total, else page count).
  bool get _hasMoreMembers => _serverTotal != null
      ? _members.length < _serverTotal!
      : _serverPage < _totalPages;

  /// '…saved on this phone · updated 14:32' — concise freshness from
  /// the existing cached_members.updated_at (no framework, no schema).
  String? _fmtUpdated(String? iso) {
    if (iso == null || iso.isEmpty) return null;
    final dt = DateTime.tryParse(iso);
    if (dt == null) return null;
    final local = dt.toLocal();
    final now = DateTime.now();
    final hh = local.hour.toString().padLeft(2, '0');
    final mm = local.minute.toString().padLeft(2, '0');
    final sameDay = local.year == now.year &&
        local.month == now.month &&
        local.day == now.day;
    return sameDay
        ? '$hh:$mm'
        : '${local.year}-${local.month.toString().padLeft(2, '0')}-${local.day.toString().padLeft(2, '0')}';
  }

  Widget _cachedStrip(String message, IconData icon) {
    final updated = _fmtUpdated(_lastSynced);
    final text = updated == null ? message : '$message · updated $updated';
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      color: AppTheme.warning.withOpacity(0.12),
      child: Row(
        children: [
          Icon(icon, size: 14, color: AppTheme.warning),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: TextStyle(
                  fontSize: 11,
                  color: AppTheme.warning,
                  fontWeight: FontWeight.w500),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Members'),
        automaticallyImplyLeading: Navigator.canPop(context),
        actions: [
          if (_canUploadPhotos) ...[
            IconButton(
              tooltip: 'Batch photo capture',
              icon: const Icon(Icons.photo_library_outlined, size: 22),
              onPressed: () => Navigator.of(context)
                  .push(SmoothPageRoute(page: const PhotoBatchCaptureScreen())),
            ),
            Stack(
              alignment: Alignment.center,
              children: [
                IconButton(
                  tooltip: 'Photo sync',
                  icon: const Icon(Icons.photo_camera_outlined, size: 22),
                  onPressed: () => Navigator.of(context).push(
                      SmoothPageRoute(page: const PhotoSyncPanelScreen())),
                ),
                if (_photoCounters.unsynced > 0)
                  Positioned(
                    right: 4,
                    top: 4,
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 5, vertical: 1),
                      decoration: BoxDecoration(
                        color: const Color(0xFF600000),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      constraints: const BoxConstraints(minWidth: 16),
                      child: Text(
                        _photoCounters.unsynced > 99
                            ? '99+'
                            : '${_photoCounters.unsynced}',
                        style: const TextStyle(
                            color: Colors.white,
                            fontSize: 9,
                            fontWeight: FontWeight.w700),
                        textAlign: TextAlign.center,
                      ),
                    ),
                  ),
              ],
            ),
          ],
          // Phase 9: scan a member-card QR to jump straight to the file.
          IconButton(
              tooltip: 'Scan member QR',
              icon: const Icon(Icons.qr_code_scanner, size: 20),
              onPressed: _openLookup),
          IconButton(
              icon: const Icon(Icons.refresh, size: 20),
              onPressed: () => _loadMembers()),
        ],
      ),
      body: Column(
        children: [
          // Honest cached-data strips: what's on screen is local.
          if (_isOffline && _members.isNotEmpty)
            _cachedStrip(
                'Waiting for network — showing members saved on this phone',
                Icons.cloud_off),
          if (!_isOffline && _refreshFailed && _members.isNotEmpty)
            _cachedStrip(
                "Couldn't update right now — showing members saved on this phone",
                Icons.sync_problem),
          // Background refresh in flight — data stays, never a blank.
          if (_refreshing)
            const LinearProgressIndicator(
                minHeight: 3, color: AppTheme.primary),

          // Search bar
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: TextField(
              controller: _searchController,
              decoration: InputDecoration(
                hintText: 'Search members...',
                prefixIcon: const Icon(Icons.search, size: 20),
                suffixIcon: _searchController.text.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.clear, size: 18),
                        onPressed: () {
                          _searchController.clear();
                          _loadMembers(newQuery: true);
                        },
                      )
                    : null,
                contentPadding:
                    const EdgeInsets.symmetric(vertical: 0, horizontal: 16),
                border:
                    OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
              ),
              onSubmitted: (_) => _loadMembers(newQuery: true),
              textInputAction: TextInputAction.search,
            ),
          ),

          if (!_photoOnly)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: [
                    _filterChip('All', ''),
                    _filterChip('Active', 'active'),
                    _filterChip('Warning', 'warning'),
                    _filterChip('Inactive', 'inactive'),
                  ],
                ),
              ),
            ),

          // List
          Expanded(
            child: _initialLoading
                ? const MemberListSkeleton()
                : _error != null
                    ? Center(
                        child: Padding(
                          padding: const EdgeInsets.all(32),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(
                                _isOffline
                                    ? Icons.cloud_off
                                    : Icons.error_outline,
                                size: 48,
                                color: _isOffline
                                    ? AppTheme.warning
                                    : AppTheme.danger,
                              ),
                              const SizedBox(height: 12),
                              Text(_error!,
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                      color: _isOffline
                                          ? AppTheme.warning
                                          : AppTheme.danger,
                                      fontSize: 14)),
                              const SizedBox(height: 12),
                              ElevatedButton.icon(
                                onPressed: () => _loadMembers(newQuery: true),
                                icon: const Icon(Icons.refresh, size: 18),
                                label: const Text('Retry'),
                              ),
                            ],
                          ),
                        ),
                      )
                    : _members.isEmpty
                        ? const Center(
                            child: Text('No members found',
                                style: TextStyle(color: Colors.grey)))
                        : RawScrollbar(
                            controller: _scrollController,
                            interactive: true,
                            thickness: 5,
                            radius: const Radius.circular(3),
                            thumbColor: const Color(0x595A1212),
                            child: RefreshIndicator(
                              onRefresh: () => _loadMembers(),
                              // Phase 3: fixed-extent rows (fast) + a
                              // visible footer: Showing N of T + Load more.
                              child: CustomScrollView(
                                controller: _scrollController,
                                cacheExtent: kListCacheExtent,
                                physics: const AlwaysScrollableScrollPhysics(),
                                slivers: [
                                  SliverPadding(
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 12),
                                    sliver: SliverFixedExtentList(
                                      itemExtent: kFastRowHeight,
                                      delegate: SliverChildBuilderDelegate(
                                        // Flat zebra row — no Card/shadow/ripple.
                                        (context, index) =>
                                            _memberCard(_members[index], index),
                                        childCount: _members.length,
                                        addAutomaticKeepAlives: false,
                                      ),
                                    ),
                                  ),
                                  SliverToBoxAdapter(
                                    child: Padding(
                                      padding: const EdgeInsets.fromLTRB(
                                          12, 0, 12, 24),
                                      child: PagedListFooter(
                                        shown: _members.length,
                                        total: _serverTotal,
                                        noun: 'members',
                                        loading: _loadingMore,
                                        hasMore: _hasMoreMembers,
                                        onLoadMore: _loadMore,
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
          ),
        ],
      ),
    );
  }

  Widget _filterChip(String label, String value) {
    final selected = _statusFilter == value;
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: FilterChip(
        label: Text(label,
            style:
                TextStyle(fontSize: 12, color: selected ? Colors.white : null)),
        selected: selected,
        onSelected: (_) {
          setState(() => _statusFilter = value);
          _loadMembers(newQuery: true);
        },
        selectedColor: AppTheme.primary,
        checkmarkColor: Colors.white,
        side: BorderSide(
            color: selected ? AppTheme.primary : AppTheme.borderLight),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
      ),
    );
  }

  // Precomputed tint colors — withOpacity() allocates a new Color on every
  // row build; during a fast fling that is thousands of avoidable allocations.
  static const Color _avatarBg = Color(0x265A1212);
  static const Map<String, Color> _chipBg = {
    'active': Color(0x26059669),
    'warning': Color(0x26D97706),
    'danger': Color(0x26DC2626),
  };

  Widget _memberCard(Map<String, dynamic> member, int index) {
    final status = member['status'] ?? 'active';
    final statusColor = status == 'active'
        ? AppTheme.success
        : status == 'warning'
            ? AppTheme.warning
            : AppTheme.danger;
    final name = '${member['student_name'] ?? ''}';
    final initial = name.trim().isEmpty ? '?' : name.trim()[0].toUpperCase();

    return FastListRow(
      index: index,
      height: kFastRowHeight,
      padding: const EdgeInsets.symmetric(horizontal: 8),
      // P1-A: detail opens offline too — it renders from the local
      // cache and refreshes when the network allows.
      onTap: () => Navigator.push(
        context,
        SmoothPageRoute(page: MemberDetailScreen(memberId: member['id'])),
      ),
      child: Row(
        children: [
          CircleAvatar(
            radius: 21,
            backgroundColor: _avatarBg,
            child: Text(initial,
                style: const TextStyle(
                    color: AppTheme.primary,
                    fontWeight: FontWeight.w700,
                    fontSize: 15)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('$name ${member['father_name'] ?? ''}',
                    style: const TextStyle(
                        fontWeight: FontWeight.w700, fontSize: 14),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis),
                const SizedBox(height: 3),
                Row(
                  children: [
                    if (member['member_code'] != null) ...[
                      Text(member['member_code'],
                          style: TextStyle(
                              fontSize: 11, color: AppTheme.textSecondary)),
                      const SizedBox(width: 8),
                    ],
                    if (!_photoOnly && member['gender'] != null)
                      Icon(
                          member['gender'] == 'male'
                              ? Icons.male
                              : Icons.female,
                          size: 14,
                          color: AppTheme.textSecondary),
                    const SizedBox(width: 4),
                    if (!_photoOnly && member['current_section'] != null)
                      Flexible(
                        child: Text(member['current_section'],
                            style: TextStyle(
                                fontSize: 11, color: AppTheme.textSecondary),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis),
                      ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 6),
          if (!_photoOnly)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              decoration: BoxDecoration(
                color: _chipBg[status] ?? const Color(0x26DC2626),
                borderRadius: BorderRadius.circular(6),
              ),
              child: Text(status,
                  style: TextStyle(
                      color: statusColor,
                      fontSize: 10,
                      fontWeight: FontWeight.w600)),
            ),
          const SizedBox(width: 2),
          const Icon(Icons.chevron_right,
              size: 16, color: AppTheme.textSecondary),
        ],
      ),
    );
  }
}
