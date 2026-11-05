import 'dart:convert';
import 'package:crypto/crypto.dart';

/// Internal exact-request identity only. NOT a receipt or authorization grant.
/// No adapter may guess a missing original year from the current session.
final class AttendanceOperationManifest {
  static const maxRecords = 2000;
  static const maxNoteScalars = 500;
  static const maxExactId = 9007199254740991;

  static Map<String, Object> create(Map<String, Object?> input) {
    _keys(input, const {
      'owner_user_id',
      'client_op_id',
      'packet_kind',
      'class_id',
      'academic_year_id',
      'date',
      'records'
    });
    for (final key in ['owner_user_id', 'class_id', 'academic_year_id']) {
      _id(input[key]);
    }
    final key = input['client_op_id'];
    if (key is! String ||
        !RegExp(r'^[A-Za-z0-9._-]{1,80}$').hasMatch(key) ||
        key.contains('\n') ||
        !['draft', 'submit'].contains(input['packet_kind'])) {
      throw const FormatException('Invalid manifest operation identity.');
    }
    final date = input['date'];
    if (date is! String ||
        !RegExp(r'^[0-9]{4}-[0-9]{2}-[0-9]{2}$').hasMatch(date) ||
        date.length != 10) {
      throw const FormatException('Invalid original manifest date.');
    }
    final year = int.parse(date.substring(0, 4));
    final month = int.parse(date.substring(5, 7));
    final day = int.parse(date.substring(8, 10));
    final parsed = DateTime.utc(year, month, day);
    if (year < 1 ||
        parsed.year != year ||
        parsed.month != month ||
        parsed.day != day) {
      throw const FormatException('Invalid original manifest date.');
    }
    final raw = input['records'];
    if (raw is! List || raw.isEmpty || raw.length > maxRecords) {
      throw const FormatException('Invalid manifest record list.');
    }
    final rows = <Map<String, Object?>>[];
    final seen = <int>{};
    for (final item in raw) {
      if (item is! Map || item.keys.any((k) => k is! String)) {
        throw const FormatException('Invalid manifest record.');
      }
      final row = Map<String, Object?>.from(item);
      _keys(row, const {'member_id', 'status', 'notes'});
      _id(row['member_id']);
      if (!seen.add(row['member_id'] as int)) {
        throw const FormatException('Duplicate manifest member.');
      }
      if (!['present', 'absent', 'late', 'excused'].contains(row['status'])) {
        throw const FormatException('Invalid manifest status.');
      }
      final notes = row['notes'];
      if (notes is! String ||
          notes.length > maxNoteScalars * 2 ||
          !_validUnicode(notes) ||
          notes.runes.length > maxNoteScalars) {
        throw const FormatException('Invalid manifest notes.');
      }
      rows.add(row);
    }
    rows.sort(
        (a, b) => (a['member_id'] as int).compareTo(b['member_id'] as int));
    final recordsHash = _digest([
      'ssms.attendance.records.v1',
      '${rows.length}',
      for (final row in rows) ...[
        '${row['member_id']}',
        row['status'] as String,
        row['notes'] as String
      ],
    ]);
    const fields = [
      'owner_user_id',
      'client_op_id',
      'packet_kind',
      'class_id',
      'academic_year_id',
      'date'
    ];
    final manifestHash = _digest([
      'ssms.attendance.manifest.v1',
      for (final field in fields) '${input[field]}',
      '${rows.length}',
      recordsHash
    ]);
    return Map<String, Object>.unmodifiable({
      'manifest_version': 1,
      'domain': 'attendance',
      for (final field in fields) field: input[field]!,
      'record_count': rows.length,
      'records_sha256': recordsHash,
      'manifest_sha256': manifestHash,
    });
  }

  static void _keys(Map<String, Object?> input, Set<String> expected) {
    if (input.length != expected.length || !expected.containsAll(input.keys)) {
      throw const FormatException('Unsupported or missing manifest field.');
    }
  }

  static void _id(Object? value) {
    if (value is! int || value < 1 || value > maxExactId) {
      throw const FormatException('Invalid manifest integer identity.');
    }
  }

  static bool _validUnicode(String text) {
    final units = text.codeUnits;
    for (var i = 0; i < units.length; i++) {
      final unit = units[i];
      if (unit >= 0xd800 && unit <= 0xdbff) {
        if (++i >= units.length || units[i] < 0xdc00 || units[i] > 0xdfff) {
          return false;
        }
      } else if (unit >= 0xdc00 && unit <= 0xdfff) {
        return false;
      }
    }
    return true;
  }

  static String _digest(List<String> values) {
    final output = _DigestOutput();
    final sink = sha256.startChunkedConversion(output);
    for (final value in values) {
      final bytes = utf8.encode(value);
      sink.add(ascii.encode('${bytes.length}:'));
      sink.add(bytes);
      sink.add(const [44]);
    }
    sink.close();
    return output.value!.toString();
  }
}

final class _DigestOutput implements Sink<Digest> {
  Digest? value;
  @override
  void add(Digest data) {
    value = data;
  }

  @override
  void close() {}
}
