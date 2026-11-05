"""P1-C Mezmur Home local-first conversion — static pins.

No sqflite runtime harness exists in this sandbox (documented
limitation since F8), so these pins assert the SOURCE: DB migration,
table wiring, read order, merge/no-derivation rules, refresh
triggers, honest offline states, and every do-not-touch invariant
(F8, SyncService, mezmur_attendance.dart, server PHP, timeout).
Behavioral verification is the device drill's responsibility.
"""

import glob
import os
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
APP = os.path.join(ROOT, 'Mobile', 'wbws_flutter_app')
LDB = os.path.join(APP, 'lib', 'services', 'local_db.dart')
HOME = os.path.join(APP, 'lib', 'screens', 'mezmur', 'mezmur_home.dart')
ATTEND = os.path.join(APP, 'lib', 'screens', 'mezmur', 'mezmur_attendance.dart')
SYNC = os.path.join(APP, 'lib', 'services', 'sync_service.dart')
POLICY = os.path.join(APP, 'lib', 'services', 'outbox_policy.dart')
CONFIG = os.path.join(APP, 'lib', 'utils', 'config.dart')
APISVC = os.path.join(APP, 'lib', 'services', 'api_service.dart')
MEZMUR_PHP = os.path.join(ROOT, 'api', 'v1', 'routes', 'mezmur.php')
MEZMUR_SVC = os.path.join(ROOT, 'admin', 'backend', 'services',
                          'MezmurAttendanceService.php')
SQLDIR = os.path.join(ROOT, 'sql')


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


class P1CDatabase(unittest.TestCase):
    """DB v27 → v28, one new table, wired into fresh installs,
    upgrades, and the logout/auth-expiry wipe."""

    def setUp(self):
        self.ldb = read(LDB)

    def test_version_tracks_current(self):
        # P1-C's bump to v28 (Mezmur days) is history. The current
        # version is centralized in the v34 schema contract; the
        # mezmur-days table itself is untouched by later steps.
        self.assertIn('version: localDatabaseSchemaVersion,', self.ldb)
        self.assertNotIn('version: 28,', self.ldb)

    def test_migration_branch_exists(self):
        self.assertIn('if (oldVersion < 28)', self.ldb)
        self.assertIn('_createMezmurDaysTable(db)', self.ldb)

    def test_table_schema(self):
        self.assertIn('CREATE TABLE IF NOT EXISTS cached_mezmur_days',
                      self.ldb)
        # Server identity is the primary key; the server aggregate
        # fields are stored directly; a local fetch stamp exists.
        self.assertIn('id INTEGER PRIMARY KEY', self.ldb)
        self.assertIn('attendance_date TEXT NOT NULL', self.ldb)
        self.assertIn('marked INTEGER NOT NULL DEFAULT 0', self.ldb)
        self.assertIn('attended INTEGER NOT NULL DEFAULT 0', self.ldb)
        self.assertIn('data_json TEXT', self.ldb)
        self.assertIn('fetched_at TEXT', self.ldb)
        # onCreate path creates it too (fresh installs).
        body = method_body(self.ldb, 'Future<void> _createTables(')
        self.assertIn('await _createMezmurDaysTable(db);', body)

    def test_table_in_user_data_wipe(self):
        body = method_body(self.ldb, 'Future<void> clearAllUserData(')
        self.assertIn("'cached_mezmur_days'", body)

    def test_migration_is_additive(self):
        self.assertNotIn('ALTER TABLE cached_mezmur_days', self.ldb)
        self.assertNotIn('DROP TABLE', method_body(
            self.ldb, 'Future<void> _createMezmurDaysTable('))
        # Existing Mezmur tables untouched by the v28 step.
        self.assertIn('CREATE TABLE IF NOT EXISTS pending_mezmur', self.ldb)
        self.assertIn('CREATE TABLE IF NOT EXISTS cached_mezmur_sheet',
                      self.ldb)
        # P1-C did not add a server migration. Do not reserve a global
        # migration number here: later, unrelated server work may use it.
        self.assertEqual(glob.glob(os.path.join(SQLDIR, '*p1c*')), [])


class P1CLocalFirstFlow(unittest.TestCase):
    """SQLite renders before the network; success persists and the
    UI re-reads the store; failure keeps rows."""

    def setUp(self):
        self.home = read(HOME)
        self.load = method_body(self.home, 'Future<void> _load()')

    def test_local_read_precedes_server(self):
        self.assertLess(self.load.find('getCachedMezmurDays()'),
                        self.load.find('getMezmurDays('),
                        'cached days must render before the API read')

    def test_offline_skips_network_entirely(self):
        # The offline early-return sits between the local read and
        # the server call — offline never waits on a doomed request.
        offline_return = self.load.find('if (offline) {')
        self.assertGreater(self.load.find('getCachedMezmurDays()'),
                           -1)
        self.assertLess(self.load.find('getCachedMezmurDays()'),
                        offline_return)
        self.assertLess(offline_return, self.load.find('getMezmurDays('))
        self.assertIn("!ConnectivityService().hasLink", self.load)
        # Cached + offline: rows render AND the skipped refresh is
        # disclosed (honest stale banner, never silent).
        self.assertIn(
            "setState(() => _error = 'You appear to be offline.');",
            self.load)

    def test_success_persists_then_rereads(self):
        cache = self.load.find('cacheMezmurDays(items)')
        reread = self.load.find('getCachedMezmurDays()', cache + 1)
        self.assertGreater(cache, -1)
        self.assertGreater(reread, cache,
                           'after persisting, the UI must re-read SQLite')

    def test_merge_is_upsert_not_destructive(self):
        ldb = read(LDB)
        body = method_body(ldb, 'Future<void> cacheMezmurDays(')
        self.assertIn('ConflictAlgorithm.replace', body)
        self.assertNotIn('db.delete', body)
        self.assertNotIn('batch.delete', body)
        # Empty pages must not wipe history either: the call is
        # guarded by items.isNotEmpty in the screen.
        self.assertIn('if (items.isNotEmpty)', self.load)

    def test_home_still_displays_five_rows(self):
        self.assertEqual(self.load.count('.take(5)'), 2)
        self.assertIn('_days = cached.take(5).toList();', self.load)
        self.assertIn('_days = localNow.take(5).toList();', self.load)

    def test_no_offset_pagination_introduced(self):
        ldb = read(LDB)
        for sig in ('Future<List<Map<String, dynamic>>> '
                    'getCachedMezmurDays(',):
            self.assertNotIn('OFFSET', method_body(ldb, sig))
        self.assertNotIn('offset:', self.load)

    def test_ordering_matches_server(self):
        ldb = read(LDB)
        body = method_body(
            ldb, 'Future<List<Map<String, dynamic>>> getCachedMezmurDays(')
        self.assertIn("orderBy: 'attendance_date DESC'", body)

    def test_aggregates_stored_directly_not_derived(self):
        ldb = read(LDB)
        body = method_body(ldb, 'Future<void> cacheMezmurDays(')
        # Server-authoritative values, stored verbatim from the API
        # row — never computed from the sheet cache.
        self.assertIn("_asIntLocal(m['marked'])", body)
        self.assertIn("_asIntLocal(m['attended'])", body)
        self.assertNotIn('cached_mezmur_sheet', body)
        self.assertNotIn('COUNT', body)
        # The screen never derives counts either.
        self.assertNotIn('cached_mezmur_sheet', self.home)

    def test_day_row_and_deep_link_preserved(self):
        self.assertIn("final date = '${d['attendance_date'] ?? ''}';",
                      self.home)
        self.assertIn("d['marked']", self.home)
        self.assertIn("d['attended']", self.home)
        self.assertIn('MezmurAttendanceScreen(initialDate: date)',
                      self.home)


class P1CFailureAndRefresh(unittest.TestCase):
    """Failure keeps cache; honest empty/offline; triggers and the
    single-flight guard."""

    def setUp(self):
        self.home = read(HOME)
        self.load = method_body(self.home, 'Future<void> _load()')

    def test_failed_refresh_keeps_cached_rows(self):
        # Only a genuinely empty history may show the error card.
        self.assertIn('if (_error != null && _days.isEmpty)', self.home)
        self.assertIn('showing recent days', self.home)
        self.assertIn('· updated ', self.home)
        self.assertIn("res.isNetworkError", self.load)
        self.assertIn("'You appear to be offline.'", self.load)

    def test_offline_empty_is_honest(self):
        self.assertIn('no attendance days are cached yet', self.load)
        self.assertIn('No attendance days yet', self.home)

    def test_connectivity_and_resume_refresh(self):
        self.assertIn('statusStream', self.home)
        self.assertIn('AppLifecycleState.resumed', self.home)
        # Pull-to-refresh preserved and non-destructive.
        self.assertIn('onRefresh: _load', self.home)

    def test_single_flight_guard(self):
        self.assertIn('if (_refreshing) return;', self.load)
        # The guard resets in a finally block — success, failure,
        # and thrown exceptions can never wedge refresh off.
        self.assertIn('} finally {', self.load)
        tail = self.load[self.load.find('} finally {'):]
        self.assertIn('_refreshing = false;', tail)

    def test_no_setstate_before_first_await(self):
        # initState calls _load — a synchronous setState there throws
        # (P1-B lesson). The first setState must follow the local read.
        first_setstate = self.load.find('setState(')
        first_await = self.load.find('await _db.getCachedMezmurDays()')
        self.assertLess(first_await, first_setstate)


class P1CDoNotTouch(unittest.TestCase):
    """The Mezmur write architecture, F8 reason guard, server contracts,
    and timeout remain intact while v34 also spares terminal states."""

    def setUp(self):
        self.ldb = read(LDB)
        self.sync = read(SYNC)
        self.policy = read(POLICY)

    def test_f8_drop_paths_unchanged(self):
        # Attendance now refuses deletion entirely; other three F8 filters stay.
        self.assertEqual(self.ldb.count('sync_error IS NULL'), 3)
        attendance = method_body(self.ldb, 'Future<void> dropPendingAttendance(')
        self.assertIn('throw StateError(', attendance)
        self.assertNotIn('.delete(', attendance)
        self.assertNotIn('await database', attendance)
        for name in ('dropPendingGrades', 'dropPendingMezmur', 'dropPendingHr'):
            body = method_body(self.ldb, f'Future<void> {name}(')
            self.assertIn('sync_error IS NULL', body, name)
            self.assertIn("sync_state IN ('pending', 'retry_wait')", body, name)
            self.assertIn('owner_user_id = ?', body, name)
            self.assertIn('created_authorization_version = ?', body, name)
        # F8 terminal/paused rows and every other owner/scope stay protected.

    def test_f8_classification_unchanged(self):
        for token in ('ALREADY_SUBMITTED', 'WORKFLOW_REJECTED',
                      'IDEMPOTENCY_CONFLICT', 'IDEMPOTENCY_IN_PROGRESS'):
            self.assertIn(token, self.policy)
        self.assertIn('classifyOutboxResponse(', self.sync)
        claim = self.ldb[self.ldb.find('claimNextLegacyOperation'):
                         self.ldb.find('claimNextLegacyOperation') + 2600]
        self.assertIn("sync_state IN ('pending', 'retry_wait')", claim)

    def test_pending_mezmur_untouched_by_read_cache(self):
        # The read cache never interacts with the outbox.
        for token in ('cached_mezmur_days', 'cacheMezmurDays',
                      'getCachedMezmurDays'):
            self.assertNotIn(token, self.sync)
        body = method_body(self.ldb, 'Future<void> cacheMezmurDays(')
        self.assertNotIn('pending_mezmur', body)

    def test_mezmur_attendance_screen_unchanged(self):
        attend = read(ATTEND)
        # Its local-first sheet order is intact (cache → pending →
        # network) and the outbox write entry point is untouched.
        sheet = method_body(attend, 'Future<void> _loadSheet()')
        self.assertLess(sheet.find('getCachedMezmurSheet'),
                        sheet.find('getPendingMezmurRecords'))
        self.assertLess(sheet.find('getPendingMezmurRecords'),
                        sheet.find('getMezmurSheet'))
        self.assertIn('await _db.saveMezmurLocal(', attend)
        # No P1-C cache references leaked into the taker screen.
        self.assertNotIn('cached_mezmur_days', attend)

    def test_server_contract_unchanged(self):
        php = read(MEZMUR_PHP)
        svc = read(MEZMUR_SVC)
        self.assertIn("($method === 'GET' && $action === 'days')", php)
        self.assertIn('ORDER BY d.attendance_date DESC', svc)
        self.assertIn('LIMIT ? OFFSET ?', svc)
        # The API client still speaks the same page-based contract.
        api = read(APISVC)
        self.assertIn("params = <String, String>{'page': '$page'}", api)
        self.assertIn("return get('/mezmur/days', params: params);", api)

    def test_timeout_unchanged(self):
        config = read(CONFIG)
        self.assertIn('connectionTimeout = 45', config)
        self.assertIn('postTimeout = 60', config)

    def test_existing_home_pins_still_hold(self):
        home = read(HOME)
        self.assertIn('getEthiopianGreeting()', home)
        self.assertIn('getTodayEthiopian()', home)
        self.assertIn('FeatureTile(', home)
        self.assertIn('NotificationBellButton', home)


if __name__ == '__main__':
    unittest.main()
