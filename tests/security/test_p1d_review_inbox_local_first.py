"""P1-D Review Inbox local-first conversion — static pins.

No sqflite runtime harness exists in this sandbox (documented
limitation since F8), so these pins assert the SOURCE: DB migration,
dept-scoped identity, local filtering, read order, merge/no-recompute
rules, online-only review actions, refresh triggers, and every
do-not-touch invariant (F8, SyncService, completed phases, server,
timeout). Behavioral verification is the device drill's responsibility.
"""

import glob
import os
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
APP = os.path.join(ROOT, 'Mobile', 'wbws_flutter_app')
LDB = os.path.join(APP, 'lib', 'services', 'local_db.dart')
REVIEWS = os.path.join(APP, 'lib', 'screens', 'reviews',
                       'review_inbox_screen.dart')
SYNC = os.path.join(APP, 'lib', 'services', 'sync_service.dart')
POLICY = os.path.join(APP, 'lib', 'services', 'outbox_policy.dart')
MEZMUR_ATTEND = os.path.join(APP, 'lib', 'screens', 'mezmur',
                             'mezmur_attendance.dart')
MEZMUR_HOME = os.path.join(APP, 'lib', 'screens', 'mezmur', 'mezmur_home.dart')
MEMBERS = os.path.join(APP, 'lib', 'screens', 'members',
                       'member_list_screen.dart')
NOTIF = os.path.join(APP, 'lib', 'screens', 'notifications',
                     'notification_center_screen.dart')
CONFIG = os.path.join(APP, 'lib', 'utils', 'config.dart')
APISVC = os.path.join(APP, 'lib', 'services', 'api_service.dart')
GRADES_PHP = os.path.join(ROOT, 'api', 'v1', 'routes', 'grades.php')
MEZMUR_PHP = os.path.join(ROOT, 'api', 'v1', 'routes', 'mezmur.php')
HR_PHP = os.path.join(ROOT, 'api', 'v1', 'routes', 'hr.php')
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


def screen_class(src, marker, end_marker):
    i = src.find(marker)
    assert i >= 0, marker
    j = src.find(end_marker, i)
    assert j > i, marker
    return src[i:j]


class P1DDatabase(unittest.TestCase):
    """DB v28 → v29, three dept-scoped read-model tables, wired into
    fresh installs, upgrades, and the logout wipe."""

    def setUp(self):
        self.ldb = read(LDB)

    def test_version_tracks_current(self):
        # P1-D's bump was to v29. The current version is centralized
        # in the v34 schema contract. The review tables themselves
        # are untouched by later steps.
        self.assertIn('version: localDatabaseSchemaVersion,', self.ldb)
        self.assertNotIn('version: 28,', self.ldb)

    def test_migration_branch_exists(self):
        self.assertIn('if (oldVersion < 29)', self.ldb)
        self.assertIn('_createReviewTables(db)', self.ldb)

    def test_tables_and_identity(self):
        self.assertIn('CREATE TABLE IF NOT EXISTS cached_review_packets',
                      self.ldb)
        self.assertIn('CREATE TABLE IF NOT EXISTS '
                      'cached_review_packet_details', self.ldb)
        self.assertIn('CREATE TABLE IF NOT EXISTS cached_review_stats',
                      self.ldb)
        # Department + review id = the logical identity (composite PK);
        # a bare id key could collide across departments.
        self.assertIn('dept TEXT NOT NULL,', self.ldb)
        self.assertIn('PRIMARY KEY (dept, id)', self.ldb)
        self.assertIn('dept TEXT NOT NULL PRIMARY KEY', self.ldb)
        # Status is a discrete, server-verbatim, queryable column.
        self.assertIn("status TEXT NOT NULL DEFAULT ''", self.ldb)
        # Complete payload + fetch stamp.
        self.assertIn('data_json TEXT', self.ldb)
        self.assertIn('fetched_at TEXT', self.ldb)
        # onCreate path creates them too (fresh installs).
        body = method_body(self.ldb, 'Future<void> _createTables(')
        self.assertIn('await _createReviewTables(db);', body)

    def test_wipe_behavior(self):
        body = method_body(self.ldb, 'Future<void> clearAllUserData(')
        for t in ('cached_review_packets', 'cached_review_packet_details',
                  'cached_review_stats'):
            self.assertIn(f"'{t}'", body)

    def test_migration_is_additive(self):
        for t in ('cached_review_packets', 'cached_review_packet_details',
                  'cached_review_stats'):
            self.assertNotIn(f'ALTER TABLE {t}', self.ldb)
        # Earlier tables untouched by the v29 step.
        for t in ('pending_mezmur', 'cached_mezmur_days',
                  'cached_notifications', 'cached_announcements'):
            self.assertIn(t, self.ldb)
        self.assertEqual(glob.glob(os.path.join(SQLDIR, '*p1d*')), [])


class P1DListFlow(unittest.TestCase):
    """Local read precedes the network; filter switches never
    refetch; merge-upsert; failure keeps rows; honest offline."""

    def setUp(self):
        self.src = read(REVIEWS)
        # The list state class spans to the detail screen class.
        self.cls = screen_class(
            self.src, 'class _ReviewInboxScreenState',
            '/// Full-context detail')
        self.load = method_body(self.cls, 'Future<void> _load()')

    def test_local_read_precedes_server(self):
        self.assertLess(self.load.find('getCachedReviewPackets('),
                        self.load.find('getReviewSubmissions('),
                        'cached packets must render before the API read')

    def test_offline_skips_network_entirely(self):
        offline_return = self.load.find("if (offline) {")
        self.assertLess(self.load.find('getCachedReviewPackets('),
                        offline_return)
        self.assertLess(offline_return,
                        self.load.find('getReviewSubmissions('))
        self.assertIn("!ConnectivityService().hasLink", self.load)
        self.assertIn(
            "setState(() => _error = 'You appear to be offline.');",
            self.load)

    def test_success_persists_then_rereads(self):
        cache = self.load.find('cacheReviewPackets(dept, rows)')
        reread = self.load.find('getCachedReviewPackets(dept,', cache + 1)
        self.assertGreater(cache, -1)
        self.assertGreater(reread, cache)

    def test_stats_cached_verbatim(self):
        self.assertIn('cacheReviewStats(dept,', self.load)
        self.assertIn("res.data!['stats']", self.load)

    def test_merge_is_upsert_not_destructive(self):
        ldb = read(LDB)
        body = method_body(ldb, 'Future<void> cacheReviewPackets(')
        self.assertIn('ConflictAlgorithm.replace', body)
        self.assertNotIn('db.delete', body)
        self.assertNotIn('batch.delete', body)
        self.assertIn('if (rows.isNotEmpty)', self.load)

    def test_filter_switch_is_local_only(self):
        apply = method_body(self.cls, 'Future<void> _applyFilter()')
        self.assertIn('getCachedReviewPackets(widget.dept', apply)
        self.assertNotIn('getReviewSubmissions', apply,
                         'a filter switch must not hit the network when '
                         'the window is cached')
        # A never-fetched window still gets ONE honest first load.
        self.assertIn('_fetchedFilters.contains(_filter)', apply)
        self.assertIn('await _load();', apply)
        # The chip routes through the local path.
        self.assertIn('_applyFilter();', self.cls)

    def test_status_windows_match_server_semantics(self):
        # 'attention' is department-specific by server contract:
        # edu = incomplete/submitted/draft; mezmur+HR add
        # revision_needed.
        self.assertIn("widget.dept == 'edu'", self.cls)
        self.assertIn("'incomplete', 'submitted', 'draft'", self.cls)
        self.assertIn("'incomplete', 'draft', 'submitted', "
                      "'revision_needed'", self.cls)

    def test_local_query_reproduces_server_window(self):
        ldb = read(LDB)
        body = method_body(
            ldb, 'Future<List<Map<String, dynamic>>> '
                 'getCachedReviewPackets(String dept')
        self.assertIn('status IN (', body)
        self.assertIn("orderBy: 'updated_at DESC, id DESC'", body)
        self.assertNotIn('OFFSET', body)

    def test_failed_refresh_keeps_cached_rows(self):
        self.assertIn("res.isNetworkError", self.load)
        self.assertIn("'You appear to be offline.'", self.load)
        self.assertIn('(_error != null && _items.isEmpty)', self.cls)
        self.assertIn('showing recent reviews', self.cls)
        self.assertIn('· updated ', self.cls)
        self.assertIn("Could not load the review queue.", self.load)

    def test_offline_empty_is_honest(self):
        self.assertIn('no reviews are cached yet', self.load)
        self.assertIn('Nothing here for this filter.', self.cls)

    def test_refresh_triggers_and_single_flight(self):
        self.assertIn('statusStream', self.cls)
        self.assertIn('AppLifecycleState.resumed', self.cls)
        self.assertIn('onRefresh: _load', self.cls)
        self.assertIn('if (_refreshing) return;', self.load)
        self.assertIn('} finally {', self.load)
        tail = self.load[self.load.find('} finally {'):]
        self.assertIn('_refreshing = false;', tail)
        # No setState before the first await (initState calls _load).
        self.assertLess(self.load.find('await _db.getCachedReviewPackets'),
                        self.load.find('setState('))

    def test_navigation_and_actions_preserved(self):
        self.assertIn('ReviewDetailScreen(', self.cls)
        self.assertIn('if (changed == true) _load();', self.cls)


class P1DDetailFlow(unittest.TestCase):
    """Detail renders from cache first; refresh persists and rereads;
    offline + uncached is honest; failure preserves the cached view."""

    def setUp(self):
        self.src = read(REVIEWS)
        self.cls = screen_class(
            self.src, 'class _ReviewDetailScreenState',
            '/// Admin landing for reviews')
        self.load = method_body(self.cls, 'Future<void> _load()')

    def test_cached_detail_renders_before_network(self):
        self.assertLess(self.load.find('getCachedReviewPacketDetail('),
                        self.load.find('getReviewSubmission('))
        self.assertIn('cacheReviewPacketDetail(widget.dept, widget.id',
                      self.load)
        reread = self.load.find(
            'getCachedReviewPacketDetail(widget.dept, widget.id',
            self.load.find('cacheReviewPacketDetail') + 1)
        self.assertGreater(reread, -1, 'must reread the store after persist')

    def test_offline_uncached_is_honest(self):
        self.assertIn('this review is not cached yet', self.load)
        self.assertIn(
            "setState(() => _error = 'You appear to be offline.');",
            self.load)
        # Error view only when there is nothing to show.
        self.assertIn('(_error != null && _sub.isEmpty)', self.cls)
        self.assertIn('showing the cached review', self.cls)

    def test_review_actions_unchanged(self):
        decide = method_body(self.cls, 'Future<void> _decide(')
        self.assertIn('await _api.reviewSubmission(', decide)
        # Success → pop (the list then refreshes + reconciles);
        # failure → honest snackbar; nothing pretends success.
        self.assertIn('res.success', decide)
        self.assertIn("'Review failed.'", self.cls)
        # No offline mutation of the cached packet's status: only the
        # _load refresh flows write the review cache.
        self.assertNotIn('cacheReviewPacketDetail', decide)
        self.assertNotIn('cacheReviewPackets', decide)
        self.assertNotIn('markCached', decide)


class P1DOnlineOnlyActions(unittest.TestCase):
    """Review actions remain ONLINE ONLY — no outbox, no queue, no
    optimistic approval/rejection."""

    def setUp(self):
        self.src = read(REVIEWS)

    def test_no_action_outbox_introduced(self):
        for token in ('pending_review', 'pendingReviews', 'review_outbox',
                      'enqueueReview', 'outbox'):
            self.assertNotIn(token, self.src)
        ldb = read(LDB)
        for token in ('pending_review', 'pending_reviews',
                      'review_outbox'):
            self.assertNotIn(token, ldb)
        sync = read(SYNC) + read(POLICY)
        for token in ('cached_review', 'cacheReviewPackets',
                      'reviewSubmission'):
            self.assertNotIn(token, sync)

    def test_actions_go_through_the_api_directly(self):
        self.assertIn("post('${_reviewBase(dept)}/submission-review'",
                      read(APISVC))

    def test_no_optimistic_status_mutation(self):
        # The cache is a read model: nothing in the screen writes a
        # changed status into it — only refresh responses do (via
        # cacheReviewPackets/cacheReviewPacketDetail in the _load
        # flows).
        self.assertNotIn("status'] = 'approved'", self.src)
        self.assertNotIn("status'] = 'rejected'", self.src)
        self.assertNotIn("status'] = 'revision_needed'", self.src)


class P1DDepartmentIsolation(unittest.TestCase):
    """Education / HR / Mezmur cache scopes cannot collide."""

    def test_identity_carries_dept(self):
        ldb = read(LDB)
        upsert = method_body(ldb, 'Future<void> cacheReviewPackets(')
        self.assertIn("'dept': dept,", upsert)
        detail = method_body(ldb, 'Future<void> cacheReviewPacketDetail(')
        self.assertIn("'dept': dept,", detail)
        query = method_body(
            ldb, 'Future<List<Map<String, dynamic>>> '
                 'getCachedReviewPackets(String dept')
        self.assertIn("where = <String>['dept = ?']", query)
        self.assertIn("whereArgs: args", query)
        single = method_body(
            ldb, 'Future<Map<String, dynamic>?> '
                 'getCachedReviewPacketDetail(')
        self.assertIn("where: 'dept = ? AND id = ?'", single)

    def test_existing_department_mapping_preserved(self):
        api = read(APISVC)
        self.assertIn("dept == 'edu' ? '/grades' : (dept == 'hr' ? "
                      "'/hr' : '/mezmur')", api)

    def test_stats_never_recomputed_locally(self):
        ldb = read(LDB)
        stats_read = method_body(
            ldb, 'Future<Map<String, dynamic>?> getCachedReviewStats(')
        self.assertNotIn('COUNT', stats_read)
        self.assertNotIn('SUM', stats_read)
        # The screen never derives stats either.
        src = read(REVIEWS)
        self.assertNotIn("'_pending'", src)
        self.assertIn("res.data!['stats']", src)


class P1DDoNotTouch(unittest.TestCase):
    """F8, SyncService, completed phases, server, and the timeout are
    byte-untouched."""

    def setUp(self):
        self.ldb = read(LDB)

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
        self.assertNotIn('Future<void> rejectMezmur(', self.ldb)
        self.assertIn('discardRejectedOperation', self.ldb)
        discard = method_body(
            self.ldb,
            'Future<SyncRecoveryActionResult> discardRejectedOperation(')
        self.assertIn('client_op_id = ?', discard)
        self.assertIn('owner_user_id = ?', discard)
        # The review cache has no relationship to the outbox.
        for token in ('pending_mezmur', 'pending_attendance',
                      'pending_grades', 'pending_hr'):
            self.assertNotIn(token, method_body(
                self.ldb, 'Future<void> cacheReviewPackets('))

    def test_completed_phases_untouched(self):
        # P1-A/P1-B/P1-C read flows still present and local-first.
        self.assertIn('getCachedNotifications', read(NOTIF))
        self.assertIn('getCachedMembers', read(MEMBERS))
        self.assertIn('getCachedMemberById', read(os.path.join(
            APP, 'lib', 'screens', 'members', 'member_detail_screen.dart')))
        home = read(MEZMUR_HOME)
        self.assertIn('getCachedMezmurDays', home)
        self.assertIn('take(5)', home)
        attend = read(MEZMUR_ATTEND)
        self.assertIn('getCachedMezmurSheet', attend)
        # P1-C's table and migration branch still exist.
        self.assertIn('cached_mezmur_days', self.ldb)
        self.assertIn('if (oldVersion < 28)', self.ldb)

    def test_server_contract_unchanged(self):
        for php, needle in (
                (GRADES_PHP, "action === 'submissions'"),
                (MEZMUR_PHP, "($method === 'GET' && $action === "
                             "'submissions')"),
                # HR_PHP dropped 2026-10-18: HR attendance fully retired (410).
                (GRADES_PHP, "action === 'submission-review'")):
            self.assertIn(needle, read(php))
        api = read(APISVC)
        self.assertIn("return get('${_reviewBase(dept)}/submissions'",
                      api)

    def test_timeout_unchanged(self):
        config = read(CONFIG)
        self.assertIn('connectionTimeout = 45', config)
        self.assertIn('postTimeout = 60', config)


if __name__ == '__main__':
    unittest.main()
