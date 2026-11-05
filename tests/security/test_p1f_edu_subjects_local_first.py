"""P1-F Education Subjects local-first conversion — static pins.

No sqflite runtime harness exists in this sandbox (documented
limitation since F8), so these pins assert the SOURCE: the DB
migration to v31, the DEDICATED subject-catalog read model (never the
teacher grade-bootstrap cached_subjects), replace-on-success snapshot
semantics (GET /subjects is a complete active set — same authorized
deviation from merge-only as P1-E), local ordering per Option A
(subject_name COLLATE NOCASE, disclosed collation edge), verbatim
class_count, honest offline behavior, and every do-not-touch
invariant (cached_subjects teacher cache, cached_classes/
cached_students/cached_members, F8/SyncService, api_service, server
PHP, timeout, sibling edu surfaces, completed phases). Behavioral
verification is the device drill's responsibility.
"""

import glob
import os
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
APP = os.path.join(ROOT, 'Mobile', 'wbws_flutter_app')
LDB = os.path.join(APP, 'lib', 'services', 'local_db.dart')
SUBJECTS = os.path.join(APP, 'lib', 'screens', 'edu_dept',
                        'edu_subjects_screen.dart')
SYNC = os.path.join(APP, 'lib', 'services', 'sync_service.dart')
POLICY = os.path.join(APP, 'lib', 'services', 'outbox_policy.dart')
WARM = os.path.join(APP, 'lib', 'services', 'warm_store.dart')
GRADES = os.path.join(APP, 'lib', 'screens', 'teacher', 'teacher_grades.dart')
NOTIF = os.path.join(APP, 'lib', 'screens', 'notifications',
                     'notification_center_screen.dart')
MEMBERS = os.path.join(APP, 'lib', 'screens', 'members',
                       'member_list_screen.dart')
MEZMUR_HOME = os.path.join(APP, 'lib', 'screens', 'mezmur', 'mezmur_home.dart')
REVIEWS = os.path.join(APP, 'lib', 'screens', 'reviews',
                       'review_inbox_screen.dart')
EDU_CLASSES = os.path.join(APP, 'lib', 'screens', 'edu_dept',
                           'edu_classes_screen.dart')
CONFIG = os.path.join(APP, 'lib', 'utils', 'config.dart')
APISVC = os.path.join(APP, 'lib', 'services', 'api_service.dart')
SUBJECTS_PHP = os.path.join(ROOT, 'api', 'v1', 'routes', 'subjects.php')
SUBJECTS_ADMIN = os.path.join(ROOT, 'admin', 'api_subjects.php')
SQLDIR = os.path.join(ROOT, 'sql')
EDU_DEPT = os.path.join(APP, 'lib', 'screens', 'edu_dept')

# Sibling education surfaces explicitly OUT of P1-F scope.
EXCLUDED_SCREENS = (
    os.path.join(EDU_DEPT, 'edu_enrollment_screen.dart'),
    os.path.join(EDU_DEPT, 'edu_teachers_screen.dart'),
    os.path.join(EDU_DEPT, 'edu_classes_screen.dart'),
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


class P1FDatabase(unittest.TestCase):
    """DB v30 → v31, one dedicated subject-catalog read-model table,
    wired into fresh installs, upgrades, and the logout wipe."""

    def setUp(self):
        self.ldb = read(LDB)

    def test_version_tracks_current(self):
        # P1-F introduced v31. The current version is centralized in
        # the v34 schema contract without changing the subject tables.
        self.assertIn('version: localDatabaseSchemaVersion,', self.ldb)
        self.assertNotIn('version: 30,', self.ldb)

    def test_migration_branch_exists(self):
        self.assertIn('if (oldVersion < 31)', self.ldb)
        self.assertIn('_createEduSubjectsTable(db)', self.ldb)
        # P1-E's own migration branch is intact history.
        self.assertIn('if (oldVersion < 30)', self.ldb)
        self.assertIn('_createEduTables(db)', self.ldb)

    def test_table_and_schema(self):
        self.assertIn('CREATE TABLE IF NOT EXISTS cached_edu_subjects',
                      self.ldb)
        # Catalog identity is the subject id alone; class_count is the
        # server aggregate stored verbatim; data_json preserves the
        # server row; fetched_at drives the freshness banner.
        for col in ('id INTEGER PRIMARY KEY',
                    "subject_name TEXT NOT NULL DEFAULT ''",
                    'subject_name_en TEXT',
                    'subject_code TEXT',
                    'class_count INTEGER NOT NULL DEFAULT 0',
                    'data_json TEXT',
                    'fetched_at TEXT'):
            self.assertIn(col, self.ldb)
        # onCreate path creates it too (fresh installs).
        body = method_body(self.ldb, 'Future<void> _createTables(')
        self.assertIn('await _createEduSubjectsTable(db);', body)

    def test_wipe_behavior(self):
        body = method_body(self.ldb, 'Future<void> clearAllUserData(')
        self.assertIn("'cached_edu_subjects'", body)

    def test_migration_is_additive(self):
        self.assertNotIn('ALTER TABLE cached_edu_subjects', self.ldb)
        self.assertNotIn('DROP TABLE cached_edu_subjects', self.ldb)
        # Earlier phases' tables are untouched by the v31 step.
        for t in ('cached_edu_classes', 'cached_edu_class_rosters',
                  'cached_review_packets', 'cached_mezmur_days',
                  'cached_notifications'):
            self.assertIn(t, self.ldb)
        # No server migration was smuggled in for a mobile-only change.
        self.assertEqual(glob.glob(os.path.join(SQLDIR, '*p1f*')), [])
        for f in glob.glob(os.path.join(SQLDIR, '*.sql')):
            self.assertNotIn('cached_edu', read(f), f)


class P1FListFlow(unittest.TestCase):
    """Catalog: SQLite renders first; refresh replaces the complete
    snapshot only on success; ordering per Option A."""

    def setUp(self):
        self.src = read(SUBJECTS)
        self.load = method_body(self.src, 'Future<void> _load()')

    def test_local_read_precedes_server(self):
        self.assertLess(self.load.find('getCachedEduSubjects('),
                        self.load.find('getSubjects('),
                        'cached subjects must render before the API read')

    def test_offline_skips_network_entirely(self):
        offline_return = self.load.find('if (offline) {')
        self.assertLess(self.load.find('getCachedEduSubjects('),
                        offline_return)
        self.assertLess(offline_return, self.load.find('getSubjects('))
        self.assertIn('!ConnectivityService().hasLink', self.load)
        self.assertIn(
            "setState(() => _error = 'You appear to be offline.');",
            self.load)

    def test_success_replaces_snapshot_then_rereads(self):
        replace = self.load.find('replaceCachedEduSubjects(rows)')
        self.assertGreater(replace, -1)
        reread = self.load.find('getCachedEduSubjects(', replace + 1)
        self.assertGreater(reread, replace,
                           'must reread the store after the replace')
        # Only a well-formed envelope may replace: a malformed
        # 'subjects' value falls to the failure branch.
        self.assertIn("res.data!['subjects'] is List", self.load)
        # An empty-but-valid catalog still replaces (zero active
        # subjects is honest emptiness, not a failure) — no
        # isNotEmpty guard wraps the snapshot write.
        self.assertNotIn('if (rows.isNotEmpty)', self.load)

    def test_replacement_is_transactional_snapshot(self):
        ldb = read(LDB)
        body = method_body(ldb, 'Future<void> replaceCachedEduSubjects(')
        self.assertIn('db.transaction', body)
        self.assertIn("txn.delete('cached_edu_subjects')", body)
        # The delete and the inserts are one atomic transaction, so a
        # crash mid-replace can never leave a half-written snapshot.
        self.assertIn('txn.insert(', body)
        self.assertNotIn('ConflictAlgorithm', body)

    def test_replace_writes_only_after_success(self):
        success = self.load.find('if (res.success')
        replace = self.load.find('replaceCachedEduSubjects(')
        self.assertLess(success, replace,
                        'the snapshot write must sit inside the '
                        'success branch — failures never write')

    def test_failed_refresh_keeps_cached_rows(self):
        self.assertIn('res.isNetworkError', self.load)
        self.assertIn("'You appear to be offline.'", self.load)
        # The error text only replaces the rows when NOTHING is
        # cached; cached rows survive under a stale banner instead.
        self.assertIn('(_error != null && _subjects.isEmpty)', self.src)
        self.assertIn('— showing cached subjects', self.src)
        self.assertIn('· updated ', self.src)
        self.assertIn('Could not load subjects', self.load)

    def test_local_ordering_matches_server_option_a(self):
        ldb = read(LDB)
        read_method = method_body(
            ldb, 'Future<List<Map<String, dynamic>>> getCachedEduSubjects(')
        # Option A (authorized with the audit): reproduce the server's
        # ORDER BY subject_name, with COLLATE NOCASE approximating
        # MySQL utf8mb4_unicode_ci's ASCII case fold (Amharic orders
        # code-point-identically; the case-mixed Latin edge is a
        # disclosed cosmetic limitation).
        self.assertIn("orderBy: 'subject_name COLLATE NOCASE'",
                      read_method)
        # Not a bare alphabetical read either — the collation fold is
        # part of the contract.
        self.assertNotIn("orderBy: 'subject_name'", read_method)
        # The server's actual ordering contract.
        php = read(SUBJECTS_PHP)
        self.assertIn('ORDER BY s.subject_name', php)

    def test_class_count_stored_verbatim_never_recomputed(self):
        ldb = read(LDB)
        write = method_body(ldb, 'Future<void> replaceCachedEduSubjects(')
        self.assertIn("'class_count': _asIntLocal(m['class_count'])", write)
        read_method = method_body(
            ldb, 'Future<List<Map<String, dynamic>>> getCachedEduSubjects(')
        self.assertNotIn('COUNT', read_method)
        self.assertNotIn('SUM', read_method)
        # The screen never derives the count from anything either —
        # class_count is a server aggregate over class_subjects.
        self.assertNotIn('COUNT', self.src)
        self.assertNotIn('cached_classes', self.src)

    def test_offline_empty_is_honest(self):
        self.assertIn('You are offline and no subjects are cached yet.',
                      self.load)
        self.assertIn('No subjects yet', self.src)

    def test_refresh_triggers_and_single_flight(self):
        self.assertIn('statusStream', self.src)
        self.assertIn('AppLifecycleState.resumed', self.src)
        self.assertIn('onRefresh: _load', self.src)
        self.assertIn('if (_refreshing) return;', self.load)
        self.assertIn('} finally {', self.load)
        tail = self.load[self.load.find('} finally {'):]
        self.assertIn('_refreshing = false;', tail)
        # No setState before the first await (initState calls _load).
        self.assertLess(self.load.find('await _db.getCachedEduSubjects'),
                        self.load.find('setState('))

    def test_skeleton_only_first_ever_load(self):
        # The network spinner is the INITIAL field value only; the
        # local read clears it before any network wait, so no
        # 45-second network-first spinner remains as the normal
        # initial read path.
        self.assertEqual(self.src.count('_loading = true'), 1)
        # The timeout is untouched (no workaround-by-shortening).
        config = read(CONFIG)
        self.assertIn('connectionTimeout = 45', config)
        self.assertIn('postTimeout = 60', config)


class P1FProtectedCaches(unittest.TestCase):
    """The teacher grade-bootstrap cache stays byte/semantically
    untouched: cached_subjects (and the other shared caches)."""

    def setUp(self):
        self.ldb = read(LDB)
        self.src = read(SUBJECTS)

    def test_teacher_grade_bootstrap_cache_untouched(self):
        # cached_subjects keeps its (id, class_id) composite identity,
        # its per-class destructive rewrite, and its class-scoped
        # reader — the whole teacher grade-bootstrap flow unchanged.
        i = self.ldb.find('CREATE TABLE cached_subjects')
        self.assertGreater(i, -1)
        # Slice to the DDL's own closing line — the composite PK's
        # inner paren would truncate a naive first-')' cut.
        ddl = self.ldb[i:self.ldb.find('\n      )', i)]
        self.assertIn('class_id INTEGER NOT NULL', ddl)
        self.assertIn('PRIMARY KEY (id, class_id)', ddl)
        # No class_count column smuggled into the teacher cache.
        self.assertNotIn('class_count', ddl)
        self.assertNotIn('data_json', ddl)
        writer = method_body(self.ldb, 'Future<void> cacheSubjects(')
        self.assertIn("delete('cached_subjects', where: 'class_id = ?'",
                      writer)
        reader = method_body(
            self.ldb,
            'Future<List<Map<String, dynamic>>> getCachedSubjects(')
        self.assertIn("where: 'class_id = ?'", reader)
        # Both established writers are still there.
        for f, why in ((GRADES, 'teacher grades'),
                       (WARM, 'warm store')):
            src = read(f)
            self.assertIn('cacheSubjects', src, why)
            self.assertIn('getCachedSubjects', src, why)

    def test_edu_surface_does_not_use_shared_caches(self):
        for token in ('cached_subjects', 'cacheSubjects',
                      'getCachedSubjects', 'cached_classes',
                      'cached_students', 'cached_members', 'CatalogService',
                      'cacheClasses', 'getCachedClasses'):
            self.assertNotIn(token, self.src)
        # The P1-F methods write ONLY the dedicated table.
        for sig in ('Future<void> replaceCachedEduSubjects(',):
            body = method_body(self.ldb, sig)
            for token in ('cached_subjects', 'cached_classes',
                          'cached_students', 'cached_members'):
                self.assertNotIn(token, body)


class P1FDoNotTouch(unittest.TestCase):
    """F8, SyncService, api_service, server PHP, completed phases,
    and the sibling education surfaces are untouched."""

    def setUp(self):
        self.ldb = read(LDB)
        self.src = read(SUBJECTS)

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
        # The subject cache has no relationship to the outbox.
        for token in ('pending_mezmur', 'pending_attendance',
                      'pending_grades', 'pending_hr'):
            self.assertNotIn(token, method_body(
                self.ldb, 'Future<void> replaceCachedEduSubjects('))

    def test_no_outbox_introduced(self):
        # Read caching only — no outbox, no queue, no write path.
        for token in ('pending_edu', 'edu_outbox', 'enqueueEdu',
                      'outbox'):
            self.assertNotIn(token, self.src)
        for token in ('pending_edu', 'edu_outbox', 'enqueueEdu'):
            self.assertNotIn(token, self.ldb)
            self.assertNotIn(token, read(SYNC))

    def test_api_service_unchanged(self):
        # getSubjects() pre-exists and suffices — P1-F added nothing.
        self.assertIn("Future<ApiResponse> getSubjects() => "
                      "get('/subjects');", read(APISVC))

    def test_server_contract_unchanged(self):
        php = read(SUBJECTS_PHP)
        # Role gate: education roles + teacher only.
        self.assertIn("apiRolesEducation(), ['teacher']", php)
        self.assertIn("err('You cannot list subjects.', 403)", php)
        # Complete active snapshot, catalog envelope, server ordering.
        self.assertIn('WHERE s.is_active = 1', php)
        self.assertIn('ORDER BY s.subject_name', php)
        self.assertIn("'subjects' => $subjects", php)
        # Web-side mutation semantics unchanged: soft delete with
        # grade history, hard delete cascading link tables, and the
        # full-replace class assignment that moves class_count.
        admin = read(SUBJECTS_ADMIN)
        self.assertIn('UPDATE subjects SET is_active = 0', admin)
        self.assertIn('DELETE FROM subjects WHERE id = ?', admin)
        self.assertIn('AssignmentService::setClassSubjects', admin)

    def test_completed_phases_untouched(self):
        # P1-A..P1-E read flows still present and local-first.
        self.assertIn('getCachedNotifications', read(NOTIF))
        self.assertIn('getCachedMembers', read(MEMBERS))
        self.assertIn('getCachedMezmurDays', read(MEZMUR_HOME))
        self.assertIn('getCachedReviewPackets', read(REVIEWS))
        classes = read(EDU_CLASSES)
        self.assertIn('getCachedEduClasses', classes)
        self.assertIn('getCachedEduClassRoster', classes)
        # Their tables and migration branches still exist.
        for t in ('cached_edu_classes', 'cached_edu_class_rosters',
                  'cached_review_packets', 'cached_mezmur_days',
                  'cached_notifications'):
            self.assertIn(t, self.ldb)
        self.assertIn('if (oldVersion < 28)', self.ldb)
        self.assertIn('if (oldVersion < 29)', self.ldb)
        self.assertIn('if (oldVersion < 30)', self.ldb)

    def test_sibling_edu_surfaces_untouched(self):
        # Enrollment (Tier C), Teachers, the P1-E classes screen, and
        # the department home were explicitly out of scope: none
        # gained the P1-F read model.
        for f in EXCLUDED_SCREENS:
            src = read(f)
            for token in ('cached_edu_subjects', 'getCachedEduSubjects',
                          'replaceCachedEduSubjects'):
                self.assertNotIn(token, src, f'{f}: {token}')


if __name__ == '__main__':
    unittest.main()
