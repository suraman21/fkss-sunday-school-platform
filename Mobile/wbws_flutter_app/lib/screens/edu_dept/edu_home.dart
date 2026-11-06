import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/app_update_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../utils/theme.dart';
import '../../utils/transitions.dart';
import '../../widgets/stat_card.dart';
import '../../widgets/app_error.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/feature_tile.dart';
import '../../widgets/use_website_note.dart';
import '../attendance/attendance_screen.dart';
import '../teacher/teacher_grades.dart';
import 'edu_classes_screen.dart';
import 'edu_teachers_screen.dart';
import 'edu_enrollment_screen.dart';
import '../reviews/review_inbox_screen.dart';
import 'edu_subjects_screen.dart';
import '../../widgets/notification_bell_button.dart';
import '../attendance_warnings/attendance_warnings_screen.dart';

class EduHomeScreen extends StatefulWidget {
  const EduHomeScreen({super.key});
  @override
  State<EduHomeScreen> createState() => EduHomeScreenState();
}

class EduHomeScreenState extends State<EduHomeScreen> {
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
    final cached = await _db.getCachedDashboardStats();
    if (cached != null) {
      if (mounted) setState(() { _stats = cached['stats'] ?? {}; _loading = false; _isOffline = !ConnectivityService().hasLink; });
    } else {
      if (mounted) setState(() { _loading = true; });
    }

    final res = await _api.getDashboardStats();
    if (!mounted) return;

    if (res.success && res.data != null) {
      setState(() { _stats = res.data['stats'] ?? {}; _loading = false; _isOffline = false; });
      _db.cacheDashboardStats(res.data, _api.userRole);
    } else if (cached == null) {
      setState(() { _error = res.message; _loading = false; _isOffline = res.isNetworkError; });
    } else {
      setState(() { _isOffline = res.isNetworkError; });
    }
  }

  void _open(Widget page) {
    Navigator.of(context).push(SmoothPageRoute(page: page));
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
                Text('የትምህርት ክፍል • Education Dept', style: TextStyle(fontSize: 11, color: Colors.white70)),
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

  List<Widget> _eduTiles() {
    const fallback = ['classes', 'teachers', 'subjects', 'enrollment', 'grades', 'attendance', 'warnings'];
    final ids = List<String>.from(AppUpdateService().tilesFor('education', fallback));
    // Attendance alerting Phase 2: the warnings tile follows the server
    // feature flag even when an older release config omits the id.
    final warningsOn = AppUpdateService().featureEnabled('attendance_v2', fallback: false);
    if (warningsOn && !ids.contains('warnings')) ids.add('warnings');
    final all = <String, FeatureTile>{
      'classes': FeatureTile(label: 'Classes', icon: Icons.class_rounded, color: AppTheme.primary, onTap: () => _open(const EduClassesScreen())),
      'teachers': FeatureTile(label: 'Teachers', icon: Icons.person_rounded, color: const Color(0xFF7C3AED), onTap: () => _open(const EduTeachersScreen())),
      'subjects': FeatureTile(label: 'Subjects', icon: Icons.book_rounded, color: AppTheme.info, onTap: () => _open(const EduSubjectsScreen())),
      'enrollment': FeatureTile(label: 'Enrollment', icon: Icons.person_add_rounded, color: AppTheme.success, onTap: () => _open(const EduEnrollmentScreen())),
      'grades': FeatureTile(label: 'Grades', icon: Icons.grading_rounded, color: AppTheme.warning, onTap: () => _open(const TeacherGradesScreen())),
      'attendance': FeatureTile(label: 'Attendance', icon: Icons.fact_check_rounded, color: AppTheme.accent, onTap: () => _open(const AttendanceScreen())),
      'reviews': FeatureTile(label: 'Reviews', icon: Icons.inbox_rounded, color: const Color(0xFF0F766E), onTap: () => _open(const ReviewInboxScreen(dept: 'edu'))),
      'warnings': FeatureTile(label: 'Warnings', icon: Icons.warning_amber_rounded, color: AppTheme.warning, onTap: () => _open(const AttendanceWarningsScreen(department: 'edu'))),
    };
    return [
      for (final id in ids)
        if (all[id] != null
            && (id != 'attendance' || AppUpdateService().featureEnabled('attendance'))
            && (id != 'grades' || AppUpdateService().featureEnabled('grades'))
            && (id != 'warnings' || warningsOn))
          all[id]!,
    ];
  }

  List<Widget> _buildContent() {
    final members = _stats['members'] ?? {};
    final att = _stats['today_attendance'] ?? {};
    return [
      Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          gradient: const LinearGradient(colors: [AppTheme.primaryDark, AppTheme.primary, AppTheme.primaryLight], begin: Alignment.topLeft, end: Alignment.bottomRight),
          borderRadius: BorderRadius.circular(16)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('የትምህርት ክፍል', style: TextStyle(fontSize: 13, color: Colors.grey.shade300, fontFamily: 'NotoSansEthiopic')),
          const SizedBox(height: 2),
          const Text('Education Department', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: Colors.white)),
          const SizedBox(height: 4),
          Text('Classes, teachers, grades & enrollment', style: TextStyle(fontSize: 12, color: Colors.grey.shade300)),
        ]),
      ),
      const SizedBox(height: 16),
      Row(children: [
        Expanded(child: StatCard(label: 'Students', value: '${members['total'] ?? 0}', icon: Icons.school_rounded, color: AppTheme.primary)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'Classes', value: '${_stats['classes_count'] ?? 0}', icon: Icons.class_rounded, color: AppTheme.info)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'Today', value: '${att['present'] ?? 0}', icon: Icons.check_circle, color: AppTheme.success)),
      ]),
      const SizedBox(height: 20),
      const SectionHeader(title: 'On this phone'),
      GridView.count(
        crossAxisCount: 3, shrinkWrap: true, physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 10, crossAxisSpacing: 10, childAspectRatio: 0.95,
        children: _eduTiles(),
      ),
      const SizedBox(height: 16),
      const UseWebsiteNote(
        title: 'Report cards, bulk enroll, new teacher login',
        body: 'Those stay on the website Education screen — same purple sidebar you already use.',
      ),
    ];
  }
}
