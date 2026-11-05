/// Read-only projection of SchoolCalendarService::mobileCalendar.
/// No weekday/holiday rules are guessed here: only server day codes are used.
class AttendanceCalendar {
  AttendanceCalendar._(this.payload, this.from, this.to, this.enforced,
      this.backdateDays, this.days, this.school);

  final Map<String, dynamic> payload;
  final DateTime from;
  final DateTime to;
  final bool enforced;
  final int backdateDays;
  final Map<int, String> days;
  final Map<String, String> school;

  static DateTime? parseDate(String value) {
    if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(value)) return null;
    final d = DateTime.tryParse('${value}T00:00:00Z');
    if (d == null || ymd(d) != value) return null;
    return d;
  }

  static String ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  static DateTime addisToday(DateTime now) {
    final d = now.toUtc().add(const Duration(hours: 3));
    return DateTime.utc(d.year, d.month, d.day);
  }

  static AttendanceCalendar? parse(dynamic raw) {
    if (raw is! Map || raw['available'] != true || raw['enforced'] is! bool) {
      return null;
    }
    final from = parseDate('${raw['from']}');
    final to = parseDate('${raw['to']}');
    final backdate = raw['backdate_days'];
    final classes = raw['classes'];
    if (from == null ||
        to == null ||
        to.isBefore(from) ||
        to.difference(from).inDays > 366 ||
        backdate is! int ||
        backdate < 0 ||
        backdate > 366 ||
        classes is! List) {
      return null;
    }
    final count = to.difference(from).inDays + 1;
    final days = <int, String>{};
    for (final row in classes) {
      if (row is! Map ||
          row['class_id'] is! int ||
          row['class_id'] <= 0 ||
          row['days'] is! String ||
          (row['days'] as String).length != count ||
          days.containsKey(row['class_id'])) {
        return null;
      }
      days[row['class_id'] as int] = row['days'] as String;
    }
    final school = <String, String>{};
    if (raw['school'] is Map) {
      for (final entry in (raw['school'] as Map).entries) {
        if (entry.key is String && entry.value is String) {
          school[entry.key as String] = entry.value as String;
        }
      }
    }
    return AttendanceCalendar._(Map<String, dynamic>.from(raw), from, to,
        raw['enforced'] as bool, backdate, days, school);
  }

  /// null means UNKNOWN, never an implicit permission to record attendance.
  Map<String, dynamic>? status(int classId, String date, DateTime now) {
    final selected = parseDate(date);
    if (selected == null) {
      return verdict(false, 'INVALID_DATE', 'A valid date is required.');
    }
    final today = addisToday(now);
    if (selected.isAfter(today)) {
      return verdict(
          false, 'FUTURE_DATE', 'Attendance opens on the class day.');
    }
    if (enforced && today.difference(selected).inDays > backdateDays) {
      return verdict(false, 'BACKDATE_LIMIT',
          'Attendance can be recorded up to $backdateDays days back.');
    }
    if (selected.isBefore(from) || selected.isAfter(to)) return null;
    final codes = days[classId];
    if (codes == null) return null;
    final code = codes[selected.difference(from).inDays];
    switch (code) {
      case 'o':
        return verdict(true, 'OK', '');
      case 'm':
        return {...verdict(true, 'OK', 'Makeup class day.'), 'is_makeup': true};
      case 'n':
        return verdict(
            false, 'NOT_CLASS_DAY', 'This class does not meet on this date.');
      case 'c':
        return verdict(false, 'NOT_CONFIGURED',
            'Education has not set this class’s working days yet.');
      case 'd':
        return verdict(
            false, 'DAY_OFF', school[date] ?? 'Day Off: classes are off.');
      case 'h':
        return verdict(
            false, 'HOLIDAY', school[date] ?? 'Holiday: classes are off.');
      default:
        return null; // x and future/unknown codes need the online verdict.
    }
  }

  static Map<String, dynamic> verdict(bool ok, String code, String detail) =>
      {'ok': ok, 'code': code, 'detail': detail, 'is_makeup': false};
}
