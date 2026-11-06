import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../utils/theme.dart';
import '../../widgets/stat_card.dart';
import '../../widgets/app_error.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/feature_tile.dart';
import '../../widgets/use_website_note.dart';
import '../../utils/transitions.dart';
import '../members/member_list_screen.dart';
import '../../widgets/notification_bell_button.dart';
import '../../services/app_update_service.dart';
import '../attendance_warnings/attendance_warnings_screen.dart';

class InfoHomeScreen extends StatefulWidget {
  const InfoHomeScreen({super.key});
  @override
  State<InfoHomeScreen> createState() => InfoHomeScreenState();
}

class InfoHomeScreenState extends State<InfoHomeScreen> {
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
    setState(() { _loading = true; _error = null; _isOffline = false; });
    final res = await _api.getDashboardStats();
    if (!mounted) return;
    if (res.success && res.data != null) {
      setState(() { _stats = res.data['stats'] ?? {}; _loading = false; });
      _db.cacheDashboardStats(res.data, _api.userRole);
    } else {
      final cached = await _db.getCachedDashboardStats();
      if (cached != null) {
        setState(() { _stats = cached['stats'] ?? {}; _loading = false; _isOffline = !ConnectivityService().hasLink; });
      } else {
        setState(() { _error = res.message; _loading = false; _isOffline = res.isNetworkError; });
      }
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
                Text('የመረጃ ክፍል • Info Department', style: TextStyle(fontSize: 11, color: Colors.white70)),
              ]),
              actions: [const NotificationBellButton(color: Colors.white), IconButton(icon: const Icon(Icons.refresh_rounded, size: 22), onPressed: _load)],
            ),
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              sliver: SliverList(delegate: SliverChildListDelegate(
                _loading ? [const DashboardSkeleton()]
                : _error != null ? [// per-screen generic offline removed — global AppStatusStrip covers it
 AppErrorCard(error: AppError.fromMessage(_error, isNetwork: _isOffline), onRetry: _load, autoRetry: true)]
                : [// per-screen generic offline removed — global AppStatusStrip covers it
 ..._buildContent()],
              )),
            ),
          ],
        ),
      ),
    );
  }

  Widget _offlineBanner() {
    return Container(
      margin: const EdgeInsets.only(bottom: 12), padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      decoration: BoxDecoration(color: AppTheme.warning.withOpacity(0.12), borderRadius: BorderRadius.circular(8)),
      child: Row(children: [
        Icon(Icons.cloud_off, size: 16, color: AppTheme.warning), const SizedBox(width: 8),
        Expanded(child: Text('Waiting for network — showing the last numbers saved on this phone.',
            style: TextStyle(fontSize: 11, color: AppTheme.warning, fontWeight: FontWeight.w500))),
      ]),
    );
  }

  List<Widget> _buildContent() {
    final members = _stats['members'] ?? {};
    return [
      Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          gradient: const LinearGradient(colors: [AppTheme.primaryDark, AppTheme.primary, AppTheme.primaryLight], begin: Alignment.topLeft, end: Alignment.bottomRight),
          borderRadius: BorderRadius.circular(16)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('የመረጃ ክፍል', style: TextStyle(fontSize: 13, color: Colors.grey.shade300, fontFamily: 'NotoSansEthiopic')),
          const SizedBox(height: 2),
          Text('Information Department', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: Colors.white)),
          const SizedBox(height: 4),
          Text('Member registration, ID cards & records', style: TextStyle(fontSize: 12, color: Colors.grey.shade300)),
        ]),
      ),
      const SizedBox(height: 16),
      Row(children: [
        Expanded(child: StatCard(label: 'Total', value: '${members['total'] ?? 0}', icon: Icons.people_rounded, color: AppTheme.primary)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'Active', value: '${members['active'] ?? 0}', icon: Icons.check_circle, color: AppTheme.success)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'New (7d)', value: '${_stats['recent_registrations'] ?? 0}', icon: Icons.person_add, color: AppTheme.info)),
      ]),
      const SizedBox(height: 20),
      const SectionHeader(title: 'On this phone'),
      GridView.count(
        crossAxisCount: 3, shrinkWrap: true, physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 10, crossAxisSpacing: 10, childAspectRatio: 0.95,
        children: [
          FeatureTile(label: 'Members', icon: Icons.people_rounded, color: AppTheme.primary,
              onTap: () => Navigator.of(context).push(SmoothPageRoute(page: const MemberListScreen()))),
          // Attendance alerting Phase 2 (Education + Mezmur, close with outcome).
          if (AppUpdateService().featureEnabled('attendance_v2', fallback: false))
            FeatureTile(label: 'Warnings', icon: Icons.warning_amber_rounded, color: AppTheme.warning,
                onTap: () => Navigator.of(context).push(SmoothPageRoute(page: const AttendanceWarningsScreen()))),
        ],
      ),
      const SizedBox(height: 16),
      Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: AppTheme.info.withOpacity(0.10),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: AppTheme.info.withOpacity(0.25)),
        ),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(color: AppTheme.info.withOpacity(0.15), borderRadius: BorderRadius.circular(10)),
            child: const Icon(Icons.query_stats_rounded, size: 20, color: AppTheme.info),
          ),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            const Text('Analytics is read-only here', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
            const SizedBox(height: 4),
            const Text(
              'The Information department views and compares attendance from Education, '
              'Mezmur and HR — it never records attendance. Full charts, comparisons and '
              'exports live in the Analytics Hub on the website.',
              style: TextStyle(fontSize: 11.5, height: 1.4),
            ),
          ])),
        ]),
      ),
      const SizedBox(height: 16),
      const UseWebsiteNote(
        title: 'Register, ID cards & analytics',
        body: 'Those stay on the website so a child’s full record and the cross-department analytics are only opened there.',
      ),
    ];
  }
}




