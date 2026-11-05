import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/app_lock_service.dart';
import '../../services/app_nav.dart';
import '../../services/session_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/app_update_service.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import '../../widgets/offline_banner.dart';
import '../../widgets/sync_attention.dart';
import '../update/update_banner.dart';
// Role home screens
import '../teacher/teacher_home.dart';
import '../teacher/teacher_grades.dart';
import '../teacher/teacher_timetable_screen.dart';
import '../att_taker/att_taker_home.dart';
import '../hr/hr_taker_home.dart';
import '../hr/hr_home.dart';
import '../reviews/review_inbox_screen.dart';
import '../admin/admin_home.dart';
import '../edu_dept/edu_home.dart';
import '../info_dept/info_home.dart';
import '../finance/finance_home.dart';
import '../material/material_home.dart';
// Shared screens
import '../attendance/attendance_screen.dart';
import '../members/member_list_screen.dart';
import '../mezmur/mezmur_home.dart';
import '../mezmur/mezmur_attendance.dart';
import '../mezmur/mezmur_hymns.dart';
import '../mezmur/mezmur_analytics.dart';
import '../profile/profile_screen.dart';

/// AppShell — Role-based bottom navigation with auto-refresh,
/// global offline banner, and auth expiry handling.
class AppShell extends StatefulWidget {
  const AppShell({super.key});
  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> with WidgetsBindingObserver {
  final _api = ApiService();
  final _connectivity = ConnectivityService();
  int _currentIndex = 0;
  late List<NavTab> _tabs;

  // Keys for refreshable screens
  final _teacherHomeKey = GlobalKey<TeacherHomeScreenState>();
  final _attTakerHomeKey = GlobalKey<AttTakerHomeScreenState>();
  final _adminHomeKey = GlobalKey<AdminHomeScreenState>();
  final _eduHomeKey = GlobalKey<EduHomeScreenState>();
  final _infoHomeKey = GlobalKey<InfoHomeScreenState>();
  final _financeHomeKey = GlobalKey<FinanceHomeScreenState>();
  final _materialHomeKey = GlobalKey<MaterialHomeScreenState>();
  final _attendanceKey = GlobalKey<AttendanceScreenState>();
  final _mezmurHomeKey = GlobalKey<MezmurHomeScreenState>();
  final _mezmurAttKey = GlobalKey<MezmurAttendanceScreenState>();
  final _gradesKey = GlobalKey<TeacherGradesScreenState>();
  final Map<String, Widget> _openedTabs = {};

  @override
  void initState() {
    super.initState();
    _tabs = _configuredTabs();
    WidgetsBinding.instance.addObserver(this);
    AppNav().tabStream.listen((id) {
      if (!mounted) return;
      final i = _tabs.indexWhere((t) => t.id == id);
      if (i >= 0) _onTabChanged(i);
    });

    // SessionCoordinator owns auth-loss transitions and starts account-scoped
    // workers only after owner/scope reconciliation.

    // Radio came back — refresh the open tab after the link settles.
    // Do not pile cacheForOffline + ping + sync on the same 4G radio.
    _connectivity.statusStream.listen((online) {
      if (online && mounted) {
        Future.delayed(const Duration(seconds: 1), () {
          if (mounted) _refreshCurrentTab();
        });
      }
    });

    if (_tabs.isEmpty) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        SessionCoordinator().enterReauthentication(reason: 'no_authorized_tabs');
      });
    }
    AppUpdateService().revision.addListener(_applyReleaseConfig);
    AppUpdateService().check().then((_) => _applyReleaseConfig());
  }

  void _applyReleaseConfig() {
    SessionCoordinator().reconcileReleaseGates();
    _applyFeatureTabs();
  }

  List<NavTab> _configuredTabs() {
    final config = AppUpdateService();
    return getTabsForRole(
      _api.userRole,
      attendanceEnabled: config.featureEnabled('attendance'),
      gradesEnabled: config.featureEnabled('grades'),
      mezmurEnabled: config.featureEnabled('mezmur'),
    );
  }

  void _applyFeatureTabs() {
    if (!mounted) return;
    final next = _configuredTabs();
    // No-op guard: check() completes asynchronously after launch/resume and
    // used to setState the whole shell — rebuilding every kept-alive tab in
    // the IndexedStack — even when the tab set was identical. That surprise
    // rebuild visibly hitched whatever was on screen at the moment.
    final sameTabs = next.length == _tabs.length &&
        _sameTabIds(next, _tabs);
    if (sameTabs && _currentIndex < _tabs.length) return;
    final currentId = _tabs.isNotEmpty && _currentIndex < _tabs.length
        ? _tabs[_currentIndex].id
        : 'home';
    final nextIndex = next.indexWhere((tab) => tab.id == currentId);
    final allowed = next.map((tab) => tab.id).toSet();
    setState(() {
      _tabs = next;
      _currentIndex = nextIndex >= 0 ? nextIndex : 0;
      _openedTabs.remove('home');
      _openedTabs.removeWhere((id, _) => !allowed.contains(id));
    });
  }

  static bool _sameTabIds(List<NavTab> a, List<NavTab> b) {
    for (var i = 0; i < a.length; i++) {
      if (a[i].id != b[i].id) return false;
    }
    return true;
  }

  @override
  void dispose() {
    AppUpdateService().revision.removeListener(_applyReleaseConfig);
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      // App lock (Telegram model): if we were away longer than the
      // auto-lock interval, the gate appears before any content.
      AppLockService().evaluateOnResume();
      _connectivity.startMonitoring();
      if (SessionCoordinator().isActive) {
        // The coordinator restarts/drains workers. The shell only refreshes
        // currently visible UI after the active root survives resume.
        _refreshCurrentTab();
        AppUpdateService().check().then((_) => _applyReleaseConfig());
      }
    } else if (state == AppLifecycleState.paused) {
      // Keep the outbox. Android freezes timers in the background anyway;
      // killing it here meant a failed Save sat until the teacher tapped Sync.
      _connectivity.stopMonitoring();
      AppLockService().recordBackgrounded();
    }
  }

  void _selectTabById(String id) {
    final i = _tabs.indexWhere((t) => t.id == id);
    if (i >= 0) _onTabChanged(i);
  }

  void _onTabChanged(int index) {
    if (index == _currentIndex) {
      _refreshCurrentTab();
      return;
    }
    setState(() => _currentIndex = index);
    _refreshTab(index);
  }

  void _refreshCurrentTab() => _refreshTab(_currentIndex);

  void _refreshTab(int index) {
    if (index >= _tabs.length) return;
    final tabId = _tabs[index].id;
    switch (tabId) {
      case 'home':
        _refreshHome();
        break;
      case 'attendance':
        if (AppNav().shouldReload('attendance') || AppNav().attendanceClassId != null) {
          _attendanceKey.currentState?.refresh();
        }
        break;
      case 'grades':
        if (AppNav().shouldReload('grades')) {
          _gradesKey.currentState?.refresh();
        }
        break;
    }
  }

  void _refreshHome() {
    switch (_api.userRole) {
      case UserRoles.teacher:
        _teacherHomeKey.currentState?.refresh();
        break;
      case UserRoles.attendanceTaker:
        _attTakerHomeKey.currentState?.refresh();
        break;
      case UserRoles.superAdmin:
      case UserRoles.schoolAdmin:
        _adminHomeKey.currentState?.refresh();
        break;
      case UserRoles.eduDept:
        _eduHomeKey.currentState?.refresh();
        break;
      case UserRoles.infoDept:
        _infoHomeKey.currentState?.refresh();
        break;
      case UserRoles.financeDept:
        _financeHomeKey.currentState?.refresh();
        break;
      case UserRoles.materialDept:
        _materialHomeKey.currentState?.refresh();
        break;
      case UserRoles.mezmurDept:
      case UserRoles.mezmurTaker:
        _mezmurHomeKey.currentState?.refresh();
        break;
      case UserRoles.hrTaker:
        break; // HR attendance was retired (2026-10-07); the taker home is a static notice.
    }
  }

  Widget _tabChild(int index) {
    final id = _tabs[index].id;
    if (_openedTabs.containsKey(id) || index == _currentIndex) {
      return _openedTabs.putIfAbsent(id, () => _buildScreen(id));
    }
    return const SizedBox.shrink();
  }

  Widget _buildScreen(String tabId) {
    switch (tabId) {
      case 'home':
        return _buildHomeForRole();
      case 'attendance':
        return AttendanceScreen(key: _attendanceKey);
      case 'mezmur_attendance':
        return MezmurAttendanceScreen(key: _mezmurAttKey);
      case 'mezmur_hymns':
        // P31: while the hymn library is showing, the library owns the
        // bottom of the screen — the main bar is hidden and a back
        // icon in the library's app bar returns here.
        return MezmurHymnsScreen(onBack: () => _selectTabById('home'));
      case 'mezmur_analytics':
        return const MezmurAnalyticsScreen();
      case 'grades':
        return TeacherGradesScreen(key: _gradesKey);
      case 'timetable':
        // T-3 teacher timetable as a bottom-nav tab (2026-10-20); the Home
        // card still opens the same screen pushed.
        return const TeacherTimetableScreen();
      case 'members':
        return const MemberListScreen();
      case 'reviews':
        // HR is gone from review routing: HR attendance was retired
        // (2026-10-07) and its mobile inbox with it — reports live on
        // the web dashboard now.
        switch (_api.userRole) {
          case UserRoles.eduDept:
            return const ReviewInboxScreen(dept: 'edu');
          case UserRoles.mezmurDept:
            return const ReviewInboxScreen(dept: 'mezmur');
          default:
            return const ReviewHubScreen();
        }
      case 'profile':
        return const ProfileScreen();
      default:
        return _buildPlaceholder(tabId);
    }
  }

  Widget _buildHomeForRole() {
    final roleFeature = <String, String>{
      UserRoles.attendanceTaker: 'attendance',
      UserRoles.financeDept: 'finance',
      UserRoles.materialDept: 'material',
      UserRoles.mezmurDept: 'mezmur',
      UserRoles.mezmurTaker: 'mezmur',
    }[_api.userRole];
    if (roleFeature != null
        && !AppUpdateService().featureEnabled(roleFeature)) {
      return _buildDisabledFeature(roleFeature);
    }
    switch (_api.userRole) {
      case UserRoles.teacher:
        return TeacherHomeScreen(key: _teacherHomeKey);
      case UserRoles.attendanceTaker:
        return AttTakerHomeScreen(key: _attTakerHomeKey);
      case UserRoles.superAdmin:
      case UserRoles.schoolAdmin:
        return AdminHomeScreen(key: _adminHomeKey);
      case UserRoles.eduDept:
        return EduHomeScreen(key: _eduHomeKey);
      case UserRoles.infoDept:
        return InfoHomeScreen(key: _infoHomeKey);
      case UserRoles.financeDept:
        return FinanceHomeScreen(key: _financeHomeKey);
      case UserRoles.materialDept:
        return MaterialHomeScreen(key: _materialHomeKey);
      case UserRoles.mezmurDept:
        return MezmurHomeScreen(key: _mezmurHomeKey);
      case UserRoles.mezmurTaker:
        // Department-owned mezmur takers use the mezmur home but are
        // not staff — library curation / analytics / taker management
        // stay hidden (see MezmurHomeScreen._isStaff).
        return MezmurHomeScreen(key: _mezmurHomeKey);
      case UserRoles.hrTaker:
        return const HrTakerHomeScreen();
      case UserRoles.hrDept:
        return const HrDeptHomeScreen();
      default:
        return TeacherHomeScreen(key: _teacherHomeKey);
    }
  }

  Widget _buildDisabledFeature(String feature) {
    final label = feature[0].toUpperCase() + feature.substring(1);
    return Scaffold(
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.lock_outline_rounded,
                size: 52, color: AppTheme.textSecondary),
            const SizedBox(height: 14),
            Text('$label is not enabled',
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            Text('This module is unavailable for this school deployment.',
                textAlign: TextAlign.center,
                style: TextStyle(color: AppTheme.textSecondary)),
          ]),
        ),
      ),
    );
  }

  Widget _buildPlaceholder(String tabId) {
    return Scaffold(
      body: Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.construction_rounded,
              size: 48, color: AppTheme.textSecondary),
          const SizedBox(height: 12),
          Text(
              '${tabId[0].toUpperCase()}${tabId.substring(1)} — Coming Soon',
              style: TextStyle(fontSize: 16, color: AppTheme.textSecondary)),
        ]),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_tabs.isEmpty) {
      return const Scaffold(
          body: Center(child: CircularProgressIndicator()));
    }
    if (_currentIndex >= _tabs.length) _currentIndex = 0;

    // P31: the hymn library carries its own bottom navigation, so the
    // main bar steps aside while that tab is active (no stacked bars).
    final hideMainNav = _tabs.isNotEmpty &&
        _currentIndex < _tabs.length &&
        _tabs[_currentIndex].id == 'mezmur_hymns';

    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      // P34: the now-playing bar now lives above the Navigator
      // (MezmurMiniPlayerHost in main.dart), so the shell no longer
      // needs a Stack to overlay it.
      body: Column(
        children: [
          // Global offline banner — appears at top when offline.
          const OfflineBanner(),
          // Owner-safe entry for attention/paused/conflict recovery.
          const SyncRecoveryBanner(),
          // P65: optional-update band (forced updates take over at the
          // root instead). Hidden until the server check reports a newer
          // non-mandatory version; rebuilds itself via the service's
          // revision notifier, so no whole-shell rebuild is involved.
          const UpdateBanner(),
          // Main content
          Expanded(
            child: IndexedStack(
              index: _currentIndex,
              children: List.generate(_tabs.length, _tabChild),
            ),
          ),
        ],
      ),
      bottomNavigationBar: hideMainNav ? null : SafeArea(
        child: Container(
          margin: const EdgeInsets.fromLTRB(20, 0, 20, 20),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(30),
            boxShadow: [
              BoxShadow(
                color: AppTheme.primaryDark.withOpacity(0.08),
                blurRadius: 20,
                spreadRadius: 2,
                offset: const Offset(0, 8),
              )
            ],
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: List.generate(_tabs.length, (index) {
                final tab = _tabs[index];
                final isSelected = _currentIndex == index;
                return GestureDetector(
                  onTap: () => _onTabChanged(index),
                  behavior: HitTestBehavior.opaque,
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 250),
                    curve: Curves.easeOutCubic,
                    padding: EdgeInsets.symmetric(
                        horizontal: isSelected ? 16 : 10, vertical: 10),
                    decoration: BoxDecoration(
                      color: isSelected ? AppTheme.primary.withOpacity(0.12) : Colors.transparent,
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          isSelected ? tab.activeIcon : tab.icon,
                          color: isSelected ? AppTheme.primary : AppTheme.textSecondary.withOpacity(0.6),
                          size: 24,
                        ),
                        if (isSelected) ...[
                          const SizedBox(width: 6),
                          Text(
                            tab.label,
                            style: const TextStyle(
                                color: AppTheme.primary,
                                fontWeight: FontWeight.w700,
                                fontSize: 13),
                          ),
                        ]
                      ],
                    ),
                  ),
                );
              }),
            ),
          ),
        ),
      ),
    );
  }
}

