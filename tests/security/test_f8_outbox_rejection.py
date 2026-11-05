"""F8 — critical data-loss fix: a 409 must NEVER mark an offline op
synced unless the response definitively establishes it was applied or
validly replayed.

Root cause being pinned shut: SyncService._accepted() treated every
409 as sync success, so a teacher's offline attendance/grades were
silently discarded + falsely reported synced when Education/admin
submitted that day/test first.

These are static source pins (the sandbox has no Flutter/PHP runtimes;
the Dart-behavior pins live in test/drain_outcome_test.dart, which the
user runs locally with `flutter test`).
"""
import re
import unittest

ROOT = __file__.rsplit('/tests/', 1)[0]
MW = f'{ROOT}/api/v1/core/middleware.php'
RESP = f'{ROOT}/api/v1/core/response.php'
ATT = f'{ROOT}/api/v1/routes/attendance.php'
GRA = f'{ROOT}/api/v1/routes/grades.php'
MEZ = f'{ROOT}/api/v1/routes/mezmur.php'
HR = f'{ROOT}/api/v1/routes/hr.php'
SYNC = (f'{ROOT}/Mobile/wbws_flutter_app/lib/services/sync_service.dart')
POLICY = (f'{ROOT}/Mobile/wbws_flutter_app/lib/services/outbox_policy.dart')
LDB = (f'{ROOT}/Mobile/wbws_flutter_app/lib/services/local_db.dart')
BANNER = (f'{ROOT}/Mobile/wbws_flutter_app/lib/widgets/sync_attention.dart')
CENTER = (f'{ROOT}/Mobile/wbws_flutter_app/lib/screens/profile/'
          'sync_center_screen.dart')
SCREENS = {
    'attendance': (f'{ROOT}/Mobile/wbws_flutter_app/lib/screens/attendance/'
                   'attendance_screen.dart'),
    'grades': (f'{ROOT}/Mobile/wbws_flutter_app/lib/screens/teacher/'
               'teacher_grades.dart'),
    'mezmur': (f'{ROOT}/Mobile/wbws_flutter_app/lib/screens/mezmur/'
               'mezmur_attendance.dart'),
    # HR attendance was retired (2026-10-07); its screen is deleted.
}


def read(path):
    with open(path, encoding='utf-8') as fh:
        return fh.read()


class F8ServerCodes(unittest.TestCase):
    """Every outbox-reachable 409 carries a machine-readable code via
    err()'s $extra (the existing convention), so the client never has
    to sniff message text."""

    def setUp(self):
        self.mw = read(MW)
        self.att = read(ATT)
        self.gra = read(GRA)
        self.mez = read(MEZ)
        self.hr = read(HR)
        self.resp = read(RESP)

    def test_err_extra_merge_is_the_transport(self):
        # response.php merges $extra into the JSON body — the `code`
        # field rides the existing convention, no new mechanism.
        self.assertIn('array_merge($response, $extra)', self.resp)

    def test_middleware_conflict_and_processing_are_distinguishable(self):
        self.assertIn("'code' => 'IDEMPOTENCY_CONFLICT'", self.mw)
        self.assertIn("'code' => 'IDEMPOTENCY_IN_PROGRESS'", self.mw)

    def test_middleware_replay_returns_original_status(self):
        # A replay is the ORIGINAL (usually 200) status + body +
        # Idempotency-Replayed header — a 409 is never a replay, and
        # response-loss retries stay on the success path (F8 §11).
        self.assertIn("http_response_code((int)($result['status_code'] ?? 200))",
                      self.mw)
        self.assertIn("'Idempotency-Replayed: true'", self.mw)

    def test_attendance_locks_and_domain_rejections(self):
        self.assertEqual(
            self.att.count("['code' => 'ALREADY_SUBMITTED']"), 2,
            'save + submit locks must be machine-rejectable')
        self.assertEqual(
            self.att.count("['code' => 'WORKFLOW_REJECTED']"), 2,
            'DomainException catches (save + submit) must be coded')

    def test_grades_locks_and_marklist_failure(self):
        # 1.6.5: the refusal reason is resolved by teacherWriteRefusal() —
        # a closed semester rejects with TERM_CLOSED, a submitted marklist
        # with ALREADY_SUBMITTED. Both save and submit keep machine codes.
        self.assertEqual(
            self.gra.count("? 'ALREADY_SUBMITTED' : 'TERM_CLOSED'"), 2,
            'save-lock + submit-lock must be machine-rejectable')
        self.assertEqual(
            self.gra.count("teacherWriteRefusal($conn, $auth, $assessmentId)"), 2,
            'both write paths must use the shared reason gate')
        self.assertEqual(
            self.gra.count("['code' => 'WORKFLOW_REJECTED']"), 1,
            'upsertMarklist workflow failure must be coded')
        # grades 885 is a review action (online-only, never drained):
        # it must NOT have been dragged into the outbox scheme.
        self.assertNotIn("['code' => 'ALREADY_SUBMITTED', 'review']", self.gra)

    def test_mezmur_lock_and_domain_rejection(self):
        self.assertEqual(
            self.mez.count("['code' => 'ALREADY_SUBMITTED']"), 1)
        self.assertEqual(
            self.mez.count("['code' => 'WORKFLOW_REJECTED']"), 1)
        # The hymn_save 409 conflict (data.item server-wins protocol,
        # handled by hymn_store.dart) must stay untouched by F8.
        hymn_conflict = re.search(
            r"err\([^\n]*already (?:saved|exists)[^\n]*409[^\n]*\);",
            self.mez)
        if hymn_conflict:
            self.assertNotIn('code', hymn_conflict.group(0),
                             'hymn conflict protocol is out of F8 scope')

    def test_hr_lock_and_domain_rejections(self):
        # HR attendance writes were RETIRED (2026-10-07, product decision):
        # both POST handlers answer 410 HR_ATTENDANCE_RETIRED. The old
        # write-lock codes are gone with the write path itself; the global
        # DomainException catch (drain-reachable) remains for reads.
        # 2026-10-18: the whole route is retired (reads too) — three 410
        # exits, all carrying the same machine code the outbox classifier
        # keys on; no DomainException path remains because nothing reads.
        self.assertEqual(
            self.hr.count("['code' => 'HR_ATTENDANCE_RETIRED'"), 3)
        self.assertIn("$method === 'POST' && $action === 'sheet'", self.hr)
        self.assertNotIn("WORKFLOW_REJECTED", self.hr)


class F8ClientClassifier(unittest.TestCase):
    """The buggy 409-is-success heuristic is gone; classification is
    explicit and evidence-based."""

    def setUp(self):
        self.s = read(SYNC)
        self.policy = read(POLICY)

    def test_the_bug_is_dead(self):
        self.assertNotIn('bool _accepted(', self.s)
        self.assertNotIn('if (res.statusCode == 409) return true;', self.s)
        self.assertNotIn("m.contains('already submitted')", self.s)

    def test_classifier_exists_and_is_pure(self):
        self.assertIn('enum OutboxDecision {', self.policy)
        self.assertIn('OutboxDecision classifyOutboxResponse(', self.policy)
        self.assertNotIn('ApiService', self.policy)

    def test_replay_invariant_is_success_only(self):
        accepted = self.policy[self.policy.find('if (evidence.success'):
                               self.policy.find('if (evidence.success') + 350]
        self.assertIn('return OutboxDecision.accepted;', accepted)
        self.assertIn('status >= 200 && status < 300', accepted)

    def test_409_taxonomy(self):
        for code in ('ALREADY_SUBMITTED', 'WORKFLOW_REJECTED',
                     'IDEMPOTENCY_CONFLICT', 'IDEMPOTENCY_IN_PROGRESS'):
            self.assertIn(code, self.policy)
        conflict = self.policy[self.policy.find('if (status == 409)'):
                               self.policy.find('if (status == 409)') + 950]
        self.assertIn('OutboxDecision.retryable', conflict)
        self.assertIn('OutboxDecision.needsAttention', conflict)
        self.assertIn('_boundedUnknown', conflict)

    def test_transient_statuses(self):
        for status in ('status == 408', 'status == 425', 'status == 429',
                       'status >= 500'):
            self.assertIn(status, self.policy)

    def test_definite_protocol_refusals_are_rejected(self):
        for status in ('status == 400', 'status == 403', 'status == 404',
                       'status == 422'):
            self.assertIn(status, self.policy)

    def test_all_four_drains_use_one_typed_classifier(self):
        self.assertIn('for (final kind in LegacyOperationKind.values)', self.s)
        self.assertIn('classifyOutboxResponse(', self.s)
        self.assertIn('response.toOutboxEvidence(', self.s)
        self.assertNotIn('classifyDrainResponse(res)', self.s)

    def test_all_four_drains_skip_terminal_attention_batches(self):
        db = read(LDB)
        claim = db[db.find('claimNextLegacyOperation'):
                   db.find('claimNextLegacyOperation') + 2600]
        self.assertIn("sync_state IN ('pending', 'retry_wait')", claim)
        self.assertNotIn("sync_state = 'needs_attention'", claim)

    def test_all_four_drains_settle_rejections_with_reason(self):
        self.assertIn('LegacySettlementKind.needsAttention', self.s)
        self.assertIn('failureCode: response.errorCode', self.s)
        self.assertIn('failureMessage:', self.s)
        self.assertIn('settleLegacyOperation(', self.s)

    def test_synced_progress_only_after_exact_accepted_settlement(self):
        # Scan the whole drain function rather than a fixed character window.
        # The original 1500-character slice silently stopped covering the
        # guard as soon as the settlement call grew, which would have let a
        # real ordering regression pass unnoticed.
        start = self.s.index('Future<_LegacyDrainStats> _drainLegacyKind(')
        end = self.s.index('Future<ApiResponse> _sendLegacyClaim(')
        body = self.s[start:end]
        self.assertIn('result != LegacySettlementResult.applied', body)
        self.assertIn('decision == OutboxDecision.accepted', body)
        guard = body.find('result != LegacySettlementResult.applied')
        increment = body.find('synced++')
        self.assertGreater(increment, guard)
        # There must be exactly one place that can advance the synced count,
        # so the guard above cannot be bypassed by a second increment.
        self.assertEqual(body.count('synced++'), 1)


class F8LocalOutbox(unittest.TestCase):
    """Rejected batches: kept, flagged, recoverable, never
    auto-deleted; no schema migration (sync_error already exists)."""

    def setUp(self):
        self.db = read(LDB)

    def test_no_migration_was_added(self):
        # rejected state = synced=0 + sync_error NOT NULL on existing
        # columns: sync_error ships in the original CREATE TABLE of
        # every pending_* table — F8 adds no column, table, or SQL
        # migration file (046 remains the last one).
        self.assertNotIn('ADD COLUMN sync_error', self.db)
        import glob
        self.assertEqual(glob.glob(f'{ROOT}/sql/*f8*'), [])

    def test_all_four_feeds_carry_the_rejected_flag(self):
        self.assertEqual(
            self.db.count(
                'MAX(CASE WHEN sync_error IS NOT NULL THEN 1 ELSE 0 END)'
                ' as rejected'), 4)

    def test_feeds_do_not_filter_rejected_rows(self):
        # getPendingAttendance is dual-use (drain feed AND the
        # teacher_home overlay): hiding rejected rows in the query
        # would hide locally-taken attendance from the UI.
        for feed in ('getPendingAttendance()', 'getPendingGrades()',
                     'getPendingMezmur()', 'getPendingHr()'):
            body = self.db[self.db.find(feed):self.db.find(feed) + 700]
            self.assertNotIn('sync_error IS NULL', body,
                             f'{feed} must not filter rejected rows')

    def test_rejection_uses_exact_claim_settlement_only(self):
        for call in ('rejectAttendance', 'rejectGrades', 'rejectMezmur',
                     'rejectHr', 'markAttendanceSynced', 'markGradesSynced',
                     'markMezmurSynced', 'markHrSynced'):
            self.assertNotIn(f'Future<void> {call}(', self.db)
        settle = self.db[self.db.find('settleLegacyOperation'):
                         self.db.find('settleLegacyOperation') + 5200]
        self.assertIn('LegacySettlementKind.needsAttention', settle)
        self.assertIn("sync_state': 'needs_attention'", settle)
        exact = self.db[self.db.find('_legacyExactWhere'):
                        self.db.find('_legacyExactWhere') + 1100]
        self.assertIn("sync_state = 'in_flight'", exact)

    def test_discard_requires_explicit_user_action(self):
        # The confirmation dialog carries the immutable operation id. Exact,
        # owner/scope-bound deletion cannot remove a replacement save.
        self.assertIn(
            'Future<SyncRecoveryActionResult> discardRejectedOperation(',
            self.db)
        discard = self.db[self.db.find('discardRejectedOperation'):
                          self.db.find('getRejectedBatches')]
        self.assertIn('client_op_id = ?', discard)
        self.assertIn('needs_attention', discard)
        self.assertIn('resolved_conflict', discard)
        self.assertIn('expectedState', discard)
        self.assertIn('owner_user_id = ?', discard)
        self.assertIn('created_authorization_version = ?', discard)
        self.assertIn('discardRejectedOperation(', read(CENTER))
        self.assertIn('expectedState: item.state', read(CENTER))
        # the sync engine must never discard
        self.assertNotIn('discardRejected', read(SYNC))

    def test_cleanup_only_removes_synced_rows(self):
        # rejected rows are never swept by housekeeping (data must
        # survive restarts until the user decides).
        for call in ('cleanupSyncedHr', 'cleanupSyncedMezmur',
                     'cleanupSynced'):
            body = self.db[self.db.find(f'Future<void> {call}('):
                           self.db.find(f'Future<void> {call}(') + 420]
            self.assertIn('synced = 1', body)
            self.assertNotIn('synced = 0', body)

    def test_get_rejected_batches_covers_all_four_outboxes(self):
        body = self.db[self.db.find('getRejectedBatches'):
                       self.db.find('getRejectedBatches') + 2600]
        for table in ('pending_attendance', 'pending_grades',
                      'pending_mezmur', 'pending_hr'):
            self.assertIn(table, body)
        self.assertIn("sync_state IN ('needs_attention', 'resolved_conflict')", body)
        self.assertIn('client_op_id', body)
        self.assertIn('owner_user_id = ?', body)
        self.assertIn('created_authorization_version = ?', body)
        self.assertIn('UNION ALL', body)

    def test_grades_outbox_now_has_an_idempotency_key(self):
        # getPendingGrades previously omitted client_op_id, so grades
        # drained with no key (safe only by upsert accident). The v34
        # feed returns an id only for one coherent generation; UUID lexical
        # MAX is explicitly forbidden because it is not temporal ordering.
        feed = self.db[self.db.find('getPendingGrades'):
                       self.db.find('getPendingGrades') + 650]
        self.assertIn('COUNT(DISTINCT client_op_id) = 1', feed)
        self.assertIn('MIN(client_op_id) ELSE NULL', feed)
        self.assertNotIn('MAX(client_op_id)', feed)


class F8DropPendingReconciliation(unittest.TestCase):
    """Attendance cleanup now fails closed, including never-rejected drafts.

    Other-domain F8 filters still spare rejected rows. These source pins
    supplement production LocalDb/real SQLite coverage in
    attendance_confirmation_required_test.dart; they are not phone acceptance.
    """

    def setUp(self):
        self.db = read(LDB)

    def _method(self, name):
        i = self.db.find(name)
        self.assertGreater(i, -1, name)
        i = self.db.rfind('Future<', 0, i + 1)
        j = self.db.find('\n  Future<', i + 8)
        return self.db[i:j if j > i else None]

    def test_attendance_drop_refuses_all_unconfirmed_cleanup(self):
        body = self._method('dropPendingAttendance')
        self.assertIn('throw StateError(', body)
        self.assertNotIn('.delete(', body)
        self.assertNotIn('await database', body)

    def test_locked_day_cleanup_spares_rejected_rows(self):
        # Case 2: synced=0 + sync_error NOT NULL must survive the
        # legacy cleanup on the other three outboxes.
        for name in ('dropPendingGrades', 'dropPendingMezmur', 'dropPendingHr'):
            body = self._method(name)
            self.assertIn('synced = 0', body, name)
            self.assertIn('sync_error IS NULL', body,
                          f'{name} must never delete F8-rejected rows')

    def test_stale_pending_cleanup_behavior_unchanged(self):
        # Case 1: ordinary stale pending rows are still cleaned, but only for
        # the active owner/scope and never by a stale cross-session action.
        for name in ('dropPendingGrades', 'dropPendingMezmur', 'dropPendingHr'):
            body = self._method(name)
            self.assertNotIn('synced = 1', body, name)
            self.assertIn("txn.delete(", body, name)
            self.assertIn('owner_user_id = ?', body, name)
            self.assertIn('created_authorization_version = ?', body, name)

    def test_explicit_discard_still_deletes_rejected_exactly(self):
        # Case 3: Discard targets only the immutable terminal operation shown
        # by the review sheet; a stale dialog cannot delete its replacement.
        body = self._method('discardRejectedOperation')
        self.assertIn('client_op_id = ?', body)
        self.assertIn('synced = 0', body)
        self.assertIn('needs_attention', body)
        self.assertNotIn('sync_error IS NULL', body)

    def test_resave_replacement_remains_owner_scope_fenced(self):
        # All four saves delegate to the serialized owner/scope-fenced method.
        # Attendance additionally preserves attempted/held or different-year
        # generations; this source check does not claim those are replaced.
        for name in ('saveAttendanceLocal', 'saveGradesLocal',
                     'saveMezmurLocal', 'saveHrLocal'):
            body = self.db[self.db.find(f'Future<LegacyOperationRef> {name}('):
                           self.db.find(f'Future<LegacyOperationRef> {name}(') + 2200]
            self.assertIn('_replaceLegacyOperation(', body, name)
        start = self.db.index('  Future<LegacyOperationRef> _replaceLegacyOperation({')
        end = self.db.index('  Future<LegacyOperationRef> saveAttendanceLocal(', start)
        replacement = self.db[start:end]
        self.assertIn('final opId = newClientOpId();', replacement)
        self.assertIn("where: '$naturalKeyWhere AND synced = 0 '", replacement)
        self.assertIn('owner_user_id = ?', replacement)
        self.assertIn('created_authorization_version = ?', replacement)
        self.assertNotIn('sync_error IS NULL', replacement)

    def test_needs_attention_pipeline_unaffected(self):
        # Case 4: the review system still reads the spared rows
        # (already pinned above; this asserts the invariant that makes
        # the spare meaningful — rejected visibility does not depend
        # on dropPending).
        for name in ('getRejectedBatches',):
            i = self.db.find(f'Future<List<Map<String, dynamic>>> {name}(')
            self.assertGreater(i, -1, name)
        self.assertNotIn('dropPending', read(SYNC),
                         'the sync engine must not invoke locked-day drops')


class F8HonestUi(unittest.TestCase):
    """A refused packet is never reported as 'waiting for network' and
    is surfaced with its reason + an explicit Discard (§10)."""

    def setUp(self):
        self.banner = read(BANNER)

    def test_banner_and_sheet_exist(self):
        self.assertIn('class SyncAttentionBanner', self.banner)
        self.assertIn('showSyncRejectedSheet', self.banner)

    def test_discard_is_gated_by_confirmation(self):
        center = read(CENTER)
        self.assertIn("'Discard saved operation?'", center)
        self.assertIn('showDialog<bool>', center)
        self.assertIn('_confirmDiscard(item)', center)

    def test_center_does_not_claim_discard_deletes_server_data(self):
        center = read(CENTER)
        self.assertIn('does not delete an accepted server record', center)
        self.assertIn('Why this needs attention', center)

    def test_status_counts_rejected_honestly(self):
        s = read(SYNC)
        self.assertIn('final int rejected;', s)
        self.assertIn("bool get needsAttention => rejected > 0;", s)
        self.assertIn("'$rejected need", s)  # breakdown callout
        self.assertIn('rejected: inventory.needsAttention,', s)
        self.assertIn('final inventory = await _db.getOutboxInventory();', s)

    def test_all_live_screens_wire_the_banner(self):
        # HR's screen was retired (2026-10-07); the remaining three keep
        # the rejected-attention banner wired.
        for name, path in SCREENS.items():
            src = read(path)
            self.assertIn("import '../../widgets/sync_attention.dart';",
                          src, name)
            self.assertRegex(src, r'SyncAttentionBanner\(')
            self.assertIn('_rejectedCount = ', src, name)


if __name__ == '__main__':
    unittest.main()
