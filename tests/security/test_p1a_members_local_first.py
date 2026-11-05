"""P1-A Members local-first conversion — static pins.

The sandbox has no sqflite runtime harness (documented limitation since
F8), so these pins assert the SOURCE: the read order, the failure
semantics, the DB contract, and the "do not touch" invariants
(timeout, F8, merge-only cache, no new sync worker). Behavioral
verification on a real device is the device drill's responsibility.
"""

import glob
import os
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
APP = os.path.join(ROOT, 'Mobile', 'wbws_flutter_app')
LDB = os.path.join(APP, 'lib', 'services', 'local_db.dart')
LIST = os.path.join(APP, 'lib', 'screens', 'members', 'member_list_screen.dart')
DETAIL = os.path.join(APP, 'lib', 'screens', 'members', 'member_detail_screen.dart')
SYNC = os.path.join(APP, 'lib', 'services', 'sync_service.dart')
CONFIG = os.path.join(APP, 'lib', 'utils', 'config.dart')
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


class P1ALocalFirst(unittest.TestCase):
    """UI → LocalDb (render) happens before any API call; the network
    is never the prerequisite for the first useful render."""

    def setUp(self):
        self.list_src = read(LIST)
        self.detail_src = read(DETAIL)
        self.ldb = read(LDB)

    def test_list_reads_local_before_api(self):
        body = method_body(self.list_src, 'Future<void> _loadMembers(')
        self.assertGreater(body.find('_readLocalWindow('), -1)
        self.assertGreater(body.find('_api.getMembers('), -1)
        self.assertLess(body.find('_readLocalWindow('),
                        body.find('_api.getMembers('),
                        'local SQLite read must precede the API read')

    def test_detail_reads_local_before_api(self):
        body = method_body(self.detail_src, 'Future<void> _loadMember(')
        self.assertGreater(body.find('getCachedMemberById'), -1)
        self.assertGreater(body.find('_api.getMember('), -1)
        self.assertLess(body.find('getCachedMemberById'),
                        body.find('_api.getMember('),
                        'cached row must render before the API read')

    def test_detail_upserts_fresh_response(self):
        body = method_body(self.detail_src, 'Future<void> _loadMember(')
        self.assertIn('cacheMembers([res.data])', body,
                      'successful GET /members/{id} must enrich the cached row')

    def test_refresh_failure_keeps_cached_list(self):
        # The old destroy-then-wait flow is gone for good.
        self.assertNotIn('_members.clear()', self.list_src)
        # Failure arm: keep data, flag the failure, never a fake empty.
        self.assertIn('_refreshFailed = true', self.list_src)
        self.assertIn('showing members saved on this phone', self.list_src)
        self.assertIn("Couldn't update right now", self.list_src)
        # Empty cache + offline stays an honest empty state.
        self.assertIn(
            'Waiting for network and no members saved on this phone',
            self.list_src)

    def test_refresh_in_flight_indicator(self):
        self.assertIn('LinearProgressIndicator', self.list_src)
        self.assertIn('_refreshing', self.list_src)


class P1ADbContract(unittest.TestCase):
    """cached_members stays DB v26 — no migration, no replacement; the
    new reads are PK-keyed and offset-paginated."""

    def setUp(self):
        self.ldb = read(LDB)

    def test_db_version_tracks_current(self):
        # v31 = P1-F's authorized education subjects-catalog table
        # (v30 P1-E education classes, v29 P1-D review inbox, v28 P1-C
        # mezmur days, v27 P1-B notifications). P1-A itself
        # introduced no version bump or migration (its commit history
        # is unchanged). The current version is centralized in the
        # v34 schema contract and pinned by its migration harness.
        self.assertIn('version: localDatabaseSchemaVersion,', self.ldb)

    def test_no_migration_introduced(self):
        self.assertNotIn('ALTER TABLE cached_members', self.ldb)
        # sql/047_account_self_service.sql landed later via server-side
        # PR #3 — not a mobile migration; P1-A adds no sql file itself.
        pass
        self.assertEqual(glob.glob(os.path.join(SQLDIR, '*p1*')), [])

    def test_get_cached_member_by_id_pk_lookup(self):
        body = method_body(
            self.ldb, 'Future<Map<String, dynamic>?> getCachedMemberById(')
        self.assertIn("where: 'id = ?'", body)
        self.assertIn('limit: 1', body)
        # Must not route through the list query (no 50-row dependency).
        self.assertNotIn('getCachedMembers(', body)
        # Wired into the detail screen.
        self.assertIn('getCachedMemberById', read(DETAIL))

    def test_local_pagination_uses_offset(self):
        sig_i = self.ldb.find('getCachedMembers({')
        self.assertGreater(sig_i, -1)
        sig = self.ldb[sig_i:self.ldb.find(') async {', sig_i)]
        self.assertIn('int offset = 0', sig)
        body = method_body(
            self.ldb, 'Future<List<Map<String, dynamic>>> getCachedMembers(')
        self.assertIn('offset: offset', body)
        # The list screen pages locally by offset.
        more = method_body(read(LIST), 'Future<void> _loadMore(')
        self.assertIn('offset: _localPagesLoaded * _pageSize', more)


class P1AOfflineAndFreshness(unittest.TestCase):
    """Offline detail access, honest cached indication, no polling."""

    def test_offline_detail_tap_not_blocked(self):
        list_src = read(LIST)
        self.assertNotIn('_isOffline ? null', list_src,
                         'the offline detail-tap gate must be gone')
        self.assertIn('MemberDetailScreen(memberId: member', list_src)

    def test_cached_banner_and_freshness(self):
        detail_src = read(DETAIL)
        self.assertIn('Showing cached data', detail_src)
        self.assertIn('local_updated_at', detail_src)
        list_src = read(LIST)
        self.assertIn('· updated', list_src)
        self.assertIn('getCachedMembersLastSynced', read(LDB))

    def test_no_polling_timers_added(self):
        self.assertNotIn('Timer.periodic', read(LIST))
        self.assertNotIn('Timer.periodic', read(DETAIL))

    def test_refresh_triggers_preserved(self):
        list_src = read(LIST)
        self.assertIn('onRefresh:', list_src)               # pull-to-refresh
        self.assertIn('Icons.refresh', list_src)            # refresh button
        self.assertIn('statusStream', list_src)             # radio return
        self.assertIn('AppLifecycleState.resumed', list_src)  # app return


class P1ADoNotTouch(unittest.TestCase):
    """The authorization's invariants: merge-only cache writes, the
    45 s timeout, F8 behavior, and no Members sync worker."""

    def setUp(self):
        self.ldb = read(LDB)

    def test_cache_members_still_merge_upsert(self):
        body = method_body(self.ldb, 'Future<void> cacheMembers(')
        self.assertIn('ConflictAlgorithm.replace', body)
        self.assertIn("Don't clear — merge", body)
        self.assertIn('batch.insert', body)
        self.assertNotIn('db.delete', body)
        self.assertNotIn('batch.delete', body)

    def test_timeouts_unchanged(self):
        config = read(CONFIG)
        self.assertIn('connectionTimeout = 45', config)
        self.assertIn('postTimeout = 60', config)

    def test_f8_guards_untouched(self):
        # Preserve other-domain F8 guards and enforce attendance no-delete policy.
        # Attendance now refuses deletion entirely; other three F8 filters stay.
        self.assertEqual(self.ldb.count('sync_error IS NULL'), 3)
        attendance = method_body(self.ldb, 'Future<void> dropPendingAttendance(')
        self.assertIn('throw StateError(', attendance)
        self.assertNotIn('.delete(', attendance)
        self.assertNotIn('await database', attendance)
        for name in ('dropPendingGrades', 'dropPendingMezmur', 'dropPendingHr'):
            body = method_body(self.ldb, f'Future<void> {name}(')
            self.assertIn('sync_error IS NULL', body, name)
        body = method_body(
            self.ldb,
            'Future<SyncRecoveryActionResult> discardRejectedOperation(')
        self.assertNotIn('sync_error IS NULL', body)
        self.assertIn('client_op_id = ?', body)
        self.assertIn('owner_user_id = ?', body)
        self.assertIn('created_authorization_version = ?', body)

    def test_no_members_sync_worker(self):
        sync = read(SYNC)
        self.assertNotIn('getCachedMembers', sync)
        self.assertNotIn('cacheMembers', sync)
        self.assertNotIn('dropPending', sync)

    def test_no_repository_abstraction_introduced(self):
        for src in (read(LIST), read(DETAIL)):
            self.assertNotIn('MemberRepository', src)


class P1ADartSyntaxGuards(unittest.TestCase):
    """The sandbox has no Dart compiler, so these pins guard the
    constructor syntax class that broke the user's 16712dd build
    (`required: this.memberId` — a colon makes the parser treat
    `required` as a parameter name and `this.memberId` as a default
    value, which is invalid). Found by the user's local
    `flutter run --release`; the fix must never regress."""

    def test_detail_constructor_syntax(self):
        detail_src = read(DETAIL)
        self.assertIn(
            'const MemberDetailScreen({super.key, required this.memberId});',
            detail_src)
        self.assertNotIn('required: this', detail_src)

    def test_no_colon_required_this_anywhere(self):
        # `required: this` is never valid Dart in this codebase.
        for root, _, files in os.walk(APP):
            for f in files:
                if not f.endswith('.dart'):
                    continue
                p = os.path.join(root, f)
                src = read(p)
                self.assertNotIn(
                    'required: this', src,
                    f'{p}: `required:` before `this.` is invalid Dart '
                    '(parameter lists take `required this.x`)')

    def test_list_screen_constructor_intact(self):
        self.assertIn('const MemberListScreen({super.key});', read(LIST))


if __name__ == '__main__':
    unittest.main()
