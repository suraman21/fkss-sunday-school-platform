import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../services/app_update_service.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import '../../widgets/stat_card.dart';
import '../../widgets/app_error.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/feature_tile.dart';
import '../../widgets/use_website_note.dart';
import '../../utils/transitions.dart';
import '../members/member_list_screen.dart';
import '../attendance/attendance_screen.dart';
import '../teacher/teacher_grades.dart';
import '../../widgets/notification_bell_button.dart';

class AdminHomeScreen extends StatefulWidget {
  const AdminHomeScreen({super.key});
  @override
  State<AdminHomeScreen> createState() => AdminHomeScreenState();
}

class AdminHomeScreenState extends State<AdminHomeScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  bool _loading = true;
  bool _isOffline = false;
  String? _error;
  Map<String, dynamic> _stats = {};

  @override
  void initState() { super.initState(); _load(); }
  void refresh() => _load();

  Future<void> _load() async {
    setState(() { _error = null; });
    // 1. Immediately try to load cached data
    final cached = await _db.getCachedDashboardStats();
    if (cached != null) {
      if (mounted) setState(() { _stats = cached['stats'] ?? {}; _loading = false; _isOffline = !ConnectivityService().hasLink; });
    } else {
      if (mounted) setState(() { _loading = true; });
    }

    // 2. Fetch fresh data in the background
    final res = await _api.getDashboardStats();
    if (!mounted) return;
    
    if (res.success && res.data != null) {
      final stats = res.data['stats'] ?? {};
      setState(() { _stats = stats; _loading = false; _isOffline = false; });
      _db.cacheDashboardStats(res.data, _api.userRole);
    } else if (cached == null) {
      setState(() { _error = res.message; _loading = false; _isOffline = res.isNetworkError; });
    } else {
      setState(() { _isOffline = res.isNetworkError; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _load,
        child: CustomScrollView(
          slivers: [
            SliverAppBar(floating: true, automaticallyImplyLeading: false,
              title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Text('FKSS', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                Text('${UserRoles.displayNameAmharic(_api.userRole)} • ${UserRoles.displayName(_api.userRole)}',
                    style: TextStyle(fontSize: 11, color: Colors.white70)),
              ]),
              actions: [
                const NotificationBellButton(color: Colors.white),
                IconButton(icon: const Icon(Icons.refresh_rounded, size: 22), onPressed: _load),
              ],
            ),
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              sliver: SliverList(delegate: SliverChildListDelegate(
                _loading ? [const DashboardSkeleton()]
                : _error != null ? [
                    // per-screen generic offline removed — global AppStatusStrip covers it

                    AppErrorCard(error: AppError.fromMessage(_error, isNetwork: _isOffline), onRetry: _load, autoRetry: true),
                  ]
                : [
                    // per-screen generic offline removed — global AppStatusStrip covers it

                    ..._buildContent(),
                  ],
              )),
            ),
          ],
        ),
      ),
    );
  }

  Widget _offlineBanner() {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      decoration: BoxDecoration(
        color: AppTheme.warning.withOpacity(0.12),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(children: [
        Icon(Icons.cloud_off, size: 16, color: AppTheme.warning),
        const SizedBox(width: 8),
        Expanded(child: Text('Waiting for network — showing the last numbers saved on this phone.',
            style: TextStyle(fontSize: 11, color: AppTheme.warning, fontWeight: FontWeight.w500))),
      ]),
    );
  }

  List<Widget> _buildContent() {
    final members = _stats['members'] ?? {};
    return [
      // Welcome
      Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            colors: [AppTheme.primaryDark, AppTheme.primary, AppTheme.primaryLight],
            begin: Alignment.topLeft, end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('ዋና አስተዳዳሪ', style: TextStyle(fontSize: 13, color: Colors.grey.shade300, fontFamily: 'NotoSansEthiopic')),
          const SizedBox(height: 2),
          Text('Welcome, ${_api.userName.split(' ').first}!',
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: Colors.white)),
          const SizedBox(height: 4),
          Text('System Control Panel', style: TextStyle(fontSize: 13, color: Colors.white70)),
        ]),
      ),
      const SizedBox(height: 16),

      // Stats
      Row(children: [
        Expanded(child: StatCard(label: 'Members', value: '${members['total'] ?? 0}', icon: Icons.people_rounded, color: AppTheme.primary)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'Active', value: '${members['active'] ?? 0}', icon: Icons.check_circle, color: AppTheme.success)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'Users', value: '${_stats['users_count'] ?? 0}', icon: Icons.admin_panel_settings, color: AppTheme.info)),
      ]),
      const SizedBox(height: 10),
      Row(children: [
        Expanded(child: StatCard(label: 'Classes', value: '${_stats['classes_count'] ?? 0}', icon: Icons.class_rounded, color: AppTheme.warning)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'Male', value: '${members['male'] ?? 0}', icon: Icons.male, color: AppTheme.info)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'Female', value: '${members['female'] ?? 0}', icon: Icons.female, color: AppTheme.accent)),
      ]),
      const SizedBox(height: 20),

      const SectionHeader(title: 'On this phone'),
      GridView.count(
        crossAxisCount: 3, shrinkWrap: true, physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 10, crossAxisSpacing: 10, childAspectRatio: 0.95,
        children: [
          FeatureTile(label: 'Members', icon: Icons.people_rounded, color: AppTheme.primary,
              onTap: () => Navigator.of(context).push(SmoothPageRoute(page: const MemberListScreen()))),
          if (AppUpdateService().featureEnabled('attendance'))
            FeatureTile(label: 'Attendance', icon: Icons.fact_check_rounded, color: AppTheme.warning,
                onTap: () => Navigator.of(context).push(SmoothPageRoute(page: const AttendanceScreen()))),
          if (AppUpdateService().featureEnabled('grades'))
            FeatureTile(label: 'Grades', icon: Icons.grading_rounded, color: AppTheme.info,
                onTap: () => Navigator.of(context).push(SmoothPageRoute(page: const TeacherGradesScreen()))),
        ],
      ),
      const SizedBox(height: 16),
      const UseWebsiteNote(
        title: 'Users, backup, academic year',
        body: 'System settings stay on the website so they are not changed from a phone by mistake.',
      ),
    ];
  }
}






