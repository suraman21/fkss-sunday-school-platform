"""P1-E Education Classes + Class Students local-first conversion — static pins.

No sqflite runtime harness exists in this sandbox (documented
limitation since F8), so these pins assert the SOURCE: the DB
migration to v30, the DEDICATED education read model (never the
teacher workflow's shared caches), replace-on-success snapshot
semantics (both /classes endpoints return complete scoped sets —
deliberately NOT P1-D's merge-only rule), local level_order
ordering, per-class roster isolation, the roster year-metadata
contract, honest offline behavior, and every do-not-touch invariant
(cached_classes/cached_students/cached_members, CatalogService,
F8/SyncService, api_service, server PHP, timeout, sibling edu
surfaces). Behavioral verification is the device drill's
responsibility.
"""

import glob
import os
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
APP = os.path.join(ROOT, 'Mobile', 'wbws_flutter_app')
LDB = os.path.join(APP, 'lib', 'services', 'local_db.dart')
EDU = os.path.join(APP, 'lib', 'screens', 'edu_dept',
                   'edu_classes_screen.dart')
SYNC = os.path.join(APP, 'lib', 'services', 'sync_service.dart')
POLICY = os.path.join(APP, 'lib', 'services', 'outbox_policy.dart')
CATALOG = os.path.join(APP, 'lib', 'services', 'catalog_service.dart')
WARM = os.path.join(APP, 'lib', 'services', 'warm_store.dart')
ATTEND = os.path.join(APP, 'lib', 'screens', 'attendance',
                      'attendance_screen.dart')
GRADES = os.path.join(APP, 'lib', 'screens', 'teacher', 'teacher_grades.dart')
TEACHER_HOME = os.path.join(APP, 'lib', 'screens', 'teacher',
                            'teacher_home.dart')
ATT_TAKER_HOME = os.path.join(APP, 'lib', 'screens', 'att_taker',
                              'att_taker_home.dart')
NOTIF = os.path.join(APP, 'lib', 'screens', 'notifications',
                     'notification_center_screen.dart')
MEMBERS = os.path.join(APP, 'lib', 'screens', 'members',
                       'member_list_screen.dart')
MEZMUR_HOME = os.path.join(APP, 'lib', 'screens', 'mezmur', 'mezmur_home.dart')
REVIEWS = os.path.join(APP, 'lib', 'screens', 'reviews',
                       'review_inbox_screen.dart')
CONFIG = os.path.join(APP, 'lib', 'utils', 'config.dart')
APISVC = os.path.join(APP, 'lib', 'services', 'api_service.dart')
CLASSES_PHP = os.path.join(ROOT, 'api', 'v1', 'routes', 'classes.php')
ENROLL_SVC = os.path.join(ROOT, 'admin', 'backend', 'services',
                          'EnrollmentService.php')
ACL = os.path.join(ROOT, 'api', 'v1', 'core', 'acl.php')
DBCORE = os.path.join(ROOT, 'api', 'v1', 'core', 'database.php')
EDU_ADMIN = os.path.join(ROOT, 'admin', 'api_education.php')
SQLDIR = os.path.join(ROOT, 'sql')
EDU_DEPT = os.path.join(APP, 'lib', 'screens', 'edu_dept')

# Sibling education surfaces explicitly OUT of P1-E scope.
EXCLUDED_SCREENS = (
    os.path.join(EDU_DEPT, 'edu_enrollment_screen.dart'),
    os.path.join(EDU_DEPT, 'edu_subjects_screen.dart'),
    os.path.join(EDU_DEPT, 'edu_teachers_screen.dart'),
    os.path.join(EDU_DEPT, 'edu_home.dart'),
)


def read(p):
    with open(p, encoding='utf-8') as f:
        return f.read()


def method_body(src, sig):
    i = src.find(sig)
    assert i >= 0, sig
    body_start = src.find(' async {', i)
    assert body_start > i, sig
    j = src.find('\n  }', body_start)
    assert j > body_start, sig
    return src[i:j]


class P1EDatabase(unittest.TestCase):
    """DB v29 → v30, two dedicated education read-model tables, wired
    into fresh installs, upgrades, and the logout wipe."""

    def setUp(self):
        self.ldb = read(LDB)

    def test_version_tracks_current(self):
        # P1-E's bump was to v30. The current version is centralized
        # in the v34 schema contract. The education class tables
        # themselves are untouched by the later steps.
        self.assertIn('version: localDatabaseSchemaVersion,', self.ldb)
        self.assertNotIn('version: 29,', self.ldb)

    def test_migration_branch_exists(self):
        self.assertIn('if (oldVersion < 30)', self.ldb)
        self.assertIn('_createEduTables(db)', self.ldb)
        # P1-D's own migration branch is intact history.
        self.assertIn('if (oldVersion < 29)', self.ldb)
        self.assertIn('_createReviewTables(db)', self.ldb)

    def test_tables_and_schema(self):
        self.assertIn('CREATE TABLE IF NOT EXISTS cached_edu_classes',
                      self.ldb)
        self.assertIn('CREATE TABLE IF NOT EXISTS '
                      'cached_edu_class_rosters', self.ldb)
        # Class list: server identity + the two columns the shared
        # teacher cache cannot express (level_order for ordering,
        # data_json for the verbatim payload) + freshness.
        for col in ('id INTEGER PRIMARY KEY',
                    'class_name TEXT NOT NULL DEFAULT \'\'',
                    'class_name_en TEXT',
                    'level_order INTEGER NOT NULL DEFAULT 0',
                    'student_count INTEGER NOT NULL DEFAULT 0',
                    'data_json TEXT',
                    'fetched_at TEXT'):
            self.assertIn(col, self.ldb)
        # Roster: one row per class_id holding the whole response,
        # year-resolution metadata included (server contract).
        for col in ('class_id INTEGER PRIMARY KEY',
                    'roster_year_id INTEGER',
                    'roster_year_name TEXT',
                    'roster_fallback INTEGER NOT NULL DEFAULT 0',
                    'data_json TEXT',
                    'fetched_at TEXT'):
            self.assertIn(col, self.ldb)
        # onCreate path creates them too (fresh installs).
        body = method_body(self.ldb, 'Future<void> _createTables(')
        self.assertIn('await _createEduTables(db);', body)

    def test_wipe_behavior(self):
        body = method_body(self.ldb, 'Future<void> clearAllUserData(')
        for t in ('cached_edu_classes', 'cached_edu_class_rosters'):
            self.assertIn(f"'{t}'", body)

    def test_migration_is_additive(self):
        for t in ('cached_edu_classes', 'cached_edu_class_rosters'):
            self.assertNotIn(f'ALTER TABLE {t}', self.ldb)
            self.assertNotIn(f'DROP TABLE {t}', self.ldb)
        # Earlier phases' tables are untouched by the v30 step.
        for t in ('cached_review_packets', 'cached_mezmur_days',
                  'cached_notifications', 'cached_announcements'):
            self.assertIn(t, self.ldb)
        # No server migration was smuggled in for a mobile-only change.
        self.assertEqual(glob.glob(os.path.join(SQLDIR, '*p1e*')), [])
        for f in glob.glob(os.path.join(SQLDIR, '*.sql')):
            self.assertNotIn('cached_edu', read(f), f)


class P1EClassesFlow(unittest.TestCase):
    """Class list: SQLite renders first; refresh replaces the complete
    snapshot only on success; ordering is the server's."""

    def setUp(self):
        self.src = read(EDU)
        self.load = method_body(self.src, 'Future<void> _load()')

    def test_local_read_precedes_server(self):
        self.assertLess(self.load.find('getCachedEduClasses('),
                        self.load.find('getClasses('),
                        'cached classes must render before the API read')

    def test_offline_skips_network_entirely(self):
        offline_return = self.load.find('if (offline) {')
        self.assertLess(self.load.find('getCachedEduClasses('),
                        offline_return)
        self.assertLess(offline_return, self.load.find('getClasses('))
        self.assertIn('!ConnectivityService().hasLink', self.load)
        self.assertIn(
            "setState(() => _error = 'You appear to be offline.');",
            self.load)

    def test_success_replaces_snapshot_then_rereads(self):
        replace = self.load.find('replaceCachedEduClasses(rows)')
        self.assertGreater(replace, -1)
        reread = self.load.find('getCachedEduClasses(', replace + 1)
        self.assertGreater(reread, replace,
                           'must reread the store after the replace')
        # Only a well-formed envelope may replace: a malformed
        # 'classes' value falls to the failure branch.
        self.assertIn("res.data!['classes'] is List", self.load)
        # An empty-but-valid class set still replaces (a scope with
        # zero active classes is honest emptiness, not a failure) —
        # no isNotEmpty guard wraps the snapshot write.
        self.assertNotIn('if (rows.isNotEmpty)', self.load)

    def test_replacement_is_transactional_snapshot(self):
        ldb = read(LDB)
        body = method_body(ldb, 'Future<void> replaceCachedEduClasses(')
        self.assertIn('db.transaction', body)
        self.assertIn("txn.delete('cached_edu_classes')", body)
        # The delete and the inserts are one atomic transaction, so a
        # crash mid-replace can never leave a half-written snapshot.
        self.assertIn('txn.insert(', body)
        self.assertNotIn('ConflictAlgorithm', body)

    def test_replace_writes_only_after_success(self):
        success = self.load.find('if (res.success')
        replace = self.load.find('replaceCachedEduClasses(')
        self.assertLess(success, replace,
                        'the snapshot write must sit inside the '
                        'success branch — failures never write')

    def test_failed_refresh_keeps_cached_rows(self):
        self.assertIn('res.isNetworkError', self.load)
        self.assertIn("'You appear to be offline.'", self.load)
        # The error card only replaces the rows when NOTHING is
        # cached; cached rows survive under a stale banner instead.
        self.assertIn('(_error != null && _classes.isEmpty)', self.src)
        self.assertIn('— showing cached classes', self.src)
        self.assertIn('· updated ', self.src)
        self.assertIn('Could not load classes', self.load)

    def test_level_order_persisted_and_local_ordering(self):
        ldb = read(LDB)
        write = method_body(ldb, 'Future<void> replaceCachedEduClasses(')
        self.assertIn("'level_order': _asIntLocal(m['level_order'])",
                      write)
        read_method = method_body(
            ldb, 'Future<List<Map<String, dynamic>>> getCachedEduClasses(')
        self.assertIn("orderBy: 'level_order, class_name'", read_method)
        # Never an alphabetical-only reconstruction of the server
        # ordering (the shared cached_classes convention cannot be
        # reused for the education surface).
        self.assertNotIn("orderBy: 'class_name'", read_method)
        # The server's actual ordering contract, both role branches.
        php = read(CLASSES_PHP)
        self.assertEqual(php.count('ORDER BY c.level_order, c.class_name'), 2)

    def test_offline_empty_is_honest(self):
        self.assertIn('You are offline and no classes are cached yet.',
                      self.load)
        self.assertIn('No classes yet', self.src)

    def test_refresh_triggers_and_single_flight(self):
        self.assertIn('statusStream', self.src)
        self.assertIn('AppLifecycleState.resumed', self.src)
        self.assertIn('onRefresh: _refreshAll', self.src)
        self.assertIn('if (_refreshing) return;', self.load)
        self.assertIn('} finally {', self.load)
        tail = self.load[self.load.find('} finally {'):]
        self.assertIn('_refreshing = false;', tail)
        # No setState before the first await (initState calls _load).
        self.assertLess(self.load.find('await _db.getCachedEduClasses'),
                        self.load.find('setState('))

    def test_skeleton_only_first_ever_load(self):
        # The network skeleton is the INITIAL field value only; the
        # local read clears it before any network wait, so no
        # 45-second network-first skeleton remains as the normal
        # initial read path.
        self.assertEqual(self.src.count('_loading = true'), 1)
        # The local read precedes any setState paint.
        self.assertLess(self.load.find('await _db.getCachedEduClasses'),
                        self.load.find('setState('))
        # The timeout is untouched (no workaround-by-shortening).
        config = read(CONFIG)
        self.assertIn('connectionTimeout = 45', config)
        self.assertIn('postTimeout = 60', config)


class P1ERosterFlow(unittest.TestCase):
    """Roster: cached roster renders first; refresh replaces only the
    selected class; selection never clears anything; year metadata is
    the server's."""

    def setUp(self):
        self.src = read(EDU)
        self.roster = method_body(self.src, 'Future<void> _loadRoster(')
        self.open = method_body(self.src, 'Future<void> _openClass(')

    def test_roster_local_read_precedes_server(self):
        self.assertLess(self.roster.find('getCachedEduClassRoster('),
                        self.roster.find('getClassStudents('),
                        'cached roster must render before the API read')

    def test_offline_roster_skips_network(self):
        offline_return = self.roster.find('if (offline) {')
        self.assertLess(self.roster.find('getCachedEduClassRoster('),
                        offline_return)
        self.assertLess(offline_return,
                        self.roster.find('getClassStudents('))
        self.assertIn('!ConnectivityService().hasLink', self.roster)
        # Cached roster + offline keeps rows with an honest note;
        # uncached + offline is the honest not-cached-yet state.
        self.assertIn("'You appear to be offline.'", self.roster)
        self.assertIn('You are offline and this roster is not '
                      'cached yet.', self.roster)

    def test_success_replaces_only_that_class(self):
        ldb = read(LDB)
        write = method_body(ldb, 'Future<void> cacheEduClassRoster(')
        self.assertIn("'class_id': classId,", write)
        self.assertIn('ConflictAlgorithm.replace', write)
        # Replace-by-PK upsert for ONE class — no delete of other
        # classes' rows, no merge of stale students.
        self.assertNotIn('delete', write)
        # The screen calls it only after a successful response.
        failure = self.roster.find('if (!res.success || res.data == null)')
        write_at = self.roster.find('cacheEduClassRoster(id, payload)')
        self.assertLess(failure, write_at)
        # And rereads the store afterwards so UI == SQLite.
        reread = self.roster.find('getCachedEduClassRoster(id',
                                  write_at + 1)
        self.assertGreater(reread, write_at)

    def test_class_selection_never_clears(self):
        # Selection is pure UI state — it makes no database call at
        # all, let alone a destructive one. Each roster stays stored,
        # keyed by class_id.
        for token in ('_db.', 'db.delete', 'batch.delete', 'txn.delete',
                      '.delete(', 'clearAllUserData', 'cacheEdu',
                      'replaceCached'):
            self.assertNotIn(token, self.open,
                             f'_openClass must not touch {token!r}')
        # Nothing in the screen issues raw deletes; the only roster
        # writer is the replace-on-success path in local_db.
        self.assertNotIn('.delete(', self.src)
        ldb = read(LDB)
        wipe = method_body(ldb, 'Future<void> clearAllUserData(')
        writers = method_body(ldb, 'Future<void> cacheEduClassRoster(')
        self.assertNotIn('delete', writers)
        # The roster table is cleared ONLY by the logout wipe.
        self.assertIn("'cached_edu_class_rosters'", wipe)

    def test_failed_roster_refresh_keeps_cached_roster(self):
        self.assertIn('res.isNetworkError', self.roster)
        self.assertIn('Could not load students. Try again.', self.roster)
        # The failure branch returns before any cache write.
        failure = self.roster.find('if (!res.success || res.data == null)')
        write = self.roster.find('cacheEduClassRoster(')
        self.assertLess(failure, write)
        # Cached rows survive under the stale banner; the error card
        # only shows when nothing is rendered.
        self.assertIn('— showing the cached roster', self.src)

    def test_switched_away_response_not_painted(self):
        # A late response for a class the user closed or switched
        # away from is persisted (keyed by class_id, served on
        # reopen) but never painted under the wrong class.
        self.assertIn('_openId != id', self.roster)

    def test_year_metadata_stored_verbatim(self):
        ldb = read(LDB)
        write = method_body(ldb, 'Future<void> cacheEduClassRoster(')
        self.assertIn("payload['roster_year_id']", write)
        self.assertIn("payload['roster_year_name']", write)
        self.assertIn("payload['roster_fallback']", write)
        # Read back verbatim too — never re-resolved locally.
        back = method_body(
            ldb, 'Future<Map<String, dynamic>?> getCachedEduClassRoster(')
        self.assertNotIn('ORDER BY', back)
        self.assertNotIn('getCurrentAcademicYear', back)

    def test_year_metadata_rendered_not_reconstructed(self):
        # The note is built ONLY from the response's own metadata
        # via RosterParse — the current-year vs most-populated-prior-
        # year resolution is server contract.
        self.assertIn('RosterParse.fallback', self.src)
        self.assertIn('RosterParse.yearName', self.src)
        self.assertIn('Showing the $year roster.', self.src)
        self.assertIn('Showing students from a previous year.',
                      self.src)
        # No local year resolution anywhere in the screen.
        self.assertNotIn('getCurrentAcademicYear', self.src)
        self.assertNotIn('academic_year', self.src)

    def test_no_membership_from_cached_members(self):
        # Class membership is a relationship returned by
        # GET /classes/{id}/students — never derived from the member
        # directory cache.
        for token in ('getCachedMembers', 'cached_members', 'Member('):
            self.assertNotIn(token, self.src)
        self.assertIn('getClassStudents', self.src)

    def test_roster_parse_guard_preserved(self):
        # The RosterParse safety net is intact: a bad row is skipped,
        # a count/payload mismatch is surfaced honestly.
        self.assertIn('The server sent students but this phone could '
                      'not read them.', self.src)
        self.assertIn('RosterParse.reportedCount', self.src)
        self.assertIn('RosterParse.students', self.src)

    def test_roster_single_flight(self):
        self.assertIn('if (_rosterRefreshing) {', self.roster)
        self.assertIn('_rosterRefreshing = true;', self.roster)
        tail = self.roster[self.roster.find('} finally {'):]
        self.assertIn('_rosterRefreshing = false;', tail)
        # A refresh in flight for another class never blocks this
        # class's cached render (local half runs before the guard).
        self.assertLess(self.roster.find('getCachedEduClassRoster('),
                        self.roster.find('if (_rosterRefreshing)'))
        # The guard returns before any network attempt.
        guard = self.roster.find('if (_rosterRefreshing) {')
        api = self.roster.find('getClassStudents(')
        self.assertLess(guard, api)
        guard_end = self.roster.find('return;', guard)
        self.assertLess(guard_end, api)


class P1EProtectedCaches(unittest.TestCase):
    """The teacher workflow's shared caches stay byte/semantically
    untouched: cached_classes, cached_students, cached_members."""

    def setUp(self):
        self.ldb = read(LDB)
        self.src = read(EDU)

    def test_shared_class_cache_untouched(self):
        # CatalogService's destructive full-table replace remains
        # exactly once, inside cacheClasses.
        self.assertEqual(self.ldb.count("batch.delete('cached_classes')"),
                         1)
        writer = method_body(self.ldb, 'Future<void> cacheClasses(')
        self.assertIn("batch.delete('cached_classes')", writer)
        # Schema unchanged: no level_order, no data_json, no year
        # fields — the education surface did not enrich it.
        i = self.ldb.find('CREATE TABLE cached_classes')
        self.assertGreater(i, -1)
        ddl = self.ldb[i:self.ldb.find(')', i)]
        self.assertIn('section TEXT', ddl)
        self.assertIn('updated_at TEXT', ddl)
        for absent in ('level_order', 'data_json', 'roster_year',
                       'fetched_at'):
            self.assertNotIn(absent, ddl)
        # Its own alphabetical reader convention is untouched.
        reader = method_body(
            self.ldb,
            'Future<List<Map<String, dynamic>>> getCachedClasses(')
        self.assertIn("orderBy: 'class_name'", reader)

    def test_shared_student_cache_shape_and_all_consumers_preserved(self):
        # cacheStudents keeps its per-class delete+rewrite shape.
        writer = method_body(self.ldb, 'Future<void> cacheStudents(')
        self.assertIn("delete('cached_students', where: 'class_id = ?'",
                      writer)
        # All three established consumers still publish the shared roster.
        # Attendance now uses the atomic roster+sheet/session-fenced publisher;
        # grades and warm-store retain the compatible standalone writer.
        for f, why in ((GRADES, 'teacher grades'), (WARM, 'warm store')):
            self.assertIn('cacheStudents', read(f), why)
        self.assertIn('cacheAttendanceSnapshot', read(ATTEND))
        pair = method_body(self.ldb, 'Future<void> cacheAttendanceSnapshot(')
        self.assertIn('db.transaction((txn)', pair)
        self.assertIn('_requireAttendanceSyncSession(txn, expectedSession)', pair)
        self.assertIn("batch.insert('cached_students', row)", pair)
        self.assertIn("batch.insert('cached_attendance'", pair)

    def test_edu_surface_does_not_use_shared_caches(self):
        for token in ('cached_classes', 'cached_students', 'CatalogService',
                      'cacheClasses', 'cacheStudents', 'getCachedClasses',
                      'getCachedStudents'):
            self.assertNotIn(token, self.src)
        # The P1-E methods write ONLY the dedicated tables.
        for sig in ('Future<void> replaceCachedEduClasses(',
                    'Future<void> cacheEduClassRoster('):
            body = method_body(self.ldb, sig)
            for token in ('cached_classes', 'cached_students',
                          'cached_members'):
                self.assertNotIn(token, body)

    def test_catalog_service_and_readers_untouched(self):
        catalog = read(CATALOG)
        self.assertIn('getCachedClasses', catalog)
        self.assertIn('cacheClasses(list)', catalog)
        # Teacher home readers still use the shared cache.
        self.assertIn('getCachedClasses', read(TEACHER_HOME))
        self.assertIn('getCachedClasses', read(ATT_TAKER_HOME))
        # SyncService still warms via CatalogService, unchanged.
        self.assertIn('CatalogService().classes()', read(SYNC))

    def test_cached_members_not_repurposed(self):
        # The member directory cache keeps its own local-first flow;
        # P1-E derived nothing from it (class membership comes only
        # from the roster endpoint — pinned in the roster flow).
        self.assertIn('getCachedMembers', read(MEMBERS))
        for sig in ('Future<void> replaceCachedEduClasses(',
                    'Future<void> cacheEduClassRoster('):
            for token in ('cached_members', 'getCachedMembers'):
                self.assertNotIn(token, method_body(self.ldb, sig))


class P1ENetworkSafety(unittest.TestCase):
    """No destructive clear before a refresh succeeds; no
    network-first skeleton on the normal read path."""

    def setUp(self):
        self.src = read(EDU)
        self.load = method_body(self.src, 'Future<void> _load()')
        self.roster = method_body(self.src, 'Future<void> _loadRoster(')

    def test_no_destructive_clear_before_success(self):
        # The screen itself never issues deletes; snapshot writes sit
        # strictly inside success branches (pinned in the flow
        # classes), and the DB replace methods run in one
        # transaction AFTER the response is in hand.
        self.assertNotIn('.delete(', self.src)
        self.assertNotIn('clearAllUserData', self.src)
        ldb = read(LDB)
        replace = method_body(ldb, 'Future<void> replaceCachedEduClasses(')
        self.assertIn('await db.transaction', replace)

    def test_no_network_first_read_path(self):
        # Both halves read SQLite before any network call, offline
        # returns come before the API call, and the radio state is
        # consulted via ConnectivityService (never a blind fetch).
        for body, local, api in (
                (self.load, 'getCachedEduClasses(', 'getClasses('),
                (self.roster, 'getCachedEduClassRoster(',
                 'getClassStudents(')):
            self.assertLess(body.find(local), body.find(api))
            self.assertLess(body.find('if (offline) {'), body.find(api))
            self.assertIn('!ConnectivityService().hasLink', body)

    def test_failure_keeps_usable_rows(self):
        # List and roster each keep their cached content under an
        # honest banner when a refresh fails or the radio is down.
        self.assertIn('(_error != null && _classes.isEmpty)', self.src)
        self.assertIn('— showing cached classes', self.src)
        self.assertIn('— showing the cached roster', self.src)


class P1EDoNotTouch(unittest.TestCase):
    """F8, SyncService, api_service, server PHP, completed phases,
    and the sibling education surfaces are untouched."""

    def setUp(self):
        self.ldb = read(LDB)
        self.src = read(EDU)

    def test_f8_unchanged(self):
        # Attendance now refuses deletion entirely; other three F8 filters stay.
        self.assertEqual(self.ldb.count('sync_error IS NULL'), 3)
        attendance = method_body(self.ldb, 'Future<void> dropPendingAttendance(')
        self.assertIn('throw StateError(', attendance)
        self.assertNotIn('.delete(', attendance)
        self.assertNotIn('await database', attendance)
        for name in ('dropPendingGrades', 'dropPendingMezmur', 'dropPendingHr'):
            body = method_body(self.ldb, f'Future<void> {name}(')
            self.assertIn('sync_error IS NULL', body, name)
        sync = read(SYNC) + read(POLICY)
        for token in ('ALREADY_SUBMITTED', 'WORKFLOW_REJECTED',
                      'IDEMPOTENCY_CONFLICT', 'IDEMPOTENCY_IN_PROGRESS',
                      'classifyOutboxResponse'):
            self.assertIn(token, sync)
        # The education cache has no relationship to the outbox.
        for token in ('pending_mezmur', 'pending_attendance',
                      'pending_grades', 'pending_hr'):
            self.assertNotIn(token, method_body(
                self.ldb, 'Future<void> replaceCachedEduClasses('))
            self.assertNotIn(token, method_body(
                self.ldb, 'Future<void> cacheEduClassRoster('))

    def test_no_education_outbox_introduced(self):
        # Read caching only — no outbox, no queue, no write path.
        for token in ('pending_edu', 'edu_outbox', 'enqueueEdu',
                      'outbox'):
            self.assertNotIn(token, self.src)
        for token in ('pending_edu', 'edu_outbox', 'enqueueEdu'):
            self.assertNotIn(token, self.ldb)
            self.assertNotIn(token, read(SYNC))

    def test_api_service_unchanged(self):
        api = read(APISVC)
        # Both calls pre-exist and suffice — P1-E added nothing.
        self.assertIn("Future<ApiResponse> getClasses() => "
                      "get('/classes');", api)
        self.assertIn('Future<ApiResponse> getClassStudents(int classId)',
                      api)
        self.assertIn("get('/classes/$classId/students')", api)

    def test_server_contract_unchanged(self):
        php = read(CLASSES_PHP)
        # Role gate: finance/material stay 403.
        self.assertIn("err('Classes are not available for this role. "
                      "Use the website.', 403)", php)
        # Roster year resolution stays server-side, verbatim envelope.
        self.assertIn('EnrollmentService::resolveRosterYear', php)
        self.assertIn("'roster_year_id' => $scope['year_id'] ?? null",
                      php)
        self.assertIn("'roster_year_name' => $scope['year_name'] ?? null",
                      php)
        self.assertIn("'roster_fallback' => !empty($scope['fallback'])",
                      php)
        # PII-shaped rows via the shared ACL helper.
        self.assertIn('apiRosterStudentRow', php)
        self.assertIn('function apiRosterStudentRow', read(ACL))
        # Year resolution service and current-year source untouched.
        enroll = read(ENROLL_SVC)
        self.assertIn('function resolveRosterYear', enroll)
        self.assertIn('function fetchRoster', enroll)
        self.assertIn('function getCurrentAcademicYear', read(DBCORE))
        # Web-side class management semantics untouched.
        self.assertIn("status = 'withdrawn'", read(EDU_ADMIN))

    def test_completed_phases_untouched(self):
        # P1-A/P1-B/P1-C/P1-D read flows still present and
        # local-first.
        self.assertIn('getCachedNotifications', read(NOTIF))
        self.assertIn('getCachedMembers', read(MEMBERS))
        self.assertIn('getCachedMezmurDays', read(MEZMUR_HOME))
        self.assertIn('getCachedReviewPackets', read(REVIEWS))
        # Their tables and migration branches still exist.
        for t in ('cached_mezmur_days', 'cached_review_packets',
                  'cached_review_packet_details', 'cached_review_stats'):
            self.assertIn(t, self.ldb)
        self.assertIn('if (oldVersion < 28)', self.ldb)
        self.assertIn('if (oldVersion < 29)', self.ldb)

    def test_sibling_edu_surfaces_untouched(self):
        # Enrollment (Tier C), Subjects, Teachers, and the department
        # home were explicitly out of scope: none gained the P1-E
        # read model.
        for f in EXCLUDED_SCREENS:
            src = read(f)
            for token in ('cached_edu_classes', 'cached_edu_class_rosters',
                          'getCachedEduClasses', 'getCachedEduClassRoster',
                          'replaceCachedEduClasses', 'cacheEduClassRoster'):
                self.assertNotIn(token, src, f'{f}: {token}')


if __name__ == '__main__':
    unittest.main()
