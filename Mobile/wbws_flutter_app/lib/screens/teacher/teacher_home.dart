import 'dart:async';
import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/app_nav.dart';
import '../../services/app_update_service.dart';
import '../../services/catalog_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../utils/config.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/theme.dart';
import '../../widgets/stat_card.dart';
import '../../widgets/app_error.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/notification_bell_button.dart';
import '../../widgets/session_logout_dialog.dart';
import 'teacher_timetable_screen.dart';

class TeacherHomeScreen extends StatefulWidget {
  const TeacherHomeScreen({super.key});

  @override
  State<TeacherHomeScreen> createState() => TeacherHomeScreenState();
}

class TeacherHomeScreenState extends State<TeacherHomeScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  bool _loading = true;
  bool _isOffline = false;
  String? _error;

  // Dashboard data
  Map<String, dynamic> _stats = {};
  List<dynamic> _myClasses = [];
  // 1.6.5: the current semester of the active year, published by
  // /dashboard/stats so the hero box can tell teachers which semester
  // grade entry is running in. Null = not set on the server — hide chip.
  Map<String, dynamic>? _currentTerm;
  Map<String, dynamic> _todayAttendance = {};
  List<dynamic> _recentActivity = [];
  StreamSubscription<bool>? _netSub;

  @override
  void initState() {
    super.initState();
    _isOffline = !ConnectivityService().hasLink;
    _netSub = ConnectivityService().statusStream.listen((hasLink) {
      // Rebuild only on a real change — duplicate events must not rebuild
      // the whole dashboard while the user is mid-fling.
      if (!mounted || _isOffline == !hasLink) return;
      setState(() => _isOffline = !hasLink);
    });
    _loadDashboard();
  }

  @override
  void dispose() {
    _netSub?.cancel();
    super.dispose();
  }

  /// Called by AppShell when tab is switched to
  void refresh() {
    _loadDashboard();
  }

  Future<void> _loadDashboard() async {
    setState(() { _error = null; });

    // 1. Immediately load cache
    final cached = await _db.getCachedDashboardStats();
    final cachedClasses = await _db.getCachedClasses();
    
    if (cached != null || cachedClasses.isNotEmpty) {
      if (mounted) setState(() {
        if (cached != null) {
          _stats = cached['stats'] ?? {};
          _todayAttendance = _stats['today_attendance'] ?? {};
          _recentActivity = (cached['recent_activity'] as List?) ?? [];
        }
        if (cachedClasses.isNotEmpty) {
          _myClasses = cachedClasses;
        }
        _loading = false;
        _isOffline = !ConnectivityService().hasLink;
      });
    } else {
      if (mounted) setState(() => _loading = true);
    }

    // 2. Fetch fresh data silently
    late ApiResponse statsRes;
    late List<dynamic> classList;
    await Future.wait([
      _api.getDashboardStats().then((v) => statsRes = v),
      CatalogService().classes().then((v) => classList = v),
    ]);
    if (!mounted) return;

    if (statsRes.success && statsRes.data != null) {
      setState(() {
        _currentTerm = (statsRes.data['current_term'] as Map?)?.cast<String, dynamic>();
        _stats = statsRes.data['stats'] ?? {};
        _todayAttendance = _stats['today_attendance'] ?? {};
        _recentActivity = (statsRes.data['recent_activity'] as List?) ?? [];
        _loading = false;
        _isOffline = !ConnectivityService().hasLink;
      });
      _db.cacheDashboardStats(statsRes.data, _api.userRole);
    }

    if (classList.isNotEmpty) {
      setState(() { _myClasses = classList; });
    }

    if (!statsRes.success && classList.isEmpty && cached == null && cachedClasses.isEmpty) {
      setState(() { _error = statsRes.message ?? 'Failed to load dashboard'; _loading = false; });
    }

    await _overlayLocalToday();
  }

  /// Local-first: a sheet sitting in the outbox already counts as taken today.
  Future<void> _overlayLocalToday() async {
    final today = DateTime.now();
    final todayStr =
        '${today.year.toString().padLeft(4, '0')}-${today.month.toString().padLeft(2, '0')}-${today.day.toString().padLeft(2, '0')}';
    final pending = await _db.getPendingAttendance();
    final mine = pending.where((b) => '${b['date']}' == todayStr).toList();
    if (mine.isEmpty || !mounted) return;

    var present = 0, absent = 0, late = 0, recorded = 0;
    final takenIds = <int>{};
    for (final b in mine) {
      final cid = b['class_id'] is int ? b['class_id'] as int : int.tryParse('${b['class_id']}');
      if (cid != null) takenIds.add(cid);
      final rows = await _db.getPendingAttendanceRecords(cid ?? 0, todayStr);
      for (final r in rows) {
        recorded++;
        final st = '${r['status'] ?? ''}';
        if (st == 'present') present++;
        if (st == 'absent') absent++;
        if (st == 'late') late++;
      }
    }
    if (!mounted) return;
    setState(() {
      if (recorded > 0) {
        _todayAttendance = {
          'present': present,
          'absent': absent,
          'late': late,
          'recorded': recorded,
        };
      }
      _myClasses = _myClasses.map((c) {
        final id = c['id'] is int ? c['id'] : int.tryParse('${c['id']}');
        if (id != null && takenIds.contains(id)) {
          final copy = Map<String, dynamic>.from(c as Map);
          copy['attendance_taken_today'] = true;
          return copy;
        }
        return c;
      }).toList();
    });
  }

  String _greeting() {
    final hour = DateTime.now().hour;
    if (hour < 12) return 'Good morning';
    if (hour < 17) return 'Good afternoon';
    return 'Good evening';
  }

  String _greetingAmharic() {
    final hour = DateTime.now().hour;
    if (hour < 12) return 'እንደምን አደሩ';
    if (hour < 17) return 'እንደምን ዋሉ';
    return 'እንደምን አመሹ';
  }

  Future<void> _logout() => showSessionLogoutDialog(context);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _loadDashboard,
        child: CustomScrollView(
          slivers: [
            // App Bar
            SliverAppBar(
              floating: true,
              automaticallyImplyLeading: false,
              title: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('FKSS',
                      style:
                          TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                  Text(
                    '${UserRoles.displayNameAmharic(_api.userRole)} • ${UserRoles.displayName(_api.userRole)}',
                    style:
                        TextStyle(fontSize: 11, color: Colors.white70),
                  ),
                ],
              ),
              actions: [
                const NotificationBellButton(color: Colors.white),
                IconButton(
                  icon: const Icon(Icons.refresh_rounded, size: 22),
                  onPressed: _loadDashboard,
                  tooltip: 'Refresh',
                ),
                IconButton(
                  icon: const Icon(Icons.logout_rounded, size: 22),
                  onPressed: _logout,
                  tooltip: 'Logout',
                ),
              ],
            ),

            // Content — animated transitions between states
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              sliver: SliverList(
                delegate: SliverChildListDelegate(
                  _loading
                      ? [const DashboardSkeleton()]
                      : _error != null
                          ? [
                              AppErrorCard(
                                error: AppError.fromMessage(_error, isNetwork: _isOffline),
                                onRetry: _loadDashboard,
                                autoRetry: true,
                              ),
                            ]
                          : _buildContent(),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  List<Widget> _buildContent() {
    return [
      // Global AppStatusStrip is the single offline source — per-screen
      // duplicate removed (space saver). Verified live by audit.

      // Welcome banner
      _buildWelcomeBanner(),
      const SizedBox(height: 16),

      // Quick stats row
      _buildQuickStats(),
      const SizedBox(height: 16),

      // My Timetable (read-only; built by Education on the web)
      _buildTimetableCard(),
      const SizedBox(height: 16),

      if (AppUpdateService().featureEnabled('attendance')) ...[
        // My classes — attendance status per class
        _buildMyClasses(),
        const SizedBox(height: 16),

        // Today's attendance overview
        _buildAttendanceOverview(),
        const SizedBox(height: 16),
      ],

      // Recent activity
      if (_recentActivity.isNotEmpty) ...[
        _buildRecentActivity(),
        const SizedBox(height: 16),
      ],
    ];
  }

  Widget _buildWelcomeBanner() {
    final dateStr = getTodayEthiopian();

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AppTheme.primaryDark, AppTheme.primary, AppTheme.primaryLight],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            _greetingAmharic(),
            style: const TextStyle(
                fontSize: 13,
                color: Colors.white70,
                fontFamily: 'NotoSansEthiopic'),
          ),
          const SizedBox(height: 2),
          Text(
            '${_greeting()}, ${_api.userName.split(' ').first}!',
            style: const TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.w700,
                color: Colors.white),
          ),
          const SizedBox(height: 4),
          Text(
            dateStr,
            style: TextStyle(fontSize: 13, color: Colors.white70),
          ),
          const SizedBox(height: 12),
          // Quick info chips
          Wrap(
            spacing: 8,
            runSpacing: 6,
            children: [
              if (_currentTerm != null)
                _infoBadge(
                    Icons.calendar_month_rounded,
                    'አሁን ያለው ሴሚስተር · ${_currentTerm!['term_name'] ?? ''}',
                    AppTheme.warning),
              _infoBadge(
                  Icons.class_rounded, '${_myClasses.length} classes',
                  AppTheme.info),
              _infoBadge(
                  Icons.people_rounded,
                  '${_stats['members']?['total'] ?? 0} students',
                  AppTheme.accent),
            ],
          ),
        ],
      ),
    );
  }

  Widget _infoBadge(IconData icon, String text, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: color.withOpacity(0.15),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 14, color: color),
          const SizedBox(width: 5),
          Text(text,
              style: TextStyle(
                  fontSize: 12, color: color, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }

  Widget _buildQuickStats() {
    final members = _stats['members'] ?? {};
    final present = _todayAttendance['present'] ?? 0;
    final recorded = _todayAttendance['recorded'] ?? 0;
    final rate =
        recorded > 0 ? '${(present / recorded * 100).toStringAsFixed(0)}%' : '—';

    return Row(
      children: [
        Expanded(
          child: StatCard(
            label: 'My Classes',
            value: '${_myClasses.length}',
            icon: Icons.class_rounded,
            color: AppTheme.primary,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: StatCard(
            label: 'Students',
            value: '${members['total'] ?? 0}',
            icon: Icons.people_rounded,
            color: AppTheme.info,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: StatCard(
            label: 'Today',
            value: rate,
            icon: Icons.trending_up_rounded,
            color: AppTheme.success,
          ),
        ),
      ],
    );
  }

  Widget _buildTimetableCard() {
    return Card(
      margin: EdgeInsets.zero,
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => const TeacherTimetableScreen()),
        ),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: AppTheme.accent.withOpacity(0.18),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.calendar_month_rounded,
                    color: AppTheme.primary, size: 24),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('My Timetable',
                        style: TextStyle(
                            fontSize: 15, fontWeight: FontWeight.w700)),
                    const SizedBox(height: 2),
                    Text('Weekly · Daily · Monthly — your lessons and makeup classes',
                        style: TextStyle(
                            fontSize: 12, color: AppTheme.textSecondary)),
                  ],
                ),
              ),
              const Icon(Icons.chevron_right_rounded,
                  color: AppTheme.textSecondary),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildMyClasses() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Text('My Classes',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
            Text('${_myClasses.length} total',
                style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
          ],
        ),
        const SizedBox(height: 10),
        if (_myClasses.isEmpty)
          const EmptyState(
            icon: Icons.class_rounded,
            title: 'No classes assigned',
            subtitle: 'Ask your admin to assign you to classes.',
          )
        else
          ..._myClasses.map((c) => _classCard(c)),
      ],
    );
  }

  Widget _classCard(dynamic classData) {
    final name = classData['class_name'] ?? 'Unnamed';
    final section = classData['section_name'] ?? '';
    final studentCount = classData['student_count'] ?? 0;
    // The API may include whether attendance was taken today
    final attendanceTaken = classData['attendance_taken_today'] == true;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () {
          final id = classData['id'] is int
              ? classData['id'] as int
              : int.tryParse('${classData['id']}');
          AppNav().openAttendance(classId: id);
        },
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              // Class icon
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: AppTheme.primary.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.class_rounded,
                    color: AppTheme.primary, size: 22),
              ),
              const SizedBox(width: 12),

              // Class info
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(name,
                        style: const TextStyle(
                            fontSize: 14, fontWeight: FontWeight.w600)),
                    const SizedBox(height: 2),
                    Row(
                      children: [
                        if (section.isNotEmpty) ...[
                          Text(section,
                              style: TextStyle(
                                  fontSize: 11,
                                  color: AppTheme.textSecondary)),
                          const SizedBox(width: 8),
                        ],
                        Icon(Icons.people_outline,
                            size: 12, color: AppTheme.textSecondary),
                        const SizedBox(width: 3),
                        Text('$studentCount students',
                            style: TextStyle(
                                fontSize: 11,
                                color: AppTheme.textSecondary)),
                      ],
                    ),
                  ],
                ),
              ),

              // Attendance status badge
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: attendanceTaken
                      ? AppTheme.success.withOpacity(0.12)
                      : AppTheme.warning.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      attendanceTaken
                          ? Icons.check_circle
                          : Icons.radio_button_unchecked,
                      size: 14,
                      color: attendanceTaken
                          ? AppTheme.success
                          : AppTheme.warning,
                    ),
                    const SizedBox(width: 4),
                    Text(
                      attendanceTaken ? 'Taken today' : 'Not taken today',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: attendanceTaken
                            ? AppTheme.success
                            : AppTheme.warning,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildAttendanceOverview() {
    final present = _todayAttendance['present'] ?? 0;
    final absent = _todayAttendance['absent'] ?? 0;
    final late_ = _todayAttendance['late'] ?? 0;
    final recorded = _todayAttendance['recorded'] ?? 0;
    final rate = recorded > 0
        ? '${(present / recorded * 100).toStringAsFixed(0)}%'
        : 'Not taken';

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text("Today's Attendance",
                    style:
                        TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: AppTheme.success.withOpacity(0.15),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(rate,
                      style: TextStyle(
                          color: recorded > 0 ? AppTheme.success : AppTheme.textSecondary,
                          fontWeight: FontWeight.w600,
                          fontSize: 13)),
                ),
              ],
            ),
            const SizedBox(height: 14),
            Row(
              children: [
                _attStat('Present', '$present', AppTheme.success),
                _attStat('Absent', '$absent', AppTheme.danger),
                _attStat('Late', '$late_', AppTheme.warning),
                _attStat('Total', '$recorded', AppTheme.info),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _attStat(String label, String value, Color color) {
    return Expanded(
      child: Column(
        children: [
          Text(value,
              style: TextStyle(
                  fontSize: 18, fontWeight: FontWeight.w700, color: color)),
          const SizedBox(height: 2),
          Text(label,
              style: TextStyle(fontSize: 10, color: AppTheme.textSecondary)),
        ],
      ),
    );
  }

  Widget _buildRecentActivity() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('Recent Activity',
            style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
        const SizedBox(height: 10),
        Card(
          child: Column(
            children: _recentActivity.take(5).map((activity) {
              final action = activity['action'] ?? '';
              final detail = activity['detail'] ?? '';
              final time = activity['created_at'] ?? '';

              return ListTile(
                dense: true,
                leading: Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(
                    color: AppTheme.primary.withOpacity(0.1),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Icon(Icons.history,
                      size: 18, color: AppTheme.primary),
                ),
                title:
                    Text(action, style: const TextStyle(fontSize: 13)),
                subtitle: Text(
                  detail.isNotEmpty ? detail : _formatTime(time),
                  style:
                      TextStyle(fontSize: 11, color: AppTheme.textSecondary),
                ),
                trailing: Text(
                  _formatTime(time),
                  style:
                      TextStyle(fontSize: 10, color: AppTheme.textSecondary),
                ),
              );
            }).toList(),
          ),
        ),
      ],
    );
  }

  String _formatTime(String dateStr) {
    if (dateStr.isEmpty) return '';
    try {
      final dt = DateTime.parse(dateStr);
      final now = DateTime.now();
      final diff = now.difference(dt);

      if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
      if (diff.inHours < 24) return '${diff.inHours}h ago';
      if (diff.inDays < 7) return '${diff.inDays}d ago';
      return EthiopianDate.fromGregorian(dt).toShortString();
    } catch (_) {
      return dateStr;
    }
  }
}





