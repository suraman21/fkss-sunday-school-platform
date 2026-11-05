import 'dart:convert';
import 'dart:math';
import 'package:sqflite/sqflite.dart';

import 'attendance_delta.dart';
import 'attendance_original_year.dart';
import 'attendance_sync_receipt.dart';
import 'interrupted_database_migration.dart';
import 'amharic_text.dart' as amharic;
import 'search_index_policy.dart';
import 'search_matching.dart';
import 'synced_lyrics_merge.dart';
import 'package:path/path.dart';

import 'taxonomy_reconcile.dart';
import 'hymn_outbox_models.dart';
import 'legacy_outbox_models.dart';
import 'local_schema_v34.dart';
import 'sync_attempt_models.dart';
import 'sync_execution.dart';
import 'session_models.dart';
import 'sync_recovery_models.dart';

String newClientOpId() {
  final r = Random.secure();
  final b = List<int>.generate(16, (_) => r.nextInt(256));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  String h(int i) => b[i].toRadixString(16).padLeft(2, '0');
  return '${h(0)}${h(1)}${h(2)}${h(3)}-${h(4)}${h(5)}-${h(6)}${h(7)}-${h(8)}${h(9)}-${h(10)}${h(11)}${h(12)}${h(13)}${h(14)}${h(15)}';
}

/// Local SQLite database for offline-first features.
/// Caches classes, students, subjects, assessments, dashboard stats, members.
/// Stores attendance + grades locally, syncs when connected.
class LocalDb {
  static final LocalDb _instance = LocalDb._internal();
  factory LocalDb() => _instance;
  LocalDb._internal();

  Database? _db;
  Future<void> _legacySaveTail = Future<void>.value();

  /// Canonical current-schema registry for authenticated read caches.
  ///
  /// Startup inventory and authorization-scope cleanup must traverse the same
  /// physical tables. Keeping one registry prevents a migration-only staging
  /// name from being copied into runtime SQL (the schema-v10 staging table is
  /// renamed to `cached_mezmur_sheet` before that migration completes).
  static const List<String> _privateReadCacheTables = [
    'cached_classes',
    'cached_students',
    'cached_subjects',
    'cached_assessments',
    'cached_dashboard',
    'cached_members',
    'cached_attendance',
    'cached_grade_sheets',
    'cached_mezmur_sheet',
    'cached_mezmur_sections',
    'cached_mezmur_days',
    'cached_mezmur_analytics_last',
    'cached_review_packets',
    'cached_review_packet_details',
    'cached_review_stats',
    'cached_edu_classes',
    'cached_edu_class_rosters',
    'cached_edu_subjects',
    'cached_edu_teacher_snapshot',
    'cached_edu_teachers',
    'cached_edu_teacher_details',
    'cached_hr_sheet',
    'cached_hr_sections',
    'comm_threads',
    'comm_messages',
    'comm_meta',
    'cached_notifications',
    'cached_announcements',
    'sync_log',
  ];

  /// Serializes packet replacement in invocation order. SQLite transactions
  /// make each replacement atomic; this chain also makes rapid same-key user
  /// intent deterministic instead of depending on platform scheduling.
  Future<T> _serializeLegacySave<T>(Future<T> Function() action) {
    final result = _legacySaveTail.then((_) => action());
    _legacySaveTail = result.then<void>((_) {}).catchError((_) {});
    return result;
  }

  Future<Database> get database async {
    if (_db != null) return _db!;
    _db = await _initDb();
    return _db!;
  }

  Future<Database> _initDb() async {
    final dbPath = await getDatabasesPath();
    final path = join(dbPath, 'wbws_offline_v4.db');
    await ensureNoInterruptedDatabaseMigration(path);

    // The offline DB contains caches AND durable unsynchronized work. It is
    // ordinary SQLite, not app-level encrypted. OS sandbox/encryption varies
    // by device; server authority does not make pending local data disposable.
    return await openDatabase(
      path,
      // A warm UI engine and a cold background engine may open the production
      // database concurrently. Do not collapse those independent connections
      // into sqflite's per-isolate singleton; SQLite transactions arbitrate
      // the shared file and LocalDb's claim/lease protocol arbitrates work.
      singleInstance: false,
      version: localDatabaseSchemaVersion,
      onConfigure: (db) async {
        await db.execute('PRAGMA foreign_keys = ON');
        // Set-form PRAGMAs must go through rawQuery on Android: db.execute()
        // throws "Queries can be performed using ... rawQuery methods only"
        // for them, which crashed every database open in 1.1.15.
        try {
          await db.rawQuery('PRAGMA secure_delete = ON');
        } catch (_) {}
      },
      onCreate: (db, version) async {
        await _createTables(db);
        await _migrateToV34(db);
        await db.execute(localSyncStateV35Sql);
        await _createSyncAttemptLedger(db);
        await migrateAttendanceOriginalYear(db);
        await db.execute(attendancePreparationSnapshotSql);
        await db.execute(gradesPreparationSnapshotSql);
        await db.execute(attendanceSyncReceiptSql);
        await db.execute(attendanceSyncReceiptIndexSql);
        await db.execute(gradesSyncReceiptSql);
        await db.execute(gradesSyncReceiptIndexSql);
      },
      onUpgrade: (db, oldVersion, newVersion) async {
        if (oldVersion < 2) {
          // Add new tables for v2
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_dashboard (
              id INTEGER PRIMARY KEY DEFAULT 1,
              stats_json TEXT,
              role TEXT,
              updated_at TEXT
            )
          ''');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_members (
              id INTEGER PRIMARY KEY,
              student_name TEXT,
              father_name TEXT,
              member_code TEXT,
              gender TEXT,
              status TEXT,
              current_section TEXT,
              data_json TEXT,
              updated_at TEXT
            )
          ''');
        }
        if (oldVersion < 3) {
          // Add cached attendance responses
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_attendance (
              class_id INTEGER NOT NULL,
              date TEXT NOT NULL,
              response_json TEXT NOT NULL,
              updated_at TEXT,
              PRIMARY KEY (class_id, date)
            )
          ''');
        }
        if (oldVersion < 4) {
          try {
            await db.execute('ALTER TABLE pending_attendance ADD COLUMN notes TEXT');
          } catch (_) {}
        }
        if (oldVersion < 5) {
          try {
            await db.execute(
                "ALTER TABLE pending_attendance ADD COLUMN packet_kind TEXT DEFAULT 'draft'");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE pending_grades ADD COLUMN packet_kind TEXT DEFAULT 'draft'");
          } catch (_) {}
          try {
            await db.execute(
                "UPDATE pending_attendance SET packet_kind = 'draft' WHERE packet_kind IS NULL");
          } catch (_) {}
          try {
            await db.execute(
                "UPDATE pending_grades SET packet_kind = 'draft' WHERE packet_kind IS NULL");
          } catch (_) {}
        }
        if (oldVersion < 6) {
          try {
            await db.execute('''
              CREATE TABLE IF NOT EXISTS cached_grade_sheets (
                assessment_id INTEGER PRIMARY KEY,
                class_id INTEGER,
                response_json TEXT NOT NULL,
                updated_at TEXT
              )
            ''');
          } catch (_) {}
        }
        if (oldVersion < 7) {
          try {
            await db.execute(
                "ALTER TABLE pending_attendance ADD COLUMN client_op_id TEXT");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE pending_grades ADD COLUMN client_op_id TEXT");
          } catch (_) {}
        }
        if (oldVersion < 8) {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_grade_sheets (
              assessment_id INTEGER PRIMARY KEY,
              class_id INTEGER,
              response_json TEXT NOT NULL,
              updated_at TEXT
            )
          ''');
        }
        if (oldVersion < 9) {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS pending_mezmur (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              date TEXT NOT NULL,
              program TEXT,
              member_id INTEGER NOT NULL,
              status TEXT NOT NULL,
              packet_kind TEXT NOT NULL DEFAULT 'draft',
              client_op_id TEXT,
              synced INTEGER NOT NULL DEFAULT 0,
              created_at TEXT NOT NULL,
              synced_at TEXT,
              sync_error TEXT
            )
          ''');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_mezmur_sheet (
              date TEXT PRIMARY KEY,
              response_json TEXT NOT NULL,
              updated_at TEXT
            )
          ''');
        }
        if (oldVersion < 10) {
          // Phase 5: mezmur attendance becomes section-scoped (teacher
          // clone). pending_mezmur gains section + notes; the sheet cache
          // key becomes (date, section); the section picker gets a cache.
          try {
            await db.execute(
                "ALTER TABLE pending_mezmur ADD COLUMN section TEXT NOT NULL DEFAULT ''");
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE pending_mezmur ADD COLUMN notes TEXT');
          } catch (_) {}
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_mezmur_sheet_v2 (
              date TEXT NOT NULL,
              section TEXT NOT NULL DEFAULT '',
              response_json TEXT NOT NULL,
              updated_at TEXT,
              PRIMARY KEY (date, section)
            )
          ''');
          // Carry over phase-4 full-roster caches as section ''.
          await db.execute('''
            INSERT OR IGNORE INTO cached_mezmur_sheet_v2 (date, section, response_json, updated_at)
            SELECT date, '', response_json, updated_at FROM cached_mezmur_sheet
          ''');
          await db.execute('DROP TABLE IF EXISTS cached_mezmur_sheet');
          await db.execute(
              'ALTER TABLE cached_mezmur_sheet_v2 RENAME TO cached_mezmur_sheet');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_mezmur_sections (
              id INTEGER PRIMARY KEY CHECK (id = 1),
              sections_json TEXT NOT NULL,
              updated_at TEXT
            )
          ''');
        }
        if (oldVersion < 11) {
          // Offline-first hymn library (local-first model):
          // full local copy + mutation outbox + delta-sync cursor.
          await _createHymnTables(db);
        }
        if (oldVersion < 12) {
          // Phase B (2026-08): HR department attendance — HR's OWN
          // section-based domain. Structurally identical to the mezmur
          // tables but fully separate: HR data never mixes with Mezmur
          // or Education, on the server or on the phone.
          await db.execute('''
            CREATE TABLE IF NOT EXISTS pending_hr (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              date TEXT NOT NULL,
              section TEXT NOT NULL DEFAULT '',
              member_id INTEGER NOT NULL,
              status TEXT NOT NULL,
              notes TEXT,
              packet_kind TEXT NOT NULL DEFAULT 'draft',
              client_op_id TEXT,
              synced INTEGER NOT NULL DEFAULT 0,
              created_at TEXT NOT NULL,
              synced_at TEXT,
              sync_error TEXT
            )
          ''');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_hr_sheet (
              date TEXT NOT NULL,
              section TEXT NOT NULL DEFAULT '',
              response_json TEXT NOT NULL,
              updated_at TEXT,
              PRIMARY KEY (date, section)
            )
          ''');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_hr_sections (
              id INTEGER PRIMARY KEY CHECK (id = 1),
              sections_json TEXT NOT NULL,
              updated_at TEXT
            )
          ''');
        }
        if (oldVersion < 13) {
          // Mezmur hymns: length + language taxonomy flags (Feature 2).
          try {
            await db.execute(
                "ALTER TABLE cached_hymns ADD COLUMN length TEXT NOT NULL DEFAULT 'long'");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE cached_hymns ADD COLUMN language TEXT NOT NULL DEFAULT 'amharic'");
          } catch (_) {}
        }
        if (oldVersion < 14) {
          // Feature 3: singer catalogue + many-to-many hymn associations.
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_mezmur_zemarians (
              id INTEGER PRIMARY KEY,
              name TEXT NOT NULL,
              name_am TEXT,
              image_url TEXT NULL,
              sort_order INTEGER NOT NULL DEFAULT 0,
              is_active INTEGER NOT NULL DEFAULT 1,
              updated_at TEXT
            )
          ''');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_hymn_categories (
              hymn_id INTEGER NOT NULL,
              category_id INTEGER NOT NULL,
              PRIMARY KEY (hymn_id, category_id)
            )
          ''');
          await db.execute(
              'CREATE INDEX IF NOT EXISTS idx_chc_category ON cached_hymn_categories (category_id)');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS cached_hymn_zemarians (
              hymn_id INTEGER NOT NULL,
              zemarian_id INTEGER NOT NULL,
              PRIMARY KEY (hymn_id, zemarian_id)
            )
          ''');
          await db.execute(
              'CREATE INDEX IF NOT EXISTS idx_chz_zemarian ON cached_hymn_zemarians (zemarian_id)');
        }
        if (oldVersion < 15) {
          await _createHymnSearchIndex(db);
          await _rebuildHymnSearchIndex(db);
        }
        if (oldVersion < 17) {
          // P30: two-level taxonomy — mains parent their subs; covers.
          try {
            await db.execute(
                'ALTER TABLE cached_mezmur_categories ADD COLUMN parent_id INTEGER NULL');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE cached_mezmur_categories ADD COLUMN image_url TEXT NULL');
          } catch (_) {}
        }
        if (oldVersion < 19) {
          // P34: singer cover images.
          try {
            await db.execute(
                'ALTER TABLE cached_mezmur_zemarians ADD COLUMN image_url TEXT NULL');
          } catch (_) {}
        }
        if (oldVersion < 20) {
          // P0 audio + synced lyrics: metadata-only columns on the hymn
          // cache. Audio BYTES never live here — this stores the R2
          // status + public URL so the player can stream directly and
          // timed LRC text can render offline. Guarded ALTERs (a fresh
          // v20 install already carries the columns from the DDL).
          final colDefs = <String, String>{
            "audio_status": "TEXT NOT NULL DEFAULT 'none'",
            'audio_url': 'TEXT NULL',
            'audio_format': 'TEXT NULL',
            'audio_size': 'INTEGER NULL',
            'audio_duration_s': 'INTEGER NULL',
            'audio_updated_at': 'TEXT NULL',
            'lyrics_synced': 'TEXT NULL',
            'lyrics_synced_at': 'TEXT NULL',
          };
          for (final e in colDefs.entries) {
            try {
              await db.execute(
                  'ALTER TABLE cached_hymns ADD COLUMN ${e.key} ${e.value}');
            } catch (_) {}
          }
        }
        if (oldVersion < 21) {
          // P33: Streaming-style offline downloads for mezmur audio.
          await _createDownloadTables(db);
        }
        if (oldVersion < 24) {
          // P39: substring retrieval. Creating the table is enough —
          // the analyzer bump to v3 makes the on-open check rebuild
          // both indexes together.
          await _createHymnTrigramIndex(db);
        }
        if (oldVersion < 25) {
          // P66 hymn art: per-hymn cover images (Streaming-style). The
          // server stores square 160/320/640 JPEG renditions and a
          // dominant color; the URLs are immutable per artwork
          // (?v=<updated_at>), so the cache is safe to keep forever.
          final colDefs = {
            "art_status": "TEXT NOT NULL DEFAULT 'none'",
            'art_color': 'TEXT NULL',
            'art_url': 'TEXT NULL',
            'art_url_medium': 'TEXT NULL',
            'art_url_small': 'TEXT NULL',
          };
          for (final e in colDefs.entries) {
            try {
              await db.execute(
                  'ALTER TABLE cached_hymns ADD COLUMN ${e.key} ${e.value}');
            } catch (_) {}
          }
        }
        if (oldVersion < 23) {
          // P38: self-healing index. No rebuild is scheduled here on
          // purpose — the analyzer stamp is left NULL so the check that
          // runs on EVERY open notices and repairs, which also covers
          // interrupted rebuilds and future normaliser changes.
          await _createSearchMetaTables(db);
        }
        if (oldVersion < 26) {
          // O1 (offline-first comm): WhatsApp-style local store — the
          // UI reads these tables first; the network only refreshes
          // them. All five are member PII → wiped on logout below.
          await _createCommTables(db);
        }
        if (oldVersion < 27) {
          // P1-B (offline-first notification center): alerts +
          // announcements cached for instant local render, mirroring
          // the two server feeds' contracts. Caches start empty and
          // fill from the first successful refresh — no data
          // migration. User-scoped server responses → wiped on
          // logout like every other cache.
          await _createNotificationTables(db);
        }
        if (oldVersion < 28) {
          // P1-C (Mezmur Home local-first): the department-wide
          // attendance-day aggregate from GET /mezmur/days, verbatim.
          // Cache starts empty and fills from the first successful
          // refresh — no data migration, nothing derived from
          // cached_mezmur_sheet. User-scoped server response → wiped
          // on logout like every other cache.
          await _createMezmurDaysTable(db);
        }
        if (oldVersion < 29) {
          // P1-D (Review Inbox local-first): the department review
          // queue's read model — list packets, detail payloads, and
          // per-department stats, all stored verbatim from the three
          // /submissions endpoints. Cache starts empty — no data
          // migration. Dept-scoped server responses (detail rows
          // carry member marks) → wiped on logout.
          await _createReviewTables(db);
        }
        if (oldVersion < 30) {
          // P1-E (Education Classes local-first): the education
          // department's own read model — the complete active class
          // list plus per-class rosters, stored verbatim from the two
          // /classes endpoints. Deliberately SEPARATE tables: the
          // teacher workflow's cached_classes/cached_students are
          // protected shared caches (destructive writers, three
          // writers, no level_order/year contract) and stay
          // byte-untouched. Cache starts empty — no data migration.
          // Rosters carry member PII → wiped on logout.
          await _createEduTables(db);
        }
        if (oldVersion < 31) {
          // P1-F (Education Subjects local-first): the education
          // department's subject catalog — the complete active set
          // from GET /subjects, stored verbatim. Dedicated table:
          // cached_subjects is the teacher grade-bootstrap cache
          // ((id, class_id) composite PK, fed from /grades/bootstrap)
          // and stays byte-untouched. Cache starts empty — no data
          // migration. Role-scoped server response → wiped on logout
          // with everything else.
          await _createEduSubjectsTable(db);
        }
        if (oldVersion < 32) {
          // P1-G (Education Teachers local-first): a dedicated,
          // COMPLETE active-teacher snapshot plus view-once details.
          // The singleton metadata row distinguishes never-cached from
          // a valid empty directory; assignment details are explicitly
          // keyed by (teacher_id, academic_year_id). Nothing is derived
          // from or written into member/teacher workflow caches.
          await _createEduTeachersTables(db);
        }
        if (oldVersion < 33) {
          // P1-H (Mezmur Analytics local-first): one bounded, sensitive
          // last-view row containing the successfully validated member-page
          // and section-rollup pair. Deliberately NOT derived from attendance
          // sheets/days/sections or pending Mezmur writes.
          await _createMezmurAnalyticsTable(db);
        }
        if (oldVersion < 22) {
          // P37: Telegram-style lyrics search. The word index is
          // rebuilt from scratch because normalisation changed —
          // every previously indexed word was stored WITHOUT Amharic
          // homophone folding, so the old rows can never match a
          // normalised query and must not be left behind.
          try {
            await db.execute('DROP TABLE IF EXISTS hymn_search_words');
          } catch (_) {}
          await _createHymnSearchIndex(db);
          await _rebuildHymnSearchIndex(db);
        }
        if (oldVersion < 18) {
          // P32: admin-pinned cover gradient colors.
          try {
            await db.execute(
                'ALTER TABLE cached_mezmur_categories ADD COLUMN gradient_start TEXT NULL');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE cached_mezmur_categories ADD COLUMN gradient_end TEXT NULL');
          } catch (_) {}
        }
        if (oldVersion < 16) {
          // P28 (item 9): single Amharic title. Fold any Amharic title
          // into the canonical one (the Amharic name IS the hymn's
          // name), retire reference, and rebuild the word index from
          // title + lyrics only (stale tokens from the retired fields
          // would keep matching queries nothing can satisfy).
          await db.execute(
              "UPDATE cached_hymns SET title = title_am WHERE IFNULL(title_am, '') <> '' AND title <> title_am");
          await db.execute(
              'UPDATE cached_hymns SET title_am = NULL, reference = NULL');
          await _rebuildHymnSearchIndex(db);
        }
        if (oldVersion < 34) {
          // Risks #1/#9/#8/#10: one coordinated owner/scope/state and
          // immutable-operation migration. sqflite wraps onUpgrade in one
          // transaction; the migration itself is repeat-safe.
            await _migrateToV34(db);
          }
          if (oldVersion < 35) {
            // Download-sync cursor state. Creating it empty is the whole
            // migration: an absent row means cursor 0, which the server
            // answers with bootstrap_required, so an upgrading device
            // rebuilds its attendance cache instead of resuming from a
            // revision it never actually applied.
            await db.execute(localSyncStateV35Sql);
          }
          if (oldVersion < 36) {
            // Operation/attempt lineage. Created empty on purpose: existing
            // pending work keeps its outbox row, its client_op_id and its
            // attempt_count untouched, and simply has no recorded history
            // before this point. Backfilling invented attempts would be
            // fabricated evidence.
            await _createSyncAttemptLedger(db);
          }
          if (oldVersion < 37) {
            // S2 Goal A.1 — execution provenance on the S1 attempt ledger.
            //
            // Guarded by a column probe because v36 installs may have been
            // created by _createSyncAttemptLedger (which now ships the column)
            // rather than by this ALTER, and adding it twice is an error.
            //
            // The DEFAULT backfills existing rows with 'foreground'. That is
            // not a placeholder: every attempt recorded before this version
            // was necessarily produced by an in-app drain, because no
            // background execution path existed to produce any other kind.
            if (await _tableExists(db, 'sync_attempts')) {
              final columns = await _columnNames(db, 'sync_attempts');
              if (!columns.contains('execution_source')) {
                await db.execute(
                  'ALTER TABLE sync_attempts ADD COLUMN execution_source '
                  "TEXT NOT NULL DEFAULT 'foreground'",
                );
              }
            }
          }
          if (oldVersion < 38) {
            await migrateAttendanceOriginalYear(db);
          }
          if (oldVersion < 39) {
            await db.execute(attendancePreparationSnapshotSql);
          }
          if (oldVersion < 40) {
            await db.execute(attendanceSyncReceiptSql);
            await db.execute(attendanceSyncReceiptIndexSql);
          }
          if (oldVersion < 41) {
            await db.execute(gradesPreparationSnapshotSql);
          }
          if (oldVersion < 42) {
            await db.execute(gradesSyncReceiptSql);
            await db.execute(gradesSyncReceiptIndexSql);
          }
        },
      onOpen: (db) async {
        // No HTTP request survives its issuing process. Recover durable claims
        // before any scheduler can observe/select work, preserving operation
        // and idempotency identity for safe replay.
        await _recoverOrphanedInFlightWithDb(db);
      },
    );
  }

  Future<bool> _tableExists(Database db, String table) async {
    final rows = await db.rawQuery(
      "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
      [table],
    );
    return rows.isNotEmpty;
  }

  Future<Set<String>> _columnNames(Database db, String table) async {
    final rows = await db.rawQuery('PRAGMA table_info($table)');
    return rows.map((row) => '${row['name']}').toSet();
  }

  /// Coordinated schema-v34 migration. onCreate/onUpgrade supply the outer
  /// transaction; every step is repeat-safe so interrupted opens can resume.
  Future<void> _migrateToV34(Database db) async {
    for (final spec in localV34ColumnSpecs) {
      if (!await _tableExists(db, spec.table)) continue;
      final columns = await _columnNames(db, spec.table);
      if (!columns.contains(spec.name)) {
        await db.execute(
          'ALTER TABLE ${spec.table} ADD COLUMN ${spec.name} '
          '${spec.declaration}',
        );
      }
    }

    await db.execute(localSessionStateV34Sql);
    await db.insert(
      'local_session_state',
      {
        'id': 1,
        'state': 'anonymous_clean',
        'generation': 0,
        'updated_at': DateTime.now().toUtc().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.ignore,
    );

    final now = DateTime.now().toUtc().toIso8601String();
    for (final spec in legacyOutboxTableSpecs) {
      if (!await _tableExists(db, spec.table)) continue;
      await db.rawUpdate(
        "UPDATE ${spec.table} SET sync_state = 'synced' "
        "WHERE synced = 1 AND sync_state <> 'synced'",
      );
      await db.rawUpdate(
        "UPDATE ${spec.table} "
        "SET sync_state = 'needs_attention', "
        "failure_code = COALESCE(failure_code, 'LEGACY_REJECTION'), "
        'failed_at = COALESCE(failed_at, ?) '
        "WHERE synced = 0 AND sync_error IS NOT NULL "
        "AND sync_state = 'pending'",
        [now],
      );
    }

    if (await _tableExists(db, 'pending_hymn_ops')) {
      await db.rawUpdate(
        "UPDATE pending_hymn_ops SET sync_state = 'synced' "
        "WHERE synced = 1 AND sync_state <> 'synced'",
      );
      final missingIds = await db.query(
        'pending_hymn_ops',
        columns: ['id'],
        where: "synced = 0 AND (client_op_id IS NULL OR "
            "TRIM(client_op_id) = '')",
      );
      for (final row in missingIds) {
        await db.update(
          'pending_hymn_ops',
          {'client_op_id': newClientOpId()},
          where: 'id = ?',
          whereArgs: [row['id']],
        );
      }
    }

    if (await _tableExists(db, 'comm_outbox')) {
      // Keep `failed` for the existing bubble UI. The structured metadata
      // gives it needs-attention semantics without switching live behavior.
      await db.rawUpdate(
        "UPDATE comm_outbox SET "
        "failure_code = COALESCE(failure_code, 'LEGACY_COMM_FAILURE'), "
        'failed_at = COALESCE(failed_at, ?) '
        "WHERE state = 'failed'",
        [now],
      );
    }

    await _reconcileLegacyOperationIds(db, now);

    for (final sql in localV34IndexSql) {
      await db.execute(sql);
    }
  }

  String _legacyBusinessKey(
    LegacyOutboxTableSpec spec,
    Map<String, Object?> row,
  ) {
    return jsonEncode([
      for (final column in spec.businessKeyColumns) row[column],
    ]);
  }

  String _legacyPacketKind(Map<String, Object?> row) {
    final value = '${row['packet_kind'] ?? 'draft'}'.trim().toLowerCase();
    return value.isEmpty ? 'draft' : value;
  }

  /// Gives coherent pre-v34 packets one shared id. Ambiguous generations are
  /// never merged/selected lexically: every row is retained and quarantined.
  Future<void> _reconcileLegacyOperationIds(Database db, String now) async {
    for (final spec in legacyOutboxTableSpecs) {
      if (!await _tableExists(db, spec.table)) continue;
      final rows = await db.query(
        spec.table,
        columns: [
          'id',
          ...spec.businessKeyColumns,
          'packet_kind',
          'client_op_id',
          'failed_at',
        ],
        where: 'synced = 0',
        orderBy: 'id',
      );
      final groups = <String, List<Map<String, Object?>>>{};
      for (final row in rows) {
        groups.putIfAbsent(_legacyBusinessKey(spec, row), () => []).add(row);
      }

      for (final group in groups.values) {
        final ids = <String>{};
        final blankRows = <Map<String, Object?>>[];
        final packetKinds = <String>{};
        for (final row in group) {
          final id = '${row['client_op_id'] ?? ''}';
          if (id.trim().isEmpty) {
            blankRows.add(row);
          } else {
            ids.add(id);
          }
          packetKinds.add(_legacyPacketKind(row));
        }

        final validPacketKind = packetKinds.length == 1 &&
            (packetKinds.single == 'draft' ||
                packetKinds.single == 'submitted');
        final coherent = validPacketKind &&
            (ids.isEmpty || (ids.length == 1 && blankRows.isEmpty));

        if (coherent && ids.isEmpty) {
          final generated = newClientOpId();
          for (final row in blankRows) {
            await db.update(
              spec.table,
              {'client_op_id': generated},
              where: 'id = ? AND synced = 0',
              whereArgs: [row['id']],
            );
          }
          continue;
        }
        if (coherent) continue;

        // Blank children in an ambiguous set receive individual identities;
        // assigning one shared id would falsely assert a coherent generation.
        for (final row in group) {
          final values = <String, Object?>{
            'sync_state': 'needs_attention',
            'failure_code': 'LEGACY_MIXED_OPERATION_SET',
            if (row['failed_at'] == null) 'failed_at': now,
          };
          if ('${row['client_op_id'] ?? ''}'.trim().isEmpty) {
            values['client_op_id'] = newClientOpId();
          }
          await db.update(
            spec.table,
            values,
            where: 'id = ? AND synced = 0',
            whereArgs: [row['id']],
          );
        }
      }
    }

    // Also reject one id reused across business keys, packet kinds, or tables.
    // UUID lexical ordering is deliberately absent from this reconciliation.
    final identitiesById = <String, Set<String>>{};
    final tablesById = <String, Set<String>>{};
    for (final spec in legacyOutboxTableSpecs) {
      if (!await _tableExists(db, spec.table)) continue;
      final rows = await db.query(
        spec.table,
        columns: [
          ...spec.businessKeyColumns,
          'packet_kind',
          'client_op_id',
        ],
        where: 'synced = 0',
      );
      for (final row in rows) {
        final id = '${row['client_op_id'] ?? ''}';
        if (id.trim().isEmpty) continue;
        final identity = '${spec.table}|${_legacyBusinessKey(spec, row)}|'
            '${_legacyPacketKind(row)}';
        identitiesById.putIfAbsent(id, () => <String>{}).add(identity);
        tablesById.putIfAbsent(id, () => <String>{}).add(spec.table);
      }
    }
    for (final entry in identitiesById.entries) {
      if (entry.value.length <= 1) continue;
      for (final table in tablesById[entry.key] ?? const <String>{}) {
        await db.rawUpdate(
          "UPDATE $table SET sync_state = 'needs_attention', "
          "failure_code = 'LEGACY_MIXED_OPERATION_SET', "
          'failed_at = COALESCE(failed_at, ?) '
          'WHERE client_op_id = ? AND synced = 0',
          [now, entry.key],
        );
      }
    }
  }


  // ── S1: durable operation/attempt lineage ────────────────────────────────
  // The outbox row stays authoritative for execution. These helpers only
  // record what happened around it, inside the very same transactions, so a
  // crash can never leave the ledger disagreeing with the row it describes.

  Future<void> _createSyncAttemptLedger(DatabaseExecutor db) async {
    await db.execute(localSyncAttemptsV36Sql);
    for (final statement in localSyncAttemptsV36IndexSql) {
      await db.execute(statement);
    }
  }

  /// Correlation id for exactly one transmission.
  ///
  /// Opaque and random by construction: it carries no user id, no token, no
  /// payload and nothing guessable about the operation it belongs to.
  String newAttemptUid() => 'att_${newClientOpId().replaceAll('-', '')}';

  /// Opens an attempt row for a claim that is about to be transmitted.
  ///
  /// Runs inside the claim transaction. `attemptNumber` is the outbox row's
  /// own post-increment `attempt_count`, so the ledger and the row can never
  /// drift. The UNIQUE(client_op_id, attempt_number) index turns a
  /// non-incrementing attempt counter into a hard failure rather than a
  /// quietly corrupted history.
  Future<void> _openSyncAttempt(
    DatabaseExecutor txn, {
    required String clientOpId,
    required int attemptNumber,
    required String attemptUid,
    required String domain,
    required DateTime startedAt,
    Map<String, Object?>? entityRef,
    int? ownerUserId,
    int? authorizationVersion,
    SyncExecutionSource executionSource = SyncExecutionSource.foreground,
  }) async {
    await txn.insert(
      'sync_attempts',
      {
        'client_op_id': clientOpId,
        'attempt_number': attemptNumber,
        'attempt_uid': attemptUid,
        'domain': domain,
        // S2 Goal A.1: provenance of the drain that opened this attempt.
        'execution_source': executionSource.storageValue,
        // Natural key only — never the records payload.
        'entity_ref': entityRef == null ? null : jsonEncode(entityRef),
        'owner_user_id': ownerUserId,
        'created_authorization_version': authorizationVersion,
        'started_at': startedAt.toUtc().toIso8601String(),
        'retry_decision': SyncRetryDecision.pending.storageValue,
      },
      conflictAlgorithm: ConflictAlgorithm.ignore,
    );
  }

  /// Closes the open attempt for an operation inside the settlement
  /// transaction. Never rewrites an attempt that is already closed, so
  /// earlier failures survive a later success.
  Future<void> _closeSyncAttempt(
    DatabaseExecutor txn, {
    required String clientOpId,
    required int attemptNumber,
    required SyncAttemptClosure closure,
    required DateTime finishedAt,
  }) async {
    final rows = await txn.query(
      'sync_attempts',
      columns: ['started_at'],
      where: 'client_op_id = ? AND attempt_number = ? AND finished_at IS NULL',
      whereArgs: [clientOpId, attemptNumber],
      limit: 1,
    );
    if (rows.isEmpty) return;
    final startedAt = DateTime.tryParse('${rows.first['started_at']}')?.toUtc();
    final finished = finishedAt.toUtc();
    final duration =
        startedAt == null ? null : finished.difference(startedAt).inMilliseconds;
    await txn.update(
      'sync_attempts',
      {
        'finished_at': finished.toIso8601String(),
        'duration_ms': duration != null && duration >= 0 ? duration : null,
        'http_status': closure.httpStatus,
        'error_category': closure.category.storageValue,
        'retry_decision': closure.decision.storageValue,
        'failure_message': _safeAttemptMessage(closure.failureMessage),
        'next_attempt_at': closure.nextAttemptAt?.toUtc().toIso8601String(),
        'server_ref': closure.serverRef,
      },
      where: 'client_op_id = ? AND attempt_number = ? AND finished_at IS NULL',
      whereArgs: [clientOpId, attemptNumber],
    );
  }

  /// Diagnostic messages are bounded and must never carry a payload.
  static String? _safeAttemptMessage(String? message) {
    if (message == null) return null;
    final trimmed = message.trim();
    if (trimmed.isEmpty) return null;
    return trimmed.length <= 200 ? trimmed : trimmed.substring(0, 200);
  }

  /// Marks attempts whose process disappeared. Called by the same recovery
  /// sweep that releases orphaned `in_flight` rows, so an interrupted
  /// transmission is never left looking like it is still running — and never
  /// looking like it succeeded.
  Future<void> _interruptOpenSyncAttempts(
    DatabaseExecutor txn,
    DateTime now,
  ) async {
    await txn.update(
      'sync_attempts',
      {
        'finished_at': now.toUtc().toIso8601String(),
        'retry_decision': SyncRetryDecision.interrupted.storageValue,
        'error_category': SyncErrorCategory.unknown.storageValue,
        'failure_message': 'The app closed before this attempt finished.',
      },
      where: 'finished_at IS NULL',
    );
  }

  /// Full history of one logical operation, oldest attempt first.
  Future<SyncOperationLineage?> operationLineage(String clientOpId) async {
    final db = await database;
    if (!await _tableExists(db, 'sync_attempts')) return null;
    final rows = await db.query(
      'sync_attempts',
      where: 'client_op_id = ?',
      whereArgs: [clientOpId],
      orderBy: 'attempt_number',
    );
    if (rows.isEmpty) return null;
    return SyncOperationLineage(
      clientOpId: clientOpId,
      domain: '${rows.first['domain']}',
      attempts: rows.map(SyncAttemptRecord.fromRow).toList(growable: false),
    );
  }

  /// Most recently active operations, newest first, each with full lineage.
  Future<List<SyncOperationLineage>> recentOperationLineages({
    int limit = 50,
  }) async {
    final db = await database;
    if (!await _tableExists(db, 'sync_attempts')) {
      return const <SyncOperationLineage>[];
    }
    final bounded = limit < 1 ? 1 : (limit > 500 ? 500 : limit);
    final heads = await db.rawQuery(
      'SELECT client_op_id, MAX(started_at) AS last_started '
      'FROM sync_attempts GROUP BY client_op_id '
      'ORDER BY last_started DESC, client_op_id DESC LIMIT ?',
      [bounded],
    );
    final lineages = <SyncOperationLineage>[];
    for (final head in heads) {
      final lineage = await operationLineage('${head['client_op_id']}');
      if (lineage != null) lineages.add(lineage);
    }
    return lineages;
  }

  /// Bounded growth guard.
  ///
  /// This is a capacity limit, not a retention policy: it keeps the newest
  /// [keepOperations] operations so the ledger cannot grow without end on a
  /// long-lived install. A dated retention policy is deliberately out of
  /// scope here. Operations with an attempt still open are never pruned.
  Future<int> pruneSyncAttempts({int keepOperations = 500}) async {
    final db = await database;
    if (!await _tableExists(db, 'sync_attempts')) return 0;
    final keep = keepOperations < 1 ? 1 : keepOperations;
    return db.rawDelete(
      'DELETE FROM sync_attempts WHERE client_op_id NOT IN ('
      '  SELECT client_op_id FROM sync_attempts'
      '  GROUP BY client_op_id'
      '  ORDER BY MAX(started_at) DESC, client_op_id DESC'
      '  LIMIT ?'
      ') AND client_op_id NOT IN ('
      '  SELECT DISTINCT client_op_id FROM sync_attempts WHERE finished_at IS NULL'
      ')',
      [keep],
    );
  }

  /// Public for startup orchestration and deterministic recovery tests.
  Future<void> recoverOrphanedInFlightOperations() async {
    final db = await database;
    await _recoverOrphanedInFlightWithDb(db);
  }

  Future<void> _recoverOrphanedInFlightWithDb(Database db) async {
    final now = DateTime.now().toUtc().toIso8601String();
    final existingLegacy = <LegacyOutboxTableSpec>[];
    for (final spec in legacyOutboxTableSpecs) {
      if (await _tableExists(db, spec.table)) existingLegacy.add(spec);
    }
    final hasHymn = await _tableExists(db, 'pending_hymn_ops');
    final hasComm = await _tableExists(db, 'comm_outbox');
    final hasAttemptLedger = await _tableExists(db, 'sync_attempts');
    await db.transaction((txn) async {
      for (final spec in existingLegacy) {
        await txn.rawUpdate(
          "UPDATE ${spec.table} SET sync_state = 'retry_wait', "
          'next_attempt_at = ? '
          "WHERE synced = 0 AND sync_state = 'in_flight'",
          [now],
        );
      }
      if (hasHymn) {
        await txn.rawUpdate(
          "UPDATE pending_hymn_ops SET sync_state = 'retry_wait', "
          'next_attempt_at = ? '
          "WHERE synced = 0 AND sync_state = 'in_flight'",
          [now],
        );
      }
      if (hasComm) {
        await txn.rawUpdate(
          "UPDATE comm_outbox SET state = 'retry_wait', next_attempt_at = ? "
          "WHERE state = 'in_flight'",
          [now],
        );
      }
      // S1 lineage: an attempt whose process vanished must not keep looking
      // like it is still running, and must never be mistaken for a success.
      if (hasAttemptLedger) {
        await _interruptOpenSyncAttempts(txn, DateTime.parse(now));
      }
    });
  }

  /// Communication offline tables (schema v26, offline-first O1).
  /// Kept in one place so onCreate and onUpgrade stay identical —
  /// every statement is CREATE ... IF NOT EXISTS, so running it on an
  /// upgraded device is a no-op. Threads/messages/outbox/drafts/meta
  /// mirror the v1 API rows; the pure row<->JSON mappers and merge
  /// rules live in comm_store.dart / messaging_view_model.dart.
  Future<void> _createCommTables(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS comm_threads (
        id INTEGER PRIMARY KEY,
        subject TEXT NOT NULL DEFAULT '',
        participants_label TEXT,
        last_body TEXT,
        last_message_at TEXT,
        unread_count INTEGER NOT NULL DEFAULT 0,
        message_count INTEGER NOT NULL DEFAULT 0,
        created_at TEXT,
        fetched_at TEXT
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS comm_messages (
        id INTEGER PRIMARY KEY,
        thread_id INTEGER NOT NULL,
        sender_id INTEGER,
        sender_name TEXT,
        sender_label TEXT,
        body TEXT NOT NULL DEFAULT '',
        created_at TEXT,
        edited INTEGER NOT NULL DEFAULT 0,
        deleted INTEGER NOT NULL DEFAULT 0,
        mine INTEGER NOT NULL DEFAULT 0,
        client_tag TEXT
      )
    ''');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_comm_messages_thread ON comm_messages(thread_id, id)');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS comm_outbox (
        client_tag TEXT PRIMARY KEY,
        thread_id INTEGER NOT NULL,
        body TEXT NOT NULL,
        state TEXT NOT NULL DEFAULT 'pending',
        attempts INTEGER NOT NULL DEFAULT 0,
        next_attempt_at TEXT,
        created_at TEXT NOT NULL,
        fail_reason TEXT,
        last_attempt_at TEXT,
        failure_code TEXT,
        failure_http_status INTEGER,
        failed_at TEXT,
        owner_user_id INTEGER,
        created_authorization_version INTEGER
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS comm_drafts (
        thread_id INTEGER PRIMARY KEY,
        body TEXT NOT NULL DEFAULT '',
        updated_at TEXT,
        owner_user_id INTEGER,
        created_authorization_version INTEGER
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS comm_meta (
        key TEXT PRIMARY KEY,
        value TEXT
      )
    ''');
  }

  /// Hymn-library offline tables (schema v11). Kept in one place so
  /// onCreate and onUpgrade stay identical.
  Future<void> _createHymnTables(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_hymns (
        id INTEGER PRIMARY KEY,
        title TEXT NOT NULL DEFAULT '',
        category TEXT,
        lyrics TEXT,
        status TEXT NOT NULL DEFAULT 'active',
        length TEXT NOT NULL DEFAULT 'long',
        language TEXT NOT NULL DEFAULT 'amharic',
        revision INTEGER NOT NULL DEFAULT 1,
        server_updated_at TEXT,
        fetched_at TEXT,
        audio_status TEXT NOT NULL DEFAULT 'none',
        audio_url TEXT,
        audio_format TEXT,
        audio_size INTEGER,
        audio_duration_s INTEGER,
        audio_updated_at TEXT,
        lyrics_synced TEXT,
        lyrics_synced_at TEXT,
        art_status TEXT NOT NULL DEFAULT 'none',
        art_color TEXT,
        art_url TEXT,
        art_url_medium TEXT,
        art_url_small TEXT
      )
    ''');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_cached_hymns_title ON cached_hymns (title)');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_cached_hymns_category ON cached_hymns (category)');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_mezmur_categories (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        parent_id INTEGER NULL,
        image_url TEXT NULL,
        gradient_start TEXT NULL,
        gradient_end TEXT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_mezmur_zemarians (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        name_am TEXT,
        image_url TEXT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_hymn_categories (
        hymn_id INTEGER NOT NULL,
        category_id INTEGER NOT NULL,
        PRIMARY KEY (hymn_id, category_id)
      )
    ''');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_chc_category ON cached_hymn_categories (category_id)');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_hymn_zemarians (
        hymn_id INTEGER NOT NULL,
        zemarian_id INTEGER NOT NULL,
        PRIMARY KEY (hymn_id, zemarian_id)
      )
    ''');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_chz_zemarian ON cached_hymn_zemarians (zemarian_id)');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS pending_hymn_ops (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        op TEXT NOT NULL,
        payload_json TEXT NOT NULL,
        client_op_id TEXT,
        created_at TEXT NOT NULL,
        synced INTEGER NOT NULL DEFAULT 0,
        synced_at TEXT,
        sync_error TEXT,
        sync_state TEXT NOT NULL DEFAULT 'pending',
        attempt_count INTEGER NOT NULL DEFAULT 0,
        next_attempt_at TEXT,
        last_attempt_at TEXT,
        failure_code TEXT,
        failure_http_status INTEGER,
        failed_at TEXT,
        created_authorization_version INTEGER,
        created_by_user_id INTEGER,
        entity_key TEXT,
        depends_on INTEGER
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_sync_meta (
        key TEXT PRIMARY KEY,
        value TEXT
      )
    ''');
    await _createHymnSearchIndex(db);
    await _createDownloadTables(db);
  }

  // ══════════════════════════════════════════════════════════════
  // P33 — offline audio downloads (Streaming model)
  // ══════════════════════════════════════════════════════════════
  // One row per hymn the user asked to keep offline. The AUDIO BYTES
  // live on the filesystem (app support dir, excluded from backup);
  // this table is the durable index the player consults BEFORE it
  // ever asks the network for a signed URL.
  //
  //   state: queued | downloading | done | failed | paused
  //
  // `source` records WHY the file is on the device:
  //   'user'  — explicitly pinned (never auto-evicted)
  //   'auto'  — smart/bulk download (evictable under a storage cap)
  //
  // `etag` + `audio_updated_at` let a delta pull notice the server
  // replaced the object and re-download instead of playing stale audio.
  Future<void> _createDownloadTables(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_downloads (
        hymn_id INTEGER PRIMARY KEY,
        state TEXT NOT NULL DEFAULT 'queued',
        source TEXT NOT NULL DEFAULT 'user',
        file_path TEXT,
        bytes_total INTEGER NOT NULL DEFAULT 0,
        bytes_done INTEGER NOT NULL DEFAULT 0,
        audio_format TEXT,
        audio_updated_at TEXT,
        etag TEXT,
        sha256 TEXT,
        error TEXT,
        attempts INTEGER NOT NULL DEFAULT 0,
        queued_at TEXT,
        completed_at TEXT,
        last_played_at TEXT
      )
    ''');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_hymn_downloads_state ON hymn_downloads (state)');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_hymn_downloads_source ON hymn_downloads (source, last_played_at)');
    // Collection-level pins ("download this category / this singer"),
    // so newly-synced hymns inside a pinned collection auto-download
    // the way a Streaming playlist keeps itself current.
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_download_pins (
        kind TEXT NOT NULL,
        ref_id INTEGER NOT NULL,
        label TEXT,
        created_at TEXT,
        PRIMARY KEY (kind, ref_id)
      )
    ''');
  }

  // ── downloads: reads ────────────────────────────────────────

  /// Every download row, newest completion first — powers the
  /// "Downloads" management screen.
  Future<List<Map<String, dynamic>>> downloadRows() async {
    final db = await database;
    return db.rawQuery('''
      SELECT d.*, h.title AS title, h.category AS category,
             h.audio_duration_s AS audio_duration_s
        FROM hymn_downloads d
        LEFT JOIN cached_hymns h ON h.id = d.hymn_id
       ORDER BY (d.state = 'done') DESC, d.completed_at DESC, d.queued_at DESC
    ''');
  }

  Future<Map<String, dynamic>?> downloadRow(int hymnId) async {
    final db = await database;
    final r = await db.query('hymn_downloads',
        where: 'hymn_id = ?', whereArgs: [hymnId], limit: 1);
    return r.isEmpty ? null : r.first;
  }

  /// hymn_id → state, for painting list badges in one query.
  Future<Map<int, String>> downloadStates() async {
    final db = await database;
    final rows = await db.query('hymn_downloads',
        columns: ['hymn_id', 'state']);
    return {
      for (final r in rows) (r['hymn_id'] as int): '${r['state']}',
    };
  }

  /// Local file for a hymn, but only when the download actually finished.
  Future<String?> downloadedPath(int hymnId) async {
    final db = await database;
    final r = await db.query('hymn_downloads',
        columns: ['file_path'],
        where: "hymn_id = ? AND state = 'done'",
        whereArgs: [hymnId],
        limit: 1);
    if (r.isEmpty) return null;
    final p = '${r.first['file_path'] ?? ''}';
    return p.isEmpty ? null : p;
  }

  Future<List<Map<String, dynamic>>> pendingDownloads({int limit = 50}) async {
    final db = await database;
    return db.query('hymn_downloads',
        where: "state IN ('queued','downloading')",
        orderBy: 'queued_at ASC',
        limit: limit);
  }

  Future<int> downloadedBytes() async {
    final db = await database;
    final r = await db.rawQuery(
        "SELECT COALESCE(SUM(bytes_done), 0) AS n FROM hymn_downloads WHERE state = 'done'");
    return (r.first['n'] as num?)?.toInt() ?? 0;
  }

  Future<int> downloadedCount() async {
    final db = await database;
    final r = await db.rawQuery(
        "SELECT COUNT(*) AS n FROM hymn_downloads WHERE state = 'done'");
    return (r.first['n'] as num?)?.toInt() ?? 0;
  }

  /// Auto-downloaded rows, least-recently-played first — the eviction
  /// order when the user's storage cap is exceeded. User-pinned rows
  /// are never returned.
  Future<List<Map<String, dynamic>>> evictionCandidates() async {
    final db = await database;
    return db.query('hymn_downloads',
        where: "state = 'done' AND source = 'auto'",
        orderBy: "COALESCE(last_played_at, completed_at, '') ASC");
  }

  // ── downloads: writes ───────────────────────────────────────

  Future<void> enqueueDownload(int hymnId,
      {String source = 'user', String? audioUpdatedAt, String? format}) async {
    final db = await database;
    final now = DateTime.now().toIso8601String();
    // A row already 'done' and still current must not be reset to
    // 'queued' — that would re-download the whole library on a re-pin.
    final existing = await downloadRow(hymnId);
    if (existing != null &&
        '${existing['state']}' == 'done' &&
        '${existing['audio_updated_at'] ?? ''}' == '${audioUpdatedAt ?? ''}') {
      if (source == 'user' && '${existing['source']}' != 'user') {
        await db.update('hymn_downloads', {'source': 'user'},
            where: 'hymn_id = ?', whereArgs: [hymnId]);
      }
      return;
    }
    await db.insert(
      'hymn_downloads',
      {
        'hymn_id': hymnId,
        'state': 'queued',
        'source': source,
        'audio_updated_at': audioUpdatedAt,
        'audio_format': format,
        'bytes_done': 0,
        'error': null,
        'attempts': 0,
        'queued_at': now,
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<void> markDownloadState(int hymnId, String state,
      {String? filePath,
      int? bytesTotal,
      int? bytesDone,
      String? etag,
      String? sha256,
      String? error,
      bool bumpAttempts = false}) async {
    final db = await database;
    final data = <String, Object?>{'state': state};
    if (filePath != null) data['file_path'] = filePath;
    if (bytesTotal != null) data['bytes_total'] = bytesTotal;
    if (bytesDone != null) data['bytes_done'] = bytesDone;
    if (etag != null) data['etag'] = etag;
    if (sha256 != null) data['sha256'] = sha256;
    data['error'] = error;
    if (state == 'done') {
      data['completed_at'] = DateTime.now().toIso8601String();
      data['error'] = null;
    }
    if (bumpAttempts) {
      await db.rawUpdate(
          'UPDATE hymn_downloads SET attempts = attempts + 1 WHERE hymn_id = ?',
          [hymnId]);
    }
    await db.update('hymn_downloads', data,
        where: 'hymn_id = ?', whereArgs: [hymnId]);
  }

  Future<void> updateDownloadProgress(int hymnId, int done, int total) async {
    final db = await database;
    await db.update('hymn_downloads', {'bytes_done': done, 'bytes_total': total},
        where: 'hymn_id = ?', whereArgs: [hymnId]);
  }

  Future<void> touchDownloadPlayed(int hymnId) async {
    final db = await database;
    await db.update(
        'hymn_downloads', {'last_played_at': DateTime.now().toIso8601String()},
        where: 'hymn_id = ?', whereArgs: [hymnId]);
  }

  Future<void> deleteDownloadRow(int hymnId) async {
    final db = await database;
    await db.delete('hymn_downloads', where: 'hymn_id = ?', whereArgs: [hymnId]);
  }

  /// Rows whose server-side audio changed since the file was stored —
  /// the delta-sync hook that keeps offline copies honest.
  Future<List<Map<String, dynamic>>> staleDownloads() async {
    final db = await database;
    return db.rawQuery('''
      SELECT d.hymn_id, h.audio_updated_at AS server_updated, d.source
        FROM hymn_downloads d
        JOIN cached_hymns h ON h.id = d.hymn_id
       WHERE d.state = 'done'
         AND IFNULL(h.audio_updated_at, '') <> IFNULL(d.audio_updated_at, '')
    ''');
  }

  // ── collection pins ─────────────────────────────────────────

  Future<void> addDownloadPin(String kind, int refId, String label) async {
    final db = await database;
    await db.insert(
        'hymn_download_pins',
        {
          'kind': kind,
          'ref_id': refId,
          'label': label,
          'created_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<void> removeDownloadPin(String kind, int refId) async {
    final db = await database;
    await db.delete('hymn_download_pins',
        where: 'kind = ? AND ref_id = ?', whereArgs: [kind, refId]);
  }

  Future<List<Map<String, dynamic>>> downloadPins() async {
    final db = await database;
    return db.query('hymn_download_pins', orderBy: 'created_at DESC');
  }

  Future<bool> hasDownloadPin(String kind, int refId) async {
    final db = await database;
    final r = await db.query('hymn_download_pins',
        where: 'kind = ? AND ref_id = ?', whereArgs: [kind, refId], limit: 1);
    return r.isNotEmpty;
  }

  /// Ready-to-play hymn ids inside a pinned collection — used to top up
  /// downloads after a delta sync adds hymns to a pinned category.
  Future<List<Map<String, dynamic>>> readyAudioHymnsIn(
      {int? categoryId, int? zemarianId}) async {
    final db = await database;
    if (categoryId != null) {
      return db.rawQuery('''
        SELECT h.id, h.audio_updated_at, h.audio_format
          FROM cached_hymns h
          JOIN cached_hymn_categories c ON c.hymn_id = h.id
         WHERE c.category_id = ? AND h.audio_status = 'ready'
               AND h.status <> 'archived'
      ''', [categoryId]);
    }
    if (zemarianId != null) {
      return db.rawQuery('''
        SELECT h.id, h.audio_updated_at, h.audio_format
          FROM cached_hymns h
          JOIN cached_hymn_zemarians z ON z.hymn_id = h.id
         WHERE z.zemarian_id = ? AND h.audio_status = 'ready'
               AND h.status <> 'archived'
      ''', [zemarianId]);
    }
    return db.query('cached_hymns',
        columns: ['id', 'audio_updated_at', 'audio_format'],
        where: "audio_status = 'ready' AND status <> 'archived'");
  }

  /// P38: index metadata (analyzer stamp + rebuild flag) and the dirty
  /// queue that drives incremental repair.
  Future<void> _createSearchMetaTables(DatabaseExecutor db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_search_meta (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        analyzer_version INTEGER,
        rebuild_in_progress INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT
      )
    ''');
    await db.execute(
        'INSERT OR IGNORE INTO hymn_search_meta (id, analyzer_version, '
        'rebuild_in_progress) VALUES (1, NULL, 0)');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_search_dirty (
        hymn_id INTEGER PRIMARY KEY,
        queued_at TEXT
      )
    ''');
  }

  /// P39: trigram index for SUBSTRING retrieval.
  ///
  /// `word LIKE 'term%'` can only find prefixes, so any Amharic word
  /// carrying a grammatical prefix (በሰላም for ሰላም) was unfindable. A
  /// `LIKE '%term%'` scan would find it but cannot use an index. The
  /// standard fix is an n-gram index: look candidates up by trigram
  /// equality (indexed), then verify exactly in Dart.
  Future<void> _createHymnTrigramIndex(DatabaseExecutor db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_search_grams (
        gram TEXT NOT NULL,
        hymn_id INTEGER NOT NULL,
        PRIMARY KEY (gram, hymn_id)
      )
    ''');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_hymn_grams_gram ON hymn_search_grams (gram)');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_hymn_grams_hymn ON hymn_search_grams (hymn_id)');
  }

  Future<void> _createHymnSearchIndex(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hymn_search_words (
        word TEXT NOT NULL,
        hymn_id INTEGER NOT NULL,
        PRIMARY KEY (word, hymn_id)
      )
    ''');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_hymn_search_words_word ON hymn_search_words (word)');
    await db.execute(
        'CREATE INDEX IF NOT EXISTS idx_hymn_search_words_hymn ON hymn_search_words (hymn_id)');
    await _createSearchMetaTables(db);
    await _createHymnTrigramIndex(db);
  }

  // ---------------------------------------------------------------
  // P38: self-healing search index.
  // ---------------------------------------------------------------

  /// Reads the current index state (analyzer stamp, rebuild flag,
  /// dirty backlog).
  Future<IndexState> searchIndexState() async {
    final db = await database;
    try {
      final rows = await db.query('hymn_search_meta',
          where: 'id = 1', limit: 1);
      final dirty = _asIntLocal((await db.rawQuery(
              'SELECT COUNT(*) c FROM hymn_search_dirty'))
          .first['c']);
      if (rows.isEmpty) return IndexState(dirtyCount: dirty);
      final r = rows.first;
      final v = r['analyzer_version'];
      return IndexState(
        stampedVersion: v == null ? null : _asIntLocal(v),
        rebuildInProgress: _asIntLocal(r['rebuild_in_progress']) == 1,
        dirtyCount: dirty,
      );
    } catch (_) {
      // Metadata unreadable => treat as never stamped, which forces a
      // rebuild rather than trusting an unknown index.
      return const IndexState();
    }
  }

  /// Marks hymns as needing reindexing. Cheap and idempotent, so callers
  /// can be liberal.
  Future<void> markHymnsDirty(Iterable<int> ids) async {
    final list = ids.where((i) => i > 0).toList();
    if (list.isEmpty) return;
    final db = await database;
    final now = DateTime.now().toIso8601String();
    final batch = db.batch();
    for (final id in list) {
      batch.insert(
        'hymn_search_dirty',
        {'hymn_id': id, 'queued_at': now},
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    }
    await batch.commit(noResult: true);
  }

  /// Reindexes queued rows in bounded batches.
  ///
  /// A row is cleared from the queue only after its words are written,
  /// so an interruption leaves it queued and it is retried next time —
  /// never silently skipped.
  ///
  /// Returns how many hymns were reindexed.
  Future<int> processDirtySearchRows({int max = 200}) async {
    final db = await database;
    final queued = await db.query('hymn_search_dirty',
        columns: ['hymn_id'], orderBy: 'queued_at ASC', limit: max);
    if (queued.isEmpty) return 0;
    final ids =
        queued.map((r) => _asIntLocal(r['hymn_id'])).where((i) => i > 0).toList();
    var done = 0;
    for (final chunk in SearchIndexPolicy.batches(ids)) {
      for (final id in chunk) {
        final rows = await db.query('cached_hymns',
            columns: ['id', 'title', 'lyrics'],
            where: 'id = ?',
            whereArgs: [id],
            limit: 1);
        if (rows.isEmpty) {
          // The hymn was deleted; drop its index rows too so it cannot
          // linger as a phantom result.
          await db.delete('hymn_search_words',
              where: 'hymn_id = ?', whereArgs: [id]);
          await db.delete('hymn_search_grams',
              where: 'hymn_id = ?', whereArgs: [id]);
        } else {
          await _reindexHymnSearchIndex(db, rows.first);
        }
        await db.delete('hymn_search_dirty',
            where: 'hymn_id = ?', whereArgs: [id]);
        done++;
      }
    }
    return done;
  }

  /// The self-heal entry point: call on open and after each sync.
  ///
  /// Decides between a full rebuild (analyzer changed / interrupted /
  /// unstamped) and incremental repair of dirty rows. The stamp is
  /// written ONLY after a rebuild finishes, so a crash mid-rebuild is
  /// retried instead of being mistaken for success.
  Future<void> ensureSearchIndexFresh({bool userIsSearching = false}) async {
    final db = await database;
    final state = await searchIndexState();
    final action = SearchIndexPolicy.decide(state);

    if (action == IndexAction.fullRebuild) {
      if (!SearchIndexPolicy.mayRebuildNow(
        appIsForeground: true,
        userIsSearching: userIsSearching,
      )) {
        // Defer the full pass, but still repair what we can so the rows
        // the user is touching stay correct.
        await processDirtySearchRows();
        return;
      }
      await db.update('hymn_search_meta', {'rebuild_in_progress': 1},
          where: 'id = 1');
      await _rebuildHymnSearchIndex(db);
      await db.delete('hymn_search_dirty');
      await db.update(
          'hymn_search_meta',
          {
            'analyzer_version': kAnalyzerVersion,
            'rebuild_in_progress': 0,
            'updated_at': DateTime.now().toIso8601String(),
          },
          where: 'id = 1');
      return;
    }
    await processDirtySearchRows();
  }

  Future<void> _rebuildHymnSearchIndex(Database db) async {
    await db.transaction((txn) async {
      await txn.delete('hymn_search_words');
      await txn.delete('hymn_search_grams');
      final rows = await txn.query('cached_hymns',
          columns: ['id', 'title', 'lyrics']);
      for (final row in rows) {
        await _reindexHymnSearchIndex(txn, row);
      }
    });
  }

  Future<void> _reindexHymnSearchIndex(
      DatabaseExecutor db, Map<String, dynamic> hymn) async {
    final id = _asIntLocal(hymn['id']);
    if (id <= 0) return;
    await db.delete('hymn_search_words', where: 'hymn_id = ?', whereArgs: [id]);
    // P28: single title — the index feeds from title + lyrics only.
    // Tokenizer contract: length >= 2 and RegExp(r'[\p{L}\p{M}\p{N}]+', unicode: true)
    // P37: normalisation now folds Amharic homophones, so a member who
    // types ጸሀይ finds a hymn stored as ፀሐይ. Index and query MUST use
    // the same normaliser or nothing matches.
    final words = <String>{
      ...amharic.indexWords('${hymn['title'] ?? ''}'),
      ...amharic.indexWords('${hymn['lyrics'] ?? ''}'),
    };
    await db.delete('hymn_search_grams', where: 'hymn_id = ?', whereArgs: [id]);
    final batch = db.batch();
    for (final word in words) {
      batch.insert('hymn_search_words', {'word': word, 'hymn_id': id});
    }
    // P39: trigrams enable SUBSTRING retrieval. Deduped across the whole
    // hymn, so a repeated word costs nothing extra.
    final grams = <String>{};
    for (final word in words) {
      grams.addAll(SearchMatching.gramsOf(word));
    }
    for (final g in grams) {
      batch.insert('hymn_search_grams', {'gram': g, 'hymn_id': id});
    }
    await batch.commit(noResult: true);
  }

  /// P1-B (DB v27): local-first Notification Center store. Two
  /// tables because the two server feeds have different contracts —
  /// alerts page by id DESC / before_id; announcements by
  /// (is_pinned, id) DESC with the server-authoritative expires_at.
  Future<void> _createNotificationTables(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_notifications (
        id INTEGER PRIMARY KEY,
        is_unread INTEGER NOT NULL DEFAULT 1,
        data_json TEXT,
        fetched_at TEXT
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_announcements (
        id INTEGER PRIMARY KEY,
        is_pinned INTEGER NOT NULL DEFAULT 0,
        is_unread INTEGER NOT NULL DEFAULT 1,
        expires_at TEXT,
        data_json TEXT,
        fetched_at TEXT
      )
    ''');
  }

  /// P1-C (DB v28): local-first Mezmur Home store — the
  /// department-wide attendance-day aggregate from GET /mezmur/days,
  /// stored verbatim. Days never disappear server-side (no delete
  /// path) and attendance_date is UNIQUE server-side, so merge-upsert
  /// by server id is safe. marked/attended are server-authoritative
  /// aggregates — never derived from cached_mezmur_sheet (which only
  /// holds (date, section) pairs visited on this phone).
  Future<void> _createMezmurDaysTable(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_mezmur_days (
        id INTEGER PRIMARY KEY,
        attendance_date TEXT NOT NULL,
        marked INTEGER NOT NULL DEFAULT 0,
        attended INTEGER NOT NULL DEFAULT 0,
        data_json TEXT,
        fetched_at TEXT
      )
    ''');
  }

  /// P1-D (DB v29): local-first Review Inbox read model. Identity is
  /// (dept, id) — the three departments' submission tables are
  /// independent server tables, so the same numeric id can exist in
  /// more than one department context. Status stays a discrete
  /// verbatim column (the server's per-filter windows query it);
  /// updated_at (server string, verbatim) reproduces every service's
  /// ORDER BY updated_at DESC, id DESC. Details live in their own
  /// table so a list refresh can never clobber a cached detail's
  /// roster rows; stats are stored verbatim per department and are
  /// NEVER recomputed locally (server stats cover a different
  /// population/window than the cached rows).
  Future<void> _createReviewTables(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_review_packets (
        dept TEXT NOT NULL,
        id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT '',
        updated_at TEXT,
        data_json TEXT,
        fetched_at TEXT,
        PRIMARY KEY (dept, id)
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_review_packet_details (
        dept TEXT NOT NULL,
        id INTEGER NOT NULL,
        data_json TEXT,
        fetched_at TEXT,
        PRIMARY KEY (dept, id)
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_review_stats (
        dept TEXT NOT NULL PRIMARY KEY,
        stats_json TEXT,
        fetched_at TEXT
      )
    ''');
  }

  /// P1-E (DB v30): local-first Education Classes read model — its
  /// OWN tables, never the teacher workflow's cached_classes /
  /// cached_students (protected shared caches: CatalogService's
  /// destructive full replace, three cacheStudents writers, no
  /// level_order column, no roster-year contract). The class list is
  /// a complete scoped snapshot replaced on every successful refresh
  /// (deletion-aware: renames, deactivations, eligible hard deletes
  /// all propagate); local ordering reproduces the server's
  /// `level_order, class_name`. Each roster is ONE row per class_id
  /// holding the server response verbatim — including the
  /// year-resolution metadata (roster_year_id / roster_year_name /
  /// roster_fallback), which is server contract and is never
  /// reconstructed locally.
  Future<void> _createEduTables(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_edu_classes (
        id INTEGER PRIMARY KEY,
        class_name TEXT NOT NULL DEFAULT '',
        class_name_en TEXT,
        level_order INTEGER NOT NULL DEFAULT 0,
        student_count INTEGER NOT NULL DEFAULT 0,
        data_json TEXT,
        fetched_at TEXT
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_edu_class_rosters (
        class_id INTEGER PRIMARY KEY,
        roster_year_id INTEGER,
        roster_year_name TEXT,
        roster_fallback INTEGER NOT NULL DEFAULT 0,
        data_json TEXT,
        fetched_at TEXT
      )
    ''');
  }

  /// P1-F (DB v31): local-first Education Subjects read model — the
  /// complete active subject catalog from GET /subjects, one row per
  /// subject, payload verbatim (including the class_count aggregate,
  /// a server count over class_subjects that is NEVER recomputed
  /// locally). Deliberately SEPARATE from cached_subjects: that table
  /// is the teacher grade-bootstrap cache ((id, class_id) composite
  /// PK, written from /grades/bootstrap) and belongs to the protected
  /// teacher workflow. The catalog is a complete scoped snapshot
  /// replaced on every successful refresh (deletion-aware: renames,
  /// deactivations and eligible hard deletes propagate); local
  /// ordering reproduces the server's ORDER BY subject_name —
  /// COLLATE NOCASE approximates MySQL utf8mb4_unicode_ci's ASCII
  /// case fold (Amharic orders code-point-identically; the residual
  /// case-mixed Latin edge is a disclosed cosmetic limitation).
  Future<void> _createEduSubjectsTable(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_edu_subjects (
        id INTEGER PRIMARY KEY,
        subject_name TEXT NOT NULL DEFAULT '',
        subject_name_en TEXT,
        subject_code TEXT,
        class_count INTEGER NOT NULL DEFAULT 0,
        data_json TEXT,
        fetched_at TEXT
      )
    ''');
  }

  /// P1-G: Education Teachers uses its OWN read model. The singleton
  /// snapshot row records a valid complete fetch even when zero teachers
  /// exist; sort_order preserves the server's cross-page ordering; details
  /// are scoped by the exact server-resolved academic year. Deliberately
  /// separate from cached_members and the teacher workflow's destructive
  /// cached_classes/cached_students/cached_subjects stores.
  Future<void> _createEduTeachersTables(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_edu_teacher_snapshot (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        academic_year_id INTEGER NOT NULL DEFAULT 0,
        academic_year_name TEXT,
        total INTEGER NOT NULL DEFAULT 0,
        fetched_at TEXT NOT NULL
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_edu_teachers (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL DEFAULT '',
        full_name TEXT NOT NULL DEFAULT '',
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT,
        assigned_classes INTEGER NOT NULL DEFAULT 0,
        assigned_subjects INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL,
        data_json TEXT NOT NULL,
        fetched_at TEXT NOT NULL
      )
    ''');
    await db.execute('''
      CREATE INDEX IF NOT EXISTS idx_cached_edu_teachers_sort
      ON cached_edu_teachers (sort_order)
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_edu_teacher_details (
        teacher_id INTEGER NOT NULL,
        academic_year_id INTEGER NOT NULL DEFAULT 0,
        academic_year_name TEXT,
        data_json TEXT NOT NULL,
        fetched_at TEXT NOT NULL,
        PRIMARY KEY (teacher_id, academic_year_id)
      )
    ''');
  }

  /// P1-H: the LAST successfully validated Mezmur analytics view only.
  /// One row stores the member-page + section-rollup pair atomically. It is
  /// sensitive member attendance data and is wiped on logout; it never reuses
  /// sheet/day/section caches or overlays pending_mezmur writes.
  Future<void> _createMezmurAnalyticsTable(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_mezmur_analytics_last (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        from_date TEXT NOT NULL,
        to_date TEXT NOT NULL,
        sessions_held INTEGER NOT NULL DEFAULT 0,
        members_response_json TEXT NOT NULL,
        sections_response_json TEXT NOT NULL,
        fetched_at TEXT NOT NULL
      )
    ''');
  }

  Future<void> _createTables(Database db) async {
    await _createCommTables(db);
    await _createNotificationTables(db);
    await _createMezmurDaysTable(db);
    await _createReviewTables(db);
    await _createEduTables(db);
    await _createEduSubjectsTable(db);
    await _createEduTeachersTables(db);
    await _createMezmurAnalyticsTable(db);
    // ---- ATTENDANCE ----
    await db.execute('''
      CREATE TABLE pending_attendance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        class_id INTEGER NOT NULL,
        class_name TEXT,
        date TEXT NOT NULL,
        member_id INTEGER NOT NULL,
        student_name TEXT,
        father_name TEXT,
        member_code TEXT,
        status TEXT NOT NULL,
        notes TEXT,
        packet_kind TEXT NOT NULL DEFAULT 'draft',
        client_op_id TEXT,
        synced INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        synced_at TEXT,
        sync_error TEXT,
        sync_state TEXT NOT NULL DEFAULT 'pending',
        attempt_count INTEGER NOT NULL DEFAULT 0,
        next_attempt_at TEXT,
        last_attempt_at TEXT,
        failure_code TEXT,
        failure_http_status INTEGER,
        failed_at TEXT,
        created_authorization_version INTEGER,
        owner_user_id INTEGER
      )
    ''');

    // ---- GRADES ----
    await db.execute('''
      CREATE TABLE pending_mezmur (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL,
        program TEXT,
        section TEXT NOT NULL DEFAULT '',
        member_id INTEGER NOT NULL,
        status TEXT NOT NULL,
        notes TEXT,
        packet_kind TEXT NOT NULL DEFAULT 'draft',
        client_op_id TEXT,
        synced INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        synced_at TEXT,
        sync_error TEXT,
        sync_state TEXT NOT NULL DEFAULT 'pending',
        attempt_count INTEGER NOT NULL DEFAULT 0,
        next_attempt_at TEXT,
        last_attempt_at TEXT,
        failure_code TEXT,
        failure_http_status INTEGER,
        failed_at TEXT,
        created_authorization_version INTEGER,
        owner_user_id INTEGER
      )
    ''');
    await db.execute('''
      CREATE TABLE cached_mezmur_sheet (
        date TEXT NOT NULL,
        section TEXT NOT NULL DEFAULT '',
        response_json TEXT NOT NULL,
        updated_at TEXT,
        PRIMARY KEY (date, section)
      )
    ''');
    await db.execute('''
      CREATE TABLE cached_mezmur_sections (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        sections_json TEXT NOT NULL,
        updated_at TEXT
      )
    ''');
    // ---- HR ATTENDANCE (HR's own section-based domain) ----
    await db.execute('''
      CREATE TABLE pending_hr (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL,
        section TEXT NOT NULL DEFAULT '',
        member_id INTEGER NOT NULL,
        status TEXT NOT NULL,
        notes TEXT,
        packet_kind TEXT NOT NULL DEFAULT 'draft',
        client_op_id TEXT,
        synced INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        synced_at TEXT,
        sync_error TEXT,
        sync_state TEXT NOT NULL DEFAULT 'pending',
        attempt_count INTEGER NOT NULL DEFAULT 0,
        next_attempt_at TEXT,
        last_attempt_at TEXT,
        failure_code TEXT,
        failure_http_status INTEGER,
        failed_at TEXT,
        created_authorization_version INTEGER,
        owner_user_id INTEGER
      )
    ''');
    await db.execute('''
      CREATE TABLE cached_hr_sheet (
        date TEXT NOT NULL,
        section TEXT NOT NULL DEFAULT '',
        response_json TEXT NOT NULL,
        updated_at TEXT,
        PRIMARY KEY (date, section)
      )
    ''');
    await db.execute('''
      CREATE TABLE cached_hr_sections (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        sections_json TEXT NOT NULL,
        updated_at TEXT
      )
    ''');
    await db.execute('''
      CREATE TABLE pending_grades (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        assessment_id INTEGER NOT NULL,
        assessment_name TEXT,
        class_id INTEGER NOT NULL,
        class_name TEXT,
        subject_id INTEGER,
        subject_name TEXT,
        member_id INTEGER NOT NULL,
        student_name TEXT,
        record_id INTEGER,
        score REAL,
        remark TEXT,
        max_score REAL DEFAULT 100,
        packet_kind TEXT NOT NULL DEFAULT 'draft',
        client_op_id TEXT,
        synced INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        synced_at TEXT,
        sync_error TEXT,
        sync_state TEXT NOT NULL DEFAULT 'pending',
        attempt_count INTEGER NOT NULL DEFAULT 0,
        next_attempt_at TEXT,
        last_attempt_at TEXT,
        failure_code TEXT,
        failure_http_status INTEGER,
        failed_at TEXT,
        created_authorization_version INTEGER,
        owner_user_id INTEGER
      )
    ''');

    // ---- CACHE: CLASSES ----
    await db.execute('''
      CREATE TABLE cached_classes (
        id INTEGER PRIMARY KEY,
        class_name TEXT,
        class_name_en TEXT,
        section TEXT,
        student_count INTEGER DEFAULT 0,
        updated_at TEXT
      )
    ''');

    // ---- CACHE: STUDENTS ----
    await db.execute('''
      CREATE TABLE cached_students (
        member_id INTEGER NOT NULL,
        class_id INTEGER NOT NULL,
        student_name TEXT,
        father_name TEXT,
        member_code TEXT,
        gender TEXT,
        updated_at TEXT,
        PRIMARY KEY (member_id, class_id)
      )
    ''');

    // ---- CACHE: SUBJECTS ----
    await db.execute('''
      CREATE TABLE cached_subjects (
        id INTEGER NOT NULL,
        class_id INTEGER NOT NULL,
        subject_name TEXT,
        subject_name_en TEXT,
        subject_code TEXT,
        updated_at TEXT,
        PRIMARY KEY (id, class_id)
      )
    ''');

    // ---- CACHE: ASSESSMENTS ----
    await db.execute('''
      CREATE TABLE cached_assessments (
        id INTEGER PRIMARY KEY,
        class_id INTEGER NOT NULL,
        subject_id INTEGER NOT NULL,
        assessment_name TEXT,
        assessment_type TEXT,
        max_score REAL DEFAULT 100,
        weight_percentage REAL DEFAULT 100,
        grades_entered INTEGER DEFAULT 0,
        updated_at TEXT
      )
    ''');

    // ---- CACHE: DASHBOARD STATS ----
    await db.execute('''
      CREATE TABLE cached_dashboard (
        id INTEGER PRIMARY KEY DEFAULT 1,
        stats_json TEXT,
        role TEXT,
        updated_at TEXT
      )
    ''');

    // ---- CACHE: MEMBERS LIST ----
    await db.execute('''
      CREATE TABLE cached_members (
        id INTEGER PRIMARY KEY,
        student_name TEXT,
        father_name TEXT,
        member_code TEXT,
        gender TEXT,
        status TEXT,
        current_section TEXT,
        data_json TEXT,
        updated_at TEXT
      )
    ''');

    // ---- SYNC LOG ----
    await db.execute('''
      CREATE TABLE sync_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action TEXT,
        detail TEXT,
        status TEXT,
        created_at TEXT
      )
    ''');

    // ---- CACHE: ATTENDANCE RESPONSES (per class+date) ----
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_attendance (
        class_id INTEGER NOT NULL,
        date TEXT NOT NULL,
        response_json TEXT NOT NULL,
        updated_at TEXT,
        PRIMARY KEY (class_id, date)
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cached_grade_sheets (
        assessment_id INTEGER PRIMARY KEY,
        class_id INTEGER,
        response_json TEXT NOT NULL,
        updated_at TEXT
      )
    ''');

    // ---- HYMN LIBRARY (offline-first) ----
    await _createHymnTables(db);
  }

  // ============================================================
  // CACHED DASHBOARD STATS
  // ============================================================

  Future<void> cacheDashboardStats(Map<String, dynamic> stats, String role) async {
    final db = await database;
    await db.insert(
      'cached_dashboard',
      {
        'id': 1,
        'stats_json': jsonEncode(stats),
        'role': role,
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<Map<String, dynamic>?> getCachedDashboardStats() async {
    final db = await database;
    final rows = await db.query('cached_dashboard', where: 'id = 1');
    if (rows.isEmpty) return null;
    final row = rows.first;
    try {
      final stats = jsonDecode(row['stats_json'] as String) as Map<String, dynamic>;
      stats['_cached'] = true;
      stats['_cached_at'] = row['updated_at'];
      return stats;
    } catch (_) {
      return null;
    }
  }

  // ============================================================
  // CACHED MEMBERS LIST
  // ============================================================

  Future<void> cacheMembers(List<dynamic> members) async {
    final db = await database;
    final batch = db.batch();
    // Don't clear — merge (keep existing if new fetch is partial)
    for (final m in members) {
      batch.insert(
        'cached_members',
        {
          'id': m['id'],
          'student_name': m['student_name'] ?? '',
          'father_name': m['father_name'] ?? '',
          'member_code': m['member_code'] ?? '',
          'gender': m['gender'] ?? '',
          'status': m['status'] ?? 'active',
          'current_section': m['current_section'] ?? '',
          'data_json': jsonEncode(m),
          'updated_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    }
    await batch.commit(noResult: true);
  }

  Future<List<Map<String, dynamic>>> getCachedMembers({
    String? search,
    String? status,
    int limit = 50,
    int offset = 0, // P1-A: local pagination (offline scrolling)
  }) async {
    final db = await database;
    String where = '1=1';
    List<dynamic> whereArgs = [];

    if (search != null && search.isNotEmpty) {
      where += ' AND (student_name LIKE ? OR father_name LIKE ? OR member_code LIKE ?)';
      whereArgs.addAll(['%$search%', '%$search%', '%$search%']);
    }
    if (status != null && status.isNotEmpty) {
      where += ' AND status = ?';
      whereArgs.add(status);
    }

    final rows = await db.query(
      'cached_members',
      where: where,
      whereArgs: whereArgs,
      orderBy: 'student_name',
      limit: limit,
      offset: offset,
    );

    // Return full member data from JSON
    return rows.map((row) {
      try {
        return jsonDecode(row['data_json'] as String) as Map<String, dynamic>;
      } catch (_) {
        return <String, dynamic>{
          'id': row['id'],
          'student_name': row['student_name'],
          'father_name': row['father_name'],
          'member_code': row['member_code'],
          'gender': row['gender'],
          'status': row['status'],
          'current_section': row['current_section'],
        };
      }
    }).toList();
  }

  Future<int> getCachedMemberCount() async {
    final db = await database;
    final r = await db.rawQuery('SELECT COUNT(*) as cnt FROM cached_members');
    return r.first['cnt'] as int? ?? 0;
  }

  /// P1-A: newest cache-write timestamp across cached_members (null when
  /// nothing is cached) — drives the Members list's freshness line.
  /// Read-only; uses the column that already exists (no schema change).
  Future<String?> getCachedMembersLastSynced() async {
    final db = await database;
    final r = await db.rawQuery('SELECT MAX(updated_at) as m FROM cached_members');
    if (r.isEmpty) return null;
    final m = r.first['m'];
    return m == null ? null : '$m';
  }

  /// P1-A: single-member local read by PRIMARY KEY. Independent of the
  /// cached-list page size (the old detail fallback loaded the whole
  /// list with its default limit and could not find members past the
  /// first 50 rows). Returns the decoded server row plus a
  /// `local_updated_at` stamp for the cached-data banner; null when
  /// this member was never cached.
  Future<Map<String, dynamic>?> getCachedMemberById(int id) async {
    final db = await database;
    final rows = await db.query('cached_members',
        where: 'id = ?', whereArgs: [id], limit: 1);
    if (rows.isEmpty) return null;
    final row = rows.first;
    try {
      final decoded =
          jsonDecode(row['data_json'] as String) as Map<String, dynamic>;
      decoded['local_updated_at'] = row['updated_at'];
      return decoded;
    } catch (_) {
      // Corrupt/missing blob — the filter columns still identify the
      // member honestly (never fabricate fields we do not have).
      return <String, dynamic>{
        'id': row['id'],
        'student_name': row['student_name'],
        'father_name': row['father_name'],
        'member_code': row['member_code'],
        'gender': row['gender'],
        'status': row['status'],
        'current_section': row['current_section'],
        'local_updated_at': row['updated_at'],
      };
    }
  }

  // ============================================================
  // P1-B: CACHED NOTIFICATION CENTER (alerts + announcements)
  // ============================================================

  /// Merge-upsert feed rows by server id (ConflictAlgorithm.replace —
  /// the server is authoritative on refresh; alert rows never
  /// disappear server-side, so merge-only is safe). The optimistic
  /// read flip writes is_unread separately and the next successful
  /// refresh reconciles it.
  Future<void> cacheNotificationRows(List<Map<String, dynamic>> rows) async {
    final db = await database;
    final batch = db.batch();
    final now = DateTime.now().toIso8601String();
    for (final m in rows) {
      batch.insert(
        'cached_notifications',
        {
          'id': m['id'],
          'is_unread': (m['is_unread'] ?? 0) == 1 ? 1 : 0,
          'data_json': jsonEncode(m),
          'fetched_at': now,
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    }
    await batch.commit(noResult: true);
  }

  /// Local feed page — the server's exact ordering (id DESC) and
  /// cursor semantics (before_id → id < ?); [unreadOnly] mirrors the
  /// server-side unread filter. Rows come back shaped like the
  /// server's feed rows (is_unread from the local column, so
  /// optimistic flips survive restarts).
  Future<List<Map<String, dynamic>>> getCachedNotifications({
    bool unreadOnly = false,
    int? beforeId,
    int limit = 40,
  }) async {
    final db = await database;
    final where = <String>[
      if (unreadOnly) 'is_unread = 1',
      if (beforeId != null && beforeId > 0) 'id < ?',
    ];
    final rows = await db.query(
      'cached_notifications',
      where: where.isEmpty ? null : where.join(' AND '),
      whereArgs: [
        if (beforeId != null && beforeId > 0) beforeId,
      ],
      orderBy: 'id DESC',
      limit: limit,
    );
    return rows.map((row) {
      try {
        final decoded =
            jsonDecode(row['data_json'] as String) as Map<String, dynamic>;
        decoded['is_unread'] = (row['is_unread'] as int? ?? 0) == 1 ? 1 : 0;
        decoded['local_fetched_at'] = row['fetched_at'];
        return decoded;
      } catch (_) {
        // Corrupt/missing blob — identify the row honestly, never
        // fabricate content.
        return <String, dynamic>{
          'id': row['id'],
          'is_unread': (row['is_unread'] as int? ?? 0) == 1 ? 1 : 0,
          'local_fetched_at': row['fetched_at'],
        };
      }
    }).toList();
  }

  /// P1-B optimistic read persistence: the flip survives restart and
  /// offline browsing; the server reconciles on the next successful
  /// refresh (and reverts it there if the mark-read write never
  /// landed — the existing revert-by-refetch semantics).
  Future<void> markCachedNotificationRead(int id) async {
    final db = await database;
    await db.update('cached_notifications', {'is_unread': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  /// Merge-upsert announcement rows by server id; expires_at is
  /// stored verbatim (server-authoritative — no local TTL).
  Future<void> cacheAnnouncementRows(List<Map<String, dynamic>> rows) async {
    final db = await database;
    final batch = db.batch();
    final now = DateTime.now().toIso8601String();
    for (final m in rows) {
      batch.insert(
        'cached_announcements',
        {
          'id': m['id'],
          'is_pinned': (m['is_pinned'] ?? 0) == 1 ? 1 : 0,
          'is_unread': (m['is_unread'] ?? 0) == 1 ? 1 : 0,
          'expires_at': m['expires_at'],
          'data_json': jsonEncode(m),
          'fetched_at': now,
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    }
    await batch.commit(noResult: true);
  }

  /// Local announcements page — the server's exact ordering
  /// (is_pinned DESC, id DESC), its tuple cursor semantics, and its
  /// retention rule: a cached announcement whose stored server
  /// expires_at has passed is never displayed (and never modified —
  /// the next successful refresh stays authoritative).
  Future<List<Map<String, dynamic>>> getCachedAnnouncements({
    int? beforePin,
    int? beforeId,
    int limit = 40,
  }) async {
    final db = await database;
    final now = _localMysqlStyleNow();
    final where = <String>[
      "(expires_at IS NULL OR expires_at > '$now')",
    ];
    final args = <dynamic>[];
    if (beforeId != null && beforeId > 0 && beforePin != null) {
      // (is_pinned, id) < (beforePin, beforeId) — the server's tuple
      // cursor, written in the classic OR form (no row-value syntax
      // dependency).
      where.add('(is_pinned < ? OR (is_pinned = ? AND id < ?))');
      args..add(beforePin)..add(beforePin)..add(beforeId);
    }
    final rows = await db.query(
      'cached_announcements',
      where: where.join(' AND '),
      whereArgs: args,
      orderBy: 'is_pinned DESC, id DESC',
      limit: limit,
    );
    return rows.map((row) {
      try {
        final decoded =
            jsonDecode(row['data_json'] as String) as Map<String, dynamic>;
        decoded['is_unread'] = (row['is_unread'] as int? ?? 0) == 1 ? 1 : 0;
        decoded['is_pinned'] = (row['is_pinned'] as int? ?? 0) == 1 ? 1 : 0;
        decoded['local_fetched_at'] = row['fetched_at'];
        return decoded;
      } catch (_) {
        return <String, dynamic>{
          'id': row['id'],
          'is_unread': (row['is_unread'] as int? ?? 0) == 1 ? 1 : 0,
          'is_pinned': (row['is_pinned'] as int? ?? 0) == 1 ? 1 : 0,
          'local_fetched_at': row['fetched_at'],
        };
      }
    }).toList();
  }

  Future<void> markCachedAnnouncementRead(int id) async {
    final db = await database;
    await db.update('cached_announcements', {'is_unread': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  /// Device-local 'YYYY-MM-DD HH:MM:SS' — the server DATETIME string
  /// shape — for the offline expiry comparison. Device clock/TZ skew
  /// vs the server is the accepted edge (audit §22); the next
  /// successful refresh remains authoritative.
  static String _localMysqlStyleNow() {
    final n = DateTime.now();
    String p2(int v) => v.toString().padLeft(2, '0');
    return '${n.year}-${p2(n.month)}-${p2(n.day)} '
        '${p2(n.hour)}:${p2(n.minute)}:${p2(n.second)}';
  }

  // ============================================================
  // P1-C: CACHED MEZMUR HOME DAYS (department-wide aggregate)
  // ============================================================

  /// Merge-upsert the server's day rows by server id
  /// (ConflictAlgorithm.replace — merge-only; days never disappear
  /// server-side, so nothing is ever deleted here). Repeated
  /// refreshes can never duplicate a day or lose history.
  Future<void> cacheMezmurDays(List<Map<String, dynamic>> rows) async {
    final db = await database;
    final batch = db.batch();
    final now = DateTime.now().toIso8601String();
    for (final m in rows) {
      batch.insert(
        'cached_mezmur_days',
        {
          'id': m['id'],
          'attendance_date': '${m['attendance_date'] ?? ''}',
          'marked': _asIntLocal(m['marked']),
          'attended': _asIntLocal(m['attended']),
          'data_json': jsonEncode(m),
          'fetched_at': now,
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    }
    await batch.commit(noResult: true);
  }

  /// Local Mezmur Home page — the server's exact ordering
  /// (attendance_date DESC; yyyy-MM-dd strings sort identically to
  /// MySQL DATE). Rows come back shaped like the server's day items
  /// plus a local_fetched_at stamp for the '· updated HH:MM' banner.
  Future<List<Map<String, dynamic>>> getCachedMezmurDays(
      {int limit = 25}) async {
    final db = await database;
    final rows = await db.query('cached_mezmur_days',
        orderBy: 'attendance_date DESC', limit: limit);
    return rows.map((row) {
      try {
        final decoded =
            jsonDecode(row['data_json'] as String) as Map<String, dynamic>;
        decoded['local_fetched_at'] = row['fetched_at'];
        return decoded;
      } catch (_) {
        // Corrupt/missing blob — identify the row honestly from its
        // discrete columns, never fabricate content.
        return <String, dynamic>{
          'id': row['id'],
          'attendance_date': row['attendance_date'],
          'marked': row['marked'],
          'attended': row['attended'],
          'local_fetched_at': row['fetched_at'],
        };
      }
    }).toList();
  }

  // ============================================================
  // P1-D: CACHED REVIEW INBOX (read model, dept-scoped)
  // ============================================================

  /// Merge-upsert list rows by (dept, id). Merge-only: review
  /// packets never disappear through the review workflow itself
  /// (deletion exists only via web-console class/subject removal —
  /// disclosed deletion-blindness, same class as P1-B), so nothing
  /// is ever deleted here and repeated refreshes cannot duplicate.
  Future<void> cacheReviewPackets(
      String dept, List<Map<String, dynamic>> rows) async {
    final db = await database;
    final batch = db.batch();
    final now = DateTime.now().toIso8601String();
    for (final m in rows) {
      batch.insert(
        'cached_review_packets',
        {
          'dept': dept,
          'id': m['id'],
          'status': '${m['status'] ?? ''}',
          'updated_at': '${m['updated_at'] ?? ''}',
          'data_json': jsonEncode(m),
          'fetched_at': now,
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    }
    await batch.commit(noResult: true);
  }

  /// Local review window — the server's exact ordering
  /// (updated_at DESC, id DESC — every department service sorts this
  /// way) with the server's per-filter semantics supplied by the
  /// caller as a status set ([statusIn] null = the 'all' window).
  Future<List<Map<String, dynamic>>> getCachedReviewPackets(String dept,
      {List<String>? statusIn, int limit = 50}) async {
    final db = await database;
    final where = <String>['dept = ?'];
    final args = <dynamic>[dept];
    if (statusIn != null && statusIn.isNotEmpty) {
      where.add('status IN (${List.filled(statusIn.length, '?').join(', ')})');
      args.addAll(statusIn);
    }
    final rows = await db.query('cached_review_packets',
        where: where.join(' AND '),
        whereArgs: args,
        orderBy: 'updated_at DESC, id DESC',
        limit: limit);
    return rows.map((row) {
      try {
        final decoded =
            jsonDecode(row['data_json'] as String) as Map<String, dynamic>;
        decoded['local_fetched_at'] = row['fetched_at'];
        return decoded;
      } catch (_) {
        // Corrupt/missing blob — identify the row honestly, never
        // fabricate content.
        return <String, dynamic>{
          'dept': row['dept'],
          'id': row['id'],
          'status': row['status'],
          'updated_at': row['updated_at'],
          'local_fetched_at': row['fetched_at'],
        };
      }
    }).toList();
  }

  /// Cache one detail payload (roster rows included) — separate
  /// table so a list refresh can never clobber it.
  Future<void> cacheReviewPacketDetail(
      String dept, int id, Map<String, dynamic> payload) async {
    final db = await database;
    await db.insert(
      'cached_review_packet_details',
      {
        'dept': dept,
        'id': id,
        'data_json': jsonEncode(payload),
        'fetched_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<Map<String, dynamic>?> getCachedReviewPacketDetail(
      String dept, int id) async {
    final db = await database;
    try {
      final rows = await db.query('cached_review_packet_details',
          where: 'dept = ? AND id = ?', whereArgs: [dept, id], limit: 1);
      if (rows.isEmpty) return null;
      final raw = rows.first['data_json'] as String?;
      if (raw == null || raw.isEmpty) return null;
      final decoded = jsonDecode(raw);
      if (decoded is Map<String, dynamic>) {
        decoded['local_fetched_at'] = rows.first['fetched_at'];
        return decoded;
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  /// Stats are server aggregates over the department's FULL queue —
  /// a different population than any cached row window. Stored
  /// verbatim per department; NEVER recomputed from cached rows.
  Future<void> cacheReviewStats(
      String dept, Map<String, dynamic> stats) async {
    final db = await database;
    await db.insert(
      'cached_review_stats',
      {
        'dept': dept,
        'stats_json': jsonEncode(stats),
        'fetched_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<Map<String, dynamic>?> getCachedReviewStats(String dept) async {
    final db = await database;
    try {
      final rows = await db.query('cached_review_stats',
          where: 'dept = ?', whereArgs: [dept], limit: 1);
      if (rows.isEmpty) return null;
      final raw = rows.first['stats_json'] as String?;
      if (raw == null || raw.isEmpty) return null;
      final decoded = jsonDecode(raw);
      return decoded is Map<String, dynamic> ? decoded : null;
    } catch (_) {
      return null;
    }
  }

  // ============================================================
  // P1-E: CACHED EDU CLASSES (education read model)
  // ============================================================

  /// Replace-on-success snapshot of the Education class list.
  /// GET /classes returns the COMPLETE active class set for the
  /// signed-in scope, so this delete + insert in ONE transaction
  /// propagates renames, deactivations and eligible hard deletes on
  /// every successful refresh — deliberately NOT P1-D's merge-only
  /// model (a merge could never drop a deactivated class). Callers
  /// invoke this only AFTER a successful server response, so a
  /// failed or offline refresh never reaches this write and cached
  /// rows always survive failures. An empty-but-valid class set
  /// replaces too (a scope with zero active classes is honest
  /// emptiness, not a failure).
  Future<void> replaceCachedEduClasses(
      List<Map<String, dynamic>> rows) async {
    final db = await database;
    final now = DateTime.now().toIso8601String();
    await db.transaction((txn) async {
      await txn.delete('cached_edu_classes');
      for (final m in rows) {
        await txn.insert('cached_edu_classes', {
          'id': m['id'],
          'class_name': '${m['class_name'] ?? ''}',
          'class_name_en':
              m['class_name_en'] == null ? null : '${m['class_name_en']}',
          'level_order': _asIntLocal(m['level_order']),
          'student_count': _asIntLocal(m['student_count']),
          'data_json': jsonEncode(m),
          'fetched_at': now,
        });
      }
    });
  }

  /// Local Education class list — the server's exact ordering
  /// (level_order, class_name), never an alphabetical-only
  /// reconstruction (the shared cached_classes cannot express this).
  /// Rows come back shaped like the server's class items plus a
  /// local_fetched_at stamp for the '· updated HH:MM' banner.
  Future<List<Map<String, dynamic>>> getCachedEduClasses() async {
    final db = await database;
    final rows = await db.query('cached_edu_classes',
        orderBy: 'level_order, class_name');
    return rows.map((row) {
      try {
        final decoded =
            jsonDecode(row['data_json'] as String) as Map<String, dynamic>;
        decoded['local_fetched_at'] = row['fetched_at'];
        return decoded;
      } catch (_) {
        // Corrupt/missing blob — identify the row honestly from its
        // discrete columns, never fabricate content.
        return <String, dynamic>{
          'id': row['id'],
          'class_name': row['class_name'],
          'class_name_en': row['class_name_en'],
          'level_order': row['level_order'],
          'student_count': row['student_count'],
          'local_fetched_at': row['fetched_at'],
        };
      }
    }).toList();
  }

  /// Replace-on-success roster for ONE class — the /classes/{id}/
  /// students response stored verbatim (students + count + year
  /// metadata). Per-class keying means refreshing class A never
  /// touches class B, and merely SELECTING another class never
  /// clears anything: each roster is isolated by class_id. Called
  /// only after a successful response, so failures never write.
  /// Class membership comes ONLY from this endpoint — never derived
  /// from cached_members (a directory is not a relationship).
  Future<void> cacheEduClassRoster(
      int classId, Map<String, dynamic> payload) async {
    final db = await database;
    await db.insert(
      'cached_edu_class_rosters',
      {
        'class_id': classId,
        'roster_year_id': payload['roster_year_id'] == null
            ? null
            : _asIntLocal(payload['roster_year_id']),
        'roster_year_name': payload['roster_year_name'] == null
            ? null
            : '${payload['roster_year_name']}',
        'roster_fallback':
            (payload['roster_fallback'] == true ||
                    payload['roster_fallback'] == 1 ||
                    payload['roster_fallback'] == '1')
                ? 1
                : 0,
        'data_json': jsonEncode(payload),
        'fetched_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  /// Cached roster for one class, or null when it was never fetched.
  /// Returns the server response shape (class_id / students / count
  /// / roster_year_id / roster_year_name / roster_fallback) plus a
  /// local_fetched_at stamp. The year metadata is read back verbatim
  /// — the current-year vs most-populated-prior-year resolution is
  /// server contract and is never re-resolved locally.
  Future<Map<String, dynamic>?> getCachedEduClassRoster(int classId) async {
    final db = await database;
    try {
      final rows = await db.query('cached_edu_class_rosters',
          where: 'class_id = ?', whereArgs: [classId], limit: 1);
      if (rows.isEmpty) return null;
      final raw = rows.first['data_json'] as String?;
      if (raw == null || raw.isEmpty) return null;
      final decoded = jsonDecode(raw);
      if (decoded is Map<String, dynamic>) {
        decoded['local_fetched_at'] = rows.first['fetched_at'];
        return decoded;
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  // ============================================================
  // P1-F: CACHED EDU SUBJECTS (education read model)
  // ============================================================

  /// Replace-on-success snapshot of the Education subject catalog.
  /// GET /subjects returns the COMPLETE active set (no pagination),
  /// so this delete + insert in ONE transaction propagates renames,
  /// deactivations and eligible hard deletes on every successful
  /// refresh. Called only AFTER a successful server response; a
  /// failed or offline refresh never reaches this write. An
  /// empty-but-valid catalog replaces too (zero active subjects is
  /// honest emptiness, not a failure).
  Future<void> replaceCachedEduSubjects(
      List<Map<String, dynamic>> rows) async {
    final db = await database;
    final now = DateTime.now().toIso8601String();
    await db.transaction((txn) async {
      await txn.delete('cached_edu_subjects');
      for (final m in rows) {
        await txn.insert('cached_edu_subjects', {
          'id': m['id'],
          'subject_name': '${m['subject_name'] ?? ''}',
          'subject_name_en':
              m['subject_name_en'] == null ? null : '${m['subject_name_en']}',
          'subject_code':
              m['subject_code'] == null ? null : '${m['subject_code']}',
          'class_count': _asIntLocal(m['class_count']),
          'data_json': jsonEncode(m),
          'fetched_at': now,
        });
      }
    });
  }

  /// Local Education subject catalog — the server's ordering
  /// (ORDER BY subject_name under MySQL utf8mb4_unicode_ci;
  /// COLLATE NOCASE approximates the case-insensitive fold for
  /// ASCII — Amharic orders code-point-identically). Never the
  /// teacher grade-bootstrap cached_subjects (different identity:
  /// (id, class_id) composite PK from /grades/bootstrap). Rows come
  /// back shaped like the server's items plus a local_fetched_at
  /// stamp for the '· updated HH:MM' banner. class_count is the
  /// server's aggregate, read verbatim — never recomputed locally.
  Future<List<Map<String, dynamic>>> getCachedEduSubjects() async {
    final db = await database;
    final rows = await db.query('cached_edu_subjects',
        orderBy: 'subject_name COLLATE NOCASE');
    return rows.map((row) {
      try {
        final decoded =
            jsonDecode(row['data_json'] as String) as Map<String, dynamic>;
        decoded['local_fetched_at'] = row['fetched_at'];
        return decoded;
      } catch (_) {
        // Corrupt/missing blob — identify the row honestly from its
        // discrete columns, never fabricate content.
        return <String, dynamic>{
          'id': row['id'],
          'subject_name': row['subject_name'],
          'subject_name_en': row['subject_name_en'],
          'subject_code': row['subject_code'],
          'class_count': row['class_count'],
          'local_fetched_at': row['fetched_at'],
        };
      }
    }).toList();
  }

  // ============================================================
  // P1-G: CACHED EDU TEACHERS + YEAR-SCOPED DETAILS
  // ============================================================

  /// Metadata for the last COMPLETE teacher-directory crawl. A present
  /// row with total=0 is a valid empty server snapshot, not "never cached".
  Future<Map<String, dynamic>?> getCachedEduTeacherSnapshot() async {
    final db = await database;
    final rows = await db.query('cached_edu_teacher_snapshot',
        where: 'id = ?', whereArgs: [1], limit: 1);
    return rows.isEmpty ? null : Map<String, dynamic>.from(rows.first);
  }

  /// Replace the complete active-teacher directory only after EVERY server
  /// page has succeeded and the caller has validated stable pagination,
  /// year scope and identity uniqueness. The transaction also invalidates
  /// details for disappeared teachers and obsolete academic-year scopes.
  Future<void> replaceCachedEduTeachers(
    List<Map<String, dynamic>> rows, {
    required int academicYearId,
    String? academicYearName,
    required int total,
  }) async {
    final db = await database;
    final now = DateTime.now().toIso8601String();
    await db.transaction((txn) async {
      await txn.delete('cached_edu_teachers');
      for (var i = 0; i < rows.length; i++) {
        final m = rows[i];
        await txn.insert('cached_edu_teachers', {
          'id': _asIntLocal(m['id']),
          'username': '${m['username'] ?? ''}',
          'full_name': '${m['full_name'] ?? ''}',
          'is_active': _asIntLocal(m['is_active']),
          'created_at':
              m['created_at'] == null ? null : '${m['created_at']}',
          'assigned_classes': _asIntLocal(m['assigned_classes']),
          'assigned_subjects': _asIntLocal(m['assigned_subjects']),
          'sort_order': i,
          'data_json': jsonEncode(m),
          'fetched_at': now,
        });
      }
      await txn.insert(
        'cached_edu_teacher_snapshot',
        {
          'id': 1,
          'academic_year_id': academicYearId,
          'academic_year_name': academicYearName,
          'total': total,
          'fetched_at': now,
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
      await txn.delete('cached_edu_teacher_details',
          where: 'academic_year_id != ?', whereArgs: [academicYearId]);
      await txn.rawDelete('''
        DELETE FROM cached_edu_teacher_details
        WHERE NOT EXISTS (
          SELECT 1 FROM cached_edu_teachers t
          WHERE t.id = cached_edu_teacher_details.teacher_id
        )
      ''');
    });
  }

  /// Search the COMPLETE cached directory locally, but retain the current
  /// screen's 50-result display cap. sort_order reproduces the server's
  /// ORDER BY u.full_name across the validated page crawl.
  Future<List<Map<String, dynamic>>> getCachedEduTeachers({
    String? search,
    int limit = 50,
  }) async {
    final db = await database;
    final q = search?.trim() ?? '';
    final rows = await db.query(
      'cached_edu_teachers',
      where: q.isEmpty ? null : '(full_name LIKE ? OR username LIKE ?)',
      whereArgs: q.isEmpty ? null : ['%$q%', '%$q%'],
      orderBy: 'sort_order ASC',
      limit: limit,
    );
    return rows.map((row) {
      try {
        final decoded =
            jsonDecode(row['data_json'] as String) as Map<String, dynamic>;
        decoded['local_fetched_at'] = row['fetched_at'];
        decoded['local_sort_order'] = row['sort_order'];
        return decoded;
      } catch (_) {
        // Corrupt/missing blob: preserve only discrete server-derived fields.
        return <String, dynamic>{
          'id': row['id'],
          'username': row['username'],
          'full_name': row['full_name'],
          'is_active': row['is_active'],
          'created_at': row['created_at'],
          'assigned_classes': row['assigned_classes'],
          'assigned_subjects': row['assigned_subjects'],
          'local_fetched_at': row['fetched_at'],
          'local_sort_order': row['sort_order'],
        };
      }
    }).toList();
  }

  /// Cache one successfully validated teacher detail under the explicit
  /// server academic-year scope. A valid empty assignments list is stored;
  /// transport/server/malformed failures never call this method.
  Future<void> cacheEduTeacherDetail(
    Map<String, dynamic> detail, {
    required int academicYearId,
    String? academicYearName,
  }) async {
    final db = await database;
    await db.insert(
      'cached_edu_teacher_details',
      {
        'teacher_id': _asIntLocal(detail['id']),
        'academic_year_id': academicYearId,
        'academic_year_name': academicYearName,
        'data_json': jsonEncode(detail),
        'fetched_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<Map<String, dynamic>?> getCachedEduTeacherDetail(
      int teacherId, int academicYearId) async {
    final db = await database;
    final rows = await db.query(
      'cached_edu_teacher_details',
      where: 'teacher_id = ? AND academic_year_id = ?',
      whereArgs: [teacherId, academicYearId],
      limit: 1,
    );
    if (rows.isEmpty) return null;
    final row = rows.first;
    try {
      final decoded =
          jsonDecode(row['data_json'] as String) as Map<String, dynamic>;
      decoded['local_fetched_at'] = row['fetched_at'];
      decoded['local_academic_year_id'] = row['academic_year_id'];
      decoded['local_academic_year_name'] = row['academic_year_name'];
      return decoded;
    } catch (_) {
      // Never fabricate assignments from a corrupt detail blob.
      return null;
    }
  }

  // ============================================================
  // P1-H: CACHED MEZMUR ANALYTICS LAST VIEW
  // ============================================================

  /// Atomically replace the one bounded last-view row. The caller invokes
  /// this only after BOTH endpoint responses have valid List items, the same
  /// canonical server window, and the same sessions_held value.
  Future<void> cacheMezmurAnalyticsLast({
    required String fromDate,
    required String toDate,
    required int sessionsHeld,
    required Map<String, dynamic> membersResponse,
    required Map<String, dynamic> sectionsResponse,
  }) async {
    final db = await database;
    await db.insert(
      'cached_mezmur_analytics_last',
      {
        'id': 1,
        'from_date': fromDate,
        'to_date': toDate,
        'sessions_held': sessionsHeld,
        'members_response_json': jsonEncode(membersResponse),
        'sections_response_json': jsonEncode(sectionsResponse),
        'fetched_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  /// Return only a coherent pair. Corrupt JSON, non-list items, or any
  /// mismatch between stored dates/held count and either response is treated
  /// as no cache; analytics is never fabricated from a half-valid blob.
  Future<Map<String, dynamic>?> getCachedMezmurAnalyticsLast() async {
    final db = await database;
    final rows = await db.query('cached_mezmur_analytics_last',
        where: 'id = ?', whereArgs: [1], limit: 1);
    if (rows.isEmpty) return null;
    final row = rows.first;
    int? strictInt(dynamic value) {
      if (value is int) return value;
      if (value is num && value.isFinite && value == value.roundToDouble()) {
        return value.toInt();
      }
      if (value is String) return int.tryParse(value);
      return null;
    }
    bool validIsoDate(dynamic value) {
      if (value is! String) return false;
      final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(value);
      if (match == null) return false;
      final parsed = DateTime.tryParse(value);
      return parsed != null &&
          parsed.year == int.parse(match.group(1)!) &&
          parsed.month == int.parse(match.group(2)!) &&
          parsed.day == int.parse(match.group(3)!);
    }

    try {
      final memberRaw = jsonDecode(row['members_response_json'] as String);
      final sectionRaw = jsonDecode(row['sections_response_json'] as String);
      if (memberRaw is! Map || sectionRaw is! Map) return null;
      final members = Map<String, dynamic>.from(memberRaw);
      final sections = Map<String, dynamic>.from(sectionRaw);
      if (members['items'] is! List || sections['items'] is! List) return null;
      final memberItems = members['items'] as List;
      final sectionItems = sections['items'] as List;
      if (memberItems.any((item) => item is! Map) ||
          sectionItems.any((item) => item is! Map) ||
          strictInt(members['page']) != 1 ||
          memberItems.length > 100) {
        return null;
      }
      final memberWindow = members['window'];
      final sectionWindow = sections['window'];
      if (memberWindow is! Map || sectionWindow is! Map) return null;
      final fromDate = row['from_date'];
      final toDate = row['to_date'];
      final held = strictInt(row['sessions_held']);
      final memberHeld = strictInt(members['sessions_held']);
      final sectionHeld = strictInt(sections['sessions_held']);
      if (!validIsoDate(fromDate) ||
          !validIsoDate(toDate) ||
          '$fromDate'.compareTo('$toDate') > 0 ||
          held == null ||
          held < 0 ||
          memberHeld != held ||
          sectionHeld != held ||
          memberWindow['from'] != fromDate ||
          memberWindow['to'] != toDate ||
          sectionWindow['from'] != fromDate ||
          sectionWindow['to'] != toDate) {
        return null;
      }
      return <String, dynamic>{
        'from_date': fromDate,
        'to_date': toDate,
        'sessions_held': held,
        'members_response': members,
        'sections_response': sections,
        'local_fetched_at': row['fetched_at'],
      };
    } catch (_) {
      return null;
    }
  }

  // ============================================================
  // CACHED CLASSES
  // ============================================================

  Future<void> cacheClasses(List<dynamic> classes) async {
    final db = await database;
    final batch = db.batch();
    batch.delete('cached_classes');
    for (final c in classes) {
      batch.insert('cached_classes', {
        'id': c['id'],
        'class_name': c['class_name'] ?? '',
        'class_name_en': c['class_name_en'] ?? '',
        'section': c['section'] ?? c['section_name'] ?? '',
        'student_count': c['student_count'] ?? 0,
        'updated_at': DateTime.now().toIso8601String(),
      });
    }
    await batch.commit(noResult: true);
  }

  Future<List<Map<String, dynamic>>> getCachedClasses() async {
    final db = await database;
    return await db.query('cached_classes', orderBy: 'class_name');
  }

  // ============================================================
  // CACHED STUDENTS
  // ============================================================

  Future<void> cacheStudents(int classId, List<dynamic> students) async {
    // Capture before the first await: later caller mutations are not a refresh.
    // This is a read cache only, not authorized preparation or offline readiness.
    final updatedAt = DateTime.now().toIso8601String();
    final rows = students.map((s) => <String, Object?>{
      'member_id': s['member_id'] ?? s['id'],
      'class_id': classId,
      'student_name': s['student_name'] ?? '',
      'father_name': s['father_name'] ?? '',
      'member_code': s['member_code'] ?? '',
      'gender': s['gender'] ?? '',
      'updated_at': updatedAt,
    }).toList(growable: false);
    final db = await database;
    await db.transaction((txn) async {
      await txn.delete('cached_students', where: 'class_id = ?',
          whereArgs: [classId]);
      final batch = txn.batch();
      for (final row in rows) {
        batch.insert('cached_students', row);
      }
      // Failure propagates and rolls back the delete as well as every insert.
      await batch.commit(noResult: true);
    });
  }

  Future<List<Map<String, dynamic>>> getCachedStudents(int classId) async {
    final db = await database;
    return await db.query('cached_students',
        where: 'class_id = ?', whereArgs: [classId], orderBy: 'student_name');
  }

  // ============================================================
  // CACHED SUBJECTS
  // ============================================================

  Future<void> cacheSubjects(int classId, List<dynamic> subjects) async {
    final db = await database;
    await db
        .delete('cached_subjects', where: 'class_id = ?', whereArgs: [classId]);
    final batch = db.batch();
    for (final s in subjects) {
      batch.insert(
          'cached_subjects',
          {
            'id': s['id'],
            'class_id': classId,
            'subject_name': s['subject_name'] ?? '',
            'subject_name_en': s['subject_name_en'] ?? '',
            'subject_code': s['subject_code'] ?? '',
            'updated_at': DateTime.now().toIso8601String(),
          },
          conflictAlgorithm: ConflictAlgorithm.replace);
    }
    await batch.commit(noResult: true);
  }

  Future<List<Map<String, dynamic>>> getCachedSubjects(int classId) async {
    final db = await database;
    return await db.query('cached_subjects',
        where: 'class_id = ?', whereArgs: [classId], orderBy: 'subject_name');
  }

  // ============================================================
  // CACHED ASSESSMENTS
  // ============================================================

  Future<void> cacheAssessments(
      int classId, int subjectId, List<dynamic> assessments) async {
    final db = await database;
    await db.delete('cached_assessments',
        where: 'class_id = ? AND subject_id = ?',
        whereArgs: [classId, subjectId]);
    final batch = db.batch();
    for (final a in assessments) {
      batch.insert(
          'cached_assessments',
          {
            'id': a['id'],
            'class_id': classId,
            'subject_id': subjectId,
            'assessment_name': a['assessment_name'] ?? '',
            'assessment_type': a['assessment_type'] ?? 'test',
            'max_score': a['max_score'] ?? 100,
            'weight_percentage': a['weight_percentage'] ?? 100,
            'grades_entered': a['grades_entered'] ?? 0,
            'updated_at': DateTime.now().toIso8601String(),
          },
          conflictAlgorithm: ConflictAlgorithm.replace);
    }
    await batch.commit(noResult: true);
  }

  Future<List<Map<String, dynamic>>> getCachedAssessments(
      int classId, int subjectId) async {
    final db = await database;
    return await db.query('cached_assessments',
        where: 'class_id = ? AND subject_id = ?',
        whereArgs: [classId, subjectId],
        orderBy: 'id');
  }

  // ============================================================
  // LEGACY OUTBOX OPERATION PRIMITIVES (schema v34)
  // ============================================================

  LegacyOutboxTableSpec _legacySpecFor(LegacyOperationKind kind) {
    final table = switch (kind) {
      LegacyOperationKind.attendance => 'pending_attendance',
      LegacyOperationKind.grades => 'pending_grades',
      LegacyOperationKind.mezmur => 'pending_mezmur',
      LegacyOperationKind.hr => 'pending_hr',
    };
    return legacyOutboxTableSpecs.firstWhere((spec) => spec.table == table);
  }

  Map<String, Object?> _legacyNaturalKey(
    LegacyOutboxTableSpec spec,
    Map<String, Object?> row,
  ) {
    return {
      for (final column in spec.businessKeyColumns) column: row[column],
    };
  }

  (String, List<Object?>) _legacyExactWhere(
    LegacyOutboxTableSpec spec,
    LegacyClaimSnapshot claim,
  ) {
    final operation = claim.operation;
    final clauses = <String>[
      'client_op_id = ?',
      'synced = 0',
      "sync_state = 'in_flight'",
      'last_attempt_at = ?',
      'owner_user_id = ?',
      'created_authorization_version = ?',
    ];
    final args = <Object?>[
      operation.clientOpId,
      claim.claimedAt.toUtc().toIso8601String(),
      operation.ownerUserId,
      operation.createdAuthorizationVersion,
    ];
    for (final column in spec.businessKeyColumns) {
      clauses.add('$column = ?');
      args.add(operation.naturalKey[column]);
    }
    return (clauses.join(' AND '), args);
  }

  Future<void> pauseLegacyInFlightForAuthentication({
    required int ownerUserId,
    required int authorizationVersion,
  }) async {
    final db = await database;
    await db.transaction((txn) async {
      for (final spec in legacyOutboxTableSpecs) {
        await txn.update(
          spec.table,
          {
            'sync_state': 'paused_auth',
            'next_attempt_at': null,
            'failure_code': 'AUTHENTICATION_REQUIRED',
            'sync_error': 'Sign in to continue sending this work.',
          },
          where: "synced = 0 AND sync_state = 'in_flight' "
              'AND owner_user_id = ? '
              'AND created_authorization_version = ?',
          whereArgs: [ownerUserId, authorizationVersion],
        );
      }
      await txn.update(
        'comm_outbox',
        {
          'state': 'paused_auth',
          'next_attempt_at': null,
          'failure_code': 'AUTHENTICATION_REQUIRED',
          'fail_reason': 'Sign in to continue sending this message.',
        },
        where: "state = 'in_flight' AND owner_user_id = ? "
            'AND created_authorization_version = ?',
        whereArgs: [ownerUserId, authorizationVersion],
      );
      // Hymn operations are shared rather than private-owner-bound. Preserve
      // the exact id and make an interrupted request retryable for the next
      // authorized curator instead of attaching it to the lost session.
      await txn.update(
        'pending_hymn_ops',
        {
          'sync_state': 'retry_wait',
          'next_attempt_at': DateTime.now().toUtc().toIso8601String(),
        },
        where: "synced = 0 AND sync_state = 'in_flight'",
      );
    });
  }

  Future<void> resumeLegacyPausedAuthentication({
    required int ownerUserId,
    required int authorizationVersion,
    bool resumeSharedHymnOperations = true,
  }) async {
    final db = await database;
    await db.transaction((txn) async {
      for (final spec in legacyOutboxTableSpecs) {
        await txn.update(
          spec.table,
          {
            'sync_state': 'pending',
            'next_attempt_at': null,
            'failure_code': null,
            'failure_http_status': null,
            'sync_error': null,
          },
          where: "synced = 0 AND sync_state = 'paused_auth' "
              'AND owner_user_id = ? '
              'AND created_authorization_version = ?',
          whereArgs: [ownerUserId, authorizationVersion],
        );
      }
      if (resumeSharedHymnOperations) {
        await txn.update(
          'pending_hymn_ops',
          {
            'sync_state': 'pending',
            'next_attempt_at': null,
            'failure_code': null,
            'failure_http_status': null,
            'sync_error': null,
          },
          where: "synced = 0 AND sync_state = 'paused_auth'",
        );
      }
      await txn.update(
        'comm_outbox',
        {
          'state': 'pending',
          'next_attempt_at': null,
          'failure_code': null,
          'failure_http_status': null,
          'fail_reason': null,
        },
        where: "state = 'paused_auth' AND owner_user_id = ? "
            'AND created_authorization_version = ?',
        whereArgs: [ownerUserId, authorizationVersion],
      );
    });
  }

  // Shared with scheduling/status: a blocked newer generation is not due.
  static const _attendanceClaimEligibilityWhere =
      "AND client_op_id IS NOT NULL AND TRIM(client_op_id) <> '' "
      "AND NOT EXISTS (SELECT 1 FROM pending_attendance older "
      "WHERE older.class_id = pending_attendance.class_id "
      "AND older.date = pending_attendance.date "
      "AND older.owner_user_id = pending_attendance.owner_user_id "
      "AND older.id < pending_attendance.id AND older.synced = 0 "
      "AND COALESCE(older.client_op_id, '') <> pending_attendance.client_op_id "
      "AND COALESCE(older.sync_state, '') <> 'resolved_conflict') ";

  /// Atomically claims and snapshots one due operation. The returned immutable
  /// snapshot is the only object that may cross the HTTP boundary.
  Future<LegacyClaimSnapshot?> claimNextLegacyOperation({
    required LegacyOperationKind kind,
    required int ownerUserId,
    required int authorizationVersion,
    required int runtimeGeneration,
    DateTime? now,
    SyncExecutionSource executionSource = SyncExecutionSource.foreground,
  }) async {
    final db = await database;
    final spec = _legacySpecFor(kind);
    final claimedAt = (now ?? DateTime.now()).toUtc();
    final claimedAtText = claimedAt.toIso8601String();
    // Probed on the outer handle: using `db` inside `db.transaction` would
    // deadlock sqflite, which serialises access to the single connection.
    final hasAttemptLedger = await _tableExists(db, 'sync_attempts');

    return db.transaction((txn) async {
      final sessionMatches = await activeSessionMatches(
        runtimeGeneration: runtimeGeneration,
        ownerUserId: ownerUserId,
        authorizationVersion: authorizationVersion,
        executor: txn,
      );
      if (!sessionMatches) return null;
      // A newer attendance intent may be saved while an older one is in
      // flight. Never send it ahead of that unresolved same-owner sheet.
      final attendanceOrder = kind == LegacyOperationKind.attendance
          ? _attendanceClaimEligibilityWhere : '';
      final candidates = await txn.rawQuery(
        'SELECT client_op_id, MIN(id) AS first_id '
        'FROM ${spec.table} '
        'WHERE synced = 0 '
        "AND sync_state IN ('pending', 'retry_wait') "
        'AND (next_attempt_at IS NULL OR next_attempt_at <= ?) '
        'AND owner_user_id = ? '
        'AND created_authorization_version = ? '
        "AND client_op_id IS NOT NULL AND TRIM(client_op_id) <> '' $attendanceOrder"
        'GROUP BY client_op_id '
        'ORDER BY MIN(created_at), MIN(id) LIMIT 1',
        [claimedAtText, ownerUserId, authorizationVersion],
      );
      if (candidates.isEmpty) return null;
      final clientOpId = '${candidates.first['client_op_id']}';
      final rows = await txn.query(
        spec.table,
        where: 'client_op_id = ? AND synced = 0 AND owner_user_id = ? '
            'AND created_authorization_version = ?',
        whereArgs: [clientOpId, ownerUserId, authorizationVersion],
        orderBy: 'id',
      );
      if (rows.isEmpty) return null;

      final naturalKeys = <String>{};
      final packetKinds = <String>{};
      final states = <String>{};
      for (final row in rows) {
        naturalKeys.add(_legacyBusinessKey(spec, row));
        packetKinds.add(_legacyPacketKind(row));
        states.add('${row['sync_state']}');
        if (row['owner_user_id'] != ownerUserId ||
            row['created_authorization_version'] != authorizationVersion) {
          return null;
        }
      }
      if (naturalKeys.length != 1 ||
          packetKinds.length != 1 ||
          !const {'draft', 'submitted'}.contains(packetKinds.single) ||
          states.length != 1 ||
          !const {'pending', 'retry_wait'}.contains(states.single)) {
        return null;
      }

      final naturalKey = _legacyNaturalKey(spec, rows.first);
      final keyClauses = <String>[];
      final updateArgs = <Object?>[claimedAtText, clientOpId, states.single];
      for (final column in spec.businessKeyColumns) {
        keyClauses.add('$column = ?');
        updateArgs.add(naturalKey[column]);
      }
      updateArgs.add(ownerUserId);
      updateArgs.add(authorizationVersion);
      final affected = await txn.rawUpdate(
        "UPDATE ${spec.table} SET sync_state = 'in_flight', "
        'attempt_count = attempt_count + 1, last_attempt_at = ?, '
        'next_attempt_at = NULL '
        'WHERE client_op_id = ? AND synced = 0 AND sync_state = ? '
        'AND ${keyClauses.join(' AND ')} '
        'AND owner_user_id = ? AND created_authorization_version = ?',
        updateArgs,
      );
      if (affected != rows.length) {
        throw StateError('Legacy operation claim was not atomic.');
      }

      final snapshotWhere = <String>[
        'client_op_id = ?',
        'synced = 0',
        "sync_state = 'in_flight'",
        ...keyClauses,
        'owner_user_id = ?',
        'created_authorization_version = ?',
      ].join(' AND ');
      final claimedRows = await txn.query(
        spec.table,
        where: snapshotWhere,
        whereArgs: [
          clientOpId,
          for (final column in spec.businessKeyColumns) naturalKey[column],
          ownerUserId,
          authorizationVersion,
        ],
        orderBy: 'id',
      );
      if (claimedRows.length != rows.length) {
        throw StateError('Legacy operation snapshot was not coherent.');
      }
      final attemptCount = int.tryParse(
            '${claimedRows.first['attempt_count'] ?? 0}',
          ) ??
          0;
      final packetKind = packetKinds.single == 'submitted'
          ? LegacyPacketKind.submitted
          : LegacyPacketKind.draft;

      // S1 lineage: one ledger row per real transmission, written in the
      // claim transaction so a process death cannot lose the fact that an
      // attempt started. attempt_number is the row's own attempt_count.
      final attemptUid = newAttemptUid();
      if (hasAttemptLedger) {
        await _openSyncAttempt(
          txn,
          clientOpId: clientOpId,
          attemptNumber: attemptCount,
          attemptUid: attemptUid,
          domain: spec.table,
          startedAt: claimedAt,
          entityRef: naturalKey,
          ownerUserId: ownerUserId,
          authorizationVersion: authorizationVersion,
          executionSource: executionSource,
        );
      }

      return LegacyClaimSnapshot(
        operation: LegacyOperationRef(
          kind: kind,
          originalAcademicYearId: kind == LegacyOperationKind.attendance
              ? coherentAttendanceOriginalYear(claimedRows) : null,
          naturalKey: naturalKey,
          clientOpId: clientOpId,
          packetKind: packetKind,
          ownerUserId: ownerUserId,
          createdAuthorizationVersion: authorizationVersion,
          runtimeGeneration: runtimeGeneration,
        ),
        records: claimedRows,
        claimedAt: claimedAt,
        attemptCount: attemptCount,
        attemptUid: attemptUid,
      );
    });
  }

  /// Settles only the exact claimed generation. A replacement, wrong state,
  /// owner/scope mismatch, or stale runtime generation changes no row.
  Future<LegacySettlementResult> settleLegacyOperation({
    required LegacyClaimSnapshot claim,
    required LegacySettlement settlement,
    required int currentOwnerUserId,
    required int currentAuthorizationVersion,
    required int currentRuntimeGeneration,
    SyncAttemptClosure? attemptClosure,
    DateTime? now,
  }) async {
    final operation = claim.operation;
    if (operation.runtimeGeneration != currentRuntimeGeneration ||
        operation.ownerUserId != currentOwnerUserId ||
        operation.createdAuthorizationVersion != currentAuthorizationVersion) {
      return LegacySettlementResult.supersededSession;
    }

    final db = await database;
    final spec = _legacySpecFor(operation.kind);
    final settledAt = (now ?? DateTime.now()).toUtc().toIso8601String();
    // Probed outside the transaction for the same reason as the claim path.
    final hasAttemptLedger = await _tableExists(db, 'sync_attempts');
    return db.transaction((txn) async {
      final sessionMatches = await activeSessionMatches(
        runtimeGeneration: currentRuntimeGeneration,
        ownerUserId: currentOwnerUserId,
        authorizationVersion: currentAuthorizationVersion,
        executor: txn,
      );
      if (!sessionMatches) {
        return LegacySettlementResult.supersededSession;
      }
      final exact = _legacyExactWhere(spec, claim);
      final before = await txn.rawQuery(
        'SELECT COUNT(*) AS count FROM ${spec.table} WHERE ${exact.$1}',
        exact.$2,
      );
      final matching = int.tryParse('${before.first['count'] ?? 0}') ?? 0;
      if (matching != claim.records.length) {
        return LegacySettlementResult.supersededLocal;
      }

      final values = <String, Object?>{
        'failure_code': settlement.failureCode,
        'failure_http_status': settlement.failureHttpStatus,
      };
      switch (settlement.kind) {
        case LegacySettlementKind.accepted:
          values.addAll({
            'sync_state': 'synced',
            'synced': 1,
            'synced_at': settledAt,
            'sync_error': null,
            'next_attempt_at': null,
            'failed_at': null,
          });
          break;
        case LegacySettlementKind.retryable:
          values.addAll({
            'sync_state': 'retry_wait',
            'next_attempt_at':
                (settlement.nextAttemptAt ?? DateTime.parse(settledAt))
                    .toUtc()
                    .toIso8601String(),
            'sync_error': settlement.failureMessage,
            'failed_at': null,
          });
          break;
        case LegacySettlementKind.needsAttention:
          values.addAll({
            'sync_state': 'needs_attention',
            'next_attempt_at': null,
            'sync_error': settlement.failureMessage,
            'failed_at': settledAt,
          });
          break;
        case LegacySettlementKind.pausedAuthentication:
          values.addAll({
            'sync_state': 'paused_auth',
            'next_attempt_at': null,
            'sync_error': settlement.failureMessage,
            'failed_at': null,
          });
          break;
        case LegacySettlementKind.pausedAuthorizationScope:
          values.addAll({
            'sync_state': 'paused_scope',
            'next_attempt_at': null,
            'sync_error': settlement.failureMessage,
            'failed_at': null,
          });
          break;
        case LegacySettlementKind.resolvedConflict:
          values.addAll({
            'sync_state': 'resolved_conflict',
            'next_attempt_at': null,
            'sync_error': settlement.failureMessage,
            'failed_at': settledAt,
          });
          break;
      }
      final affected = await txn.update(
        spec.table,
        values,
        where: exact.$1,
        whereArgs: exact.$2,
      );
      if (affected != matching) {
        throw StateError('Legacy operation settlement was not atomic.');
      }

      // S1 lineage: close this attempt in the same transaction that settles
      // the row. Only the matching open attempt is touched, so a later
      // success can never rewrite an earlier failure.
      if (attemptClosure != null && hasAttemptLedger) {
        await _closeSyncAttempt(
          txn,
          clientOpId: operation.clientOpId,
          attemptNumber: claim.attemptCount,
          closure: attemptClosure,
          finishedAt: DateTime.parse(settledAt),
        );
      }

      // Exact atomic receipt: when an attendance operation is accepted with a
      // server response, store the receipt in the same transaction that marks
      // the outbox row completed. Never outside the transaction.
      if (settlement.kind == LegacySettlementKind.accepted &&
          operation.kind == LegacyOperationKind.attendance &&
          settlement.receiptJson != null &&
          settlement.receiptJson!.isNotEmpty) {
        try {
          final nat = operation.naturalKey;
          final classId = nat['class_id'] is int
              ? nat['class_id'] as int
              : int.tryParse('${nat['class_id']}') ?? 0;
          final date = '${nat['date'] ?? ''}';
          // Idempotent replay must not overwrite the first receipt.
          await txn.rawInsert(
            'INSERT OR IGNORE INTO attendance_sync_receipt '
            '(client_op_id, owner_user_id, authorization_version, generation, class_id, date, http_status, receipt_json, server_request_id, created_at) '
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
              operation.clientOpId,
              operation.ownerUserId,
              operation.createdAuthorizationVersion,
              operation.runtimeGeneration,
              classId,
              date,
              settlement.httpStatus ?? 201,
              settlement.receiptJson,
              settlement.serverRequestId,
              settledAt,
            ],
          );
        } catch (_) {
          // Receipt write failure must not hide a successful business settlement.
          // The outbox row is already marked completed; the receipt can be
          // reconciled on the next pull. Never throw here.
        }
      }
      // Exact atomic receipt for grades (v42): same pattern, assessment-scoped.
      if (settlement.kind == LegacySettlementKind.accepted &&
          operation.kind == LegacyOperationKind.grades &&
          settlement.receiptJson != null &&
          settlement.receiptJson!.isNotEmpty) {
        try {
          final nat = operation.naturalKey;
          final assessmentId = nat['assessment_id'] is int
              ? nat['assessment_id'] as int
              : int.tryParse('${nat['assessment_id']}') ?? 0;
          await txn.rawInsert(
            'INSERT OR IGNORE INTO grades_sync_receipt '
            '(client_op_id, owner_user_id, authorization_version, generation, assessment_id, http_status, receipt_json, server_request_id, created_at) '
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
              operation.clientOpId,
              operation.ownerUserId,
              operation.createdAuthorizationVersion,
              operation.runtimeGeneration,
              assessmentId,
              settlement.httpStatus ?? 201,
              settlement.receiptJson,
              settlement.serverRequestId,
              settledAt,
            ],
          );
        } catch (_) {
          // Never hide business success.
        }
      }
      return LegacySettlementResult.applied;
    });
  }

  /// Converts only the exact, still-unclaimed submitted generation back to a
  /// fresh draft generation. It never falls back to a natural-key mutation.
  Future<SubmitUndoResult> undoSubmittedLegacyOperation(
    LegacyOperationRef operation,
  ) {
    if (operation.packetKind != LegacyPacketKind.submitted) {
      return Future.value(SubmitUndoResult.supersededLocal);
    }
    return _serializeLegacySave(() async {
      final db = await database;
      final spec = _legacySpecFor(operation.kind);
      return db.transaction((txn) async {
        Map<String, int> binding;
        int runtimeGeneration;
        try {
          binding = await requireActiveOwnerBinding(txn);
          runtimeGeneration = await _activeRuntimeGeneration(txn);
        } catch (_) {
          return SubmitUndoResult.supersededSession;
        }
        if (binding['owner_user_id'] != operation.ownerUserId ||
            binding['created_authorization_version'] !=
                operation.createdAuthorizationVersion ||
            runtimeGeneration != operation.runtimeGeneration) {
          return SubmitUndoResult.supersededSession;
        }

        final keyClauses = <String>[];
        final keyArgs = <Object?>[];
        for (final column in spec.businessKeyColumns) {
          keyClauses.add('$column = ?');
          keyArgs.add(operation.naturalKey[column]);
        }
        final baseWhere = 'synced = 0 AND ${keyClauses.join(' AND ')} '
            'AND owner_user_id = ? AND created_authorization_version = ?';
        final baseArgs = <Object?>[
          ...keyArgs,
          operation.ownerUserId,
          operation.createdAuthorizationVersion,
        ];
        final attendance = operation.kind == LegacyOperationKind.attendance;
        final latestOnly = attendance ? ' AND $_latestAttendanceGenerationWhere' : '';
        final rows = await txn.query(
          spec.table,
          columns: ['client_op_id', 'packet_kind', 'sync_state',
            'attempt_count', 'last_attempt_at'],
          where: '$baseWhere$latestOnly',
          whereArgs: baseArgs,
        );
        if (rows.isEmpty) return SubmitUndoResult.alreadyClaimed;
        final exact = rows.where((row) =>
            '${row['client_op_id']}' == operation.clientOpId &&
            '${row['packet_kind']}' == 'submitted');
        if (exact.isEmpty || exact.length != rows.length) {
          return SubmitUndoResult.supersededLocal;
        }
        if (attendance) {
          if (exact.any((row) => row['attempt_count'] != 0 ||
              row['last_attempt_at'] != null)) {
            return SubmitUndoResult.alreadyClaimed;
          }
          final attempts = await txn.query('sync_attempts', columns: ['id'],
              where: 'client_op_id = ?', whereArgs: [operation.clientOpId], limit: 1);
          if (attempts.isNotEmpty) return SubmitUndoResult.alreadyClaimed;
        }
        if (exact.any((row) => '${row['sync_state']}' == 'in_flight')) {
          return SubmitUndoResult.alreadyClaimed;
        }
        if (exact.any((row) => !const {'pending', 'retry_wait'}
            .contains('${row['sync_state']}'))) {
          return SubmitUndoResult.supersededLocal;
        }

        final freshId = newClientOpId();
        final affected = await txn.update(
          spec.table,
          {
            'packet_kind': 'draft',
            'client_op_id': freshId,
            'sync_state': 'pending',
            'attempt_count': 0,
            'next_attempt_at': null,
            'last_attempt_at': null,
            'failure_code': null,
            'failure_http_status': null,
            'failed_at': null,
            'sync_error': null,
            'created_at': DateTime.now().toUtc().toIso8601String(),
          },
          where: '$baseWhere AND client_op_id = ? '
              "AND packet_kind = 'submitted' "
              "AND sync_state IN ('pending', 'retry_wait')",
          whereArgs: [...baseArgs, operation.clientOpId],
        );
        if (affected != rows.length) {
          if (attendance) throw StateError('Attendance undo was not atomic.');
          return SubmitUndoResult.supersededLocal;
        }
        return SubmitUndoResult.applied;
      });
    });
  }

  Future<LegacyOperationRef> _replaceLegacyOperation({
    required LegacyOperationKind kind,
    required String table,
    required String naturalKeyWhere,
    required List<Object?> naturalKeyArgs,
    required Map<String, Object?> naturalKey,
    required String packetKind,
    DateTime? notBefore,
    required List<Map<String, Object?>> rows,
    LegacyWriteSession? expectedSession,
    int? originalAcademicYearId,
  }) {
    final memberIds = rows
        .map((row) => _asIntLocal(row['member_id']))
        .where((id) => id > 0)
        .toSet();
    if (rows.isEmpty || memberIds.length != rows.length) {
      throw ArgumentError(
          'A durable operation requires one row per valid member.');
    }
    return _serializeLegacySave(() async {
      final db = await database;
      final createdAt = DateTime.now().toUtc().toIso8601String();
      final normalizedKind =
          packetKind == 'submitted' ? 'submitted' : 'draft';
      final opId = newClientOpId();
      late Map<String, int> binding;
      late int runtimeGeneration;
      await db.transaction((txn) async {
        // Attendance captures belong to the issuing sheet, not a later login.
        // Check in this same transaction before any replacement can delete rows.
        if (expectedSession != null &&
            (!expectedSession.isValid ||
                !await activeSessionMatches(
                  runtimeGeneration: expectedSession.runtimeGeneration,
                  ownerUserId: expectedSession.ownerUserId,
                  authorizationVersion: expectedSession.authorizationVersion,
                  executor: txn,
                ))) {
          throw const StaleLegacyWriteSession();
        }
        binding = await requireActiveOwnerBinding(txn);
        runtimeGeneration = await _activeRuntimeGeneration(txn);
        // sqflite's delete builder disallows null whereArgs. Keep unknown
        // provenance explicit, not coerced to a sentinel year.
        final attendanceYearWhere = originalAcademicYearId == null
            ? "AND original_academic_year_id IS NULL "
            : "AND original_academic_year_id IS ? ";
        final replaceable = kind == LegacyOperationKind.attendance
            ? " AND sync_state = 'pending' AND attempt_count = 0 "
                "AND last_attempt_at IS NULL "
                "$attendanceYearWhere"
                "AND client_op_id IS NOT NULL AND TRIM(client_op_id) <> '' "
                "AND NOT EXISTS (SELECT 1 FROM pending_attendance sibling "
                "WHERE sibling.client_op_id = pending_attendance.client_op_id "
                "AND (sibling.synced IS NOT 0 OR sibling.sync_state IS NOT 'pending' "
                "OR sibling.attempt_count IS NOT 0 OR sibling.last_attempt_at IS NOT NULL "
                "OR sibling.original_academic_year_id IS NOT "
                "pending_attendance.original_academic_year_id)) "
                "AND NOT EXISTS (SELECT 1 FROM sync_attempts attempts "
                "WHERE attempts.client_op_id = pending_attendance.client_op_id)"
            : '';
        await txn.delete(
          table,
          where: '$naturalKeyWhere AND synced = 0 '
              'AND owner_user_id = ? '
              'AND created_authorization_version = ?$replaceable',
          whereArgs: [
            ...naturalKeyArgs,
            binding['owner_user_id'],
            binding['created_authorization_version'],
            if (kind == LegacyOperationKind.attendance && originalAcademicYearId != null)
              originalAcademicYearId,
          ],
        );
        final batch = txn.batch();
        for (final row in rows) {
          batch.insert(table, {
            ...row,
            if (kind == LegacyOperationKind.attendance)
              attendanceOriginalYearColumn: originalAcademicYearId,
            'packet_kind': normalizedKind,
            'client_op_id': opId,
            'synced': 0,
            'sync_state': 'pending',
            'attempt_count': 0,
            'next_attempt_at': notBefore?.toUtc().toIso8601String(),
            'last_attempt_at': null,
            'failure_code': null,
            'failure_http_status': null,
            'failed_at': null,
            'sync_error': null,
            'created_at': createdAt,
            ...binding,
          });
        }
        await batch.commit(noResult: true);
      });
      return LegacyOperationRef(
        kind: kind,
        originalAcademicYearId: originalAcademicYearId,
        naturalKey: naturalKey,
        clientOpId: opId,
        packetKind: normalizedKind == 'submitted'
            ? LegacyPacketKind.submitted
            : LegacyPacketKind.draft,
        ownerUserId: binding['owner_user_id']!,
        createdAuthorizationVersion:
            binding['created_authorization_version']!,
        runtimeGeneration: runtimeGeneration,
      );
    });
  }

  // ============================================================
  // PENDING ATTENDANCE
  // ============================================================

  Future<LegacyOperationRef> saveAttendanceLocal(int classId, String className, String date,
      List<Map<String, dynamic>> records,
      {required LegacyWriteSession expectedSession,
      int? originalAcademicYearId,
      String packetKind = 'draft', DateTime? notBefore}) async {
    if (originalAcademicYearId != null &&
        !isValidOriginalAttendanceYear(originalAcademicYearId)) {
      throw ArgumentError('Invalid original attendance academic year.');
    }
    const validStatuses = {'present', 'absent', 'late', 'excused'};
    if (records.isEmpty) {
      throw ArgumentError('Attendance records are required.');
    }
    for (final record in records) {
      final status = '${record['status'] ?? ''}'.trim().toLowerCase();
      final memberId = record['member_id'] is int
          ? record['member_id'] as int
          : int.tryParse('${record['member_id'] ?? ''}');
      if (memberId == null || memberId <= 0 || !validStatuses.contains(status)) {
        throw ArgumentError('Attendance must explicitly mark every student.');
      }
    }

    return _replaceLegacyOperation(
      kind: LegacyOperationKind.attendance,
      originalAcademicYearId: originalAcademicYearId,
      table: 'pending_attendance',
      naturalKeyWhere: 'class_id = ? AND date = ?',
      naturalKeyArgs: [classId, date],
      naturalKey: {'class_id': classId, 'date': date},
      packetKind: packetKind,
      expectedSession: expectedSession,
      notBefore: notBefore,
      rows: records
          .map((r) => <String, Object?>{
                'class_id': classId,
                'class_name': className,
                'date': date,
                'member_id': r['member_id'],
                'student_name': r['student_name'] ?? '',
                'father_name': r['father_name'] ?? '',
                'member_code': r['member_code'] ?? '',
                'status': '${r['status']}'.trim().toLowerCase(),
                'notes': r['notes'] ?? r['note'] ?? '',
              })
          .toList(),
    );
  }

  // Select across synced and unsynced history: an acknowledged newer intent
  // must not expose an older terminal/needs-review generation as new marks.
  static const _latestAttendanceGenerationWhere =
      'client_op_id IS (SELECT newest.client_op_id FROM pending_attendance newest '
      'WHERE newest.class_id = pending_attendance.class_id '
      'AND newest.date = pending_attendance.date '
      'AND newest.owner_user_id = pending_attendance.owner_user_id '
      'AND newest.created_authorization_version = pending_attendance.created_authorization_version '
      'ORDER BY newest.id DESC LIMIT 1)';

  Future<List<Map<String, dynamic>>> getPendingAttendance() async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      return txn.rawQuery('''
        SELECT class_id, class_name, date,
               CASE WHEN SUM(CASE WHEN IFNULL(packet_kind,'draft') = 'submitted' THEN 1 ELSE 0 END) > 0
                    THEN 'submitted' ELSE 'draft' END as packet_kind,
               CASE WHEN COUNT(DISTINCT client_op_id) = 1
                    THEN MIN(client_op_id) ELSE NULL END as client_op_id,
               COUNT(*) as student_count, MIN(created_at) as created_at,
               MAX(CASE WHEN sync_error IS NOT NULL THEN 1 ELSE 0 END) as rejected
        FROM pending_attendance
        WHERE synced = 0 AND sync_state IN ('pending', 'retry_wait')
          AND owner_user_id = ? AND created_authorization_version = ?
          AND $_latestAttendanceGenerationWhere
        GROUP BY class_id, date ORDER BY date DESC
      ''', [
        binding['owner_user_id'],
        binding['created_authorization_version'],
      ]);
    });
  }

  Future<List<Map<String, dynamic>>> getPendingAttendanceRecords(
      int classId, String date) async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      return txn.query(
        'pending_attendance',
        where: 'class_id = ? AND date = ? AND synced = 0 '
            'AND owner_user_id = ? AND created_authorization_version = ? '
            'AND $_latestAttendanceGenerationWhere',
        whereArgs: [
          classId,
          date,
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
      );
    });
  }

  /// Phase 8 QR scan: offline resolve of a scanned member code. Used to
  /// distinguish "member exists but not in THIS class/section" from
  /// "unknown to this device". Indexed lookup (idx_members_code mirror).
  Future<Map<String, dynamic>?> findCachedMemberByCode(String code) async {
    final db = await database;
    final rows = await db.query('cached_members',
        where: 'member_code = ?', whereArgs: [code], limit: 1);
    return rows.isEmpty ? null : rows.first;
  }

  /// Phase 8 QR scan (edu): the class a cached member is enrolled in,
  /// so a wrong-class scan can name the member's real class.
  Future<String?> cachedClassNameOfMember(int memberId) async {
    final db = await database;
    final rows = await db.rawQuery(
        'SELECT cc.class_name FROM cached_students cs '
        'JOIN cached_classes cc ON cc.id = cs.class_id '
        'WHERE cs.member_id = ? LIMIT 1',
        [memberId]);
    return rows.isEmpty ? null : rows.first['class_name'] as String?;
  }



  // ============================================================
  // PENDING GRADES
  // ============================================================

  Future<LegacyOperationRef> saveGradesLocal(
      int assessmentId,
      String assessmentName,
      int classId,
      String className,
      int subjectId,
      String subjectName,
      double maxScore,
      List<Map<String, dynamic>> grades,
      {String packetKind = 'draft', DateTime? notBefore}) async {
    final memberIds = grades
        .map((grade) => _asIntLocal(grade['member_id']))
        .where((id) => id > 0)
        .toSet();
    if (grades.isEmpty || memberIds.length != grades.length) {
      throw ArgumentError('Grades require one row per valid member.');
    }
    return _replaceLegacyOperation(
      kind: LegacyOperationKind.grades,
      table: 'pending_grades',
      naturalKeyWhere: 'assessment_id = ?',
      naturalKeyArgs: [assessmentId],
      naturalKey: {'assessment_id': assessmentId},
      packetKind: packetKind,
      notBefore: notBefore,
      rows: grades
          .map((g) => <String, Object?>{
                'assessment_id': assessmentId,
                'assessment_name': assessmentName,
                'class_id': classId,
                'class_name': className,
                'subject_id': subjectId,
                'subject_name': subjectName,
                'member_id': g['member_id'],
                'student_name': g['student_name'] ?? '',
                'record_id': g['record_id'],
                'score': g['score'],
                'remark': g['remark'] ?? '',
                'max_score': maxScore,
              })
          .toList(),
    );
  }

  Future<List<Map<String, dynamic>>> getPendingGrades() async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      return txn.rawQuery('''
        SELECT assessment_id, assessment_name, class_name, subject_name,
               CASE WHEN SUM(CASE WHEN IFNULL(packet_kind,'draft') = 'submitted' THEN 1 ELSE 0 END) > 0
                    THEN 'submitted' ELSE 'draft' END as packet_kind,
               CASE WHEN COUNT(DISTINCT client_op_id) = 1
                    THEN MIN(client_op_id) ELSE NULL END as client_op_id,
               COUNT(*) as grade_count, MIN(created_at) as created_at,
               MAX(CASE WHEN sync_error IS NOT NULL THEN 1 ELSE 0 END) as rejected
        FROM pending_grades
        WHERE synced = 0 AND sync_state IN ('pending', 'retry_wait')
          AND owner_user_id = ? AND created_authorization_version = ?
        GROUP BY assessment_id ORDER BY created_at DESC
      ''', [
        binding['owner_user_id'],
        binding['created_authorization_version'],
      ]);
    });
  }

  Future<List<Map<String, dynamic>>> getPendingGradeRecords(
      int assessmentId) async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      return txn.query(
        'pending_grades',
        where: 'assessment_id = ? AND synced = 0 '
            'AND owner_user_id = ? AND created_authorization_version = ?',
        whereArgs: [
          assessmentId,
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
      );
    });
  }

  // ============================================================
  // CACHED ATTENDANCE RESPONSES
  // ============================================================

  // ============================================================
  // DOWNLOAD SYNC — CURSOR STATE AND DELTA APPLICATION
  // ============================================================
  // The server feed (sql/057, sql/058) reports per-record attendance
  // changes. This cache does not store attendance records: it stores a
  // whole class/day sheet blob keyed by (class_id, date), whose student
  // entries are roster rows carrying member_id and a nullable status.
  //
  // So a delta is applied by patching the student entry inside the
  // affected sheet, not by inserting or deleting cache rows. Deleting an
  // attendance record means the student becomes unmarked again, because
  // the roster entry itself is enrollment data and must stay.
  //
  // Only the server layer is touched. Unsynced local edits live in
  // pending_attendance and are overlaid on top of the cache at read time
  // by the attendance screen, so a pull can never silently discard a
  // local change that has not been uploaded yet.

  /// Last revision whose changes are fully applied locally for [domain].
  /// Absent means 0, which the server answers with bootstrap_required.
  Future<int> getSyncCursor(String domain) async {
    final db = await database;
    try {
      final rows = await db.query('sync_state',
          columns: ['cursor'], where: 'domain = ?', whereArgs: [domain], limit: 1);
      if (rows.isEmpty) return 0;
      final value = rows.first['cursor'];
      return value is int ? value : int.tryParse('$value') ?? 0;
    } catch (_) {
      // A device that predates the table behaves like a fresh install.
      return 0;
    }
  }

  Future<Map<String, dynamic>?> getSyncState(String domain) async {
    final db = await database;
    try {
      final rows = await db.query('sync_state',
          where: 'domain = ?', whereArgs: [domain], limit: 1);
      return rows.isEmpty ? null : Map<String, dynamic>.from(rows.first);
    } catch (_) {
      return null;
    }
  }

  /// Record the outcome of a sync attempt without moving the cursor.
  /// Used for failures, so the UI can say "sync failed" while the cursor
  /// still points at the last revision that was genuinely applied.
  Future<void> setSyncStatus(String domain, String status,
      {String? error, LegacyWriteSession? expectedSession}) async {
    final db = await database;
    if (expectedSession != null) {
      await db.transaction((txn) async {
        await _requireAttendanceSyncSession(txn, expectedSession);
        final rows = await txn.query('sync_state', columns: ['cursor'],
            where: 'domain = ?', whereArgs: [domain], limit: 1);
        await txn.insert('sync_state', {
          'domain': domain,
          'cursor': rows.isEmpty ? 0 : _asIntLocal(rows.first['cursor']),
          'last_sync_at': DateTime.now().toIso8601String(),
          'status': status,
          'error': error,
        }, conflictAlgorithm: ConflictAlgorithm.replace);
      });
      return;
    }
    try {
      await db.insert(
        'sync_state',
        {
          'domain': domain,
          'cursor': await getSyncCursor(domain),
          'last_sync_at': DateTime.now().toIso8601String(),
          'status': status,
          'error': error,
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    } catch (_) {}
  }

  Future<void> _requireAttendanceSyncSession(
      DatabaseExecutor executor, LegacyWriteSession expectedSession) async {
    if (!expectedSession.isValid ||
        !await activeSessionMatches(
          runtimeGeneration: expectedSession.runtimeGeneration,
          ownerUserId: expectedSession.ownerUserId,
          authorizationVersion: expectedSession.authorizationVersion,
          executor: executor,
        )) {
      throw const StaleAttendanceSyncSession();
    }
  }

  /// Apply one page of attendance changes and advance the cursor, both
  /// inside a single SQLite transaction.
  ///
  /// The cursor moves only on the same commit that writes the data, so
  /// the two can never disagree: if anything throws, sqflite rolls the
  /// whole transaction back and the next pull re-requests the same page.
  /// Re-applying a page is harmless — each change is an idempotent
  /// "set this student's status in this sheet to this value".
  ///
  /// [items] are the feed entries (op, class_id, member_id, date) and
  /// [records] maps attendance id to the canonical server row. An id
  /// missing from [records] is a tombstone.
  Future<void> applyAttendanceDelta({
    required LegacyWriteSession expectedSession,
    required List<Map<String, dynamic>> items,
    required Map<String, dynamic> records,
    required int nextCursor,
    String domain = 'attendance',
  }) async {
    final db = await database;
    await db.transaction((txn) async {
      await _requireAttendanceSyncSession(txn, expectedSession);
      // Sheets are rewritten once each, not once per change, so a class
      // day with thirty corrections costs one read and one write.
      final touched = <String, Map<String, dynamic>>{};
      final sheetKeys = <String, List<Object>>{};

      Future<Map<String, dynamic>?> loadSheet(int classId, String date) async {
        final key = '$classId|$date';
        if (touched.containsKey(key)) return touched[key];
        final rows = await txn.query('cached_attendance',
            where: 'class_id = ? AND date = ?', whereArgs: [classId, date], limit: 1);
        if (rows.isEmpty) {
          // Nothing cached for that day: there is no stale copy to
          // correct, and inventing one would fabricate a roster the
          // server never sent.
          touched[key] = <String, dynamic>{};
          return null;
        }
        final decoded = _decodeSheet(rows.first['response_json'] as String);
        touched[key] = decoded;
        sheetKeys[key] = [classId, date];
        return decoded;
      }

      for (final item in items) {
        final raw = records['${item['entity_id'] ?? ''}'];
        final record = raw is Map ? Map<String, dynamic>.from(raw) : null;

        // What this change means is decided by AttendanceDelta, which is
        // pure and unit-testable; this method only supplies storage and
        // the transaction.
        final target = AttendanceDelta.resolve(item, record);
        if (target == null) continue;

        final sheet = await loadSheet(target.classId, target.date);
        if (sheet == null) continue;

        AttendanceDelta.applyToSheet(sheet, target);
      }

      for (final entry in sheetKeys.entries) {
        final sheet = touched[entry.key];
        if (sheet == null || sheet.isEmpty) continue;
        await txn.update(
          'cached_attendance',
          {
            'response_json': jsonEncode(sheet),
            'updated_at': DateTime.now().toIso8601String(),
          },
          where: 'class_id = ? AND date = ?',
          whereArgs: entry.value,
        );
      }

      // Same transaction as the data above. This is the only place the
      // cursor moves forward.
      await txn.insert(
        'sync_state',
        {
          'domain': domain,
          'cursor': nextCursor,
          'last_sync_at': DateTime.now().toIso8601String(),
          'status': 'ok',
          'error': null,
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    });
  }

  /// Drop the cached attendance sheets and reset the cursor together, so
  /// a bootstrap cannot leave an empty cache paired with a cursor that
  /// claims to be up to date.
  Future<void> resetAttendanceSyncForBootstrap(
      {required LegacyWriteSession expectedSession}) async {
    final db = await database;
    await db.transaction((txn) async {
      await _requireAttendanceSyncSession(txn, expectedSession);
      await txn.delete('cached_attendance');
      await txn.insert(
        'sync_state',
        {
          'domain': 'attendance',
          'cursor': 0,
          'last_sync_at': DateTime.now().toIso8601String(),
          'status': 'bootstrap',
          'error': null,
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    });
  }

  /// Store the cursor a completed bootstrap corresponds to.
  Future<void> completeAttendanceBootstrap(int cursor,
      {required LegacyWriteSession expectedSession}) async {
    final db = await database;
    await db.transaction((txn) async {
      await _requireAttendanceSyncSession(txn, expectedSession);
      await txn.insert(
        'sync_state',
        {
          'domain': 'attendance',
          'cursor': cursor,
          'last_sync_at': DateTime.now().toIso8601String(),
          'status': 'ok',
          'error': null,
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    });
  }

  /// Publish the two legacy attendance read caches together under the captured
  /// durable session. This is NOT complete server preparation or a lease.
  Future<void> cacheAttendanceSnapshot(
      int classId, String date, List<Map<String, dynamic>> students,
      {required LegacyWriteSession expectedSession,
      String? submissionStatus, bool locked = false}) async {
    // Capture both representations before awaiting any database work.
    final updatedAt = DateTime.now().toIso8601String();
    final responseJson = jsonEncode({
      'students': students,
      'submission_status': submissionStatus ?? '',
      'locked': locked,
    });
    final rows = students.map((s) => <String, Object?>{
      'member_id': s['member_id'] ?? s['id'],
      'class_id': classId,
      'student_name': s['student_name'] ?? '',
      'father_name': s['father_name'] ?? '',
      'member_code': s['member_code'] ?? '',
      'gender': s['gender'] ?? '',
      'updated_at': updatedAt,
    }).toList(growable: false);
    final db = await database;
    await db.transaction((txn) async {
      await _requireAttendanceSyncSession(txn, expectedSession);
      await txn.delete('cached_students', where: 'class_id = ?',
          whereArgs: [classId]);
      final batch = txn.batch();
      for (final row in rows) {
        batch.insert('cached_students', row);
      }
      batch.insert('cached_attendance', {
        'class_id': classId,
        'date': date,
        'response_json': responseJson,
        'updated_at': updatedAt,
      }, conflictAlgorithm: ConflictAlgorithm.replace);
      await batch.commit(noResult: true);
    });
  }

  Future<void> cacheAttendanceResponse(int classId, String date, List<Map<String, dynamic>> students,
      {String? submissionStatus, bool locked = false}) async {
    final db = await database;
    await db.insert(
      'cached_attendance',
      {
        'class_id': classId,
        'date': date,
        'response_json': jsonEncode({
          'students': students,
          'submission_status': submissionStatus ?? '',
          'locked': locked,
        }),
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<void> cacheGradeSheet(int assessmentId, int classId, List<Map<String, dynamic>> students,
      {String? submissionStatus, bool locked = false}) async {
    final db = await database;
    await db.insert(
      'cached_grade_sheets',
      {
        'assessment_id': assessmentId,
        'class_id': classId,
        'response_json': jsonEncode({
          'students': students,
          'submission_status': submissionStatus ?? '',
          'locked': locked,
        }),
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Map<String, dynamic> _decodeSheet(String raw) {
    final decoded = jsonDecode(raw);
    if (decoded is List) {
      return {
        'students': decoded.map((e) => Map<String, dynamic>.from(e)).toList(),
        'submission_status': '',
        'locked': false,
      };
    }
    if (decoded is Map) {
      final map = Map<String, dynamic>.from(decoded);
      final list = map['students'];
      return {
        'students': list is List
            ? list.map((e) => Map<String, dynamic>.from(e as Map)).toList()
            : <Map<String, dynamic>>[],
        'submission_status': '${map['submission_status'] ?? ''}',
        'locked': map['locked'] == true,
      };
    }
    return {'students': <Map<String, dynamic>>[], 'submission_status': '', 'locked': false};
  }

  Future<Map<String, dynamic>?> getCachedGradeSheetMeta(int assessmentId) async {
    final db = await database;
    try {
      final rows = await db.query(
        'cached_grade_sheets',
        where: 'assessment_id = ?',
        whereArgs: [assessmentId],
      );
      if (rows.isEmpty) return null;
      return _decodeSheet(rows.first['response_json'] as String);
    } catch (_) {
      return null;
    }
  }

  Future<List<Map<String, dynamic>>?> getCachedGradeSheet(int assessmentId) async {
    final meta = await getCachedGradeSheetMeta(assessmentId);
    if (meta == null) return null;
    return List<Map<String, dynamic>>.from(meta['students'] as List);
  }

  Future<Map<String, dynamic>?> getCachedAttendanceSheet(int classId, String date) async {
    final db = await database;
    try {
      final rows = await db.query(
        'cached_attendance',
        where: 'class_id = ? AND date = ?',
        whereArgs: [classId, date],
      );
      if (rows.isEmpty) return null;
      return _decodeSheet(rows.first['response_json'] as String);
    } catch (_) {
      return null;
    }
  }

  Future<List<Map<String, dynamic>>?> getCachedAttendanceResponse(int classId, String date) async {
    final meta = await getCachedAttendanceSheet(classId, date);
    if (meta == null) return null;
    return List<Map<String, dynamic>>.from(meta['students'] as List);
  }

  @Deprecated('A lock is not an acceptance receipt; do not use for lock cleanup.')
  Future<void> dropPendingAttendance(int classId, String date) async {
    throw StateError('Attendance requires exact server confirmation before removal.');
  }

  @Deprecated('A lock is not an acceptance receipt; do not use for lock cleanup.')
  Future<void> dropPendingGrades(int assessmentId) async {
    final db = await database;
    await db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      await txn.delete(
        'pending_grades',
        // F8: spare workflow-rejected rows (see dropPendingAttendance).
        where: "assessment_id = ? AND synced = 0 "
            "AND sync_state IN ('pending', 'retry_wait') "
            'AND sync_error IS NULL AND owner_user_id = ? '
            'AND created_authorization_version = ?',
        whereArgs: [
          assessmentId,
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
      );
    });
  }

  // ============================================================
  // PENDING COUNTS
  // ============================================================

  // ============================================================
  // PENDING MEZMUR (offline outbox, date-keyed)
  // ============================================================

  Future<LegacyOperationRef> saveMezmurLocal(String date, String section,
      List<Map<String, dynamic>> records,
      {String packetKind = 'draft', DateTime? notBefore}) async {
    // Teacher parity: present / absent / late / excused.
    const validStatuses = {'present', 'absent', 'late', 'excused'};
    if (records.isEmpty) {
      throw ArgumentError('Mezmur records are required.');
    }
    for (final record in records) {
      final status = '${record['status'] ?? ''}'.trim().toLowerCase();
      final memberId = record['member_id'] is int
          ? record['member_id'] as int
          : int.tryParse('${record['member_id'] ?? ''}');
      if (memberId == null || memberId <= 0 || !validStatuses.contains(status)) {
        throw ArgumentError('Attendance must explicitly mark every member.');
      }
    }

    return _replaceLegacyOperation(
      kind: LegacyOperationKind.mezmur,
      table: 'pending_mezmur',
      naturalKeyWhere: 'date = ? AND section = ?',
      naturalKeyArgs: [date, section],
      naturalKey: {'date': date, 'section': section},
      packetKind: packetKind,
      notBefore: notBefore,
      rows: records.map((r) {
        final note = '${r['notes'] ?? r['note'] ?? ''}'.trim();
        return <String, Object?>{
          'date': date,
          'section': section,
          'program': r['program'],
          'member_id': r['member_id'],
          'status': '${r['status']}'.trim().toLowerCase(),
          if (note.isNotEmpty) 'notes': note,
        };
      }).toList(),
    );
  }

  /// Pending packets grouped by (date, section).
  Future<List<Map<String, dynamic>>> getPendingMezmur() async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      return txn.rawQuery('''
        SELECT date, section,
               CASE WHEN SUM(CASE WHEN IFNULL(packet_kind,'draft') = 'submitted' THEN 1 ELSE 0 END) > 0
                    THEN 'submitted' ELSE 'draft' END as packet_kind,
               CASE WHEN COUNT(DISTINCT client_op_id) = 1
                    THEN MIN(client_op_id) ELSE NULL END as client_op_id,
               COUNT(*) as member_count, MIN(created_at) as created_at,
               MAX(CASE WHEN sync_error IS NOT NULL THEN 1 ELSE 0 END) as rejected
        FROM pending_mezmur
        WHERE synced = 0 AND sync_state IN ('pending', 'retry_wait')
          AND owner_user_id = ? AND created_authorization_version = ?
        GROUP BY date, section ORDER BY date DESC
      ''', [
        binding['owner_user_id'],
        binding['created_authorization_version'],
      ]);
    });
  }

  Future<List<Map<String, dynamic>>> getPendingMezmurRecords(
      String date, String section) async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      return txn.query(
        'pending_mezmur',
        where: 'date = ? AND section = ? AND synced = 0 '
            'AND owner_user_id = ? AND created_authorization_version = ?',
        whereArgs: [
          date,
          section,
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
      );
    });
  }



  @Deprecated('A lock is not an acceptance receipt; do not use for lock cleanup.')
  Future<void> dropPendingMezmur(String date, String section) async {
    final db = await database;
    await db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      await txn.delete(
        'pending_mezmur',
        // F8: spare workflow-rejected rows (see dropPendingAttendance).
        where: 'date = ? AND section = ? AND synced = 0 '
            "AND sync_state IN ('pending', 'retry_wait') "
            'AND sync_error IS NULL AND owner_user_id = ? '
            'AND created_authorization_version = ?',
        whereArgs: [
          date,
          section,
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
      );
    });
  }

  Future<int> getPendingMezmurCount() async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      final rows = await txn.rawQuery(
        'SELECT COUNT(DISTINCT client_op_id) AS cnt FROM pending_mezmur '
        'WHERE synced = 0 AND owner_user_id = ? '
        'AND created_authorization_version = ?',
        [
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
      );
      return rows.first['cnt'] as int? ?? 0;
    });
  }

  Future<void> cacheMezmurSheet(
      String date, String section, Map<String, dynamic> payload) async {
    final db = await database;
    await db.insert(
        'cached_mezmur_sheet',
        {
          'date': date,
          'section': section,
          'response_json': jsonEncode(payload),
          'updated_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<Map<String, dynamic>?> getCachedMezmurSheet(
      String date, String section) async {
    final db = await database;
    try {
      final rows = await db.query('cached_mezmur_sheet',
          where: 'date = ? AND section = ?', whereArgs: [date, section]);
      if (rows.isEmpty) return null;
      final raw = rows.first['response_json'] as String?;
      if (raw == null || raw.isEmpty) return null;
      final decoded = jsonDecode(raw);
      return decoded is Map<String, dynamic> ? decoded : null;
    } catch (_) {
      return null;
    }
  }

  /// Warm cache for the [Section ▾] picker (offline parity).
  Future<void> cacheMezmurSections(List<Map<String, dynamic>> sections) async {
    final db = await database;
    await db.insert(
        'cached_mezmur_sections',
        {
          'id': 1,
          'sections_json': jsonEncode(sections),
          'updated_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<List<Map<String, dynamic>>?> getCachedMezmurSections() async {
    final db = await database;
    try {
      final rows = await db.query('cached_mezmur_sections', where: 'id = 1');
      if (rows.isEmpty) return null;
      final raw = rows.first['sections_json'] as String?;
      if (raw == null || raw.isEmpty) return null;
      final decoded = jsonDecode(raw);
      if (decoded is List) {
        return decoded
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .toList();
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  Future<int> getPendingAttendanceCount() async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      final rows = await txn.rawQuery(
        'SELECT COUNT(DISTINCT client_op_id) AS cnt FROM pending_attendance '
        'WHERE synced = 0 AND owner_user_id = ? '
        'AND created_authorization_version = ?',
        [
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
      );
      return rows.first['cnt'] as int? ?? 0;
    });
  }

  Future<int> getPendingGradesCount() async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      final rows = await txn.rawQuery(
        'SELECT COUNT(DISTINCT client_op_id) AS cnt FROM pending_grades '
        'WHERE synced = 0 AND owner_user_id = ? '
        'AND created_authorization_version = ?',
        [
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
      );
      return rows.first['cnt'] as int? ?? 0;
    });
  }

  Future<DateTime?> nextOutboxAttemptAt({
    required int ownerUserId,
    required int authorizationVersion,
  }) async {
    final db = await database;
    final selects = <String>[
      for (final spec in legacyOutboxTableSpecs)
        "SELECT next_attempt_at AS due FROM ${spec.table} "
            "WHERE synced = 0 AND sync_state IN ('pending', 'retry_wait') "
            'AND next_attempt_at IS NOT NULL AND owner_user_id = ? '
            'AND created_authorization_version = ? '
            "${spec.table == 'pending_attendance' ? _attendanceClaimEligibilityWhere : ''}",
      "SELECT next_attempt_at AS due FROM pending_hymn_ops "
          "WHERE synced = 0 AND sync_state IN ('pending', 'retry_wait') "
          'AND next_attempt_at IS NOT NULL',
    ];
    final rows = await db.rawQuery(
      'SELECT MIN(due) AS due FROM (${selects.join(' UNION ALL ')})',
      [
        for (var i = 0; i < legacyOutboxTableSpecs.length; i++) ...[
          ownerUserId,
          authorizationVersion,
        ],
      ],
    );
    final raw = rows.isEmpty ? null : rows.first['due']?.toString();
    return raw == null ? null : DateTime.tryParse(raw)?.toUtc();
  }

  Future<int> getTotalPendingCount() async {
    return (await getPendingAttendanceCount()) +
        (await getPendingGradesCount()) +
        (await getPendingMezmurCount()) +
        (await getPendingHrCount()) +
        (await getPendingHymnOpsCount());
  }

  // ============================================================
  // HR DEPARTMENT ATTENDANCE (offline-first, section-scoped)
  // Structural clone of the mezmur outbox — separate tables, so the
  // two departments' data never touch each other on the phone either.
  // ============================================================

  // HR attendance was RETIRED (2026-10-07): no screen creates new HR
  // packets anymore. Kept for four-outbox symmetry (owner/scope stamping,
  // notBefore, rejected-replacement) — the outbox drain still settles
  // packets queued by old app versions against the server's honest 410.
  Future<LegacyOperationRef> saveHrLocal(String date, String section,
      List<Map<String, dynamic>> records,
      {String packetKind = 'draft', DateTime? notBefore}) async {
    const validStatuses = {'present', 'absent', 'late', 'excused'};
    if (records.isEmpty) {
      throw ArgumentError('HR attendance records are required.');
    }
    for (final record in records) {
      final status = '${record['status'] ?? ''}'.trim().toLowerCase();
      final memberId = record['member_id'] is int
          ? record['member_id'] as int
          : int.tryParse('${record['member_id'] ?? ''}');
      if (memberId == null || memberId <= 0 || !validStatuses.contains(status)) {
        throw ArgumentError('Attendance must explicitly mark every member.');
      }
    }

    return _replaceLegacyOperation(
      kind: LegacyOperationKind.hr,
      table: 'pending_hr',
      naturalKeyWhere: 'date = ? AND section = ?',
      naturalKeyArgs: [date, section],
      naturalKey: {'date': date, 'section': section},
      packetKind: packetKind,
      notBefore: notBefore,
      rows: records.map((r) {
        final note = '${r['notes'] ?? r['note'] ?? ''}'.trim();
        return <String, Object?>{
          'date': date,
          'section': section,
          'member_id': r['member_id'],
          'status': '${r['status']}'.trim().toLowerCase(),
          if (note.isNotEmpty) 'notes': note,
        };
      }).toList(),
    );
  }

  /// Pending HR packets grouped by (date, section).
  Future<List<Map<String, dynamic>>> getPendingHr() async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      return txn.rawQuery('''
        SELECT date, section,
               CASE WHEN SUM(CASE WHEN IFNULL(packet_kind,'draft') = 'submitted' THEN 1 ELSE 0 END) > 0
                    THEN 'submitted' ELSE 'draft' END as packet_kind,
               CASE WHEN COUNT(DISTINCT client_op_id) = 1
                    THEN MIN(client_op_id) ELSE NULL END as client_op_id,
               COUNT(*) as member_count, MIN(created_at) as created_at,
               MAX(CASE WHEN sync_error IS NOT NULL THEN 1 ELSE 0 END) as rejected
        FROM pending_hr
        WHERE synced = 0 AND sync_state IN ('pending', 'retry_wait')
          AND owner_user_id = ? AND created_authorization_version = ?
        GROUP BY date, section ORDER BY date DESC
      ''', [
        binding['owner_user_id'],
        binding['created_authorization_version'],
      ]);
    });
  }

  Future<List<Map<String, dynamic>>> getPendingHrRecords(
      String date, String section) async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      return txn.query(
        'pending_hr',
        where: 'date = ? AND section = ? AND synced = 0 '
            'AND owner_user_id = ? AND created_authorization_version = ?',
        whereArgs: [
          date,
          section,
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
      );
    });
  }



  // ── F8: workflow-rejected outbox batches ──────────────────────────
  //
  // A 409 from the school's workflow (day/test submitted while this
  // phone was offline) is NOT sync success. The batch stays here,
  // unsynced, with the server's reason in sync_error — recoverable
  // until the user explicitly discards it. Rejected batches are
  // excluded from every drain (the feeds above carry `rejected` and
  // the worker skips them) but still count as "not yet sent" in the
  // UI, which is the truth: the data lives only on this phone.









  /// Explicit, user-consented destruction of the exact terminal operation
  /// shown by the review sheet. A stale dialog can never delete a replacement
  /// save that reused the same natural key.
  LegacyOutboxTableSpec _legacyRecoverySpec(String kind) =>
      _legacySpecFor(switch (kind) {
        'attendance' => LegacyOperationKind.attendance,
        'grades' => LegacyOperationKind.grades,
        'mezmur' => LegacyOperationKind.mezmur,
        'hr' => LegacyOperationKind.hr,
        _ => throw ArgumentError.value(kind, 'kind', 'Unknown outbox kind'),
      });

  /// Compare-and-set retry of one exact terminal generation. A stale handle
  /// never falls back to the packet's natural key and therefore changes
  /// nothing if a save, worker claim, or scope transition won the race.
  Future<SyncRecoveryActionResult> retryRejectedOperation(
    String kind,
    String clientOpId,
    String expectedState,
  ) async {
    if (!const {'needs_attention', 'blocked_dependency', 'failed'}
        .contains(expectedState)) {
      return SyncRecoveryActionResult.stale;
    }
    final spec = _legacyRecoverySpec(kind);
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      final baseWhere = 'client_op_id = ? AND synced = 0 '
          'AND owner_user_id = ? AND created_authorization_version = ?';
      final args = <Object?>[
        clientOpId,
        binding['owner_user_id'],
        binding['created_authorization_version'],
      ];
      final rows = await txn.query(spec.table,
          columns: ['sync_state', ...spec.businessKeyColumns],
          where: baseWhere,
          whereArgs: args);
      if (rows.isEmpty ||
          rows.any((row) => '${row['sync_state']}' != expectedState)) {
        return SyncRecoveryActionResult.stale;
      }
      final naturalKey = _legacyNaturalKey(spec, rows.first);
      if (rows.any((row) => spec.businessKeyColumns.any(
          (column) => row[column] != naturalKey[column]))) {
        return SyncRecoveryActionResult.stale;
      }
      final keyWhere = spec.businessKeyColumns
          .map((column) => '$column = ?')
          .join(' AND ');
      final keyArgs = spec.businessKeyColumns
          .map((column) => naturalKey[column])
          .toList(growable: false);
      final affected = await txn.update(
        spec.table,
        {
          'sync_state': 'pending',
          // Attendance ledger numbers must stay monotonic across retries.
          if (kind != 'attendance') 'attempt_count': 0,
          'next_attempt_at': null,
          if (kind != 'attendance') 'last_attempt_at': null,
          'failure_code': null,
          'failure_http_status': null,
          'failed_at': null,
          'sync_error': null,
        },
        where: '$baseWhere AND $keyWhere AND sync_state = ?',
        whereArgs: [...args, ...keyArgs, expectedState],
      );
      return affected == rows.length
          ? SyncRecoveryActionResult.applied
          : SyncRecoveryActionResult.stale;
    });
  }

  /// Confirmed compare-and-set deletion of one exact terminal generation.
  Future<SyncRecoveryActionResult> discardRejectedOperation(
    String kind,
    String clientOpId, {
    String? expectedState,
  }) async {
    // Neither explicit consent nor a local rejection/conflict label is proof
    // that this attendance operation has a terminal server disposition.
    if (kind == 'attendance') {
      return SyncRecoveryActionResult.serverConfirmationRequired;
    }
    const terminal = {
      'needs_attention',
      'blocked_dependency',
      'failed',
      'resolved_conflict',
    };
    if (expectedState != null && !terminal.contains(expectedState)) {
      return SyncRecoveryActionResult.stale;
    }
    final spec = _legacyRecoverySpec(kind);
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      final baseWhere = 'client_op_id = ? AND synced = 0 '
          'AND owner_user_id = ? AND created_authorization_version = ?';
      final args = <Object?>[
        clientOpId,
        binding['owner_user_id'],
        binding['created_authorization_version'],
      ];
      final rows = await txn.query(spec.table,
          columns: ['sync_state', ...spec.businessKeyColumns],
          where: baseWhere,
          whereArgs: args);
      if (rows.isEmpty || rows.any((row) {
        final state = '${row['sync_state']}';
        return !terminal.contains(state) ||
            (expectedState != null && state != expectedState);
      })) {
        return SyncRecoveryActionResult.stale;
      }
      final naturalKey = _legacyNaturalKey(spec, rows.first);
      if (rows.any((row) => spec.businessKeyColumns.any(
          (column) => row[column] != naturalKey[column]))) {
        return SyncRecoveryActionResult.stale;
      }
      final keyWhere = spec.businessKeyColumns
          .map((column) => '$column = ?')
          .join(' AND ');
      final keyArgs = spec.businessKeyColumns
          .map((column) => naturalKey[column])
          .toList(growable: false);
      final affected = await txn.delete(
        spec.table,
        where: expectedState == null
            ? "$baseWhere AND $keyWhere AND sync_state IN ('needs_attention', "
                "'blocked_dependency', 'failed', 'resolved_conflict')"
            : '$baseWhere AND $keyWhere AND sync_state = ?',
        whereArgs: expectedState == null
            ? [...args, ...keyArgs]
            : [...args, ...keyArgs, expectedState],
      );
      return affected == rows.length
          ? SyncRecoveryActionResult.applied
          : SyncRecoveryActionResult.stale;
    });
  }

  /// All rejected batches across the four legacy outboxes, for the
  /// review sheet and the SyncStatus count. Fields: kind, label, detail,
  /// client_op_id and reason (PII-light: names/dates, never member rows).
  Future<List<Map<String, dynamic>>> getRejectedBatches() async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      final owner = binding['owner_user_id'];
      final scope = binding['created_authorization_version'];
      return txn.rawQuery('''
        SELECT 'attendance' AS kind, class_name AS label, date AS detail,
               client_op_id, MAX(sync_error) AS reason
        FROM pending_attendance
        WHERE synced = 0
          AND sync_state IN ('needs_attention', 'resolved_conflict')
          AND owner_user_id = ? AND created_authorization_version = ?
        GROUP BY client_op_id, class_id, date
        UNION ALL
        SELECT 'grades' AS kind, assessment_name AS label,
               COALESCE(class_name, '') AS detail,
               client_op_id, MAX(sync_error) AS reason
        FROM pending_grades
        WHERE synced = 0
          AND sync_state IN ('needs_attention', 'resolved_conflict')
          AND owner_user_id = ? AND created_authorization_version = ?
        GROUP BY client_op_id, assessment_id
        UNION ALL
        SELECT 'mezmur' AS kind, 'Mezmur attendance' AS label,
               date || ' · ' || section AS detail,
               client_op_id, MAX(sync_error) AS reason
        FROM pending_mezmur
        WHERE synced = 0
          AND sync_state IN ('needs_attention', 'resolved_conflict')
          AND owner_user_id = ? AND created_authorization_version = ?
        GROUP BY client_op_id, date, section
        UNION ALL
        SELECT 'hr' AS kind, 'HR attendance' AS label,
               date || ' · ' || section AS detail,
               client_op_id, MAX(sync_error) AS reason
        FROM pending_hr
        WHERE synced = 0
          AND sync_state IN ('needs_attention', 'resolved_conflict')
          AND owner_user_id = ? AND created_authorization_version = ?
        GROUP BY client_op_id, date, section
        ORDER BY kind, detail
      ''', [owner, scope, owner, scope, owner, scope, owner, scope]);
    });
  }


  /// Current-scope detail rows for the Sync Recovery Center. The owner and
  /// authorization predicates are part of every private query. Older-scope
  /// work is intentionally absent here and is surfaced only as a safe count
  /// from [getOutboxInventory]. Shared hymn metadata is returned only when
  /// the current role is allowed to curate the shared library.
  Future<List<SyncRecoveryItem>> getSyncRecoveryItems({
    required bool includeSharedHymns,
  }) async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      final owner = binding['owner_user_id'];
      final scope = binding['created_authorization_version'];
      final rows = await txn.rawQuery('''
        SELECT 'attendance' AS domain, client_op_id AS operation_id,
               NULL AS row_id, sync_state AS state,
               COALESCE(NULLIF(class_name, ''), 'Attendance') AS title,
               date AS detail, MAX(sync_error) AS reason,
               MAX(next_attempt_at) AS next_attempt_at
          FROM pending_attendance
         WHERE synced = 0 AND owner_user_id = ?
           AND created_authorization_version = ?
         GROUP BY client_op_id, sync_state, class_id, class_name, date
        UNION ALL
        SELECT 'grades', client_op_id, NULL, sync_state,
               COALESCE(NULLIF(assessment_name, ''), 'Grades'),
               COALESCE(NULLIF(class_name, ''), 'Grade sheet'),
               MAX(sync_error), MAX(next_attempt_at)
          FROM pending_grades
         WHERE synced = 0 AND owner_user_id = ?
           AND created_authorization_version = ?
         GROUP BY client_op_id, sync_state, assessment_id,
                  assessment_name, class_name
        UNION ALL
        SELECT 'mezmur', client_op_id, NULL, sync_state,
               'Mezmur attendance', date || ' · ' || section,
               MAX(sync_error), MAX(next_attempt_at)
          FROM pending_mezmur
         WHERE synced = 0 AND owner_user_id = ?
           AND created_authorization_version = ?
         GROUP BY client_op_id, sync_state, date, section
        UNION ALL
        SELECT 'hr', client_op_id, NULL, sync_state,
               'HR attendance', date || ' · ' || section,
               MAX(sync_error), MAX(next_attempt_at)
          FROM pending_hr
         WHERE synced = 0 AND owner_user_id = ?
           AND created_authorization_version = ?
         GROUP BY client_op_id, sync_state, date, section
        UNION ALL
        SELECT 'communication', client_tag, NULL, state,
               'Message', 'Conversation #' || thread_id,
               fail_reason, next_attempt_at
          FROM comm_outbox
         WHERE state <> 'synced' AND owner_user_id = ?
           AND created_authorization_version = ?
        ORDER BY next_attempt_at, domain, detail
      ''', [
        owner, scope,
        owner, scope,
        owner, scope,
        owner, scope,
        owner, scope,
      ]);

      final result = <SyncRecoveryItem>[];
      for (final row in rows) {
        final domain = switch ('${row['domain']}') {
          'attendance' => SyncRecoveryDomain.attendance,
          'grades' => SyncRecoveryDomain.grades,
          'mezmur' => SyncRecoveryDomain.mezmur,
          'hr' => SyncRecoveryDomain.hr,
          _ => SyncRecoveryDomain.communication,
        };
        result.add(SyncRecoveryItem(
          domain: domain,
          operationId: '${row['operation_id']}',
          rowId: row['row_id'] == null ? null : _asIntLocal(row['row_id']),
          state: '${row['state']}',
          title: '${row['title']}',
          detail: '${row['detail']}',
          reason: row['reason']?.toString(),
          nextAttemptAt: DateTime.tryParse('${row['next_attempt_at'] ?? ''}')
              ?.toLocal(),
        ));
      }

      if (includeSharedHymns) {
        final hymnRows = await txn.query(
          'pending_hymn_ops',
          columns: [
            'id',
            'client_op_id',
            'op',
            'sync_state',
            'sync_error',
            'next_attempt_at',
          ],
          where: 'synced = 0',
          orderBy: 'id',
        );
        for (final row in hymnRows) {
          final op = '${row['op']}';
          final title = switch (op) {
            'hymn_save' => 'Hymn change',
            'hymn_delete' => 'Hymn archive',
            'category_save' => 'Hymn category change',
            'category_delete' => 'Hymn category removal',
            'zemarian_save' => 'Singer change',
            'zemarian_delete' => 'Singer removal',
            'lyrics_synced' => 'Timed lyrics change',
            _ => 'Shared hymn library change',
          };
          result.add(SyncRecoveryItem(
            domain: SyncRecoveryDomain.hymn,
            operationId: '${row['client_op_id']}',
            rowId: _asIntLocal(row['id']),
            state: '${row['sync_state']}',
            title: title,
            detail: 'Shared library operation',
            reason: row['sync_error']?.toString(),
            nextAttemptAt:
                DateTime.tryParse('${row['next_attempt_at'] ?? ''}')
                    ?.toLocal(),
          ));
        }
      }
      return List<SyncRecoveryItem>.unmodifiable(result);
    });
  }

  /// Exact compare-and-set retry for one shared hymn operation.
  Future<SyncRecoveryActionResult> retryHymnRecoveryOperation(
    int rowId,
    String clientOpId,
    String expectedState,
  ) async {
    if (!const {'needs_attention', 'blocked_dependency', 'failed'}
        .contains(expectedState)) {
      return SyncRecoveryActionResult.stale;
    }
    final db = await database;
    final affected = await db.update(
      'pending_hymn_ops',
      {
        'sync_state': 'pending',
        'attempt_count': 0,
        'next_attempt_at': null,
        'last_attempt_at': null,
        'failure_code': null,
        'failure_http_status': null,
        'failed_at': null,
        'sync_error': null,
      },
      where: 'id = ? AND client_op_id = ? AND synced = 0 '
          'AND sync_state = ?',
      whereArgs: [rowId, clientOpId, expectedState],
    );
    return affected == 1
        ? SyncRecoveryActionResult.applied
        : SyncRecoveryActionResult.stale;
  }

  /// Exact terminal discard/acknowledgement for one shared operation.
  Future<SyncRecoveryActionResult> discardHymnRecoveryOperation(
    int rowId,
    String clientOpId,
    String expectedState,
  ) async {
    if (!const {
      'needs_attention',
      'blocked_dependency',
      'failed',
      'resolved_conflict',
    }.contains(expectedState)) {
      return SyncRecoveryActionResult.stale;
    }
    final db = await database;
    final affected = await db.delete(
      'pending_hymn_ops',
      where: 'id = ? AND client_op_id = ? AND synced = 0 '
          'AND sync_state = ?',
      whereArgs: [rowId, clientOpId, expectedState],
    );
    return affected == 1
        ? SyncRecoveryActionResult.applied
        : SyncRecoveryActionResult.stale;
  }

  @Deprecated('A lock is not an acceptance receipt; do not use for lock cleanup.')
  Future<void> dropPendingHr(String date, String section) async {
    final db = await database;
    await db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      await txn.delete(
        'pending_hr',
        // F8: spare workflow-rejected rows (see dropPendingAttendance).
        where: 'date = ? AND section = ? AND synced = 0 '
            "AND sync_state IN ('pending', 'retry_wait') "
            'AND sync_error IS NULL AND owner_user_id = ? '
            'AND created_authorization_version = ?',
        whereArgs: [
          date,
          section,
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
      );
    });
  }

  Future<int> getPendingHrCount() async {
    final db = await database;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      final rows = await txn.rawQuery(
        'SELECT COUNT(DISTINCT client_op_id) AS cnt FROM pending_hr '
        'WHERE synced = 0 AND owner_user_id = ? '
        'AND created_authorization_version = ?',
        [
          binding['owner_user_id'],
          binding['created_authorization_version'],
        ],
      );
      return rows.first['cnt'] as int? ?? 0;
    });
  }

  Future<void> cacheHrSheet(
      String date, String section, Map<String, dynamic> payload) async {
    final db = await database;
    await db.insert(
        'cached_hr_sheet',
        {
          'date': date,
          'section': section,
          'response_json': jsonEncode(payload),
          'updated_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<Map<String, dynamic>?> getCachedHrSheet(
      String date, String section) async {
    final db = await database;
    try {
      final rows = await db.query('cached_hr_sheet',
          where: 'date = ? AND section = ?', whereArgs: [date, section]);
      if (rows.isEmpty) return null;
      final raw = rows.first['response_json'] as String?;
      if (raw == null || raw.isEmpty) return null;
      final decoded = jsonDecode(raw);
      return decoded is Map<String, dynamic> ? decoded : null;
    } catch (_) {
      return null;
    }
  }

  /// Warm cache for the HR [Section ▾] picker (offline parity).
  Future<void> cacheHrSections(List<Map<String, dynamic>> sections) async {
    final db = await database;
    await db.insert(
        'cached_hr_sections',
        {
          'id': 1,
          'sections_json': jsonEncode(sections),
          'updated_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<List<Map<String, dynamic>>?> getCachedHrSections() async {
    final db = await database;
    try {
      final rows = await db.query('cached_hr_sections', where: 'id = 1');
      if (rows.isEmpty) return null;
      final raw = rows.first['sections_json'] as String?;
      if (raw == null || raw.isEmpty) return null;
      final decoded = jsonDecode(raw);
      if (decoded is List) {
        return decoded
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .toList();
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  Future<void> cleanupSyncedHr() async {
    final db = await database;
    await db.delete('pending_hr', where: 'synced = 1');
  }

  // ============================================================
  // HYMN LIBRARY (offline-first: local store + outbox + cursor)
  // ============================================================

  static int _asIntLocal(dynamic v) =>
      v is int ? v : int.tryParse('$v') ?? 0;

  List<int> _asIntList(dynamic v) {
    if (v is List) {
      return v.map((e) => _asIntLocal(e)).where((e) => e > 0).toList();
    }
    if (v is int && v > 0) return [v];
    return const [];
  }

  /// Upsert rows pulled from the server delta. [protectIds] are rows
  /// with LOCAL edits still queued in the outbox — server deltas must
  /// not clobber an edit the user has not seen synced yet. Archived
  /// deltas flip status so "deleted" hymns leave the active list
  /// without data loss.
  Future<void> upsertHymns(List<dynamic> rows, {Set<int>? protectIds}) async {
    if (rows.isEmpty) return;
    final db = await database;
    final now = DateTime.now().toIso8601String();
    final protected = protectIds ?? const <int>{};
    final indexedRows = <Map<String, dynamic>>[];
    // P38: searchable text BEFORE the write, so we can reindex only the
    // rows whose title/lyrics actually changed. A play-count or cover
    // update must not cost an index rewrite.
    final priorText = <int, (String, String)>{};
    await db.transaction((txn) async {
      final batch = txn.batch();
      for (final h in rows.whereType<Map>()) {
        final id = _asIntLocal(h['id']);
        if (id <= 0) continue;
        if (protected.contains(id)) continue;
        final existing = await txn.query('cached_hymns',
            columns: [
              'title',
              'lyrics',
              'lyrics_synced',
              'audio_status',
              'audio_url',
              'audio_format',
              'audio_size',
              'audio_duration_s',
              'audio_updated_at',
              'art_status',
              'art_color',
              'art_url',
              'art_url_medium',
              'art_url_small'
            ],
            where: 'id = ?',
            whereArgs: [id],
            limit: 1);
        priorText[id] = (
          '${existing.isNotEmpty ? existing.first['title'] ?? '' : ''}',
          '${existing.isNotEmpty ? existing.first['lyrics'] ?? '' : ''}');
        final stored = Map<String, dynamic>.from(h);
        if (!h.containsKey('lyrics') && existing.isNotEmpty) {
          stored['lyrics'] = existing.first['lyrics'];
        }
        indexedRows.add(stored);
        // P0 audio/synced-lyrics preservation: a delta that does NOT
        // carry a heavy field (e.g. synced LRC, which is fetched lazily
        // per hymn) must not wipe the cached copy — same rule as lyrics.
        final old = existing.isEmpty ? <String, dynamic>{} : existing.first;
        String? textPreserve(String key, [String? def]) =>
            h.containsKey(key) ? '${h[key] ?? ''}' : (old[key] as String? ?? def);
        int? intPreserve(String key) => h.containsKey(key)
            ? (h[key] == null ? null : _asIntLocal(h[key]))
            : (old[key] == null ? null : _asIntLocal(old[key]));
        batch.insert(
          'cached_hymns',
          {
            'id': id,
            'title': '${h['title'] ?? ''}',
            'category': h['category'] ?? '',
            'lyrics': h.containsKey('lyrics')
              ? h['lyrics']
              : (existing.isEmpty ? null : existing.first['lyrics']),
            'status': '${h['status'] ?? 'active'}',
            'length': '${h['length'] ?? 'long'}',
            'language': '${h['language'] ?? 'amharic'}',
            'revision': _asIntLocal(h['revision']),
            'server_updated_at': '${h['updated_at'] ?? ''}',
            'fetched_at': now,
            // P0 media columns (audio_status always rides the delta; the
            // rest preserve the cached copy when the payload omits them).
            'audio_status': textPreserve('audio_status', 'none') ?? 'none',
            'audio_url': textPreserve('audio_url') ?? '',
            'audio_format': textPreserve('audio_format'),
            'audio_size': intPreserve('audio_size'),
            'audio_duration_s': intPreserve('audio_duration_s'),
            'audio_updated_at': textPreserve('audio_updated_at'),
            // P48: DEFENSIVE MERGE for timed lyrics.
            //
            // An EMPTY STRING from the server is ambiguous: it means
            // either "this hymn genuinely has no timings" or "my schema
            // lacks the column so I cannot tell you". Older deployments
            // emit '' AS lyrics_synced for the second case, and the old
            // rule below dutifully wiped perfectly good local LRC on
            // EVERY delta pull — which is why karaoke highlighting,
            // animation and auto-scroll silently stopped working on the
            // phone while the web player was fine.
            //
            // Rule now:
            //   key absent      -> keep local (nothing was said)
            //   value null      -> keep local (column unknown/absent)
            //   value ''        -> keep local (cannot distinguish -> do
            //                      not destroy user work)
            //   non-empty value -> take it (the only authoritative case)
            //
            // Clearing timings is therefore driven by the explicit
            // lyrics_synced op, not by an ambiguous sync payload. Losing
            // a curator's work is far worse than a stale clear that the
            // next real edit fixes.
            'lyrics_synced': _mergeSyncedLyrics(
                h.containsKey('lyrics_synced') ? h['lyrics_synced'] : null,
                old['lyrics_synced'] as String?),
            'lyrics_synced_at': textPreserve('lyrics_synced_at'),
            // P66 hymn art: server-authoritative like audio_status —
            // the fields ride every delta (a probe-guarded server
            // reports status 'none'), so apply them whenever present;
            // an ABSENT key (pre-art payload, e.g. an old save echo)
            // keeps the cached copy. UI renders nothing unless
            // art_status == 'ready', so a stale URL can never show.
            'art_status': textPreserve('art_status', 'none') ?? 'none',
            'art_color': textPreserve('art_color'),
            'art_url': textPreserve('art_url') ?? '',
            'art_url_medium': textPreserve('art_url_medium') ?? '',
            'art_url_small': textPreserve('art_url_small') ?? '',
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
        // P25 (singer/category sync fix): the API speaks BOTH shapes —
        // delta pulls send `category_ids`/`zemarian_ids` (int lists) while
        // hymn save echoes send `categories`/`zemarians` (object lists).
        // Previously the echo shape was ignored, so a hymn saved offline
        // LOST its joins on-device until the next delta pull (its
        // placeholder joins were dropped right after). Normalize both.
        final catIds = h.containsKey('category_ids')
            ? _asIntList(h['category_ids'])
            : _idListOfMaps(h['categories']);
        final zemIds = h.containsKey('zemarian_ids')
            ? _asIntList(h['zemarian_ids'])
            : _idListOfMaps(h['zemarians']);
        if (catIds != null || zemIds != null) {
          await txn.delete('cached_hymn_categories',
              where: 'hymn_id = ?', whereArgs: [id]);
          await txn.delete('cached_hymn_zemarians',
              where: 'hymn_id = ?', whereArgs: [id]);
          for (final cid in catIds ?? const <int>[]) {
            await txn.insert(
                'cached_hymn_categories', {'hymn_id': id, 'category_id': cid});
          }
          for (final zid in zemIds ?? const <int>[]) {
            await txn.insert(
                'cached_hymn_zemarians', {'hymn_id': id, 'zemarian_id': zid});
          }
        }
      }
      await batch.commit(noResult: true);
    });
    // P38: reindex only rows whose SEARCHABLE text actually changed, and
    // do it through the dirty queue so a sync that touches hundreds of
    // hymns cannot stall on index writes, and an interruption leaves the
    // work queued rather than silently lost.
    final dirty = <int>[];
    for (final hymn in indexedRows) {
      final id = _asIntLocal(hymn['id']);
      if (id <= 0) continue;
      final before = priorText[id];
      if (before == null ||
          SearchIndexPolicy.needsReindex(
            oldTitle: before.$1,
            oldLyrics: before.$2,
            newTitle: '${hymn['title'] ?? ''}',
            newLyrics: '${hymn['lyrics'] ?? ''}',
          )) {
        dirty.add(id);
      }
    }
    await markHymnsDirty(dirty);
    await processDirtySearchRows();
  }

  /// Fill lyrics into an already-cached row (lazy blob download).
  /// P48: delegate to the shared, unit-tested policy so the DB layer and
  /// any future caller can never disagree about when a sync payload may
  /// overwrite a curator's timings.
  static String? _mergeSyncedLyrics(Object? incoming, String? local) =>
      SyncedLyricsMerge.merge(incoming: incoming, local: local);

  /// P46: write timed (LRC) lyrics locally.
  ///
  /// Optimistic: the karaoke view reflects an edit before the upload
  /// happens, so timing work is visible offline. `revision` is left
  /// alone — the server owns it, and the delta pull will reconcile.
  Future<void> updateHymnSyncedLyrics(int hymnId, String? lrc) async {
    final db = await database;
    await db.update(
      'cached_hymns',
      {
        'lyrics_synced': lrc,
        'lyrics_synced_at': DateTime.now().toUtc().toIso8601String(),
      },
      where: 'id = ?',
      whereArgs: [hymnId],
    );
  }

  Future<void> updateHymnLyrics(int id, String lyrics, int revision) async {
    final db = await database;
    await db.rawUpdate(
        'UPDATE cached_hymns SET lyrics = ?, revision = MAX(revision, ?), fetched_at = ? WHERE id = ?',
        [lyrics, revision, DateTime.now().toIso8601String(), id]);
    // P38: lyrics blobs stream in long after the row was cached, and
    // this is the ONLY moment a hymn becomes searchable by its body.
    // Route it through the dirty queue like every other write.
    await markHymnsDirty([id]);
    await processDirtySearchRows();
  }

  /// Indexed local search. The query returns only word-index candidates, so
  /// low-end devices do not load and scan every cached lyrics blob.
  Future<List<Map<String, dynamic>>> searchHymnCandidates(
    String search, {
    String? category,
    bool includeArchived = false,
    String? length,
    String? language,
    int? categoryId,
    int? zemarianId,
    int limit = 500,
  }) async {
    final db = await database;
    final terms = amharic.queryTerms(search);
    if (terms.isEmpty) return const [];
    // P37: rank candidates by HOW MANY query terms they contain, so a
    // hymn matching every word is fetched even when thousands of rows
    // contain one common word.
    //
    // The previous query was `WHERE word LIKE a% OR word LIKE b% LIMIT n`,
    // which is wrong twice over: LIMIT applied to an unordered set, so
    // the best row was routinely cut before ranking ever saw it, and
    // multi-word queries behaved as pure OR with no preference for rows
    // matching everything. GROUP BY + ORDER BY hits fixes both while
    // still returning partial matches (below the complete ones).
    // P39: retrieval must find SUBSTRINGS, not just prefixes. Amharic
    // words carry grammatical prefixes (በ-, ለ-, የ-, ከ-), so the root the
    // user types is usually NOT at the start of the stored word — the
    // old `word LIKE 'term%'` silently missed those, which is the
    // "it says no match but there is" bug.
    //
    // `LIKE '%term%'` would find them but cannot use an index. So we
    // look candidates up by TRIGRAM equality (indexed) and let the Dart
    // ranker verify exactly. Terms shorter than a trigram have no
    // interior grams, so they fall back to a prefix probe on the word
    // index — that is the 1-2 character type-ahead case.
    final gramTerms = terms.where(SearchMatching.isIndexable).toList();
    final shortTerms = terms.where((t) => !SearchMatching.isIndexable(t));

    List<Map<String, Object?>> idRows = const [];
    if (gramTerms.isNotEmpty) {
      final grams = <String>{};
      for (final t in gramTerms) {
        grams.addAll(SearchMatching.queryGrams(t));
      }
      if (grams.isNotEmpty) {
        final ph = List.filled(grams.length, '?').join(',');
        idRows = await db.rawQuery(
            'SELECT hymn_id, COUNT(DISTINCT gram) AS hits '
            'FROM hymn_search_grams WHERE gram IN ($ph) '
            'GROUP BY hymn_id ORDER BY hits DESC LIMIT ?',
            [...grams, limit]);
      }
    }
    if (idRows.isEmpty) {
      // Short query (or nothing gram-indexed): prefix probe. Cheap and
      // indexed, and enough to make single letters feel instant.
      final probes = [...shortTerms, ...gramTerms];
      if (probes.isEmpty) return const [];
      final ors = probes.map((_) => 'word LIKE ?').join(' OR ');
      idRows = await db.rawQuery(
          'SELECT hymn_id, COUNT(DISTINCT word) AS hits '
          'FROM hymn_search_words WHERE $ors '
          'GROUP BY hymn_id ORDER BY hits DESC LIMIT ?',
          [for (final t in probes) '$t%', limit]);
    }
    final ids = idRows.map((row) => _asIntLocal(row['hymn_id'])).where((id) => id > 0).toList();
    if (ids.isEmpty) return const [];
    return _getLocalHymnsByIds(db, ids,
        category: category,
        includeArchived: includeArchived,
        length: length,
        language: language,
        categoryId: categoryId,
        zemarianId: zemarianId);
  }

  Future<List<Map<String, dynamic>>> _getLocalHymnsByIds(
      Database db, List<int> ids,
      {String? category,
      bool includeArchived = false,
      String? length,
      String? language,
      int? categoryId,
      int? zemarianId}) async {
    final where = <String>['id IN (${List.filled(ids.length, '?').join(',')})'];
    final args = <dynamic>[...ids];
    if (!includeArchived) where.add("status = 'active'");
    if (category != null && category.isNotEmpty) {
      where.add('category = ?');
      args.add(category);
    }
    if (length != null && length.isNotEmpty) {
      where.add('length = ?');
      args.add(length);
    }
    if (language != null && language.isNotEmpty) {
      where.add('language = ?');
      args.add(language);
    }
    if (categoryId != null && categoryId > 0) {
      where.add(
          'EXISTS (SELECT 1 FROM cached_hymn_categories cc WHERE cc.hymn_id = cached_hymns.id AND (cc.category_id = ? OR cc.category_id IN (SELECT id FROM cached_mezmur_categories WHERE parent_id = ?)))');
      args.add(categoryId);
      args.add(categoryId);
    }
    if (zemarianId != null && zemarianId > 0) {
      where.add('EXISTS (SELECT 1 FROM cached_hymn_zemarians cz WHERE cz.hymn_id = cached_hymns.id AND cz.zemarian_id = ?)');
      args.add(zemarianId);
    }
    return db.query('cached_hymns',
        where: where.join(' AND '),
        whereArgs: args,
        orderBy: 'title COLLATE NOCASE');
  }

  /// Structural (non-search) hymn filters, shared by the list query and
  /// the P28 filter-sheet count query so the two can never diverge.
  void _hymnStructWhere(
    List<String> where,
    List<dynamic> args, {
    String? category,
    bool includeArchived = false,
    String? length,
    String? language,
    int? categoryId,
    int? zemarianId,
  }) {
    if (!includeArchived) where.add("status = 'active'");
    if (category != null && category.isNotEmpty) {
      where.add('category = ?');
      args.add(category);
    }
    if (length != null && length.isNotEmpty) {
      where.add('length = ?');
      args.add(length);
    }
    if (language != null && language.isNotEmpty) {
      where.add('language = ?');
      args.add(language);
    }
    if (categoryId != null && categoryId > 0) {
      where.add(
          'EXISTS (SELECT 1 FROM cached_hymn_categories cc WHERE cc.hymn_id = cached_hymns.id AND (cc.category_id = ? OR cc.category_id IN (SELECT id FROM cached_mezmur_categories WHERE parent_id = ?)))');
      args.add(categoryId);
      args.add(categoryId);
    }
    if (zemarianId != null && zemarianId > 0) {
      where.add(
          'EXISTS (SELECT 1 FROM cached_hymn_zemarians cz WHERE cz.hymn_id = cached_hymns.id AND cz.zemarian_id = ?)');
      args.add(zemarianId);
    }
  }

  /// Instant local search across title (P28: single Amharic title).
  /// Local-first: the list never waits on the network.
  Future<List<Map<String, dynamic>>> getLocalHymns({
    String? search,
    String? category,
    bool includeArchived = false,
    String? length,
    String? language,
    int? categoryId,
    int? zemarianId,
    int limit = 500,
  }) async {
    final db = await database;
    final where = <String>[];
    final args = <dynamic>[];
    _hymnStructWhere(where, args,
        category: category,
        includeArchived: includeArchived,
        length: length,
        language: language,
        categoryId: categoryId,
        zemarianId: zemarianId);
    if (search != null && search.trim().isNotEmpty) {
      final like = '%${search.trim()}%';
      where.add('title LIKE ?');
      args.add(like);
    }
    return db.query(
      'cached_hymns',
      where: where.isEmpty ? null : where.join(' AND '),
      whereArgs: args.isEmpty ? null : args,
      orderBy: 'title COLLATE NOCASE',
      limit: limit,
    );
  }

  /// P28 (item 5): live result count for the filter sheet's Apply
  /// button ("Show 47 hymns") — same structural filters as the list.
  Future<int> countLocalHymns({
    String? category,
    bool includeArchived = false,
    String? length,
    String? language,
    int? categoryId,
    int? zemarianId,
  }) async {
    final db = await database;
    final where = <String>[];
    final args = <dynamic>[];
    _hymnStructWhere(where, args,
        category: category,
        includeArchived: includeArchived,
        length: length,
        language: language,
        categoryId: categoryId,
        zemarianId: zemarianId);
    final rows = await db.rawQuery(
        'SELECT COUNT(*) c FROM cached_hymns${where.isEmpty ? '' : ' WHERE ${where.join(' AND ')}'}',
        args.isEmpty ? null : args);
    return rows.isEmpty ? 0 : _asIntLocal(rows.first['c']);
  }

  Future<Map<String, dynamic>?> getLocalHymn(int id) async {
    final db = await database;
    final rows = await db.query('cached_hymns', where: 'id = ?', whereArgs: [id]);
    return rows.isEmpty ? null : rows.first;
  }

  /// Hymns whose lyrics blob has not been downloaded yet (prefetch queue).
  Future<List<Map<String, dynamic>>> getHymnsMissingLyrics(int limit) async {
    final db = await database;
    return db.query(
      'cached_hymns',
      where: "status = 'active' AND (lyrics IS NULL)",
      orderBy: 'id',
      limit: limit,
    );
  }

  Future<int> getLocalHymnCount() async {
    final db = await database;
    final r = await db.rawQuery("SELECT COUNT(*) c FROM cached_hymns WHERE status = 'active'");
    return _asIntLocal(r.first['c']);
  }

  // ── categories ──────────────────────────────────────────────

  /// Replace the local category list with the server's canonical one.
  ///
  /// This is a RECONCILING sync, not a blind upsert. The categories
  /// endpoint always returns the *complete* list, so any local row whose
  /// id is absent from [rows] no longer exists on the server and must be
  /// deleted — otherwise a category deleted in the web admin lingers on
  /// every phone forever (there is no per-row tombstone to pull).
  ///
  /// Two things are deliberately preserved:
  ///   • negative ids — offline-created rows that have not been pushed
  ///     yet. They are not "missing from the server", they were never
  ///     sent. Deleting them would destroy unsynced user work.
  ///   • rows named in [protectIds] — ids with a queued local edit.
  ///
  /// [authoritative] must be false when the caller could not actually
  /// reach the server; an empty list from a failed request must never be
  /// read as "the server has no categories".
  Future<void> upsertCategories(List<dynamic> rows,
      {bool authoritative = false, Set<int> protectIds = const {}}) async {
    if (rows.isEmpty && !authoritative) return;
    final db = await database;
    final now = DateTime.now().toIso8601String();
    final serverIds = <int>{};
    await db.transaction((txn) async {
      final batch = txn.batch();
      for (final c in rows.whereType<Map>()) {
        final id = _asIntLocal(c['id']);
        if (id <= 0) continue;
        serverIds.add(id);
        batch.insert(
          'cached_mezmur_categories',
          {
            'id': id,
            'name': '${c['name'] ?? ''}',
            'parent_id': c['parent_id'] == null ? null : _asIntLocal(c['parent_id']),
            'image_url': c['image_url'] == null || '${c['image_url']}' == ''
                ? null
                : '${c['image_url']}',
            'gradient_start':
                c['gradient_start'] == null || '${c['gradient_start']}' == ''
                    ? null
                    : '${c['gradient_start']}',
            'gradient_end':
                c['gradient_end'] == null || '${c['gradient_end']}' == ''
                    ? null
                    : '${c['gradient_end']}',
            'sort_order': _asIntLocal(c['sort_order']),
            'is_active': _asIntLocal(c['is_active']),
            'updated_at': now,
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await batch.commit(noResult: true);

      if (!authoritative) return;
      // ── reconcile: drop what the server no longer has ──────────
      // Decision lives in TaxonomyReconcile so it is unit-tested; this
      // block only applies the result.
      final localRows =
          await txn.query('cached_mezmur_categories', columns: ['id']);
      final stale = TaxonomyReconcile.staleIds(
        localIds: localRows.map((r) => _asIntLocal(r['id'])),
        serverIds: serverIds,
        protectIds: protectIds,
      );
      if (stale.isEmpty) return;
      final marks = List.filled(stale.length, '?').join(',');
      await txn.delete('cached_mezmur_categories',
          where: 'id IN ($marks)', whereArgs: stale);
      // Join rows would otherwise keep pointing at a category that is
      // gone, leaving hymns filed under a phantom section.
      await txn.delete('cached_hymn_categories',
          where: 'category_id IN ($marks)', whereArgs: stale);
      // A deleted MAIN category orphans its subs. The server drops them
      // too (FK cascade), so they are already absent from `rows` and the
      // sweep above catches them; this only cleans a parent pointer left
      // dangling by an out-of-order response.
      await txn.update('cached_mezmur_categories', {'parent_id': null},
          where: 'parent_id IN ($marks)', whereArgs: stale);
    });
  }

  /// Apply a category edit instantly (optimistic local-first write).
  Future<void> upsertCategoryLocal(Map<String, dynamic> c) async {
    final db = await database;
    // REPLACE rewrites the WHOLE row, so columns the caller does not
    // carry (parent_id, image_url) must be merged from the existing
    // row — a rename must never flatten a sub back to a main.
    final existing = await db.query('cached_mezmur_categories',
        where: 'id = ?', whereArgs: [_asIntLocal(c['id'])], limit: 1);
    final prev = existing.isNotEmpty
        ? existing.first
        : <String, Object?>{};
    await db.insert(
      'cached_mezmur_categories',
      {
        'id': _asIntLocal(c['id']),
        'name': '${c['name'] ?? prev['name'] ?? ''}',
        'parent_id': c.containsKey('parent_id')
            ? (c['parent_id'] == null ||
                    '${c['parent_id']}'.trim().isEmpty ||
                    _asIntLocal(c['parent_id']) <= 0
                ? null
                : _asIntLocal(c['parent_id']))
            : prev['parent_id'],
        'image_url': '${c['image_url'] ?? prev['image_url'] ?? ''}',
        'gradient_start':
            '${c['gradient_start'] ?? prev['gradient_start'] ?? ''}',
        'gradient_end': '${c['gradient_end'] ?? prev['gradient_end'] ?? ''}',
        'sort_order': _asIntLocal(c['sort_order'] ?? prev['sort_order'] ?? 0),
        'is_active': _isOne(c['is_active'] ?? prev['is_active']),
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  int _isOne(dynamic v) => v == 1 || v == '1' ? 1 : 0;

  Future<List<Map<String, dynamic>>> getLocalCategories({bool activeOnly = true}) async {
    final db = await database;
    return db.query(
      'cached_mezmur_categories',
      where: activeOnly ? 'is_active = 1' : null,
      orderBy: 'sort_order, name COLLATE NOCASE',
    );
  }

  // ── zemarians (singers) + associations ─────────────────────

  /// Reconciling sync for singers — same contract as [upsertCategories]:
  /// server list is canonical, absent ids are deleted, negative
  /// (offline-created) and [protectIds] rows survive.
  Future<void> upsertZemarians(List<dynamic> rows,
      {bool authoritative = false, Set<int> protectIds = const {}}) async {
    if (rows.isEmpty && !authoritative) return;
    final db = await database;
    final now = DateTime.now().toIso8601String();
    final serverIds = <int>{};
    await db.transaction((txn) async {
      final batch = txn.batch();
      for (final z in rows.whereType<Map>()) {
        final id = _asIntLocal(z['id']);
        if (id <= 0) continue;
        serverIds.add(id);
        batch.insert(
          'cached_mezmur_zemarians',
          {
            'id': id,
            'name': '${z['name'] ?? ''}',
            'name_am': z['name_am'],
            'image_url': z['image_url'] == null || '${z['image_url']}' == ''
                ? null
                : '${z['image_url']}',
            'sort_order': _asIntLocal(z['sort_order']),
            'is_active': _asIntLocal(z['is_active']),
            'updated_at': now,
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await batch.commit(noResult: true);

      if (!authoritative) return;
      final localRows =
          await txn.query('cached_mezmur_zemarians', columns: ['id']);
      final stale = TaxonomyReconcile.staleIds(
        localIds: localRows.map((r) => _asIntLocal(r['id'])),
        serverIds: serverIds,
        protectIds: protectIds,
      );
      if (stale.isEmpty) return;
      final marks = List.filled(stale.length, '?').join(',');
      await txn.delete('cached_mezmur_zemarians',
          where: 'id IN ($marks)', whereArgs: stale);
      await txn.delete('cached_hymn_zemarians',
          where: 'zemarian_id IN ($marks)', whereArgs: stale);
    });
  }

  Future<void> upsertZemarianLocal(Map<String, dynamic> z) async {
    final db = await database;
    // Merge from the existing row: REPLACE rewrites the whole row and a
    // local rename/hide must never wipe the singer's cover image (P34).
    final existing = await db.query('cached_mezmur_zemarians',
        where: 'id = ?', whereArgs: [_asIntLocal(z['id'])], limit: 1);
    final prev = existing.isNotEmpty ? existing.first : <String, Object?>{};
    await db.insert(
      'cached_mezmur_zemarians',
      {
        'id': _asIntLocal(z['id']),
        'name': '${z['name'] ?? prev['name'] ?? ''}',
        'name_am': z['name_am'] ?? prev['name_am'],
        'image_url': '${z['image_url'] ?? prev['image_url'] ?? ''}',
        'sort_order': _asIntLocal(z['sort_order'] ?? prev['sort_order'] ?? 0),
        'is_active': _isOne(z['is_active'] ?? prev['is_active']),
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<List<Map<String, dynamic>>> getLocalZemarians({bool activeOnly = true}) async {
    final db = await database;
    return db.query(
      'cached_mezmur_zemarians',
      where: activeOnly ? 'is_active = 1' : null,
      orderBy: 'sort_order, name COLLATE NOCASE',
    );
  }

  /// P24: hymn counts per category / singer (Streaming-style tiles) —
  /// active hymns only, computed on-device from the cached joins.
  Future<Map<int, int>> getCategoryHymnCounts() async {
    final db = await database;
    // P30: rolled-up counts — a MAIN's total includes every sub's
    // hymns (deduped via EXISTS), a SUB counts its own leaves.
    final rows = await db.rawQuery(
      "SELECT c.id AS tid, (SELECT COUNT(*) FROM cached_hymns h "
      "WHERE h.status = 'active' AND EXISTS (SELECT 1 FROM cached_hymn_categories cc "
      "WHERE cc.hymn_id = h.id AND (cc.category_id = c.id OR cc.category_id IN "
      "(SELECT id FROM cached_mezmur_categories WHERE parent_id = c.id)))) AS n "
      "FROM cached_mezmur_categories c");
    return {for (final r in rows) _asIntLocal(r['tid']): _asIntLocal(r['n'])};
  }

  Future<Map<int, int>> getZemarianHymnCounts() async {
    final db = await database;
    final rows = await db.rawQuery(
      "SELECT cz.zemarian_id AS tid, COUNT(*) AS n FROM cached_hymn_zemarians cz "
      "JOIN cached_hymns h ON h.id = cz.hymn_id AND h.status = 'active' "
      "GROUP BY cz.zemarian_id");
    return {for (final r in rows) _asIntLocal(r['tid']): _asIntLocal(r['n'])};
  }

  /// Extract ids from an API object list ({'id':..,'name':..}); null
  /// when the value is absent so callers can distinguish "no data" from
  /// "empty list".
  List<int>? _idListOfMaps(dynamic v) {
    if (v == null) return null;
    if (v is! List) return const [];
    final out = <int>[];
    for (final e in v) {
      if (e is Map) {
        final id = _asIntLocal(e['id']);
        if (id > 0) out.add(id);
      }
    }
    return out;
  }

  Future<List<int>> getHymnCategoryIds(int hymnId) async {
    final db = await database;
    final rows = await db.query('cached_hymn_categories',
        columns: ['category_id'], where: 'hymn_id = ?', whereArgs: [hymnId]);
    // P23: placeholder ids (< 0, offline-created taxonomy) are returned
    // too — filtering them out made the editor silently forget the
    // selection, and re-saving erased the links on-device.
    return rows.map((r) => _asIntLocal(r['category_id'])).where((e) => e != 0).toList();
  }

  Future<List<int>> getHymnZemarianIds(int hymnId) async {
    final db = await database;
    final rows = await db.query('cached_hymn_zemarians',
        columns: ['zemarian_id'], where: 'hymn_id = ?', whereArgs: [hymnId]);
    return rows.map((r) => _asIntLocal(r['zemarian_id'])).where((e) => e != 0).toList();
  }

  // ── outbox: queued hymn mutations ───────────────────────────

  String? _hymnEntityKey(String op, Map<String, dynamic> payload) {
    final id = _asIntLocal(payload['id']);
    if (op.startsWith('hymn_') || op == 'lyrics_synced') {
      return id == 0 ? null : 'hymn:$id';
    }
    if (op.startsWith('category_')) {
      if (id != 0) return 'category:$id';
      final name = '${payload['name'] ?? ''}'.trim().toLowerCase();
      return name.isEmpty ? null : 'category-name:$name';
    }
    if (op.startsWith('zemarian_')) {
      if (id != 0) return 'zemarian:$id';
      final name = '${payload['name'] ?? ''}'.trim().toLowerCase();
      return name.isEmpty ? null : 'zemarian-name:$name';
    }
    return null;
  }

  bool _hasNegativeHymnReference(Map<String, dynamic> payload) {
    for (final field in const ['categories', 'zemarians']) {
      final values = payload[field];
      if (values is! List) continue;
      for (final value in values) {
        final id = value is Map
            ? _asIntLocal(value['id'])
            : _asIntLocal(value);
        if (id < 0) return true;
      }
    }
    return false;
  }

  Future<int?> _hymnDependencyFor(
    Transaction txn,
    String op,
    Map<String, dynamic> payload,
    String? entityKey,
  ) async {
    final ids = <int>[];
    if (entityKey != null) {
      final prior = await txn.query(
        'pending_hymn_ops',
        columns: ['id'],
        where: 'synced = 0 AND entity_key = ?',
        whereArgs: [entityKey],
        orderBy: 'id DESC',
        limit: 1,
      );
      if (prior.isNotEmpty) ids.add(_asIntLocal(prior.first['id']));
    }
    final isPlaceholderTaxonomy =
        (op == 'category_save' || op == 'zemarian_save') &&
            _asIntLocal(payload['id']) < 0;
    if (isPlaceholderTaxonomy ||
        (op == 'hymn_save' && _hasNegativeHymnReference(payload))) {
      final priorPlaceholder = await txn.rawQuery('''
        SELECT id FROM pending_hymn_ops
         WHERE synced = 0
           AND (entity_key LIKE 'category:-%'
                OR entity_key LIKE 'zemarian:-%')
         ORDER BY id DESC LIMIT 1
      ''');
      if (priorPlaceholder.isNotEmpty) {
        ids.add(_asIntLocal(priorPlaceholder.first['id']));
      }
    }
    ids.removeWhere((id) => id <= 0);
    if (ids.isEmpty) return null;
    return ids.reduce((a, b) => a > b ? a : b);
  }

  Future<int> enqueueHymnOp(String op, Map<String, dynamic> payload) async {
    final db = await database;
    final opId = newClientOpId();
    payload['client_op_id'] = opId;
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      final entityKey = _hymnEntityKey(op, payload);
      final dependsOn =
          await _hymnDependencyFor(txn, op, payload, entityKey);
      return txn.insert('pending_hymn_ops', {
        'op': op,
        'payload_json': jsonEncode(payload),
        'client_op_id': opId,
        'created_at': DateTime.now().toIso8601String(),
        'created_by_user_id': binding['owner_user_id'],
        'created_authorization_version':
            binding['created_authorization_version'],
        'entity_key': entityKey,
        'depends_on': dependsOn,
      });
    });
  }

  Future<List<Map<String, dynamic>>> getPendingHymnOps() async {
    final db = await database;
    return db.query('pending_hymn_ops', where: 'synced = 0', orderBy: 'id');
  }

  Future<int> getPendingHymnOpsCount() async {
    final db = await database;
    final r = await db.rawQuery(
        'SELECT COUNT(*) c FROM pending_hymn_ops WHERE synced = 0');
    return _asIntLocal(r.first['c']);
  }

  /// Claims one due hymn operation. New keyed operations are FIFO per entity;
  /// old unkeyed rows conservatively retain global FIFO ordering. A failed
  /// prerequisite blocks its dependent payload instead of letting it overtake.
  Future<HymnOutboxClaim?> claimNextHymnOperation({
    required int runtimeGeneration,
    required int ownerUserId,
    required int authorizationVersion,
    DateTime? now,
    // S2 Goal A.7: the provenance of the drain performing this claim. The
    // authoritative execution boundary passes it down; it is never inferred
    // here from who called us.
    SyncExecutionSource executionSource = SyncExecutionSource.foreground,
  }) async {
    final db = await database;
    final hasAttemptLedger = await _tableExists(db, 'sync_attempts');
    final claimedAt = (now ?? DateTime.now()).toUtc();
    final claimedAtText = claimedAt.toIso8601String();
    return db.transaction((txn) async {
      final sessionMatches = await activeSessionMatches(
        runtimeGeneration: runtimeGeneration,
        ownerUserId: ownerUserId,
        authorizationVersion: authorizationVersion,
        executor: txn,
      );
      if (!sessionMatches) return null;
      await txn.rawUpdate('''
        UPDATE pending_hymn_ops
           SET sync_state = 'blocked_dependency',
               failure_code = 'DEPENDENCY_UNRESOLVED',
               sync_error = 'A required earlier change needs attention.',
               next_attempt_at = NULL
         WHERE synced = 0
           AND sync_state IN ('pending', 'retry_wait')
           AND depends_on IS NOT NULL
           AND EXISTS (
             SELECT 1 FROM pending_hymn_ops dependency
              WHERE dependency.id = pending_hymn_ops.depends_on
                AND dependency.synced = 0
                AND dependency.sync_state IN (
                  'needs_attention', 'paused_scope', 'resolved_conflict',
                  'blocked_dependency'
                )
           )
      ''');
      final rows = await txn.rawQuery('''
        SELECT candidate.*
          FROM pending_hymn_ops candidate
         WHERE candidate.synced = 0
           AND candidate.sync_state IN ('pending', 'retry_wait')
           AND (candidate.next_attempt_at IS NULL
                OR candidate.next_attempt_at <= ?)
           AND (
             candidate.depends_on IS NULL OR EXISTS (
               SELECT 1 FROM pending_hymn_ops dependency
                WHERE dependency.id = candidate.depends_on
                  AND dependency.synced = 1
             )
           )
           AND NOT EXISTS (
             SELECT 1 FROM pending_hymn_ops earlier
              WHERE earlier.id < candidate.id
                AND earlier.synced = 0
                AND earlier.sync_state <> 'resolved_conflict'
                AND (
                  earlier.entity_key IS NULL
                  OR TRIM(earlier.entity_key) = ''
                  OR candidate.entity_key IS NULL
                  OR TRIM(candidate.entity_key) = ''
                  OR earlier.entity_key = candidate.entity_key
                )
           )
         ORDER BY candidate.id
         LIMIT 1
      ''', [claimedAtText]);
      if (rows.isEmpty) return null;
      final row = rows.first;
      final rowId = _asIntLocal(row['id']);
      final priorState = '${row['sync_state']}';
      final affected = await txn.rawUpdate(
        "UPDATE pending_hymn_ops SET sync_state = 'in_flight', "
        'attempt_count = attempt_count + 1, last_attempt_at = ?, '
        'next_attempt_at = NULL '
        'WHERE id = ? AND synced = 0 AND sync_state = ?',
        [claimedAtText, rowId, priorState],
      );
      if (affected != 1) {
        throw StateError('Hymn operation claim was not atomic.');
      }
      final clientOpId = '${row['client_op_id'] ?? ''}';
      final operation = '${row['op'] ?? ''}';
      final attemptNumber = _asIntLocal(row['attempt_count']) + 1;
      final entityKey = '${row['entity_key'] ?? ''}'.trim();

      // S2 Goal A.7 lineage: hymn operations join the SAME durable attempt
      // ledger the legacy outbox has written since S1. The row is opened
      // inside the claim transaction, after the claim has already proven
      // atomic, so there can be no attempt row without a successful claim and
      // no claimed operation whose attempt went unrecorded across a process
      // death. _openSyncAttempt inserts with ConflictAlgorithm.ignore, so a
      // replayed claim of the same (client_op_id, attempt_number) is a no-op
      // instead of duplicated lineage.
      //
      // Rows predating client_op_id carry no transmission identity, and
      // sync_attempts keys on it; inventing one would be fabricated lineage,
      // so those rows claim exactly as before and stay unledgered.
      if (hasAttemptLedger && clientOpId.isNotEmpty) {
        final rowOwner = row['created_by_user_id'];
        final rowAuthorizationVersion = row['created_authorization_version'];
        await _openSyncAttempt(
          txn,
          clientOpId: clientOpId,
          attemptNumber: attemptNumber,
          attemptUid: newAttemptUid(),
          domain: 'pending_hymn_ops',
          startedAt: claimedAt,
          // Natural key only — never the operation payload.
          entityRef: {
            'op': operation,
            if (entityKey.isNotEmpty) 'entity_key': entityKey,
          },
          // The claimed operation's OWN lineage, not the drainer's. Hymn
          // operations are shared, so the session draining one may differ
          // from the session that created it; the legacy path records the
          // claimed row's owner and this preserves that meaning. Falls back
          // to the claiming session only for rows written before these
          // columns carried a value.
          ownerUserId: rowOwner == null ? ownerUserId : _asIntLocal(rowOwner),
          authorizationVersion: rowAuthorizationVersion == null
              ? authorizationVersion
              : _asIntLocal(rowAuthorizationVersion),
          executionSource: executionSource,
        );
      }

      return HymnOutboxClaim(
        rowId: rowId,
        operation: operation,
        payloadJson: '${row['payload_json'] ?? ''}',
        clientOpId: clientOpId,
        runtimeGeneration: runtimeGeneration,
        attemptCount: attemptNumber,
        claimedAt: claimedAt,
      );
    });
  }

  Future<HymnSettlementResult> settleHymnOperation({
    required HymnOutboxClaim claim,
    required HymnSettlement settlement,
    required int currentRuntimeGeneration,
    DateTime? now,
    // S2 Goal A.7: how this attempt ended. Optional so a caller that does not
    // participate in the ledger settles exactly as before.
    SyncAttemptClosure? attemptClosure,
  }) async {
    if (claim.runtimeGeneration != currentRuntimeGeneration) {
      return HymnSettlementResult.supersededSession;
    }
    final db = await database;
    final hasAttemptLedger = await _tableExists(db, 'sync_attempts');
    final settledAt = (now ?? DateTime.now()).toUtc().toIso8601String();
    final values = <String, Object?>{
      'failure_code': settlement.failureCode,
      'failure_http_status': settlement.failureHttpStatus,
    };
    switch (settlement.kind) {
      case HymnSettlementKind.accepted:
        values.addAll({
          'sync_state': 'synced',
          'synced': 1,
          'synced_at': settledAt,
          'sync_error': null,
          'next_attempt_at': null,
          'failed_at': null,
        });
        break;
      case HymnSettlementKind.retryable:
        values.addAll({
          'sync_state': 'retry_wait',
          'next_attempt_at':
              (settlement.nextAttemptAt ?? DateTime.parse(settledAt))
                  .toUtc()
                  .toIso8601String(),
          'sync_error': settlement.failureMessage,
          'failed_at': null,
        });
        break;
      case HymnSettlementKind.needsAttention:
        values.addAll({
          'sync_state': 'needs_attention',
          'next_attempt_at': null,
          'sync_error': settlement.failureMessage,
          'failed_at': settledAt,
        });
        break;
      case HymnSettlementKind.pausedAuthentication:
        values.addAll({
          'sync_state': 'paused_auth',
          'next_attempt_at': null,
          'sync_error': settlement.failureMessage,
          'failed_at': null,
        });
        break;
      case HymnSettlementKind.pausedAuthorizationScope:
        values.addAll({
          'sync_state': 'paused_scope',
          'next_attempt_at': null,
          'sync_error': settlement.failureMessage,
          'failed_at': null,
        });
        break;
      case HymnSettlementKind.resolvedConflict:
        values.addAll({
          'sync_state': 'resolved_conflict',
          'next_attempt_at': null,
          'sync_error': settlement.failureMessage,
          'failed_at': settledAt,
        });
        break;
      case HymnSettlementKind.blockedDependency:
        values.addAll({
          'sync_state': 'blocked_dependency',
          'next_attempt_at': null,
          'sync_error': settlement.failureMessage,
          'failed_at': null,
        });
        break;
    }
    final affected = await db.transaction((txn) async {
      final sessionMatches = await activeSessionMatches(
        runtimeGeneration: currentRuntimeGeneration,
        executor: txn,
      );
      if (!sessionMatches) return -1;
      final updated = await txn.update(
        'pending_hymn_ops',
        values,
        where: "id = ? AND synced = 0 AND sync_state = 'in_flight' "
            'AND client_op_id = ? AND last_attempt_at = ?',
        whereArgs: [
          claim.rowId,
          claim.clientOpId,
          claim.claimedAt.toUtc().toIso8601String(),
        ],
      );
      // S2 Goal A.7 lineage: close this attempt in the same transaction that
      // settles the row, and only when the settlement actually applied — a
      // superseded settlement never closes an attempt it did not own. Only
      // the matching open attempt is touched, so a later success can never
      // rewrite an earlier failure.
      if (updated == 1 &&
          attemptClosure != null &&
          hasAttemptLedger &&
          claim.clientOpId.isNotEmpty) {
        await _closeSyncAttempt(
          txn,
          clientOpId: claim.clientOpId,
          attemptNumber: claim.attemptCount,
          closure: attemptClosure,
          finishedAt: DateTime.parse(settledAt),
        );
      }
      return updated;
    });
    if (affected < 0) return HymnSettlementResult.supersededSession;
    return affected == 1
        ? HymnSettlementResult.applied
        : HymnSettlementResult.supersededLocal;
  }

  /// Queued hymn_save ops for one LOCAL row id (negative placeholders).
  /// Lets a re-save collapse into a single server create — without this,
  /// create + edit while offline would post two hymns.
  /// Deliberately replaces unsent edits for one optimistic hymn placeholder.
  /// An HTTP-owned in-flight row is immutable; a fresh dependent generation is
  /// inserted behind it instead of mutating the bytes under that request.
  Future<bool> replacePendingHymnSaveForLocalId(
    int localId,
    Map<String, dynamic> payload,
  ) async {
    final db = await database;
    return db.transaction((txn) async {
      final rows = await txn.query(
        'pending_hymn_ops',
        where: "op = 'hymn_save' AND synced = 0",
        orderBy: 'id',
      );
      final matching = <Map<String, Object?>>[];
      for (final row in rows) {
        try {
          final decoded = jsonDecode('${row['payload_json'] ?? ''}');
          if (decoded is Map && _asIntLocal(decoded['id']) == localId) {
            matching.add(row);
          }
        } catch (_) {}
      }
      if (matching.isEmpty) return false;

      final inFlight = matching
          .where((row) => '${row['sync_state']}' == 'in_flight')
          .toList(growable: false);
      final replaceableIds = matching
          .where((row) => '${row['sync_state']}' != 'in_flight')
          .map((row) => _asIntLocal(row['id']))
          .where((id) => id > 0)
          .toList(growable: false);
      if (replaceableIds.isNotEmpty) {
        final marks = List.filled(replaceableIds.length, '?').join(',');
        await txn.delete(
          'pending_hymn_ops',
          where: 'id IN ($marks)',
          whereArgs: replaceableIds,
        );
      }

      final binding = await requireActiveOwnerBinding(txn);
      final freshId = newClientOpId();
      final freshPayload = Map<String, dynamic>.from(payload)
        ..['client_op_id'] = freshId;
      final entityKey = _hymnEntityKey('hymn_save', freshPayload);
      final dependency = inFlight.isNotEmpty
          ? inFlight.map((row) => _asIntLocal(row['id'])).reduce(
                (a, b) => a > b ? a : b,
              )
          : await _hymnDependencyFor(
              txn,
              'hymn_save',
              freshPayload,
              entityKey,
            );
      await txn.insert('pending_hymn_ops', {
        'op': 'hymn_save',
        'payload_json': jsonEncode(freshPayload),
        'client_op_id': freshId,
        'created_at': DateTime.now().toUtc().toIso8601String(),
        'sync_state': 'pending',
        'entity_key': entityKey,
        'depends_on': dependency,
        'created_by_user_id': binding['owner_user_id'],
        'created_authorization_version':
            binding['created_authorization_version'],
      });
      payload['client_op_id'] = freshId;
      return true;
    });
  }

  /// After a placeholder create is accepted, rebase any newer dependent edit
  /// onto the real server id while preserving its optimistic cached contents.
  Future<bool> rebasePendingHymnPlaceholder(
    int localId,
    int completedRowId,
    Map<String, dynamic> canonical,
  ) async {
    if (localId >= 0) return false;
    final serverId = _asIntLocal(canonical['id']);
    if (serverId <= 0) return false;
    final db = await database;
    return db.transaction((txn) async {
      final rows = await txn.query(
        'pending_hymn_ops',
        where: "id > ? AND op = 'hymn_save' AND synced = 0 "
            "AND sync_state IN ('pending', 'retry_wait', 'blocked_dependency')",
        whereArgs: [completedRowId],
        orderBy: 'id',
      );
      var rebased = false;
      for (final row in rows) {
        try {
          final decoded = jsonDecode('${row['payload_json'] ?? ''}');
          if (decoded is! Map || _asIntLocal(decoded['id']) != localId) {
            continue;
          }
          final payload = Map<String, dynamic>.from(decoded)
            ..['id'] = serverId
            ..['base_revision'] = _asIntLocal(canonical['revision']);
          await txn.update(
            'pending_hymn_ops',
            {
              'payload_json': jsonEncode(payload),
              'entity_key': 'hymn:$serverId',
              'depends_on': null,
              if ('${row['sync_state']}' == 'blocked_dependency') ...{
                'sync_state': 'pending',
                'failure_code': null,
                'sync_error': null,
              },
            },
            where: "id = ? AND synced = 0 AND sync_state <> 'in_flight'",
            whereArgs: [row['id']],
          );
          rebased = true;
        } catch (_) {}
      }
      if (!rebased) return false;

      final localRows = await txn.query(
        'cached_hymns',
        where: 'id = ?',
        whereArgs: [localId],
        limit: 1,
      );
      if (localRows.isEmpty) return false;
      for (final spec in const [
        ('cached_hymn_categories', 'category_id'),
        ('cached_hymn_zemarians', 'zemarian_id'),
      ]) {
        await txn.rawDelete(
          'DELETE FROM ${spec.$1} WHERE hymn_id = ? AND ${spec.$2} IN '
          '(SELECT ${spec.$2} FROM ${spec.$1} WHERE hymn_id = ?)',
          [localId, serverId],
        );
        await txn.update(
          spec.$1,
          {'hymn_id': serverId},
          where: 'hymn_id = ?',
          whereArgs: [localId],
        );
      }
      await txn.delete(
        'cached_hymns',
        where: 'id = ?',
        whereArgs: [serverId],
      );
      await txn.update(
        'cached_hymns',
        {
          'id': serverId,
          'revision': _asIntLocal(canonical['revision']),
          'server_updated_at': canonical['updated_at']?.toString(),
        },
        where: 'id = ?',
        whereArgs: [localId],
      );
      return true;
    });
  }

  Future<bool> rebaseNewerPendingHymnRevision(
    int hymnId,
    int completedRowId,
    Map<String, dynamic> canonical,
  ) async {
    if (hymnId <= 0) return false;
    final db = await database;
    return db.transaction((txn) async {
      final rows = await txn.query(
        'pending_hymn_ops',
        where: "id > ? AND op = 'hymn_save' AND synced = 0 "
            "AND sync_state IN ('pending', 'retry_wait', 'blocked_dependency')",
        whereArgs: [completedRowId],
        orderBy: 'id',
      );
      var rebased = false;
      for (final row in rows) {
        try {
          final decoded = jsonDecode('${row['payload_json'] ?? ''}');
          if (decoded is! Map || _asIntLocal(decoded['id']) != hymnId) {
            continue;
          }
          final payload = Map<String, dynamic>.from(decoded)
            ..['base_revision'] = _asIntLocal(canonical['revision']);
          await txn.update(
            'pending_hymn_ops',
            {
              'payload_json': jsonEncode(payload),
              'depends_on': null,
              if ('${row['sync_state']}' == 'blocked_dependency') ...{
                'sync_state': 'pending',
                'failure_code': null,
                'sync_error': null,
              },
            },
            where: "id = ? AND synced = 0 AND sync_state <> 'in_flight'",
            whereArgs: [row['id']],
          );
          rebased = true;
        } catch (_) {}
      }
      return rebased;
    });
  }

  Future<List<Map<String, dynamic>>> getPendingHymnSavesForLocalId(
      int localId) async {
    final db = await database;
    final ops = await db.query('pending_hymn_ops',
        where: "op = 'hymn_save' AND synced = 0", orderBy: 'id');
    final out = <Map<String, dynamic>>[];
    for (final op in ops) {
      try {
        final payload = jsonDecode('${op['payload_json'] ?? '{}'}');
        if (payload is Map && '${payload['id']}' == '$localId') out.add(op);
      } catch (_) {}
    }
    return out;
  }

  // ── delta-sync cursor ───────────────────────────────────────

  Future<String> getHymnSyncCursor() async {
    final db = await database;
    final rows = await db.query('hymn_sync_meta',
        where: "key = 'cursor'", whereArgs: []);
    return rows.isEmpty ? '' : '${rows.first['value'] ?? ''}';
  }

  Future<void> setHymnSyncCursor(String cursor) async {
    final db = await database;
    await db.insert(
      'hymn_sync_meta',
      {'key': 'cursor', 'value': cursor},
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  /// 1.6.2 sync anchor: the dataset identity this local corpus was
  /// built from (see server SyncInstanceService). Empty when never set.
  Future<String> getHymnSyncInstanceId() async {
    final db = await database;
    final rows = await db.query('hymn_sync_meta',
        where: "key = 'sync_instance_id'", whereArgs: []);
    return rows.isEmpty ? '' : '${rows.first['value'] ?? ''}';
  }

  Future<void> setHymnSyncInstanceId(String instanceId) async {
    final db = await database;
    await db.insert(
      'hymn_sync_meta',
      {'key': 'sync_instance_id', 'value': instanceId},
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  /// 1.6.2 sync-anchor reset: the server's dataset identity changed, so
  /// every server-derived hymn row belongs to a previous dataset (a
  /// "ghost" the delta stream can never remove — deletions only travel
  /// as tombstones inside one dataset's own timeline). Deletes the
  /// corpus minus ids with unpushed local edits (the outbox still owns
  /// those and re-pushes them), clears the delta cursor so the next
  /// pull rebuilds from the authoritative dataset, and drops orphaned
  /// taxonomy join rows. The search index self-heals on the next open
  /// (P38). Returns the number of hymn rows removed.
  Future<int> resetHymnSyncForInstanceChange(Set<int> protectIds) async {
    final db = await database;
    var removed = 0;
    await db.transaction((txn) async {
      await txn.delete('hymn_sync_meta', where: "key = 'cursor'");
      if (protectIds.isEmpty) {
        removed = await txn.delete('cached_hymns');
      } else {
        final marks = List.filled(protectIds.length, '?').join(',');
        removed = await txn.delete('cached_hymns',
            where: 'id NOT IN ($marks)', whereArgs: protectIds.toList());
      }
      await txn.rawDelete(
          'DELETE FROM cached_hymn_categories WHERE hymn_id NOT IN '
          '(SELECT id FROM cached_hymns)');
      await txn.rawDelete(
          'DELETE FROM cached_hymn_zemarians WHERE hymn_id NOT IN '
          '(SELECT id FROM cached_hymns)');
    });
    return removed;
  }

  // ============================================================
  // SESSION OWNERSHIP + PRIVATE-DATA INVENTORY (v34)
  // ============================================================

  Future<bool> activeSessionMatches({
    required int runtimeGeneration,
    int? ownerUserId,
    int? authorizationVersion,
    DatabaseExecutor? executor,
  }) async {
    final db = executor ?? await database;
    final rows = await db.query(
      'local_session_state',
      columns: [
        'state',
        'generation',
        'owner_user_id',
        'owner_authorization_version',
      ],
      where: 'id = ?',
      whereArgs: [1],
      limit: 1,
    );
    if (rows.isEmpty || rows.first['state'] != 'active') return false;
    final row = rows.first;
    if (_asIntLocal(row['generation']) != runtimeGeneration) return false;
    if (ownerUserId != null &&
        _nullablePositiveInt(row['owner_user_id']) != ownerUserId) {
      return false;
    }
    if (authorizationVersion != null &&
        _nullableNonNegativeInt(row['owner_authorization_version']) !=
            authorizationVersion) {
      return false;
    }
    return true;
  }

  Future<int> _activeRuntimeGeneration(DatabaseExecutor executor) async {
    final rows = await executor.query(
      'local_session_state',
      columns: ['generation'],
      where: 'id = ?',
      whereArgs: [1],
      limit: 1,
    );
    if (rows.isEmpty) throw StateError('Active session generation is missing.');
    return _asIntLocal(rows.first['generation']);
  }

  Future<Map<String, int>> requireActiveOwnerBinding(
      [DatabaseExecutor? executor]) async {
    final db = executor ?? await database;
    final rows = await db.query(
      'local_session_state',
      columns: [
        'state',
        'owner_user_id',
        'owner_authorization_version',
      ],
      where: 'id = ?',
      whereArgs: [1],
      limit: 1,
    );
    if (rows.isEmpty || rows.first['state'] != 'active') {
      throw StateError('Private writes require an active reconciled session.');
    }
    final owner = _nullablePositiveInt(rows.first['owner_user_id']);
    final version =
        _nullableNonNegativeInt(rows.first['owner_authorization_version']);
    if (owner == null || version == null) {
      throw StateError('The active session has no owner/scope binding.');
    }
    return {
      'owner_user_id': owner,
      'created_authorization_version': version,
    };
  }

  /// Quarantine private writes created under any earlier authorization scope.
  /// Payloads and operation ids are retained for the recovery UI; none are
  /// rebound merely because the same user received a new role/version.
  Future<void> pausePrivateOperationsOutsideAuthorizationScope({
    required int ownerUserId,
    required int authorizationVersion,
  }) async {
    final db = await database;
    await db.transaction((txn) async {
      for (final spec in legacyOutboxTableSpecs) {
        await txn.update(
          spec.table,
          {
            'sync_state': 'paused_scope',
            'next_attempt_at': null,
            'sync_error':
                'Authorization changed before this saved operation was sent.',
            'failure_code': 'AUTH_SCOPE_CHANGED',
            'failure_http_status': null,
          },
          where: 'synced = 0 AND owner_user_id = ? AND '
              '(created_authorization_version IS NULL OR '
              'created_authorization_version <> ?)',
          whereArgs: [ownerUserId, authorizationVersion],
        );
      }
      await txn.update(
        'comm_outbox',
        {
          'state': 'paused_scope',
          'next_attempt_at': null,
          'fail_reason':
              'Authorization changed before this saved message was sent.',
          'failure_code': 'AUTH_SCOPE_CHANGED',
          'failure_http_status': null,
        },
        where: "state <> 'synced' AND owner_user_id = ? AND "
            '(created_authorization_version IS NULL OR '
            'created_authorization_version <> ?)',
        whereArgs: [ownerUserId, authorizationVersion],
      );
    });
  }

  /// Purge server-derived reads whose visibility depends on the current role.
  /// Durable private writes/drafts and every shared hymn table are preserved.
  Future<void> clearAuthorizationScopedReadCaches() async {
    final db = await database;
    await db.transaction((txn) async {
      for (final table in _privateReadCacheTables) {
        await txn.delete(table);
      }
      // The download cursor describes caches that no longer exist. Left
      // alone, the next pull would ask for changes "since" a revision it
      // can no longer substantiate and would only ever receive deltas,
      // never the rows the purge removed. Resetting to 0 makes the
      // server demand a bootstrap, which is the honest answer.
      try {
        await txn.delete('sync_state');
      } catch (_) {}
    });
    // Remove deleted role-scoped pages from the WAL without the much heavier
    // VACUUM used by an explicit destructive account purge.
    try {
      await db.rawQuery('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (_) {}
  }

  Future<LocalSessionRecord> getLocalSession() async {
    final db = await database;
    final rows = await db.query(
      'local_session_state',
      where: 'id = ?',
      whereArgs: [1],
      limit: 1,
    );
    if (rows.isEmpty) {
      return const LocalSessionRecord(
        state: SessionState.anonymousClean,
        generation: 0,
      );
    }
    final row = rows.first;
    return LocalSessionRecord(
      state: SessionState.fromStorage(row['state']?.toString()),
      generation: _asIntLocal(row['generation']),
      ownerUserId: _nullablePositiveInt(row['owner_user_id']),
      authorizationVersion:
          _nullableNonNegativeInt(row['owner_authorization_version']),
      ownerRole: row['owner_role']?.toString(),
      ownerUsername: row['owner_username']?.toString(),
      ownerDisplayName: row['owner_display_name']?.toString(),
      reauthReason: row['reason']?.toString(),
      updatedAt: row['updated_at']?.toString(),
    );
  }

  Future<void> persistLocalSession({
    required SessionState state,
    required int generation,
    int? ownerUserId,
    int? authorizationVersion,
    String? ownerRole,
    String? ownerUsername,
    String? ownerDisplayName,
    String? reason,
  }) async {
    final db = await database;
    await db.insert(
      'local_session_state',
      {
        'id': 1,
        'owner_user_id': ownerUserId,
        'owner_username': ownerUsername,
        'owner_display_name': ownerDisplayName,
        'owner_role': ownerRole,
        'owner_authorization_version': authorizationVersion,
        'state': state.storageValue,
        'reason': reason,
        'generation': generation,
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  /// A complete credential bundle is the only evidence permitted to claim
  /// ownerless legacy rows.  Shared hymn work receives creator provenance but
  /// remains outside the private-account deletion boundary.
  Future<void> backfillOwnerlessRows({
    required int ownerUserId,
    required int authorizationVersion,
  }) async {
    final db = await database;
    await db.transaction((txn) async {
      for (final table in const [
        'pending_attendance',
        'pending_grades',
        'pending_mezmur',
        'pending_hr',
      ]) {
        await txn.update(
          table,
          {
            'owner_user_id': ownerUserId,
            'created_authorization_version': authorizationVersion,
          },
          where: 'synced = 0 AND owner_user_id IS NULL',
        );
      }
      for (final table in const ['comm_outbox', 'comm_drafts']) {
        await txn.update(
          table,
          {
            'owner_user_id': ownerUserId,
            'created_authorization_version': authorizationVersion,
          },
          where: 'owner_user_id IS NULL',
        );
      }
      await txn.update(
        'pending_hymn_ops',
        {
          'created_by_user_id': ownerUserId,
          'created_authorization_version': authorizationVersion,
        },
        where: 'synced = 0 AND created_by_user_id IS NULL',
      );
    });
  }

  /// Whether another bounded legacy drain pass has immediately due work for
  /// the active owner/scope. Shared hymn and communication rows are excluded;
  /// their own workers provide the corresponding bounded-rescan guarantees.
  Future<bool> hasDueLegacyOutbox({DateTime? now}) async {
    final db = await database;
    final nowText = (now ?? DateTime.now()).toUtc().toIso8601String();
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      for (final spec in legacyOutboxTableSpecs) {
        final rows = await txn.rawQuery(
          'SELECT 1 FROM ${spec.table} '
          "WHERE synced = 0 AND sync_state IN ('pending', 'retry_wait') "
          'AND (next_attempt_at IS NULL OR next_attempt_at <= ?) '
          'AND owner_user_id = ? '
          'AND created_authorization_version = ? '
          "${spec.table == 'pending_attendance' ? _attendanceClaimEligibilityWhere : ''}LIMIT 1",
          [
            nowText,
            binding['owner_user_id'],
            binding['created_authorization_version'],
          ],
        );
        if (rows.isNotEmpty) return true;
      }
      return false;
    });
  }

  /// One state-separated snapshot for schedulers and status UI. Counts are
  /// operations (not legacy child rows), and terminal review states never
  /// masquerade as network-retry work. Actionable private state counts use
  /// the active owner/scope; older-scope private work contributes only to the
  /// safe paused/total counts. Shared hymn operations remain global by design.
  Future<OutboxInventory> getOutboxInventory({DateTime? now}) async {
    final db = await database;
    final nowText = (now ?? DateTime.now()).toUtc().toIso8601String();
    return db.transaction((txn) async {
      final binding = await requireActiveOwnerBinding(txn);
      final ownerUserId = binding['owner_user_id']!;
      final authorizationVersion = binding['created_authorization_version']!;

      Future<int> scalar(String sql, [List<Object?>? args]) async {
        final rows = await txn.rawQuery(sql, args);
        return rows.isEmpty ? 0 : _asIntLocal(rows.first.values.first);
      }

      Future<int> legacyState(String predicate,
          [List<Object?> args = const [], String? attendancePredicate]) async {
        var total = 0;
        for (final spec in legacyOutboxTableSpecs) {
          final scopedPredicate = spec.table == 'pending_attendance'
              ? attendancePredicate ?? predicate : predicate;
          total += await scalar(
            'SELECT COUNT(DISTINCT client_op_id) FROM ${spec.table} '
            'WHERE synced = 0 AND ($scopedPredicate) '
            'AND owner_user_id = ? '
            'AND created_authorization_version = ?',
            [...args, ownerUserId, authorizationVersion],
          );
        }
        return total;
      }

      Future<int> legacyOwnerState(String predicate,
          [List<Object?> args = const []]) async {
        var total = 0;
        for (final spec in legacyOutboxTableSpecs) {
          total += await scalar(
            'SELECT COUNT(DISTINCT client_op_id) FROM ${spec.table} '
            'WHERE synced = 0 AND ($predicate) AND owner_user_id = ?',
            [...args, ownerUserId],
          );
        }
        return total;
      }

      Future<int> allState(
        String legacyPredicate,
        String hymnPredicate,
        String commPredicate, {
        String? attendancePredicate,
        List<Object?> legacyArgs = const [],
        List<Object?> hymnArgs = const [],
        List<Object?> commArgs = const [],
      }) async {
        return await legacyState(legacyPredicate, legacyArgs, attendancePredicate) +
            await scalar(
              'SELECT COUNT(DISTINCT client_op_id) FROM pending_hymn_ops '
              'WHERE synced = 0 AND ($hymnPredicate)',
              hymnArgs,
            ) +
            await scalar(
              'SELECT COUNT(*) FROM comm_outbox WHERE ($commPredicate) '
              'AND owner_user_id = ? '
              'AND created_authorization_version = ?',
              [...commArgs, ownerUserId, authorizationVersion],
            );
      }

      final due = await allState(
        "sync_state IN ('pending', 'retry_wait') AND "
            '(next_attempt_at IS NULL OR next_attempt_at <= ?)',
        "sync_state IN ('pending', 'retry_wait') AND "
            '(next_attempt_at IS NULL OR next_attempt_at <= ?) AND '
            '(depends_on IS NULL OR EXISTS ('
            'SELECT 1 FROM pending_hymn_ops dependency '
            'WHERE dependency.id = pending_hymn_ops.depends_on '
            'AND dependency.synced = 1))',
        "state IN ('pending', 'retry_wait') AND "
            '(next_attempt_at IS NULL OR next_attempt_at <= ?)',
        attendancePredicate: "sync_state IN ('pending', 'retry_wait') AND "
            '(next_attempt_at IS NULL OR next_attempt_at <= ?) '
            '$_attendanceClaimEligibilityWhere',
        legacyArgs: [nowText],
        hymnArgs: [nowText],
        commArgs: [nowText],
      );
      final waiting = await allState(
        "sync_state IN ('pending', 'retry_wait') AND next_attempt_at > ?",
        "(sync_state IN ('pending', 'retry_wait') AND next_attempt_at > ?) "
            "OR (sync_state IN ('pending', 'retry_wait') "
            'AND depends_on IS NOT NULL AND EXISTS ('
            'SELECT 1 FROM pending_hymn_ops dependency '
            'WHERE dependency.id = pending_hymn_ops.depends_on '
            'AND dependency.synced = 0))',
        "state IN ('pending', 'retry_wait') AND next_attempt_at > ?",
        attendancePredicate: "sync_state IN ('pending', 'retry_wait') "
            'AND next_attempt_at > ? $_attendanceClaimEligibilityWhere',
        legacyArgs: [nowText],
        hymnArgs: [nowText],
        commArgs: [nowText],
      );
      final inFlight = await allState(
        "sync_state = 'in_flight'",
        "sync_state = 'in_flight'",
        "state = 'in_flight'",
      );
      final attention = await allState(
        "sync_state = 'needs_attention'",
        "sync_state = 'needs_attention'",
        "state IN ('needs_attention', 'failed')",
      );
      final pausedAuth = await allState(
        "sync_state = 'paused_auth'",
        "sync_state = 'paused_auth'",
        "state = 'paused_auth'",
      );
      final currentScopePaused = await allState(
        "sync_state = 'paused_scope'",
        "sync_state = 'paused_scope'",
        "state = 'paused_scope'",
      );
      // Older authorization versions are deliberately count-only. Include
      // every unresolved private row so even an interrupted reconciliation
      // cannot hide saved work or expose its roster/message payload.
      final oldScopePrivate = await legacyOwnerState(
            'created_authorization_version <> ?',
            [authorizationVersion],
          ) +
          await scalar(
            "SELECT COUNT(*) FROM comm_outbox WHERE state <> 'synced' "
            'AND owner_user_id = ? '
            'AND created_authorization_version <> ?',
            [ownerUserId, authorizationVersion],
          );
      final pausedScope = currentScopePaused + oldScopePrivate;
      final blocked = await allState(
        "sync_state = 'blocked_dependency'",
        "sync_state = 'blocked_dependency'",
        "state = 'blocked_dependency'",
        attendancePredicate: "sync_state = 'blocked_dependency' OR "
            "(sync_state IN ('pending', 'retry_wait') "
            'AND NOT (1 = 1 $_attendanceClaimEligibilityWhere))',
      );
      final conflicts = await allState(
        "sync_state = 'resolved_conflict'",
        "sync_state = 'resolved_conflict'",
        "state = 'resolved_conflict'",
      );
      final privateUnresolved =
          await legacyOwnerState('1 = 1') +
              await scalar(
                "SELECT COUNT(*) FROM comm_outbox WHERE state <> 'synced' "
                'AND owner_user_id = ?',
                [ownerUserId],
              );
      final sharedHymns = await scalar(
        'SELECT COUNT(DISTINCT client_op_id) FROM pending_hymn_ops '
        'WHERE synced = 0',
      );
      final drafts = await scalar(
        "SELECT COUNT(*) FROM comm_drafts WHERE TRIM(body) <> '' "
        'AND owner_user_id = ?',
        [ownerUserId],
      );
      return OutboxInventory(
        retryableDue: due,
        retryableWaiting: waiting,
        inFlight: inFlight,
        needsAttention: attention,
        pausedAuth: pausedAuth,
        pausedScope: pausedScope,
        blockedDependency: blocked,
        resolvedConflict: conflicts,
        privateUnresolvedTotal: privateUnresolved,
        sharedHymnUnresolvedTotal: sharedHymns,
        communicationDraftCount: drafts,
      );
    });
  }

  /// One SQLite snapshot used by logout, forgot-PIN and owner activation.
  /// Legacy domains count operations/batches, not member rows. Paused and
  /// attention counts are reported separately and intentionally overlap the
  /// domain totals rather than inflating the destructive-work decision.
  Future<LocalDataInventory> getLocalDataInventory() async {
    final db = await database;
    return db.transaction((txn) async {
      Future<int> scalar(String sql, [List<Object?>? args]) async {
        final rows = await txn.rawQuery(sql, args);
        if (rows.isEmpty) return 0;
        return _asIntLocal(rows.first.values.first);
      }

      Future<int> operationCount(String table) => scalar(
            'SELECT COUNT(DISTINCT client_op_id) FROM $table '
            'WHERE synced = 0',
          );

      final attendance = await operationCount('pending_attendance');
      final grades = await operationCount('pending_grades');
      final mezmur = await operationCount('pending_mezmur');
      final hr = await operationCount('pending_hr');
      final commPending = await scalar(
        "SELECT COUNT(*) FROM comm_outbox WHERE state NOT IN "
        "('synced', 'failed', 'needs_attention', 'resolved_conflict')",
      );
      final commFailed = await scalar(
        "SELECT COUNT(*) FROM comm_outbox WHERE state IN "
        "('failed', 'needs_attention', 'resolved_conflict')",
      );
      final drafts = await scalar(
        "SELECT COUNT(*) FROM comm_drafts WHERE TRIM(body) <> ''",
      );
      final attention = await scalar('''
        SELECT
          (SELECT COUNT(DISTINCT client_op_id) FROM pending_attendance
             WHERE synced = 0 AND sync_state = 'needs_attention') +
          (SELECT COUNT(DISTINCT client_op_id) FROM pending_grades
             WHERE synced = 0 AND sync_state = 'needs_attention') +
          (SELECT COUNT(DISTINCT client_op_id) FROM pending_mezmur
             WHERE synced = 0 AND sync_state = 'needs_attention') +
          (SELECT COUNT(DISTINCT client_op_id) FROM pending_hr
             WHERE synced = 0 AND sync_state = 'needs_attention') +
          (SELECT COUNT(*) FROM comm_outbox
             WHERE state IN ('needs_attention', 'failed'))
      ''');
      final paused = await scalar('''
        SELECT
          (SELECT COUNT(DISTINCT client_op_id) FROM pending_attendance
             WHERE synced = 0 AND sync_state IN ('paused_auth', 'paused_scope')) +
          (SELECT COUNT(DISTINCT client_op_id) FROM pending_grades
             WHERE synced = 0 AND sync_state IN ('paused_auth', 'paused_scope')) +
          (SELECT COUNT(DISTINCT client_op_id) FROM pending_mezmur
             WHERE synced = 0 AND sync_state IN ('paused_auth', 'paused_scope')) +
          (SELECT COUNT(DISTINCT client_op_id) FROM pending_hr
             WHERE synced = 0 AND sync_state IN ('paused_auth', 'paused_scope')) +
          (SELECT COUNT(*) FROM comm_outbox
             WHERE state IN ('paused_auth', 'paused_scope'))
      ''');
      final sharedHymns = await scalar(
        'SELECT COUNT(DISTINCT client_op_id) FROM pending_hymn_ops '
        'WHERE synced = 0',
      );
      final ownerRows = await txn.rawQuery('''
        SELECT owner_user_id FROM pending_attendance
          WHERE synced = 0 AND owner_user_id IS NOT NULL
        UNION SELECT owner_user_id FROM pending_grades
          WHERE synced = 0 AND owner_user_id IS NOT NULL
        UNION SELECT owner_user_id FROM pending_mezmur
          WHERE synced = 0 AND owner_user_id IS NOT NULL
        UNION SELECT owner_user_id FROM pending_hr
          WHERE synced = 0 AND owner_user_id IS NOT NULL
        UNION SELECT owner_user_id FROM comm_outbox
          WHERE owner_user_id IS NOT NULL
        UNION SELECT owner_user_id FROM comm_drafts
          WHERE TRIM(body) <> '' AND owner_user_id IS NOT NULL
        ORDER BY owner_user_id
      ''');
      final privateOwners = ownerRows
          .map((row) => _nullablePositiveInt(row['owner_user_id']))
          .whereType<int>()
          .toList(growable: false);

      var cacheRows = 0;
      for (final table in _privateReadCacheTables) {
        cacheRows += await scalar('SELECT COUNT(*) FROM $table');
      }

      return LocalDataInventory(
        attendanceOperations: attendance,
        gradeOperations: grades,
        mezmurOperations: mezmur,
        hrOperations: hr,
        communicationPending: commPending,
        communicationFailed: commFailed,
        communicationDrafts: drafts,
        attentionOperations: attention,
        pausedOperations: paused,
        privateCacheRows: cacheRows,
        privateOwnerUserIds: privateOwners,
        sharedHymnOperations: sharedHymns,
      );
    });
  }

  static int? _nullablePositiveInt(Object? value) {
    final parsed = _asIntLocal(value);
    return parsed > 0 ? parsed : null;
  }

  static int? _nullableNonNegativeInt(Object? value) {
    if (value == null) return null;
    final parsed = _asIntLocal(value);
    return parsed >= 0 ? parsed : null;
  }

  // ============================================================
  // CLEANUP
  // ============================================================

  Future<void> cleanupSyncedHymnOps() async {
    final db = await database;
    final cutoff =
        DateTime.now().subtract(const Duration(days: 7)).toIso8601String();
    await db.delete('pending_hymn_ops',
        where: 'synced = 1 AND synced_at < ?', whereArgs: [cutoff]);
  }

  Future<void> cleanupSyncedMezmur() async {
    final db = await database;
    await db
        .delete('pending_mezmur', where: 'synced = 1');
  }

  Future<void> cleanupSynced() async {
    final db = await database;
    final cutoff =
        DateTime.now().subtract(const Duration(days: 7)).toIso8601String();
    // Keep just the newest accepted checkpoint while older unsynced history
    // could otherwise reappear in the latest-generation attendance overlay.
    await db.delete('pending_attendance',
        where: 'synced = 1 AND synced_at < ? AND ('
            'EXISTS (SELECT 1 FROM pending_attendance newer '
            'WHERE newer.class_id = pending_attendance.class_id '
            'AND newer.date = pending_attendance.date '
            'AND newer.owner_user_id = pending_attendance.owner_user_id '
            'AND newer.created_authorization_version = pending_attendance.created_authorization_version '
            'AND newer.synced = 1 AND newer.id > pending_attendance.id '
            'AND newer.client_op_id IS NOT pending_attendance.client_op_id) '
            'OR NOT EXISTS (SELECT 1 FROM pending_attendance older '
            'WHERE older.class_id = pending_attendance.class_id '
            'AND older.date = pending_attendance.date '
            'AND older.owner_user_id = pending_attendance.owner_user_id '
            'AND older.created_authorization_version = pending_attendance.created_authorization_version '
            'AND older.synced = 0 AND older.id < pending_attendance.id))',
        whereArgs: [cutoff]);
    await db.delete('pending_grades',
        where: 'synced = 1 AND synced_at < ?', whereArgs: [cutoff]);
    await db.delete('pending_mezmur',
        where: 'synced = 1 AND synced_at < ?', whereArgs: [cutoff]);
    await db.delete('pending_hr',
        where: 'synced = 1 AND synced_at < ?', whereArgs: [cutoff]);
    await db.delete('pending_hymn_ops',
        where: 'synced = 1 AND synced_at < ?', whereArgs: [cutoff]);
  }

  Future<void> logSync(String action, String detail, String status) async {
    final db = await database;
    await db.insert('sync_log', {
      'action': action,
      'detail': detail,
      'status': status,
      'created_at': DateTime.now().toIso8601String(),
    });
  }

  /// Clear all cached data (on logout)
  Future<void> clearAllCache() async {
    final db = await database;
    await db.delete('cached_classes');
    await db.delete('cached_students');
    await db.delete('cached_subjects');
    await db.delete('cached_assessments');
    await db.delete('cached_dashboard');
    await db.delete('cached_members');
    try { await db.delete('cached_attendance'); } catch (_) {}
    try { await db.delete('cached_grade_sheets'); } catch (_) {}
    try { await db.delete('cached_mezmur_sheet'); } catch (_) {}
    try { await db.delete('cached_mezmur_sections'); } catch (_) {}
    try { await db.delete('cached_hr_sheet'); } catch (_) {}
    try { await db.delete('cached_hr_sections'); } catch (_) {}
    await db.delete('attendance_preparation_snapshot');
    await db.delete('grades_preparation_snapshot');
    try { await db.delete('attendance_sync_receipt'); } catch (_) {}
    try { await db.delete('grades_sync_receipt'); } catch (_) {}
    // NOTE: the hymn library (cached_hymns, cached_mezmur_categories,
    // hymn_sync_meta, pending_hymn_ops) is deliberately NOT cleared —
    // it is shared department content, not member data, and queued
    // hymn edits must survive logout (product decision 2026-08-28).
  }

  /// Full transactional wipe for this device user — member/attendance
  /// cache + unsynced rows. Prevents the next login on this phone from
  /// seeing the previous roster. The SHARED hymn library stays: it is
  /// department content, not member data (and its queued edits survive).
  Future<void> clearAllUserData() async {
    final db = await database;
    await db.transaction((txn) async {
      for (final table in [
        'cached_classes',
        'cached_students',
        'cached_subjects',
        'cached_assessments',
        'cached_dashboard',
        'cached_members',
        'cached_attendance',
        'cached_grade_sheets',
        'cached_mezmur_sheet',
        'cached_mezmur_sections',
        'cached_mezmur_days',
        // P1-H: member attendance analytics (names/codes/rates) is
        // authenticated PII and must never cross a logout boundary.
        'cached_mezmur_analytics_last',
        'cached_review_packets',
        'cached_review_packet_details',
        'cached_review_stats',
        // P1-E: education read model is user-scoped (rosters carry
        // member PII; the class list is role-filtered) — same wipe
        // discipline as every other cache.
        'cached_edu_classes',
        'cached_edu_class_rosters',
        // P1-F: the subject catalog is a role-scoped server response
        // — same wipe discipline.
        'cached_edu_subjects',
        // P1-G: staff directory + assignment details are authenticated,
        // year-scoped data. Never retain or reuse them across logout.
        'cached_edu_teacher_snapshot',
        'cached_edu_teachers',
        'cached_edu_teacher_details',
        'pending_attendance',
        'pending_grades',
        'pending_mezmur',
        'pending_hr',
        'attendance_preparation_snapshot',
        'grades_preparation_snapshot',
        'attendance_sync_receipt',
        'grades_sync_receipt',
        'cached_hr_sheet',
        'cached_hr_sections',
        // Intentionally kept on logout: cached_hymns,
        // cached_mezmur_categories, hymn_sync_meta, pending_hymn_ops.
        // Hymns are shared library content (no member PII); queued
        // hymn edits wait here until a curator signs in again.
        // Comm tables (threads/messages/outbox/drafts/meta) ARE
        // member PII — wiped like everything else.
        'comm_threads',
        'comm_messages',
        'comm_outbox',
        'comm_drafts',
        'comm_meta',
        // P1-B: cached notification center rows are user-scoped
        // server responses — same wipe discipline as the comm store.
        'cached_notifications',
        'cached_announcements',
        'sync_log',
      ]) {
        await txn.delete(table);
      }
    });
    // secure_delete is enabled on open; checkpoint/truncate also discards WAL
    // pages that may contain the previous user's sensitive offline records.
    try { await db.rawQuery('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (_) {}
    try { await db.execute('VACUUM'); } catch (_) {}
  }
}
