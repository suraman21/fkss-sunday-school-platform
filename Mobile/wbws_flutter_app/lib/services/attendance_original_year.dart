import 'package:sqflite/sqflite.dart';

/// Original operation WRITE scope, never a roster fallback or today's year.
const attendanceOriginalYearColumn = 'original_academic_year_id';
const maxOriginalAttendanceYearId = 9007199254740991;

bool isValidOriginalAttendanceYear(Object? value) =>
    value is int && value > 0 && value <= maxOriginalAttendanceYearId;

/// Unknown/mixed/corrupt provenance is not proof. Never repair it by coercion.
/// This projection does not change existing legacy transport eligibility.
int? coherentAttendanceOriginalYear(Iterable<Map<String, Object?>> records) {
  int? year;
  for (final row in records) {
    final candidate = row[attendanceOriginalYearColumn];
    if (!isValidOriginalAttendanceYear(candidate) ||
        (year != null && year != candidate)) {
      return null;
    }
    year = candidate as int;
  }
  return year;
}

/// Caller owns the sqflite open/upgrade transaction. Add only nullable storage;
/// existing rows remain unknown. Errors propagate; never replace/rebuild a DB.
Future<void> migrateAttendanceOriginalYear(DatabaseExecutor db) async {
  final columns = await db.rawQuery('PRAGMA table_info(pending_attendance)');
  if (columns.isEmpty) {
    throw StateError('Attendance storage requires review before upgrading.');
  }
  final existing =
      columns.where((c) => c['name'] == attendanceOriginalYearColumn);
  if (existing.isNotEmpty) {
    final column = existing.single;
    if ('${column['type']}'.toUpperCase() != 'INTEGER' ||
        column['notnull'] != 0 ||
        column['pk'] != 0 ||
        column['dflt_value'] != null) {
      throw StateError('Original attendance year storage requires review.');
    }
    return;
  }
  await db.execute('ALTER TABLE pending_attendance ADD COLUMN '
      '$attendanceOriginalYearColumn INTEGER');
}
