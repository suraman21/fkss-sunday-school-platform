import 'package:flutter/material.dart';

/// FKSS App — Configuration Constants
class AppConfig {
  static const String apiBaseUrl = 'https://felegekidusan.com/api/v1';

  /// Site root (apiBaseUrl without the /api/v1 suffix) — the origin that
  /// serves RELATIVE upload URLs (category/singer covers, hymn art
  /// renditions). One definition so every screen resolves them the same
  /// way (previously the replaceFirst pattern was copy-pasted per file).
  static String get siteOrigin =>
      apiBaseUrl.replaceFirst(RegExp(r'/api/v1$'), '');

  static const String appName = 'FKSS';
  static const String appNameAmharic = 'ፈለገ ቅዱሳን ሰንበት ት/ቤት';
  // ⚠ SINGLE SOURCE OF TRUTH for the update system: the app compares
  // THESE values against the server's .fkss_app_release.php — pubspec.yaml's
  // version only feeds the Android package version. They MUST stay in sync
  // (test/version_sync_test.dart fails the release build if they drift —
  // drifting is what hid updates from phones before P65).
  // Mirrored from pubspec.yaml and pinned by the release-gate test because
  // API headers and update decisions need these values before package lookup.
  static const String appVersion = '1.7.5';
  static const int appBuild = 42;
  static const String tokenKey = 'fkss_token';
  static const String refreshTokenKey = 'fkss_refresh_token';
  static const String userDataKey = 'fkss_user';
  static const int defaultPageSize = 20;
  static const int maxPageSize = 100;
  /// Slow Ethiopian 4G (≈18 KB/s) needs TLS + PHP room. 5s made the app
  /// lie about "no internet". Telegram keeps sockets open; Apple uses 60s.
  /// Screenshot showed ~8 KB/s. TLS + PHP on that radio needs a full minute.
  static const int connectionTimeout = 45;
  static const int receiveTimeout = 45;
  static const int postTimeout = 60;
}

/// User roles
class UserRoles {
  static const String superAdmin = 'super_admin';
  static const String schoolAdmin = 'school_admin';
  static const String infoDept = 'info_dept';
  static const String eduDept = 'edu_dept';
  static const String financeDept = 'finance_dept';
  static const String materialDept = 'material_dept';
  static const String mezmurDept = 'mezmur_dept';
  static const String teacher = 'teacher';
  static const String attendanceTaker = 'attendance_taker';
  // Department-owned takers (2026-08-28): each department creates and
  // manages ONLY its own taker accounts; datasets never combine.
  static const String mezmurTaker = 'mezmur_attendance_taker';
  static const String hrTaker = 'hr_attendance_taker';
  static const String hrDept = 'hr_dept';

  static String displayName(String role) {
    switch (role) {
      case superAdmin: return 'Super Admin';
      case schoolAdmin: return 'School Admin';
      case infoDept: return 'Info Department';
      case eduDept: return 'Education Department';
      case financeDept: return 'Finance Department';
      case materialDept: return 'Material Department';
      case mezmurDept: return 'Mezmur Department';
      case teacher: return 'Teacher';
      case attendanceTaker: return 'Attendance Taker';
      case mezmurTaker: return 'Mezmur Attendance Taker';
      case hrTaker: return 'HR Attendance Taker';
      case hrDept: return 'HR Department';
      default: return role;
    }
  }

  static String displayNameAmharic(String role) {
    switch (role) {
      case superAdmin: return 'ዋና አስተዳዳሪ';
      case schoolAdmin: return 'የትምህርት ቤት አስተዳዳሪ';
      case infoDept: return 'የመረጃ ክፍል';
      case eduDept: return 'የትምህርት ክፍል';
      case financeDept: return 'የፋይናንስ ክፍል';
      case materialDept: return 'የቁሳቁስ ክፍል';
      case mezmurDept: return 'መዝሙር ክፍል';
      case teacher: return 'መምህር';
      case attendanceTaker: return 'ቅጥረት ያዥ';
      case mezmurTaker: return 'የመዝሙር ቅጥረት ያዥ';
      case hrTaker: return 'የኤችአር ቅጥረት ያዥ';
      default: return role;
    }
  }

  static bool canManageMembers(String role) =>
      [superAdmin, schoolAdmin, infoDept].contains(role);
  static bool canBrowseMembers(String role) =>
      [superAdmin, schoolAdmin, infoDept, eduDept].contains(role);
  static bool canTakeAttendance(String role) =>
      [superAdmin, schoolAdmin, teacher, attendanceTaker, eduDept].contains(role);
  static bool canManageGrades(String role) =>
      [superAdmin, schoolAdmin, teacher, eduDept].contains(role);
  static bool canManageEducation(String role) =>
      [superAdmin, schoolAdmin, eduDept].contains(role);
}

// ============================================================
// ROLE-BASED NAVIGATION
// ============================================================

class NavTab {
  final String id;
  final String label;
  final IconData icon;
  final IconData activeIcon;
  const NavTab({required this.id, required this.label, required this.icon, IconData? activeIcon})
      : activeIcon = activeIcon ?? icon;
}

List<NavTab> _baseTabsForRole(String role) {
  switch (role) {
    // ---- TEACHER ----
    case UserRoles.teacher:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'attendance', label: 'Attendance', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'grades', label: 'Grades', icon: Icons.grading_outlined, activeIcon: Icons.grading_rounded),
        NavTab(id: 'timetable', label: 'Timetable', icon: Icons.calendar_month_outlined, activeIcon: Icons.calendar_month_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- ATTENDANCE TAKER ----
    case UserRoles.attendanceTaker:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'attendance', label: 'Attendance', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'mezmur_attendance', label: 'Mezmur', icon: Icons.music_note_outlined, activeIcon: Icons.music_note),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- MEZMUR ATTENDANCE TAKER (department-owned) ----
    case UserRoles.mezmurTaker:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'mezmur_attendance', label: 'Attendance', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- HR ATTENDANCE TAKER (retired 2026-10-07) ----
    // HR no longer takes attendance; the taker home is a retirement
    // notice and reports live on the web dashboard. Home + Profile
    // only — no attendance tab anymore.
    case UserRoles.hrTaker:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- EDUCATION DEPARTMENT ----
    case UserRoles.eduDept:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'attendance', label: 'Attend.', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'grades', label: 'Grades', icon: Icons.grading_outlined, activeIcon: Icons.grading_rounded),
        NavTab(id: 'reviews', label: 'Reviews', icon: Icons.inbox_outlined, activeIcon: Icons.inbox_rounded),
        NavTab(id: 'members', label: 'Students', icon: Icons.people_outline, activeIcon: Icons.people_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- SUPER ADMIN ----
    case UserRoles.superAdmin:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'members', label: 'Members', icon: Icons.people_outline, activeIcon: Icons.people_rounded),
        NavTab(id: 'attendance', label: 'Attend.', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'grades', label: 'Grades', icon: Icons.grading_outlined, activeIcon: Icons.grading_rounded),
        NavTab(id: 'reviews', label: 'Reviews', icon: Icons.inbox_outlined, activeIcon: Icons.inbox_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- SCHOOL ADMIN ----
    case UserRoles.schoolAdmin:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'members', label: 'Members', icon: Icons.people_outline, activeIcon: Icons.people_rounded),
        NavTab(id: 'attendance', label: 'Attend.', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'grades', label: 'Grades', icon: Icons.grading_outlined, activeIcon: Icons.grading_rounded),
        NavTab(id: 'reviews', label: 'Reviews', icon: Icons.inbox_outlined, activeIcon: Icons.inbox_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- HR DEPARTMENT (attendance retired 2026-10-07) ----
    // HR reads combined Education + Mezmur reports on the web dashboard;
    // the mobile review inbox is gone with the workflow it reviewed.
    case UserRoles.hrDept:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- INFO DEPARTMENT ----
    case UserRoles.infoDept:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'members', label: 'Members', icon: Icons.people_outline, activeIcon: Icons.people_rounded),
        NavTab(id: 'attendance', label: 'Attend.', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- FINANCE DEPARTMENT ----
    case UserRoles.financeDept:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- MATERIAL DEPARTMENT ----
    case UserRoles.materialDept:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- MEZMUR DEPARTMENT ----
    case UserRoles.mezmurDept:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'mezmur_attendance', label: 'Attend.', icon: Icons.fact_check_outlined, activeIcon: Icons.fact_check_rounded),
        NavTab(id: 'mezmur_hymns', label: 'Hymns', icon: Icons.music_note_outlined, activeIcon: Icons.music_note),
        NavTab(id: 'mezmur_analytics', label: 'Insights', icon: Icons.insights_outlined, activeIcon: Icons.insights),
        NavTab(id: 'reviews', label: 'Reviews', icon: Icons.inbox_outlined, activeIcon: Icons.inbox_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];

    // ---- FALLBACK ----
    default:
      return const [
        NavTab(id: 'home', label: 'Home', icon: Icons.home_outlined, activeIcon: Icons.home_rounded),
        NavTab(id: 'profile', label: 'Profile', icon: Icons.person_outline, activeIcon: Icons.person_rounded),
      ];
  }
}


List<NavTab> getTabsForRole(
  String role, {
  bool attendanceEnabled = true,
  bool gradesEnabled = true,
  bool mezmurEnabled = true,
}) {
  return _baseTabsForRole(role).where((tab) {
    if (tab.id == 'attendance') return attendanceEnabled;
    if (tab.id == 'grades') return gradesEnabled;
    if (tab.id == 'mezmur_attendance') return mezmurEnabled;
    return true;
  }).toList(growable: false);
}
