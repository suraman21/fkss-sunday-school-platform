import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:fkss_app/utils/attendance_calendar.dart';
import 'package:fkss_app/services/attendance_calendar_cache.dart';

Map<String, dynamic> payload({String codes = 'omnc dhx'}) => {
      'available': true,
      'enforced': true,
      'backdate_days': 45,
      'from': '2026-10-01',
      'to': '2026-10-07',
      'classes': [
        {'class_id': 3, 'days': codes.replaceAll(' ', '')}
      ],
      'school': {
        '2026-10-05': 'Day Off: training',
        '2026-10-06': 'Holiday: feast'
      },
    };

void main() {
  final now = DateTime.utc(2026, 10, 7, 12);
  AttendanceCalendar calendar() => AttendanceCalendar.parse(payload())!;
  group('server day-code projection', () {
    for (final entry in {
      '01': true,
      '02': true,
      '03': false,
      '04': false,
      '05': false,
      '06': false
    }.entries) {
      test('day ${entry.key} returns ${entry.value}', () {
        expect(calendar().status(3, '2026-10-${entry.key}', now)!['ok'],
            entry.value);
      });
    }
    test('makeup is allowed even when school-wide detail would say off', () {
      final p = payload();
      (p['school'] as Map)['2026-10-02'] = 'Day Off';
      final s = AttendanceCalendar.parse(p)!.status(3, '2026-10-02', now)!;
      expect(s['ok'], true);
      expect(s['is_makeup'], true);
    });
    test('school reasons are retained', () {
      expect(calendar().status(3, '2026-10-05', now)!['detail'],
          'Day Off: training');
      expect(
          calendar().status(3, '2026-10-06', now)!['detail'], 'Holiday: feast');
    });
    test('unknown x, new code, missing class and uncovered date stay unknown',
        () {
      expect(calendar().status(3, '2026-10-07', now), isNull);
      expect(
          AttendanceCalendar.parse(payload(codes: 'ooooooz'))!
              .status(3, '2026-10-07', now),
          isNull);
      expect(calendar().status(99, '2026-10-01', now), isNull);
      expect(calendar().status(3, '2026-09-30', now), isNull);
    });
    test('future class/makeup days are not recordable', () {
      expect(
          calendar()
              .status(3, '2026-10-02', DateTime.utc(2026, 10, 1))!['code'],
          'FUTURE_DATE');
    });
    test('45-day boundary moves with Addis date, not cached server today', () {
      expect(
          calendar()
              .status(3, '2026-10-01', DateTime.utc(2026, 11, 15, 20))!['ok'],
          true);
      expect(
          calendar()
              .status(3, '2026-10-01', DateTime.utc(2026, 11, 15, 21))!['code'],
          'BACKDATE_LIMIT');
    });
    test('migration-disabled server does not invent a backdate rule', () {
      final p = payload()..['enforced'] = false;
      expect(
          AttendanceCalendar.parse(p)!
              .status(3, '2026-10-01', DateTime.utc(2026, 12, 1))!['ok'],
          true);
    });
    test('UTC+3 midnight and leap-day parsing', () {
      expect(
          AttendanceCalendar.ymd(
              AttendanceCalendar.addisToday(DateTime.utc(2026, 10, 1, 21))),
          '2026-10-02');
      expect(AttendanceCalendar.parseDate('2024-02-29'), isNotNull);
      expect(AttendanceCalendar.parseDate('2026-02-29'), isNull);
      expect(AttendanceCalendar.parseDate('2026-10-32'), isNull);
      expect(calendar().status(3, 'bad', now)!['code'], 'INVALID_DATE');
    });
    test('malformed or unavailable payload is never an allowed calendar', () {
      for (final p in [
        null,
        {},
        {'available': false},
        payload()..['enforced'] = 'true',
        payload()..['backdate_days'] = -1,
        payload()..['from'] = '2026-02-30',
        payload()..['to'] = '2026-09-01',
        payload(codes: 'o'),
        payload()
          ..['classes'] = [
            {'class_id': '3', 'days': 'ooooooo'}
          ],
        payload()
          ..['classes'] = [
            {'class_id': 3, 'days': 'ooooooo'},
            {'class_id': 3, 'days': 'ooooooo'}
          ],
      ]) {
        expect(AttendanceCalendar.parse(p), isNull, reason: '$p');
      }
    });
  });
  group('persistent cache boundaries', () {
    setUp(() => SharedPreferences.setMockInitialValues({}));
    final cache = AttendanceCalendarCache();
    final key = AttendanceCalendarCache.key('https://dev/api/v1', 7, 2);
    test('roundtrip survives new cache instance', () async {
      await cache.save(key, calendar(), isCurrent: () => true);
      final read = await AttendanceCalendarCache().read(key);
      expect(read!.status(3, '2026-10-05', now)!['code'], 'DAY_OFF');
    });
    test('user, auth scope and server cannot inherit another calendar',
        () async {
      await cache.save(key, calendar(), isCurrent: () => true);
      for (final other in [
        AttendanceCalendarCache.key('https://dev/api/v1', 8, 2),
        AttendanceCalendarCache.key('https://dev/api/v1', 7, 3),
        AttendanceCalendarCache.key('https://prod/api/v1', 7, 2)
      ]) {
        expect(await cache.read(other), isNull);
      }
    });
    test('superseded fetch cannot persist', () async {
      await cache.save(key, calendar(), isCurrent: () => false);
      expect(await cache.read(key), isNull);
    });
    test('clear on logout/scope change preserves unrelated preferences',
        () async {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('unrelated', 'keep');
      await cache.save(key, calendar(), isCurrent: () => true);
      await AttendanceCalendarCache.clearAll();
      expect(await cache.read(key), isNull);
      expect(prefs.getString('unrelated'), 'keep');
    });
    test('corrupt and unavailable cached payloads fail closed', () async {
      final prefs = await SharedPreferences.getInstance();
      for (final raw in [
        'not json',
        jsonEncode({'available': false}),
        '[]'
      ]) {
        await prefs.setString(key, raw);
        expect(await cache.read(key), isNull);
      }
    });
  });
}
