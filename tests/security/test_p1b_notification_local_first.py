"""P1-B Notification Center local-first conversion — static pins.

No sqflite runtime harness exists in this sandbox (documented
limitation since F8), so these pins assert the SOURCE: DB migration,
table wiring, read order, cursor semantics, merge/expiry/read-state
rules, and every "do not touch" invariant (F8, timeout, SyncService,
P1-A). Behavioral verification is the device drill's responsibility.
"""

import glob
import os
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
APP = os.path.join(ROOT, 'Mobile', 'wbws_flutter_app')
LDB = os.path.join(APP, 'lib', 'services', 'local_db.dart')
SCREEN = os.path.join(APP, 'lib', 'screens', 'notifications',
                      'notification_center_screen.dart')
SYNC = os.path.join(APP, 'lib', 'services', 'sync_service.dart')
CONFIG = os.path.join(APP, 'lib', 'utils', 'config.dart')
APISVC = os.path.join(APP, 'lib', 'services', 'api_service.dart')
MEMBERS_DETAIL = os.path.join(APP, 'lib', 'screens', 'members',
                              'member_detail_screen.dart')
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


class P1BDatabase(unittest.TestCase):
    """DB v26 → v27, two tables, wired into fresh installs, upgrades,
    and the logout/auth-expiry wipe."""

    def setUp(self):
        self.ldb = read(LDB)

    def test_version_tracks_current(self):
        # P1-B's bump to v27 (notification center) is history. The
        # current version is centralized in the v34 schema contract; the
        # notification tables themselves are untouched by later steps.
        self.assertIn('version: localDatabaseSchemaVersion,', self.ldb)
        self.assertNotIn('version: 28,', self.ldb)

    def test_migration_branch_exists(self):
        self.assertIn('if (oldVersion < 27)', self.ldb)
        self.assertIn('_createNotificationTables(db)', self.ldb)

    def test_two_tables_created(self):
        self.assertIn('CREATE TABLE IF NOT EXISTS cached_notifications', self.ldb)
        self.assertIn('CREATE TABLE IF NOT EXISTS cached_announcements', self.ldb)
        # onCreate path creates them too (fresh installs).
        body = method_body(self.ldb, 'Future<void> _createTables(')
        self.assertIn('await _createNotificationTables(db);', body)

    def test_tables_in_user_data_wipe(self):
        body = method_body(self.ldb, 'Future<void> clearAllUserData(')
        self.assertIn("'cached_notifications'", body)
        self.assertIn("'cached_announcements'", body)

    def test_no_unrelated_migrations(self):
        # sql/047_account_self_service.sql landed later via server-side
        # PR #3 (account self-service) — it is not a mobile migration.
        # P1-B's own no-sql-file intent is pinned by the *p1b* glob.
        self.assertEqual(glob.glob(os.path.join(SQLDIR, '*p1b*')), [])
        self.assertNotIn('ALTER TABLE cached_notifications', self.ldb)
        self.assertNotIn('ALTER TABLE cached_announcements', self.ldb)


class P1BLocalFirstReads(unittest.TestCase):
    """SQLite renders before the API; failures keep rows; merges
    dedupe; the server cursors are preserved, not offset."""

    def setUp(self):
        self.screen = read(SCREEN)

    def test_alerts_local_read_precedes_api(self):
        body = method_body(self.screen, 'Future<void> _loadAlerts(')
        self.assertGreater(body.find('getCachedNotifications'), -1)
        self.assertGreater(body.find('getNotificationFeed'), -1)
        self.assertLess(body.find('getCachedNotifications'),
                        body.find('getNotificationFeed'),
                        'cached alerts must render before the API read')

    def test_announcements_local_read_precedes_api(self):
        body = method_body(self.screen, 'Future<void> _loadAnnouncements(')
        self.assertGreater(body.find('getCachedAnnouncements'), -1)
        self.assertGreater(body.find('getAnnouncements'), -1)
        self.assertLess(body.find('getCachedAnnouncements'),
                        body.find('getAnnouncements'),
                        'cached announcements must render before the API read')

    def test_successful_pages_are_persisted(self):
        for meth, cache in (('_loadAlerts(', 'cacheNotificationRows'),
                            ('_loadAnnouncements(', 'cacheAnnouncementRows'),
                            ('_loadOlderAlerts(', 'cacheNotificationRows'),
                            ('_loadOlderAnnouncements(', 'cacheAnnouncementRows')):
            body = method_body(self.screen, f'Future<void> {meth}')
            self.assertIn(cache, body, meth)

    def test_before_id_semantics_preserved(self):
        ldb = read(LDB)
        local = method_body(
            ldb, 'Future<List<Map<String, dynamic>>> getCachedNotifications(')
        self.assertIn("'id < ?'", local)      # the server's cursor, on SQLite
        self.assertIn("orderBy: 'id DESC'", local)
        self.assertNotIn('OFFSET', local)
        self.assertNotIn('offset', local)

    def test_announcement_tuple_cursor_preserved(self):
        ldb = read(LDB)
        local = method_body(
            ldb, 'Future<List<Map<String, dynamic>>> getCachedAnnouncements(')
        self.assertIn('(is_pinned < ? OR (is_pinned = ? AND id < ?))', local)
        self.assertIn("orderBy: 'is_pinned DESC, id DESC'", local)
        self.assertNotIn('OFFSET', local)
        self.assertNotIn('offset', local)

    def test_no_offset_pagination_introduced(self):
        # The screen's local reads page by cursor only (the legacy
        # offset params in api_service are pre-existing server API
        # surface, untouched).
        for meth in ('_loadOlderAlerts(', '_loadOlderAnnouncements('):
            body = method_body(self.screen, f'Future<void> {meth}')
            self.assertNotIn('offset:', body, meth)

    def test_failed_refresh_keeps_cached_rows(self):
        # The C2 guard survives: only a successful load may replace.
        self.assertIn('if (localNow != null || _alerts.isEmpty)', self.screen)
        self.assertIn('if (localNow != null || _announcements.isEmpty)',
                      self.screen)
        self.assertIn('showing recent $what', self.screen)
        self.assertIn("_staleBanner(_error!, 'alerts'", self.screen)
        self.assertIn("_staleBanner(_annError!, 'announcements'", self.screen)
        self.assertIn('Could not load', self.screen)

    def test_duplicate_prevention(self):
        # Server-id merge-upsert + mergeOlderRows dedupe at the edge.
        ldb = read(LDB)
        for sig in ('Future<void> cacheNotificationRows(',
                    'Future<void> cacheAnnouncementRows('):
            body = method_body(ldb, sig)
            self.assertIn('ConflictAlgorithm.replace', body, sig)
            self.assertNotIn('db.delete', body, sig)
        for meth in ('_loadOlderAlerts(', '_loadOlderAnnouncements('):
            body = method_body(self.screen, f'Future<void> {meth}')
            self.assertIn('mergeOlderRows', body, meth)


class P1BReadStateAndExpiry(unittest.TestCase):
    """Optimistic read persistence, honest mark failures, and the
    server-authoritative announcement expiry."""

    def setUp(self):
        self.screen = read(SCREEN)
        self.ldb = read(LDB)

    def test_read_state_persists_locally(self):
        alerts = method_body(self.screen, 'Future<void> _markRead(')
        self.assertIn('markCachedNotificationRead(id)', alerts)
        ann = method_body(self.screen, 'Future<void> _markAnnouncementRead(')
        self.assertIn('markCachedAnnouncementRead(id)', ann)
        for sig in ('Future<void> markCachedNotificationRead(',
                    'Future<void> markCachedAnnouncementRead('):
            body = method_body(self.ldb, sig)
            self.assertIn("'is_unread': 0", body, sig)

    def test_mark_read_failure_is_honest(self):
        # The success/failure branch + snackbar survive; no false
        # server-success claim.
        alerts = method_body(self.screen, 'Future<void> _markRead(')
        self.assertIn('if (res.success)', alerts)
        self.assertIn("Could not mark as read.", self.screen)

    def test_mark_all_has_failure_feedback(self):
        body = method_body(self.screen, 'Future<void> _markAll(')
        self.assertIn('if (!res.success)', body)
        self.assertIn('Could not mark all as read.', body)
        # No outbox / retry queue for read markers.
        self.assertNotIn('enqueue', body)

    def test_announcement_expiry_respected_offline(self):
        local = method_body(
            self.ldb,
            'Future<List<Map<String, dynamic>>> getCachedAnnouncements(')
        self.assertIn('(expires_at IS NULL OR expires_at >', local)
        # The stored stamp is written verbatim from the server row.
        upsert = method_body(self.ldb, 'Future<void> cacheAnnouncementRows(')
        self.assertIn("'expires_at': m['expires_at']", upsert)
        # No invented local TTL in the new store methods.
        p1b = (method_body(self.ldb, 'Future<List<Map<String, dynamic>>> '
                           'getCachedAnnouncements(')
               + method_body(self.ldb, 'Future<void> cacheAnnouncementRows('))
        self.assertNotIn('ttl', p1b.lower())

    def test_no_read_outbox(self):
        # No queue tables/ops for read markers.
        self.assertNotIn('pending_notification', self.ldb)
        self.assertNotIn('pending_reads', self.ldb)


class P1BTriggersAndContracts(unittest.TestCase):
    """Radio/resume refresh, the member deep link, and every
    do-not-touch invariant."""

    def setUp(self):
        self.screen = read(SCREEN)

    def test_connectivity_and_resume_refresh(self):
        self.assertIn('statusStream', self.screen)
        self.assertIn('AppLifecycleState.resumed', self.screen)
        # Rows stay visible — a refresh never clears before a response.
        self.assertIn('_refreshingAll', self.screen)

    def test_deep_link_to_member_detail_intact(self):
        self.assertIn('MemberDetailScreen(memberId: memberId)', self.screen)
        self.assertIn("memberTargetId", self.screen)
        # The P1-A detail target still reads local-first.
        detail = read(MEMBERS_DETAIL)
        self.assertIn('getCachedMemberById', detail)

    def test_empty_cache_offline_is_honest(self):
        # 'all caught up' only ever follows a genuinely empty result;
        # offline+empty keeps the error state.
        self.assertIn('You are all caught up', self.screen)
        self.assertIn('Could not load', self.screen)
        self.assertIn('You appear to be offline.', self.screen)

    def test_no_syncservice_notification_worker(self):
        sync = read(SYNC)
        for token in ('cached_notifications', 'cached_announcements',
                      'cacheNotificationRows', 'cacheAnnouncementRows',
                      'getCachedNotifications', 'getCachedAnnouncements'):
            self.assertNotIn(token, sync)

    def test_f8_behavior_unchanged(self):
        ldb = read(LDB)
        # Attendance now refuses deletion entirely; other three F8 filters stay.
        self.assertEqual(ldb.count('sync_error IS NULL'), 3)
        attendance = method_body(ldb, 'Future<void> dropPendingAttendance(')
        self.assertIn('throw StateError(', attendance)
        self.assertNotIn('.delete(', attendance)
        self.assertNotIn('await database', attendance)
        for name in ('dropPendingGrades', 'dropPendingMezmur', 'dropPendingHr'):
            body = method_body(ldb, f'Future<void> {name}(')
            self.assertIn('sync_error IS NULL', body, name)

    def test_timeout_unchanged(self):
        config = read(CONFIG)
        self.assertIn('connectionTimeout = 45', config)
        self.assertIn('postTimeout = 60', config)

    def test_server_api_client_untouched(self):
        # The pre-existing endpoint surface (incl. legacy offset
        # params) is byte-unchanged — the local store needed no
        # server contract change.
        api = read(APISVC)
        self.assertIn("get('/notifications/feed'", api)
        self.assertIn("get('/notifications/announcements'", api)


if __name__ == '__main__':
    unittest.main()
