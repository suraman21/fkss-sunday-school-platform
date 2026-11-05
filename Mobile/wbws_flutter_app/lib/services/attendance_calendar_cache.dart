import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';
import '../utils/attendance_calendar.dart';

/// Same small-JSON persistence pattern as TimetableCache, additionally scoped
/// to API origin and authorization version. Never stores students or marks.
class AttendanceCalendarCache {
  static const _prefix = 'attendance_calendar_v1_';

  static String key(String apiBase, int userId, int authorizationVersion) =>
      '$_prefix${base64Url.encode(utf8.encode(apiBase))}_${userId}_$authorizationVersion';

  Future<AttendanceCalendar?> read(String scope) async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(scope);
    if (raw == null) return null;
    try {
      return AttendanceCalendar.parse(jsonDecode(raw));
    } catch (_) {
      return null; // corrupt cache is unknown, not an allowed class day
    }
  }

  Future<void> save(String scope, AttendanceCalendar calendar,
      {required bool Function() isCurrent}) async {
    final prefs = await SharedPreferences.getInstance();
    if (!isCurrent()) return;
    await prefs.setString(scope, jsonEncode(calendar.payload));
  }

  Future<void> remove(String scope,
      {required bool Function() isCurrent}) async {
    final prefs = await SharedPreferences.getInstance();
    if (isCurrent()) await prefs.remove(scope);
  }

  static Future<void> clearAll() async {
    final prefs = await SharedPreferences.getInstance();
    for (final key
        in prefs.getKeys().where((k) => k.startsWith(_prefix)).toList()) {
      await prefs.remove(key);
    }
  }
}
