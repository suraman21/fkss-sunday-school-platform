import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite/sqflite.dart' as sqflite;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:fkss_app/services/local_db.dart';

void main() {
  late Directory dir;
  setUpAll(() async {
    sqfliteFfiInit();
    dir = await Directory.systemTemp.createTemp('receipt-v40-');
    await databaseFactoryFfi.setDatabasesPath(dir.path);
    sqflite.databaseFactory = databaseFactoryFfi;
  });

  tearDownAll(() async {
    await dir.delete(recursive: true);
  });

  test('v41 creates attendance_sync_receipt with correct schema', () async {
    final db = LocalDb();
    final raw = await db.database;
    final ver = await raw.rawQuery('PRAGMA user_version');
    expect(ver.single.values.single, 42);
    final info = await raw.rawQuery("PRAGMA table_info('attendance_sync_receipt')");
    final cols = info.map((r) => r['name'] as String).toSet();
    expect(cols, contains('client_op_id'));
    expect(cols, contains('receipt_json'));
    expect(cols, contains('server_request_id'));
    expect(cols, contains('owner_user_id'));
    await raw.insert('attendance_sync_receipt', {
      'client_op_id': 'op-1',
      'owner_user_id': 7,
      'authorization_version': 3,
      'generation': 7,
      'class_id': 42,
      'date': '2026-03-01',
      'http_status': 201,
      'receipt_json': '{"saved":1}',
      'server_request_id': 'req-1',
      'created_at': DateTime.now().toUtc().toIso8601String(),
    });
    final rows = await raw.query('attendance_sync_receipt', where: 'client_op_id = ?', whereArgs: ['op-1']);
    expect(rows.length, 1);
    expect(rows.first['http_status'], 201);
    await raw.delete('attendance_sync_receipt');
    await raw.close();
  });
}
