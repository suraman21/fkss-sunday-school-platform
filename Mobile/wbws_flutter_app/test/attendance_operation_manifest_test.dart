import 'dart:convert';
import 'dart:io';
import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/attendance_operation_manifest.dart';

void main() {
  final fixture = jsonDecode(
      File('../../tests/fixtures/attendance_manifest_v1.json')
          .readAsStringSync()) as Map;
  final vectors = fixture['vectors'] as List;
  Map<String, Object?> copy(Map input) =>
      Map<String, Object?>.from(jsonDecode(jsonEncode(input)) as Map);
  final base = copy((vectors.first as Map)['input'] as Map);
  List<int> hex(String value) => [
        for (var i = 0; i < value.length; i += 2)
          int.parse(value.substring(i, i + 2), radix: 16)
      ];

  for (final v in vectors.cast<Map>()) {
    test('shared golden ${v['name']}', () {
      final input = copy(v['input'] as Map);
      final before = jsonEncode(input);
      final result = AttendanceOperationManifest.create(input);
      expect(result, v['expected']);
      expect(jsonEncode(input), before);
      expect(sha256.convert(hex(v['records_bytes_hex'] as String)).toString(),
          result['records_sha256']);
      expect(sha256.convert(hex(v['manifest_bytes_hex'] as String)).toString(),
          result['manifest_sha256']);
      expect(() => result['class_id'] = 999, throwsUnsupportedError);
      (input['records'] as List).clear();
      expect(
          result['manifest_sha256'], (v['expected'] as Map)['manifest_sha256']);
    });
  }
  final original = AttendanceOperationManifest.create(base);
  for (final change in {
    'owner_user_id': 102,
    'client_op_id': 'other',
    'packet_kind': 'submit',
    'class_id': 4,
    'academic_year_id': 2019,
    'date': '2026-11-06'
  }.entries) {
    test('original context ${change.key} is bound', () {
      final input = copy(base);
      input[change.key] = change.value;
      final result = AttendanceOperationManifest.create(input);
      expect(result['manifest_sha256'], isNot(original['manifest_sha256']));
      expect(result['records_sha256'], original['records_sha256']);
    });
  }
  for (final change
      in {'member_id': 32, 'status': 'late', 'notes': 'different'}.entries) {
    test('record ${change.key} is bound', () {
      final input = copy(base);
      ((input['records'] as List).first as Map)[change.key] = change.value;
      final result = AttendanceOperationManifest.create(input);
      expect(result['records_sha256'], isNot(original['records_sha256']));
      expect(result['manifest_sha256'], isNot(original['manifest_sha256']));
    });
  }
  for (final field in ['owner_user_id', 'class_id', 'academic_year_id']) {
    for (final value in [
      null,
      0,
      -1,
      true,
      '1',
      1.0,
      9007199254740992,
      double.nan,
      double.infinity
    ]) {
      test('invalid ${field}_${value.runtimeType}_$value', () {
        final input = copy(base);
        input[field] = value;
        expect(() => AttendanceOperationManifest.create(input),
            throwsFormatException);
      });
    }
  }
  for (final value in [
    '2026-02-29',
    '1900-02-29',
    '2100-02-29',
    '2026-02-31',
    '0000-01-01',
    '10000-01-01',
    '2026-1-01',
    '2026-11-05T00:00:00Z',
    null
  ]) {
    test('invalid date $value', () {
      final input = copy(base);
      input['date'] = value;
      expect(() => AttendanceOperationManifest.create(input),
          throwsFormatException);
    });
  }
  for (final value in [
    '',
    List.filled(81, 'a').join(),
    ' padded',
    'a:b',
    'a\nb',
    null
  ]) {
    test('invalid key ${jsonEncode(value)}', () {
      final input = copy(base);
      input['client_op_id'] = value;
      expect(() => AttendanceOperationManifest.create(input),
          throwsFormatException);
    });
  }
  for (final key in base.keys) {
    test('missing $key cannot be guessed', () {
      final input = copy(base)..remove(key);
      expect(() => AttendanceOperationManifest.create(input),
          throwsFormatException);
    });
  }
  test('unknown fields, aliases, duplicates and malformed rows fail closed',
      () {
    final duplicate = copy(base);
    (duplicate['records'] as List).add((duplicate['records'] as List).first);
    final alias = copy(base);
    ((alias['records'] as List).first as Map)['note'] = 'alias';
    final missing = copy(base);
    ((missing['records'] as List).first as Map).remove('notes');
    for (final input in [
      duplicate,
      alias,
      missing,
      copy(base)..['attempt_number'] = 1,
      copy(base)..['records'] = [],
      copy(base)..['records'] = {},
      copy(base)..['records'] = [null],
      copy(base)..['packet_kind'] = 'submitted'
    ]) {
      expect(() => AttendanceOperationManifest.create(input),
          throwsFormatException);
    }
  });
  for (final value in [
    false,
    null,
    [],
    123,
    List.filled(501, 'x').join(),
    String.fromCharCode(0xd800),
    String.fromCharCode(0xdc00)
  ]) {
    test('invalid notes ${value.runtimeType}_${jsonEncode(value)}', () {
      final input = copy(base);
      ((input['records'] as List).first as Map)['notes'] = value;
      expect(() => AttendanceOperationManifest.create(input),
          throwsFormatException);
    });
  }
  for (final value in ['Present', ' present', 'unknown', null]) {
    test('invalid status $value', () {
      final input = copy(base);
      ((input['records'] as List).first as Map)['status'] = value;
      expect(() => AttendanceOperationManifest.create(input),
          throwsFormatException);
    });
  }
  for (final value in [true, 1.0, '30', 0, -1, 9007199254740992]) {
    test('invalid member ID ${value.runtimeType}_$value', () {
      final input = copy(base);
      ((input['records'] as List).first as Map)['member_id'] = value;
      expect(() => AttendanceOperationManifest.create(input),
          throwsFormatException);
    });
  }
  test(
      'requested membership count is bound and no normalized note equivalence is inferred',
      () {
    final input = copy(base);
    (input['records'] as List).removeLast();
    expect(AttendanceOperationManifest.create(input)['records_sha256'],
        isNot(original['records_sha256']));
    expect((vectors[4] as Map)['expected']['records_sha256'],
        isNot((vectors[5] as Map)['expected']['records_sha256']));
  });
  test('maximum record list is supported and excess is refused', () {
    final input = copy(base)
      ..['records'] = [
        for (var i = 1; i <= 2000; i++)
          {
            'member_id': i,
            'status': 'present',
            'notes': (fixture['max_case'] as Map)['notes']
          }
      ];
    expect(AttendanceOperationManifest.create(input),
        (fixture['max_case'] as Map)['expected']);
    (input['records'] as List)
        .add({'member_id': 2001, 'status': 'present', 'notes': ''});
    expect(
        () => AttendanceOperationManifest.create(input), throwsFormatException);
  });
}
