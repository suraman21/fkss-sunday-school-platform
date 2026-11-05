"""Phase-5 tests: mezmur attendance becomes the VERBATIM clone of
the teachers ↔ education workflow, scoped by (date, section):

  - sql/024: mezmur_submissions packet table (UNIQUE date+section),
    excused status + notes on mezmur_attendance (additive, guarded)
  - MezmurSubmissionService mirrors SubmissionService vocabulary
  - mobile API: section-scoped sheet + draft/submitted packets,
    409 lock semantics identical to teachers
  - web API: batched overview, review inbox, reason-mandatory review
  - web dashboard rescue: lazy tabs + bounded GETs (no more
    skeleton-forever), program types removed from every UI
  - mobile v2: [Section ▾] + P/A/L/E + notes + outbox keyed by
    (date, section), offline/background-sync parity

Static analysis only (mirrors the rest of the suite).
"""

from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class MezmurPhase5Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sql24 = (ROOT / "sql/024_mezmur_submissions.sql").read_text(encoding="utf-8")
        cls.sub_service = (
            ROOT / "admin/backend/services/MezmurSubmissionService.php"
        ).read_text(encoding="utf-8")
        cls.att_service = (
            ROOT / "admin/backend/services/MezmurAttendanceService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.shell = (ROOT / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.css = (ROOT / "themes/components.css").read_text(encoding="utf-8")
        cls.screen = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_attendance.dart"
        ).read_text(encoding="utf-8")
        cls.analytics = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_analytics.dart"
        ).read_text(encoding="utf-8")
        cls.db = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/local_db.dart"
        ).read_text(encoding="utf-8")
        cls.sync = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/sync_service.dart"
        ).read_text(encoding="utf-8")
        cls.dart_api = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/api_service.dart"
        ).read_text(encoding="utf-8")

    # ── sql/024: additive, idempotent, guarded ────────────────
    def test_sql024_packet_table_contract(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `mezmur_submissions`", self.sql24)
        self.assertIn(
            "UNIQUE KEY `uq_mezmur_submissions_date_section` (`attendance_date`, `section`)",
            self.sql24,
        )
        # grade_submissions vocabulary parity
        for col in [
            "taker_id", "status", "member_count", "present_count",
            "late_count", "absent_count", "excused_count",
            "submitted_at", "reviewed_by", "reviewed_at", "review_notes",
            "client_op_id",
        ]:
            self.assertIn(f"`{col}`", self.sql24)
        # FKs set-null (never destroy history when a user is removed)
        self.assertIn("ON DELETE SET NULL", self.sql24)

    def test_sql024_is_guarded_and_additive(self):
        # excused widening + notes column + nullable session_id are
        # guarded ALTERs; nothing dropped
        self.assertIn("information_schema.columns", self.sql24)
        self.assertIn("COLUMN_TYPE LIKE '%excused%'", self.sql24)
        self.assertIn("IS_NULLABLE = 'YES'", self.sql24)
        self.assertIn("MODIFY COLUMN `session_id` BIGINT UNSIGNED DEFAULT NULL", self.sql24)
        self.assertNotIn("DROP TABLE", self.sql24)
        self.assertNotIn("DROP COLUMN", self.sql24)
        # mezmur_days untouched (legacy labels preserved)
        self.assertNotIn("ALTER TABLE `mezmur_days`", self.sql24)

    # ── MezmurSubmissionService: edu-workflow clone ───────────
    def test_submission_service_mirrors_edu_vocabulary(self):
        for token in [
            "STATUS_INCOMPLETE = 'incomplete'",
            "STATUS_SUBMITTED = 'submitted'",
            "STATUS_APPROVED = 'approved'",
            "STATUS_REJECTED = 'rejected'",
            "STATUS_REVISION = 'revision_needed'",
            "STATUS_DRAFT = 'draft'",
            "public static function statusIsOpen(",
            "public static function normalizeStatus(",
            "public static function staffCanOverride(",
            "public static function isLockedForTaker(",
            "public static function countsFromRecords(",
        ]:
            self.assertIn(token, self.sub_service)
        # review authority: mezmur dept + admins ONLY
        self.assertIn(
            "private const REVIEW_ROLES = ['mezmur_dept', 'school_admin', 'super_admin'];",
            self.sub_service,
        )

    def test_review_requires_reason_for_returns(self):
        self.assertIn("mb_strlen($notes) < 3", self.sub_service)
        self.assertIn("Write a short reason so the taker knows what to fix.", self.sub_service)
        self.assertIn("mb_substr($notes, 0, 500)", self.sub_service)
        # immutable audit trail on every decision
        self.assertIn("Mezmur Submission Reviewed", self.sub_service)
        self.assertIn("'mezmur_submission'", self.sub_service)
        self.assertIn("previous_status", self.sub_service)

    def test_upsert_lock_semantics_match_teachers(self):
        self.assertIn(
            "This attendance is already submitted. Only administrators can change it.",
            self.sub_service,
        )
        # open states: draft / incomplete / revision_needed
        self.assertIn("self::STATUS_REVISION], true)", self.sub_service)

    # ── MezmurAttendanceService: section-scoped sheets ────────
    def test_attendance_service_section_surface(self):
        for token in [
            "public static function sectionRoster(",
            "public static function sectionListWithCounts(",
            "public static function fetchSectionSheet(",
            "public static function saveSectionSheet(",
            "bool $ownTransaction = true",
        ]:
            self.assertIn(token, self.att_service)
        # teacher parity statuses incl. excused + notes column used
        self.assertIn(
            "public const STATUSES = ['present', 'late', 'absent', 'excused'];",
            self.att_service,
        )
        self.assertIn("notesByMember", self.att_service)

    def test_no_nested_transactions(self):
        # saveSectionSheet only commits/rolls back when it owns the tx
        self.assertIn("if ($ownTransaction) {", self.att_service)

    # ── mobile API routes: teacher-contract parity ────────────
    def test_mobile_route_section_endpoints(self):
        self.assertIn("$action === 'sections'", self.route)
        self.assertIn("sectionListWithCounts", self.route)
        self.assertIn("fetchSectionSheet", self.route)
        self.assertIn("saveSectionSheet", self.route)
        self.assertIn("MezmurSubmissionService::takerMayWrite", self.route)
        # 409 lock + idempotency + rate limiting intact (F8: the lock
        # now carries a machine-readable code for the mobile outbox)
        self.assertIn("err('This attendance is already submitted. Only administrators can change it.', 409,\n                    ['code' => 'ALREADY_SUBMITTED']);", self.route)
        self.assertIn("apiIdempotencyBegin(", self.route)
        self.assertIn("isApiRateLimited('mezmur_sheet_save'", self.route)

    def test_mobile_route_atomic_rows_plus_packet(self):
        # rows and packet commit or roll back as ONE unit
        self.assertIn("$conn->begin_transaction();", self.route)
        self.assertIn("$conn->commit();", self.route)
        self.assertIn("$conn->rollback();", self.route)
        # ownTransaction=false — the route owns the transaction. (Attendance
        # rework Phase A extended the call: drafts now also pass
        # $requireComplete so partial autosave drafts merge instead of 422.)
        self.assertIn("false, $requireComplete);", self.route)
        # legacy date-only save kept for older clients (break nothing)
        self.assertIn("MezmurAttendanceService::saveSheet($conn, $date, $records", self.route)

    # ── web API: overview batch + dept review ─────────────────
    def test_web_api_new_actions(self):
        for token in [
            "case 'overview':",
            "case 'sections':",
            "case 'submissions_list':",
            "case 'submission_detail':",
            "case 'submission_review':",
        ]:
            self.assertIn(token, self.api)
        # review is POST-only + role-checked + rate-limited as a write.
        # Asserted against the $postActions ARRAY itself rather than its
        # trailing literal, which moved when P0 appended the audio actions.
        post_actions = re.search(r"\$__postActions = \[(.*?)\];", self.api, re.S).group(1)
        for action in ("'submission_review'", "'save'", "'set_status'",
                       "'zemarian_status'", "'zemarian_image'", "'zemarian_image_remove'",
                       "'audio_presign'", "'audio_confirm'", "'audio_remove'",
                       "'audio_set_duration'", "'migrate'"):
            self.assertIn(action, post_actions)
        self.assertIn("in_array($action, $__postActions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST'", self.api)
        # the same list drives the write rate-limit bucket
        self.assertIn("in_array($action, $__postActions, true)\n    ? 'mezmur_write'", self.api)
        # schema-drift killer endpoints
        self.assertIn("case 'schema'", self.api)
        self.assertIn("case 'migrate'", self.api)
        self.assertIn("MezmurSubmissionService::canReview(", self.api)
        # schema probe tells admins exactly which migration to run
        self.assertIn("sql/024_mezmur_submissions.sql", self.api)

    def test_overview_is_one_round_trip(self):
        ov = self.api.split("case 'overview':")[1].split("case 'submissions_list'")[0]
        self.assertIn("mezmur_respond([", ov)
        self.assertIn("'recent_packets'", ov)
        self.assertIn("'prev_month'", ov)

    # ── web dashboard rescue ──────────────────────────────────
    def test_js_no_undefined_api_global(self):
        # ROOT CAUSE of the skeleton-forever bug: SSMS.api never existed
        self.assertNotIn("SSMS.api", self.js)
        self.assertIn("window.api.get('mezmur.php?' + q)", self.js)
        self.assertIn("window.api.post('mezmur.php', data)", self.js)

    def test_js_bounded_gets(self):
        self.assertIn("GET_TIMEOUT = 12000", self.js)
        self.assertIn("clearTimeout(timer)", self.js)
        # every timeout lands in the error+Retry state, never a spinner
        self.assertIn("The server took too long to answer", self.js)

    def test_js_lazy_tabs(self):
        self.assertIn("var tabLoaded = { overview: false, library: false, attendance: false, analytics: false, takers: false };", self.js)
        self.assertIn("window.switchSection = function (name) {", self.js)
        self.assertIn("_origSwitch(name);", self.js)
        # DOMContentLoaded loads ONLY the active tab (wiring block)
        dom = self.js.split("document.addEventListener('DOMContentLoaded'")[1]
        dom = dom.split("// Public API")[0]
        self.assertIn("loadTab(name);", dom)
        self.assertNotIn("loadOverview();", dom)
        self.assertNotIn("loadTakers();", dom)
        self.assertNotIn("loadSubmissions();", dom)

    def test_web_attendance_is_readonly_review_console(self):
        # The department INSPECTS and REVIEWS; taking attendance lives
        # exclusively in the mobile app (product decision 2026-08-28).
        for token in [
            "action=sections",
            "mzViewSection",
            "viewSheet",
            "quickReview",
            "mzRvDecision",
            "action: 'submission_review'",
            "unmarkedCount()",
        ]:
            self.assertIn(token, self.js)
        # no editing surface left on the web
        self.assertNotIn("mzAttSection", self.js)
        self.assertNotIn("mzAttDate", self.js)
        self.assertNotIn("Mezmur.setMark(", self.js)
        self.assertNotIn("saveSheet(kind)", self.js)
        self.assertNotIn("seg-btn", self.js)
        # shell shows the read-only contract (record on mobile)
        self.assertIn("Read-only — sheets are recorded and submitted from the mobile app", self.shell)

    def test_attendance_section_is_edu_submissions_clone(self):
        # 2026-08-28: the mezmur console mirrors the edu Submissions
        # workflow — Drafts/Submitted/Insights tabs, insight strip,
        # Excel export, detail + review modals.
        for token in [
            'id="mzSubTabDraft"', 'id="mzSubTabSubmitted"', 'id="mzSubTabInsights"',
            'id="mzSubStatsRow"', 'id="mzSubInsights"', 'id="mzSubSection"',
            'id="mzSubmissionsList"',
        ]:
            self.assertIn(token, self.shell)
        for fn in [
            "function switchSubTab(", "function loadSubmissions(",
            "function renderSubStats(", "function exportSubmissions()",
            "function loadSubInsights()", "function quickDecision(",
        ]:
            self.assertIn(fn, self.js)
        # insight strip data comes from the governed stats block
        self.assertIn("packetStats", self.sub_service)
        self.assertIn("$out['stats'] = MezmurSubmissionService::packetStats($conn);", self.api)
        # shared submission-tab styling lives in the component theme
        self.assertIn(".sub-tab", self.css)
        # Excel parity (SheetJS, same CDN as edu_dept)
        self.assertIn("xlsx.full.min.js", self.shell)
        self.assertIn("Mezmur.exportSubmissions()", self.shell)
        self.assertIn("Read-only — sheets are recorded and submitted from the mobile app.", self.shell)
        self.assertIn("Review Queue", self.shell)
        self.assertNotIn("Take Attendance", self.shell)
        self.assertIn('id="mzViewSection"', self.shell)

    def test_program_types_removed_from_web_ui(self):
        self.assertNotIn("mzAttProgram", self.shell)
        self.assertNotIn("mzAnProgram", self.shell)
        self.assertNotIn("mzAttProgram", self.js)
        self.assertNotIn("PROGRAM_LABELS", self.js)
        # server keeps legacy program filter (older rows) but UI drops it
        self.assertNotIn("program_type=' +", self.js)

    def test_shell_review_inbox_and_status_banner(self):
        for token in [
            'id="mzSubTbody"',
            'id="mzSheetStatus"',
            'id="mzReviewModal"',
            'id="mzPacketModal"',
            'id="mzOvQueue"',
        ]:
            self.assertIn(token, self.shell)
        self.assertIn('onclick="Mezmur.viewSheet()"', self.shell)

    def test_css_supports_excused_and_banners(self):
        self.assertIn(".seg-btn[aria-pressed=\"true\"].seg-excused", self.css)
        self.assertIn(".mz-banner", self.css)

    # ── mobile v2: teacher clone ──────────────────────────────
    def test_mobile_screen_teacher_clone(self):
        for token in [
            "_loadSections()",
            "getMezmurSections()",
            "cacheMezmurSections(",
            "'present',\n    'absent',\n    'late',\n    'excused',",
            "_statusBtn('E', 'excused'",
            "_editNote(",
            "packetKind: 'submitted'",
            "showUndoToast(",
            "TeacherActionBar(",
            "SubmittedBar()",
            "showEthiopianDatePicker(",
            "formatGregorianAsEthiopian(_selectedDate)",
        ]:
            self.assertIn(token, self.screen)

    def test_mobile_screen_packet_semantics(self):
        # server packet status drives the lock, like teachers
        self.assertIn("submission_status", self.screen)
        self.assertIn("PacketLock.isLocked(", self.screen)
        # A lock still makes the sheet read-only, but is not proof that this
        # phone's unsynchronized operation was accepted.
        self.assertNotIn("dropPendingMezmur(", self.screen)
        # department wording (not "Education") on the return banner
        self.assertIn("Returned by the Mezmur department", self.screen)
        self.assertIn("_mezmurReturnNote(", self.screen)
        self.assertNotIn("Only Education can change this", self.screen)

    def test_mobile_program_types_gone(self):
        self.assertNotIn("_program", self.screen)
        self.assertNotIn("Rehearsal", self.screen)
        self.assertNotIn("_program", self.analytics)
        self.assertNotIn("program_type", self.analytics)

    def test_mobile_outbox_is_section_scoped(self):
        self.assertIn("saveMezmurLocal(_selectedDate, section, _records()", self.screen)
        self.assertIn("getPendingMezmurRecords(_selectedDate, section)", self.screen)
        self.assertIn("cacheMezmurSheet(_selectedDate, section", self.screen)
        self.assertIn("getMezmurSheet(_selectedDate, section: section)", self.screen)

    # ── php -l (when a PHP CLI is available) ──────────────────
    def test_php_lint_new_backend_files(self):
        if shutil.which("php") is None:
            self.skipTest("php CLI not available")
        for rel in [
            "admin/backend/services/MezmurSubmissionService.php",
            "admin/backend/services/MezmurAttendanceService.php",
            "admin/api_mezmur.php",
            "api/v1/routes/mezmur.php",
        ]:
            r = subprocess.run(
                ["php", "-l", str(ROOT / rel)],
                capture_output=True, text=True, timeout=60,
            )
            self.assertEqual(r.returncode, 0, f"php -l failed for {rel}: {r.stdout}{r.stderr}")


class TypoTolerantSearchTests(unittest.TestCase):
    """Patch 22 (2026-09-01): Telegram-style typo-tolerant search.

    Root cause fixed: the fuzzy (Levenshtein) ranking tier could never
    fire because the strict pass (FULLTEXT / LIKE) must MATCH first — a
    misspelled query matched zero rows, so there was nothing to rank.
    Two-stage retrieval (bounded candidate pool under the STRUCTURAL
    filters, then fuzzy rescue + rerank) on every surface:

    - service (MezmurHymnService::listHymns): strict LIKE pass, then a
      fuzzy-rescue pool under the same filters minus the text condition
    - web (admin/api_mezmur.php list): the same rescue around the
      FULLTEXT/LIKE pass — first page only, honest totals, page-size cap
    - web JS: 160ms debounce + 2-char minimum + bounded query cache
      (every successful mutation drops it, via the apiPost choke point)
    - mobile (hymn_store.dart): structural filters in SQL, text match
      fully in memory so the fuzzy tier actually fires; 2-char clamp

    Keystroke hygiene: 1-char queries are dropped server-side (service
    AND web clamp) and client-side (web JS + Dart store) — a '%x%' scan
    cannot use an index.
    """

    @classmethod
    def setUpClass(cls):
        cls.svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.store = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart"
        ).read_text(encoding="utf-8")

    def test_service_two_stage_with_fuzzy_rescue(self):
        # structural filters are built separately from the text condition
        self.assertIn("$filterSql = $where ? implode(' AND ', $where) : '1=1'", self.svc)
        # stage 1: strict LIKE candidates, bounded
        self.assertIn(
            "$filterSql AND title LIKE ? ORDER BY updated_at DESC, id DESC LIMIT 500",  # P28: single title
            self.svc,
        )
        # stage 2: rescue pool = same filters WITHOUT the text condition
        self.assertIn(
            '$conn->prepare($selectSearch . "$filterSql ORDER BY updated_at DESC, id DESC LIMIT 500")',
            self.svc,
        )
        # 1-char queries never reach SQL
        self.assertIn("if (mb_strlen($search) < 2) {", self.svc)

    def test_web_two_stage_with_fuzzy_rescue(self):
        self.assertIn("if (mb_strlen($search) < 2) $search = '';", self.api)
        self.assertIn("$filterSql = $where ? ('WHERE ' . implode(' AND ', $where)) : ''", self.api)
        self.assertIn("$rescued = 0;", self.api)
        self.assertIn("FROM mezmur_hymns $filterSql", self.api)
        # rescue only fills page 1 (honest totals), then respects page size
        self.assertIn("$search !== '' && $page === 1 && count($items) < $perPage", self.api)
        self.assertIn("$items = array_slice($items, 0, $perPage);", self.api)

    def test_web_client_min_length_and_query_cache(self):
        # a single character waits for the second keystroke
        self.assertIn("if (t.length === 1) return;", self.js)
        # identical queries are served from memory, not the server
        self.assertIn("if (listCache[q]) {", self.js)
        self.assertIn("cachePut(q, d);", self.js)
        self.assertIn("keys.length >= 10", self.js)  # bounded
        # every successful mutation invalidates the cache (apiPost = the
        # single choke point all mutations travel through)
        post = self.js.split("POST_TIMEOUT);")[1][:800]
        self.assertIn("if (d && d.status === 'success') listCache = {};", post)

    def test_mobile_in_memory_text_match(self):
        # 1-char clamp mirrors the server
        self.assertIn("search.trim().length < 2", self.store)
        # the text match happens in memory (LIKE would drop typos before
        # the fuzzy tier could fire) — the store no longer pushes the
        # search string into SQL
        self.assertIn("if (score <= 0) continue;", self.store)
        self.assertIn("h['similarity'] = score;", self.store)
        self.assertNotIn("search: search,", self.store)

    def test_ranked_best_first_on_all_surfaces(self):
        # every surface sorts by similarity, descending
        self.assertIn("usort($items", self.api)
        self.assertIn("scored.sort(", self.store)


class TaxonomySyncTests(unittest.TestCase):
    """Patch 23 (2026-09-01): seamless bidirectional taxonomy sync.

    Deep-analysis findings fixed (web <-> mobile categories/singers):
    - S1 normalizeIds silently dropped negative placeholder ids, so a
      hymn saved offline with a just-created category synced WITHOUT it.
      Fix: placeholder refs travel as {id, name} and the server resolves
      by natural key (name) INSIDE the hymn save, creating when absent.
    - S2 create was not idempotent: a second device creating the same
      name got a 422, dropped its op, kept the placeholder -> duplicate
      rows after the next pull. Fix: id <= 0 + existing name links to
      the existing row (natural-key convergence).
    - S3 renames echoed a hardcoded is_active=1, un-hiding hidden
      rows on the renaming device. Fix: echo the real value.
    - M1 placeholder replacement orphaned on-device join rows; they are
      now repointed at the real server id before the placeholder drops.
    - M2 queued hymn payloads still referenced placeholder ids; they are
      rewritten to synced twin ids at push time.
    - M3 the editor reloaded selections through a >0 filter, silently
      forgetting placeholder picks (re-save erased the links).
    - M4 hide/show ops on never-synced placeholders resolved by name.
    - Visibility: the web picker labels hidden entries "(hidden)"
      instead of offering them unlabeled (mobile hides them from
      picking; both preserve existing links).
    """

    @classmethod
    def setUpClass(cls):
        cls.svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.store = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart"
        ).read_text(encoding="utf-8")
        cls.db = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/local_db.dart"
        ).read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")

    def test_hymn_save_resolves_offline_refs_by_name(self):
        self.assertIn("parseTaxonomyRefs", self.svc)
        self.assertIn("'pendingNames'", self.svc)
        # resolve-then-create, inside the save transaction (MZ-10 pattern)
        self.assertIn("resolveNameToId($conn, 'mezmur_categories', $pname)", self.svc)
        self.assertIn("createNamedTaxonomy($conn, 'mezmur_zemarians', $pname, $actorId)", self.svc)
        self.assertIn("Mezmur Category Created (offline sync)", self.svc)

    def test_taxonomy_creates_are_idempotent(self):
        self.assertIn("if ($dup && $id <= 0) {", self.svc)
        self.assertIn("Category already exists — linked.", self.svc)
        self.assertIn("Singer already exists — linked.", self.svc)
        # rename collisions (id > 0) stay honest errors
        self.assertIn("'A category with this name already exists.'", self.svc)

    def test_rename_echoes_real_is_active(self):
        self.assertIn('"SELECT name, sort_order, is_active" . ($twoLevel ? ", parent_id" : "")', self.svc)  # P30 parent-aware (P32 appends gradient cols)
        self.assertIn("SELECT name, name_am, is_active FROM mezmur_zemarians", self.svc)
        self.assertIn("'is_active' => (int)$old['is_active']", self.svc)

    def test_mobile_placeholder_refs_travel_with_names(self):
        self.assertIn("_taxonomyRefPayload", self.store)
        self.assertIn("out.add({'id': id, 'name': name});", self.store)
        # queued payloads are rewritten to synced twin ids at push time
        self.assertIn("await _rewritePlaceholderRefs(payload);", self.store)

    def test_mobile_joins_repointed_before_placeholder_drop(self):
        self.assertIn("Future<void> _repointJoin(", self.store)
        self.assertIn("'cached_hymn_categories'", self.store)
        self.assertIn("'category_id'", self.store)
        self.assertIn("'cached_hymn_zemarians'", self.store)
        self.assertIn("'zemarian_id'", self.store)
        # duplicate (hymn_id, real_id) pairs removed first — PK is that pair
        self.assertIn("DELETE FROM $table WHERE $col = ? AND hymn_id IN", self.store)

    def test_mobile_preserves_placeholder_selections(self):
        self.assertIn(".where((e) => e != 0).toList()", self.db)
        # hide/show ops on placeholders resolve by name at push time
        self.assertIn("'name': row['name']", self.store)
        self.assertIn("_localIdByName", self.store)

    def test_web_picker_labels_hidden_entries(self):
        self.assertIn("function catLabel(i)", self.js)
        self.assertIn("(hidden)", self.js)


class LyricsStylingBrowseTests(unittest.TestCase):
    """Patch 24 (2026-09-01): modern lyrics styling + Spotify-like
    browsing (user item 6).

    Genius/Spotify standard (research 2026-08-31): [Section] square-
    bracket headers, **bold** / *italic* emphasis, PLAIN TEXT stored —
    parsing happens at render time only, so old data and old clients
    keep working (no migration, no schema change).

    - web: mezmur.js renderLyrics() — escape FIRST then transform
      (XSS-safe by construction); view modal renders through it; the
      editor shows a markup hint.
    - mobile: _LyricsView widget (section headers + bold/italic spans
      + stanza spacing); tappable category/singer chips open the
      filtered hymn list; the library is a self-standing screen with a
      bottom nav (Hymns | Categories | Singers) and Spotify-style
      gradient tiles carrying on-device hymn counts.
    """

    @classmethod
    def setUpClass(cls):
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.page = (
            ROOT / "frontend/pages/mezmur_dept.php"
        ).read_text(encoding="utf-8")
        cls.detail = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymn_detail.dart"
        ).read_text(encoding="utf-8")
        cls.lib = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymns.dart"
        ).read_text(encoding="utf-8")
        cls.editor = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymn_editor.dart"
        ).read_text(encoding="utf-8")
        cls.store = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart"
        ).read_text(encoding="utf-8")
        cls.db = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/local_db.dart"
        ).read_text(encoding="utf-8")

    def test_web_renderer_escapes_before_transform(self):
        self.assertIn("function renderLyrics(", self.js)
        # escape FIRST — everything after only adds our own safe tags
        # the escape happens inside the function head, before any
        # transform can emit markup
        body = self.js.split("function renderLyrics(")[1][:400]
        self.assertIn("var txt = esc(", body)
        self.assertIn("<strong>$1</strong>", self.js)
        self.assertIn("<em>$1</em>", self.js)

    def test_web_view_renders_through_parser(self):
        self.assertIn("$('mzViewLyrics').innerHTML = renderLyrics(", self.js)

    def test_markup_hints_present(self):
        # P30: visual editor — hints describe the toolbar, not markers.
        self.assertIn("mz-ed-toolbar", self.page)              # web toolbar
        self.assertIn("Style as you write", self.page)          # web hint
        self.assertIn("Style with the toolbar", self.editor)    # mobile hint

    def test_mobile_lyrics_widget(self):
        self.assertIn("class _LyricsView", self.detail)
        self.assertIn("_sectionRe", self.detail)   # [Section] headers
        self.assertIn("_inlineRe", self.detail)    # **bold** / *italic*
        self.assertIn("TextSpan", self.detail)     # rich spans, not flat text

    def test_mobile_taxonomy_chips_open_filtered_list(self):
        self.assertIn("categoryNamesFor", self.detail)
        self.assertIn("zemarianNamesFor", self.detail)
        self.assertIn("ActionChip", self.detail)
        self.assertIn("initialCategoryId: singer ? null : id", self.detail)

    def test_library_self_standing_with_top_tabs(self):
        # P26 made the library self-standing; P30 (item 6) moved its
        # section navigation to the BOTTOM bar (the screen is pushed
        # full-screen, so the main app nav is out of the way).
        self.assertIn("bottomNavigationBar: NavigationBar(", self.lib)
        self.assertIn("TabBarView(", self.lib)  # still one plane per tab
        for label in ("'Hymns'", "'Categories'", "'Singers'"):
            self.assertIn(label, self.lib)
        self.assertIn("initialCategoryId", self.lib)
        self.assertIn("initialZemarianId", self.lib)
        self.assertIn("_browseGrid", self.lib)
        self.assertNotIn("BottomNavigationBar", self.lib)

    def test_browse_tiles_carry_on_device_counts(self):
        self.assertIn("getCategoryHymnCounts", self.db)
        self.assertIn("getZemarianHymnCounts", self.db)
        # counts consider ACTIVE hymns only
        self.assertIn("h.status = 'active'", self.db)
        self.assertIn("Future<Map<int, int>> categoryHymnCounts()", self.store)

    def test_active_filter_is_clearable(self):
        self.assertIn("InputChip", self.lib)
        self.assertIn("onDeleted: () {", self.lib)
        # the legacy name-chip filter is gone (id-based browse instead)
        self.assertNotIn("_chip('All', '')", self.lib)


class WordIndexSearchTests(unittest.TestCase):
    """Patch 25 (2026-09-01): lyrics search + script-agnostic engine.

    User finding: searching a word that IS in the lyrics returned "no
    match". Four stacked causes, all fixed:
    - InnoDB FULLTEXT cannot tokenize Ge'ez script (verified live:
      'ሰላም' -> 0 hits on a HEALTHY index) — the engine is now the
      mezmur_hymn_words inverted index (sql/032), script-agnostic and
      prefix-scannable at scale.
    - A CREATE FULLTEXT INDEX build was observed returning 0 for
      everything; the reconciler no longer creates FT indexes at all.
    - The mobile service API's strict LIKE never included lyrics.
    - The on-device _similarity haystack excluded lyrics.

    Scoring (server + store parity): title tiers (exact 100 > prefix 90
    > substring 70 > fuzzy 40x) PLUS a lyrics tier (50 per matched
    term), match_in marker ('title' | 'lyrics') and a ±60-char snippet.
    #7 fix rides along: hymn save echoes carry categories/zemarians as
    OBJECT lists — upsertHymns now normalizes both shapes so joins are
    written at push time (they were wiped until the next delta pull).
    """

    @classmethod
    def setUpClass(cls):
        cls.svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.rec = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.store = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart"
        ).read_text(encoding="utf-8")
        cls.db = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/local_db.dart"
        ).read_text(encoding="utf-8")
        cls.sql = (ROOT / "sql/032_mezmur_hymn_words.sql").read_text(encoding="utf-8")

    def test_word_table_schema(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS mezmur_hymn_words", self.sql)
        self.assertIn("PRIMARY KEY (word, hymn_id)", self.sql)  # prefix scans
        self.assertIn("VARBINARY(80)", self.sql)

    def test_service_maintains_index_on_save(self):
        self.assertIn("public static function reindexHymnWords", self.svc)
        # both write paths reindex INSIDE the save transaction, plus the
        # backfill path
        self.assertEqual(self.svc.count("self::reindexHymnWords($conn,"), 3)
        self.assertIn("DELETE FROM mezmur_hymn_words WHERE hymn_id = ?", self.svc)
        self.assertIn("public static function backfillHymnWords", self.svc)

    def test_service_search_uses_word_candidates_with_like_fallback(self):
        self.assertIn("self::searchWordCandidates($conn, $search)", self.svc)
        self.assertIn("id IN ($in) AND $filterSql", self.svc)
        # zero-guard fallback keeps the title LIKE path
        self.assertIn("$filterSql AND title LIKE ?", self.svc)  # P28: single title

    def test_lyrics_tier_scoring_and_payload_hygiene(self):
        # 50/term lyrics tier, below title substring (70), above fuzzy
        self.assertIn("$score += 50.0;", self.svc)
        self.assertIn("$r['match_in'] = $titleScore > 0.0 ? 'title' : 'lyrics';", self.svc)
        self.assertIn("lyricSnippet", self.svc)
        # lyrics never travel in list payloads
        self.assertIn("unset($it['lyrics']);", self.svc)

    def test_web_uses_word_mode_with_like_zero_guard(self):
        self.assertIn("MezmurHymnService::searchWordCandidates($conn, $raw)", self.api)
        self.assertIn("$searchMode = 'word';", self.api)
        self.assertIn("$searchMode = 'like';", self.api)
        self.assertIn("OR lyrics LIKE ?", self.api)
        # scoring happens where lyrics are still loaded, ranking is sort-only
        self.assertIn("searchScore($search, (string)$r['title'], (string)($r['lyrics'] ?? ''))", self.api)  # P28 single title

    def test_reconciler_drops_fulltext_creates(self):
        self.assertIn("public const INDEXES = [];", self.rec)
        self.assertNotIn("ADD FULLTEXT INDEX", self.rec)

    def test_mobile_store_searches_lyrics(self):
        self.assertIn("score += 50;", self.store)
        self.assertIn("h['match_in'] = titleScore > 0 ? 'title' : 'lyrics';", self.store)
        self.assertIn("_lyricSnippet", self.store)

    def test_mobile_collection_search_for_unified_tabs(self):
        self.assertIn("Future<List<Map<String, dynamic>>> searchCategories(", self.store)
        self.assertIn("Future<List<Map<String, dynamic>>> searchZemarians(", self.store)

    def test_upsert_accepts_both_taxonomy_shapes(self):
        # delta pulls send *_ids; save echoes send object lists
        self.assertIn("_idListOfMaps(h['categories'])", self.db)
        self.assertIn("_idListOfMaps(h['zemarians'])", self.db)


class LibraryTopTabsTests(unittest.TestCase):
    """Patch 26 (2026-09-01): library rebuilt around Material top tabs
    and Telegram's unified search (user items 2/3/4/6/8).

    - #2 the nested BottomNavigationBar (a second nav plane inside a
      pushed screen) is GONE — Hymns/Categories/Singers/Add live as
      AppBar tabs, the Material-recommended single-plane pattern.
    - #3 ONE search field serves all three browse tabs.
    - #4 the tabs act as Telegram result-type filters over the SAME
      query: hymns rank with lyrics matches (tag + snippet), while the
      category/singer tabs show ranked catalog results; tapping one
      opens the Hymns tab filtered by it (query cleared).
    - #6 Add Hymn is a real tab (curators only) — the editor renders
      embedded (no inner Scaffold), saving resets the form and jumps
      to the Hymns tab; the FAB is gone.
    - #8 no horizontal category strip under the search bar (the tabs
      own category browsing); quick filters are length/language only.
    """

    @classmethod
    def setUpClass(cls):
        cls.lib = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymns.dart"
        ).read_text(encoding="utf-8")
        cls.editor = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymn_editor.dart"
        ).read_text(encoding="utf-8")

    def test_top_tabs_replace_bottom_nav(self):
        self.assertIn("TabController(", self.lib)
        self.assertIn("SingleTickerProviderStateMixin", self.lib)
        self.assertNotIn("BottomNavigationBar", self.lib)
        self.assertNotIn("floatingActionButton:", self.lib)

    def test_shared_search_field_all_browse_tabs(self):
        # one field, per-tab hint, hidden on the Add tab
        self.assertIn("if (_tab != _addTab)", self.lib)
        self.assertIn("Search hymns — title, Amharic or lyrics…", self.lib)
        self.assertIn("Search categories…", self.lib)
        self.assertIn("Search singers…", self.lib)
        # 1-char queries never search (client parity)
        self.assertIn("length >= 2", self.lib)

    def test_telegram_result_type_tabs(self):
        # same query ranks the catalogs for their tabs
        self.assertIn("_store.searchCategories(query)", self.lib)
        self.assertIn("_store.searchZemarians(query)", self.lib)
        self.assertIn("_taxonomyTab(categories: true)", self.lib)
        self.assertIn("_taxonomyTab(categories: false)", self.lib)
        self.assertIn("Widget _resultList({required bool categories})", self.lib)
        # tapping a result opens the filtered hymns list
        # P31 drill-down: mains open the category screen, subs open the
        # named hymn list; singers still use the filtered Hymns tab.
        self.assertIn("MezmurSubListScreen(", self.lib)
        self.assertIn("_tabCtrl.animateTo(_hymnsTab)", self.lib)

    def test_hymn_rows_show_lyrics_match_context(self):
        self.assertIn("'LYRICS'", self.lib)
        self.assertIn("h['snippet']", self.lib)
        self.assertIn("match_in", self.lib)

    def test_editor_embeds_as_add_tab(self):
        self.assertIn("this.embedded = false", self.editor)
        self.assertIn("widget.embedded", self.editor)
        self.assertIn("widget.onSaved?.call();", self.editor)
        self.assertIn("MezmurHymnEditorScreen(", self.lib)
        self.assertIn("embedded: true", self.lib)

    def test_no_category_strip_under_search(self):
        # quick filters are length/language/archived only
        self.assertIn("_flagChip('Long'", self.lib)
        self.assertNotIn("_chip('All', '')", self.lib)


class SingleTitleAndFilterSheetTests(unittest.TestCase):
    """P28: one Amharic title (items 5/9/10/11).
    - sql/033 folds title_am into title collision-safely and drops the
      retired columns; the reconciler must NEVER re-add them.
    - The service accepts-and-ignores the legacy fields (old app builds
      keep working — no breakage) and never persists them.
    - Web + mobile surfaces show exactly one title field.
    - Filter sheet (item 5) and dropdown pickers (item 10) exist.
    - Web catalog management (item 11) gained rename + usage counts."""

    @classmethod
    def setUpClass(cls):
        R = ROOT
        cls.svc = (R / "admin/backend/services/MezmurHymnService.php").read_text(encoding="utf-8")
        cls.rec = (R / "admin/backend/services/MezmurSchemaReconciler.php").read_text(encoding="utf-8")
        cls.page = (R / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.js = (R / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.editor = (R / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymn_editor.dart").read_text(encoding="utf-8")
        cls.list = (R / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymns.dart").read_text(encoding="utf-8")
        cls.db = (R / "Mobile/wbws_flutter_app/lib/services/local_db.dart").read_text(encoding="utf-8")
        cls.mig = (R / "sql/033_mezmur_single_title.sql").read_text(encoding="utf-8")

    # ── item 9: single Amharic title ────────────────────────────
    def test_migration_033_folds_and_drops(self):
        self.assertIn("DROP COLUMN IF EXISTS title_am", self.mig)
        self.assertIn("DROP COLUMN IF EXISTS reference", self.mig)
        self.assertIn("DELETE FROM mezmur_hymn_words", self.mig)
        # collision safety: the fold is skipped when another row owns
        # the target title, and when two candidates share a target.
        self.assertIn("o.title = x.title_am AND o.id <> x.id", self.mig)
        self.assertIn("WHERE d.title_am = x.title_am) = 1", self.mig)

    def test_reconciler_never_readds_retired_columns(self):
        self.assertNotIn("'title_am'", self.rec)
        self.assertNotIn("'reference'", self.rec)

    def test_service_ignores_legacy_fields(self):
        self.assertIn("accepted-and-ignored", self.svc)
        # INSERT / UPDATE column lists are single-title.
        self.assertIn(
            "INSERT INTO mezmur_hymns (title, category, lyrics, length, language, status, created_by, updated_by)",
            self.svc)
        self.assertIn("'ssssssii', $title, $categoryName, $lyrics, $length, $language, $status, $actorId, $actorId", self.svc)
        self.assertNotIn("SET title=?, title_am=?", self.svc)
        # search scoring is single-title.
        self.assertIn("public static function searchScore(string $query, ?string $title, ?string $lyrics = null)", self.svc)
        self.assertNotIn("title_am LIKE", self.svc)

    def test_web_single_title(self):
        self.assertNotIn("mzTitleAm", self.page)
        self.assertNotIn("mzReference", self.page)
        self.assertNotIn("title_am", self.js)
        self.assertIn("Title (ርዕስ) *", self.page)
        self.assertIn("Search by title or lyrics", self.page)

    def test_mobile_single_title(self):
        self.assertNotIn("_titleAmCtrl", self.editor)
        self.assertNotIn("_referenceCtrl", self.editor)
        self.assertIn("Title (ርዕስ) *", self.editor)
        # local DB folds on upgrade to v16 and rebuilds the word index.
        self.assertIn("version: localDatabaseSchemaVersion,", self.db)
        self.assertIn("UPDATE cached_hymns SET title = title_am", self.db)
        self.assertIn("UPDATE cached_hymns SET title_am = NULL, reference = NULL", self.db)
        # local LIKE search is title-only.
        self.assertIn("where.add('title LIKE ?')", self.db)

    # ── item 10: dropdown pickers ───────────────────────────────
    def test_searchable_picker_widget_exists_and_used(self):
        self.assertTrue((ROOT / "Mobile/wbws_flutter_app/lib/widgets/taxonomy_pick_sheet.dart").exists())
        self.assertIn("TaxonomyPickField", self.editor)
        self.assertNotIn("_multiSelectSection", self.editor)

    # ── item 5: filter sheet ────────────────────────────────────
    def test_filter_sheet_present(self):
        self.assertIn("_openFilterSheet", self.list)
        self.assertIn("CLEAR ALL", self.list)
        self.assertIn("Show $count hymn", self.list)
        self.assertIn("countLocalHymns", self.db)

    # ── item 11: web catalog management (P31: standalone section) ──
    def test_web_catalog_rename_and_counts(self):
        self.assertIn("mgrSave", self.js)          # inline rename (no popups)
        self.assertIn("hymn_count", self.svc)


class DrillDownAndSingleBottomNavTests(unittest.TestCase):
    """P31 (mobile): category -> sub-category boxes (smaller than the
    category boxes) -> named hymn list; and exactly ONE bottom bar at a
    time — the shell hides the main bar while the hymn library shows."""

    @classmethod
    def setUpClass(cls):
        M = ROOT / "Mobile/wbws_flutter_app/lib"
        cls.catscr = (M / "screens/mezmur/mezmur_category_screen.dart").read_text(encoding="utf-8")
        cls.lib = (M / "screens/mezmur/mezmur_hymns.dart").read_text(encoding="utf-8")
        cls.shell = (M / "screens/shell/app_shell.dart").read_text(encoding="utf-8")

    def test_sub_boxes_smaller_than_category_boxes(self):
        # sub boxes: aspect 2.2 vs 1.45 for the browse category tiles
        self.assertIn("childAspectRatio: 2.2", self.catscr)
        self.assertIn("childAspectRatio: 1.45", self.lib)
        self.assertIn("_subBox(", self.catscr)

    def test_sub_images_render(self):
        # sub boxes use their own uploaded cover or a name gradient
        self.assertIn("sub['image_url']", self.catscr)
        self.assertIn("NetworkImage(_img(imageUrl))", self.catscr)

    def test_named_hymn_list_leaf(self):
        self.assertIn("class MezmurSubListScreen", self.catscr)
        self.assertIn("MezmurSubListScreen(", self.lib)  # reachable

    def test_shell_hides_main_bar_for_hymn_library(self):
        self.assertIn("hideMainNav", self.shell)
        self.assertIn("_selectTabById('home')", self.shell)
        self.assertIn("onBack: () => _selectTabById('home')", self.shell)
        # the library carries the back icon when hosted in the shell
        self.assertIn("this.onBack", self.lib)
        self.assertIn("Back to home", self.lib)


class CoverColorAndUxStateTests(unittest.TestCase):
    """P32 (UI/UX audit): interaction-state polish everywhere (subtle
    hover layers, visible focus, reduced-motion), plus the cover-color
    system — image preview before upload, gradient picker with live
    preview, clear-to-auto, and remove-image."""

    @classmethod
    def setUpClass(cls):
        R = ROOT
        cls.page = (R / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.js = (R / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.css = (R / "themes/components.css").read_text(encoding="utf-8")
        cls.svc = (R / "admin/backend/services/MezmurHymnService.php").read_text(encoding="utf-8")
        cls.route = (R / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        M = R / "Mobile/wbws_flutter_app/lib"
        cls.db = (M / "services/local_db.dart").read_text(encoding="utf-8")
        cls.store = (M / "services/hymn_store.dart").read_text(encoding="utf-8")
        cls.cats = (M / "screens/mezmur/mezmur_categories.dart").read_text(encoding="utf-8")
        cls.hymns = (M / "screens/mezmur/mezmur_hymns.dart").read_text(encoding="utf-8")

    # ── interaction states (hover / focus / motion) ────────────
    def test_hover_and_focus_states_present(self):
        self.assertIn(".mz-swatch:hover", self.css)
        self.assertIn(".mz-swatch:focus-visible", self.css)
        self.assertIn(".mz-mgr tbody tr:hover", self.css)
        self.assertIn(".mz-pick-item label:focus-within", self.css)
        self.assertIn(".btn-primary:active", self.css)

    def test_reduced_motion_covers_new_elements(self):
        block = self.css[self.css.index("@media (prefers-reduced-motion: reduce)"):]
        self.assertIn(".mz-swatch", block)

    # ── image preview + upload fix ─────────────────────────────
    def test_image_preview_before_upload(self):
        self.assertIn('id="mzImageDialog"', self.page)
        self.assertIn("createObjectURL", self.js)
        self.assertIn("revokeObjectURL", self.js)
        # the browser upload actually reaches the action (latent P30
        # bug: the FormData carried no action)
        # P66: the kind is captured BEFORE the imgPick reset (the old
        # imgPick.kind read raced the reset below it); art has its own
        # FormData branch, zem/cat keeps the ternary fallback.
        self.assertIn("var kind = imgPick.kind;", self.js)
        self.assertIn("fda.append('action', 'art_upload');", self.js)
        self.assertIn("fd.append('action', kind === 'zem' ? 'zemarian_image' : 'category_image');", self.js)

    # ── gradient picker ────────────────────────────────────────
    def test_color_dialog_markup(self):
        self.assertIn('id="mzColorDialog"', self.page)
        self.assertIn('id="mzSwatches"', self.page)
        self.assertIn('type="color"', self.page)
        self.assertIn('id="mzGradAuto"', self.page)   # clear to auto
        self.assertIn('id="mzRemoveImg"', self.page)  # transparency path

    def test_pinned_gradient_wins_in_js(self):
        self.assertIn("function gradOf(item)", self.js)
        self.assertIn("item.gradient_start && item.gradient_end", self.js)

    def test_inline_edit_keyboard_behavior(self):
        self.assertIn("initInlineKeys", self.js)      # Enter commits
        self.assertIn("e.key === 'Escape'", self.js)

    # ── server gradient contract ───────────────────────────────
    def test_service_gradient_contract(self):
        self.assertIn("gradientsReady", self.svc)
        self.assertIn("hexColorOrNull", self.svc)
        self.assertIn("removeCategoryImage", self.svc)
        self.assertIn("wantsColors", self.svc)        # absent keys = untouched
        self.assertIn("colorsChanged", self.svc)      # color-only edits allowed
        self.assertIn("Colors must be hex like", self.svc)

    def test_sql035_and_debrand(self):
        self.assertTrue((ROOT / "sql/035_mezmur_category_gradient.sql").exists())
        self.assertNotIn("Spotify", (ROOT / "sql/034_mezmur_subcategories.sql").read_text(encoding="utf-8"))

    # ── mobile gradient system ─────────────────────────────────
    def test_mobile_gradient_pipeline(self):
        self.assertTrue((ROOT / "Mobile/wbws_flutter_app/lib/utils/cover_palette.dart").exists())
        self.assertIn("version: localDatabaseSchemaVersion,", self.db)
        self.assertIn("gradient_start", self.db)           # columns + upserts
        self.assertIn("coverColors", self.hymns)           # shared util used
        self.assertIn("Cover color", self.cats)            # manager entry
        self.assertIn("_hexOrNull", self.store)            # offline validation
        self.assertIn("removeCategoryImage", self.store)

    def test_rest_remove_image_route_gated(self):
        self.assertIn("category-image-remove", self.route)


class SyncFixAndCascadeParityTests(unittest.TestCase):
    """P33: the four owner-reported defects — broken image upload sync
    (multipart content-type), editor cascade + styling parity with the
    web, opacity control in the cover-color pickers, and the header
    back-icon/title overlap."""

    @classmethod
    def setUpClass(cls):
        R = ROOT
        M = R / "Mobile/wbws_flutter_app/lib"
        cls.api = (M / "services/api_service.dart").read_text(encoding="utf-8")
        cls.store = (M / "services/hymn_store.dart").read_text(encoding="utf-8")
        cls.editor = (M / "screens/mezmur/mezmur_hymn_editor.dart").read_text(encoding="utf-8")
        cls.catscr = (M / "screens/mezmur/mezmur_category_screen.dart").read_text(encoding="utf-8")
        cls.mgr = (M / "screens/mezmur/mezmur_categories.dart").read_text(encoding="utf-8")
        cls.page = (R / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.js = (R / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.svc = (R / "admin/backend/services/MezmurHymnService.php").read_text(encoding="utf-8")

    def test_multipart_upload_content_type(self):
        # the JSON content-type broke the multipart boundary -> the
        # server saw no file at all (every app upload failed)
        self.assertIn("hs.remove('Content-Type')", self.api)

    def test_upload_applies_locally_immediately(self):
        self.assertIn("'image_url': url", self.store)   # server-confirmed
        self.assertIn("'image_url': ''", self.store)    # removal clears

    def test_editor_styling_toolbar(self):
        self.assertIn("_styleToolbar()", self.editor)
        self.assertIn("_wrapSelection('**', '**')", self.editor)  # bold
        self.assertIn("_wrapSelection('__', '__')", self.editor)  # underline
        self.assertIn("_insertSection", self.editor)

    def test_header_never_overlaps_back_icon(self):
        # back pinned top, title pinned bottom — impossible to collide
        self.assertGreaterEqual(
            self.catscr.count("mainAxisAlignment: MainAxisAlignment.spaceBetween"), 2)

    def test_opacity_control_web(self):
        self.assertIn('id="mzGradStartOp"', self.page)
        self.assertIn('id="mzGradEndOp"', self.page)
        self.assertIn("function withAlpha", self.js)
        self.assertIn("mz-checker", self.js)          # checkerboard preview

    def test_manager_reorder_parity(self):
        # web has up/down arrows; the app menu matches (offline swap)
        self.assertIn("Move up", self.mgr)
        self.assertIn("Future<void> _move(", self.mgr)

    def test_opacity_control_mobile(self):
        self.assertIn("opStart", self.mgr)
        self.assertIn("_opOf", self.mgr)
        self.assertIn("_CheckerPainter", self.mgr)    # transparency preview

    def test_server_alpha_and_sync_bumps(self):
        self.assertIn("{8}", self.svc)  # #rrggbbaa accepted
        self.assertIn("gradient_start=?, gradient_end=?, updated_at=NOW()", self.svc)
        self.assertIn("image_path = ?, updated_at = NOW()", self.svc)
        self.assertTrue((ROOT / "sql/036_mezmur_gradient_alpha.sql").exists())


class AuditRegressionTests(unittest.TestCase):
    """P31e audit locks: each assertion below pins a defect found by
    the detailed self-audit, so it can never quietly return."""

    @classmethod
    def setUpClass(cls):
        R = ROOT
        cls.js = (R / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.cats = (
            R / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_categories.dart"
        ).read_text(encoding="utf-8")
        cls.plist = (R / "Mobile/wbws_flutter_app/ios/Runner/Info.plist").read_text(encoding="utf-8")

    def test_schema_sync_has_no_confirm_loop(self):
        # migrateRun must be confirm-free (the confirm lives in
        # migrateSchema); this shape re-entered itself endlessly.
        self.assertNotIn("function () { migrateRun(); }", self.js)
        self.assertEqual(self.js.count("sysConfirm('Align the mezmur"), 1)

    def test_hymn_save_enforces_category(self):
        self.assertIn("Choose a category and sub-category", self.js)
        self.assertIn("— Select sub-category —", self.js)  # placeholder

    def test_hymn_draft_protected_on_catalog_jump(self):
        self.assertIn("hymnFormHasDraft", self.js)
        self.assertIn("Unsaved changes will be lost.", self.js)

    def test_mobile_image_upload_guards(self):
        self.assertIn("still syncing", self.cats)      # placeholder ids
        self.assertIn("Go online once", self.cats)     # offline

    def test_ios_photo_permission_declared(self):
        # image_picker crashes on device without the usage description
        self.assertIn("NSPhotoLibraryUsageDescription", self.plist)


class MobileManagerParityTests(unittest.TestCase):
    """P31c (mobile): the category manager reaches two-level parity —
    subs creatable offline (queued ops carry parent_id), cover images
    upload through the hardened REST route, and local writes never
    flatten the hierarchy."""

    @classmethod
    def setUpClass(cls):
        M = ROOT / "Mobile/wbws_flutter_app/lib"
        cls.store = (M / "services/hymn_store.dart").read_text(encoding="utf-8")
        cls.db = (M / "services/local_db.dart").read_text(encoding="utf-8")
        cls.api = (M / "services/api_service.dart").read_text(encoding="utf-8")
        cls.screen = (M / "screens/mezmur/mezmur_categories.dart").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")

    def test_rest_image_route_reuses_hardened_service(self):
        self.assertIn("category-image", self.route)
        self.assertIn("uploadCategoryImage", self.route)
        self.assertIn("is_uploaded_file", self.route)   # multipart only
        self.assertIn("apiRoleIs($auth, $MEZMUR_LIBRARY_WRITE_ROLES)", self.route)

    def test_category_save_carries_parent(self):
        self.assertIn("'parent_id': parentId", self.store)      # op payload
        self.assertIn("A sub-category with this name", self.store)  # scoped dup
        # dup check compares the parent scope, not just the name
        self.assertIn("_asInt(c['parent_id']) == (parentId ?? 0)", self.store)

    def test_local_upsert_preserves_hierarchy(self):
        # rename/hide must not wipe parent_id / image_url
        self.assertIn("prev['parent_id']", self.db)
        self.assertIn("prev['image_url']", self.db)

    def test_manager_is_two_level_with_images(self):
        self.assertIn("_subsOf(", self.screen)
        self.assertIn("Add sub-category", self.screen)
        self.assertIn("ImageSource.gallery", self.screen)
        self.assertIn("setCategoryImage", self.screen)

    def test_api_multipart_upload(self):
        self.assertIn("uploadCategoryImage", self.api)
        self.assertIn("MultipartRequest", self.api)
        self.assertIn("_headers(withAuth: true)", self.api)  # bearer attached


class CatalogManagerTests(unittest.TestCase):
    """P31 (web): standalone catalog management section, cascading hymn
    form, and a strict no-browser-popups rule — every interaction is
    handled by the system's own UI."""

    @classmethod
    def setUpClass(cls):
        R = ROOT
        cls.page = (R / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.js = (R / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.css = (R / "themes/components.css").read_text(encoding="utf-8")

    def test_standalone_catalog_section(self):
        # the section must be REACHABLE: sidebar item + mobile bottom
        # bar button + the section itself (a section with no nav item
        # once shipped invisibly — never again).
        self.assertRegex(
            self.page,
            r'school-nav-link" data-section="catalog"')
        self.assertRegex(
            self.page,
            r'school-bottom-nav-btn" data-section="catalog"')
        self.assertIn('id="section-catalog"', self.page)
        self.assertIn("mzMgrCatRows", self.page)                    # categories table
        self.assertIn("mzMgrZemRows", self.page)                    # singers table
        self.assertIn("mzMgrMainName", self.page)                   # add-main form
        self.assertIn(".mz-mgr-thumb", self.css)

    def test_catalog_modal_retired(self):
        self.assertNotIn("mzCatalogModal", self.page)
        self.assertNotIn("window.prompt", self.js)

    def test_cascading_hymn_category_selects(self):
        self.assertIn('id="mzHymnMainCat"', self.page)
        self.assertIn('id="mzHymnSubCat"', self.page)
        self.assertIn("populateHymnCats", self.js)
        self.assertIn("hymnSubOptions", self.js)
        # sub list is filtered by the chosen main
        self.assertIn("mgrSubsOf(Number(mainId))", self.js)
        self.assertIn("selectedCategoryIds()", self.js)  # save uses the cascade

    def test_no_browser_popups_anywhere(self):
        for banned in ("window.prompt", "window.confirm", "window.alert", "alert("):
            self.assertNotIn(banned, self.js)
        self.assertIn("sysConfirm", self.js)               # in-system confirm
        self.assertIn('id="mzSysDialog"', self.page)
        self.assertIn("mzSecPop", self.page)               # editor popover
        self.assertIn("toggleSecPop", self.js)

    def test_manager_is_inline_and_complete(self):
        for fn in ("mgrAddMain", "mgrAddSubOpen", "mgrSave", "mgrToggle",
                   "mgrSort", "mgrImage", "mgrAddZem"):
            self.assertIn(fn, self.js)
        # cover uploads reuse the hardened service path
        self.assertIn("uploadCategoryImage", (ROOT / "admin/backend/services/MezmurHymnService.php").read_text(encoding="utf-8"))
        self.assertIn("mgrImage", self.js)


class SubcategoryClientTests(unittest.TestCase):
    """P30 (client phase): two-level browse UX everywhere.
    Web: compact one-row toolbar, grouped dropdown pickers, the visual
    lyrics editor (portable markup contract), no third-party company
    names in the UI. Mobile: bottom section navigation, full-screen
    category pages with covers, rolled-up counts/filters, v17 cache."""

    @classmethod
    def setUpClass(cls):
        R = ROOT
        cls.page = (R / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.js = (R / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.css = (R / "themes/components.css").read_text(encoding="utf-8")
        base = R / "Mobile/wbws_flutter_app/lib"
        cls.hymns = (base / "screens/mezmur/mezmur_hymns.dart").read_text(encoding="utf-8")
        cls.editor = (base / "screens/mezmur/mezmur_hymn_editor.dart").read_text(encoding="utf-8")
        cls.detail = (base / "screens/mezmur/mezmur_hymn_detail.dart").read_text(encoding="utf-8")
        cls.db = (base / "services/local_db.dart").read_text(encoding="utf-8")

    # ── web ──
    def test_web_compact_toolbar(self):
        self.assertIn("toolbar-compact", self.page)
        self.assertIn(".toolbar-compact { flex-wrap: nowrap; }", self.css)

    def test_web_grouped_dropdown_pickers(self):
        self.assertIn("mz-pick-btn", self.page)
        self.assertIn("mz-pick-panel", self.css)
        self.assertIn("optgroup", self.js)  # two-level filter
        self.assertIn("renderFilterSelects", self.js)

    def test_web_visual_editor(self):
        self.assertIn('id="mzEditor"', self.page)
        self.assertIn("contenteditable", self.page)
        self.assertIn("markupToHtml", self.js)
        self.assertIn("editorToMarkup", self.js)
        # markup stays the storage contract (old clients unaffected)
        self.assertIn("execCommand", self.js)
        self.assertIn("getData('text/plain')", self.js)  # paste = plain
        self.assertIn("mz-ed-sec", self.css)
        # viewer renders the underline tier too
        self.assertIn("replace(/__(.+?)__/g, '<u>$1</u>')", self.js)

    def test_no_company_names_in_ui(self):
        for src in (self.page, self.js, self.editor, self.hymns, self.detail):
            self.assertNotIn("Genius", src)
            self.assertNotIn("Spotify", src)

    # ── mobile ──
    def test_mobile_section_bottom_nav(self):
        self.assertIn("bottomNavigationBar: NavigationBar(", self.hymns)
        self.assertNotIn("bottom: TabBar(", self.hymns)  # top tabs retired

    def test_mobile_fullscreen_category_pages(self):
        self.assertTrue((ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_category_screen.dart").exists())
        self.assertIn("MezmurCategoryScreen(", self.hymns)
        self.assertIn("image_url", self.db)   # covers cached on-device
        self.assertIn("version: localDatabaseSchemaVersion,", self.db)

    def test_mobile_rollup(self):
        # local filter + counts roll a MAIN over its subs
        self.assertIn("OR cc.category_id IN (SELECT id FROM cached_mezmur_categories WHERE parent_id = ?)", self.db)
        self.assertIn("parent_id = c.id", self.db)  # rolled-up counts

    def test_mobile_editor_groups_subs(self):
        # P33: cascading picks (web parity) — category first, then one
        # of ITS subs; the old grouped multi-pick is gone.
        self.assertIn("_subCategoryField", self.editor)
        self.assertIn("_syncSelected()", self.editor)
        self.assertIn("Choose a category and sub-category", self.editor)
        self.assertNotIn("_pickCategories", self.editor)

    def test_mobile_underline_parity(self):
        self.assertIn("__ (.+?) __|".replace(" ", ""), self.detail.replace(" ", "")[:0] or self.detail) if False else None
        self.assertRegex(self.detail, r"__\(\.\+\?\)__")


class SubcategoryServerTests(unittest.TestCase):
    """P30 (server phase): two-level taxonomy — main category -> subs.
    Hymns live at the leaves; filtering by a main rolls up over its
    subs; images are uploaded through the OWASP-hardened path."""

    @classmethod
    def setUpClass(cls):
        R = ROOT
        cls.svc = (R / "admin/backend/services/MezmurHymnService.php").read_text(encoding="utf-8")
        cls.api = (R / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.mig = (R / "sql/034_mezmur_subcategories.sql").read_text(encoding="utf-8")

    def test_migration_two_level_structure(self):
        self.assertIn("ADD COLUMN IF NOT EXISTS parent_id", self.mig)
        self.assertIn("ADD COLUMN IF NOT EXISTS image_path", self.mig)
        self.assertIn("uq_mc_parent_name (parent_id, name)", self.mig)
        self.assertIn("fk_mc_parent", self.mig)
        # every existing main gets a General sub; links move to leaves
        self.assertIn("'አጠቃላይ', c.id", self.mig)
        self.assertIn("c.parent_id IS NULL", self.mig)

    def test_rollup_filter_everywhere(self):
        for src in (self.svc, self.api):
            self.assertIn("(mc2.id = ? OR mc2.parent_id = ?)", src)

    def test_depth_two_enforced(self):
        self.assertIn("two levels maximum", self.svc)
        self.assertIn("cannot become a sub-category itself", self.svc)
        self.assertIn("parent_id <=> ?", self.svc)  # scoped uniqueness

    def test_secure_image_upload(self):
        self.assertIn("finfo(FILEINFO_MIME_TYPE)", self.svc)      # magic bytes
        self.assertIn("imagecreatefromstring", self.svc)          # full decode
        self.assertIn("imagepng($img, $dest, 6)", self.svc)       # re-encode strips payloads
        self.assertIn("bin2hex(random_bytes(16))", self.svc)      # random name
        self.assertIn("2 * 1024 * 1024", self.svc)                # size cap
        self.assertIn("case 'category_image':", self.api)
        self.assertIn("case 'zemarian_image':", self.api)  # P34


class TokenizerRegressionTests(unittest.TestCase):
    """P27c: guards against the tokenizer regression shipped in the
    on-device word-index commit ([\\\\p{L}...] with QUADRUPLE backslashes
    matched only the literal characters p/{/L/M/N, so every real query
    produced zero tokens and local search silently died — Amharic and
    English alike). Pins the corrected regex + the surrounding search
    plumbing that must never regress again."""

    @classmethod
    def setUpClass(cls):
        cls.db = (ROOT / "Mobile/wbws_flutter_app/lib/services/local_db.dart").read_text(encoding="utf-8")
        cls.api = (ROOT / "Mobile/wbws_flutter_app/lib/services/api_service.dart").read_text(encoding="utf-8")
        cls.store = (ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart").read_text(encoding="utf-8")

    def test_unicode_tokenizer_regex_single_backslash(self):
        # Raw string needs exactly ONE backslash for a unicode class.
        self.assertIn("[\\p{L}\\p{M}\\p{N}]+", self.db)

    def test_quadruple_backslash_regression_absent(self):
        # The broken form that matched only literal p/{/L/M/N characters.
        self.assertNotIn("[\\\\p{L}", self.db)

    def test_tokenizer_drops_single_char_tokens(self):
        # Server parity: WORD_MIN_CHARS = 2 (a 1-char prefix LIKE would
        # return half the library).
        self.assertRegex(self.db, r"length >= 2")

    def test_mezmur_root_payload_parsing(self):
        # Mezmur endpoints return items at the root (no data envelope);
        # without the ?? json fallback every list parsed as null.
        self.assertIn("final data = json['data'];", self.api)
        self.assertIn("data ?? json", self.api)

    def test_sparse_index_full_scan_fallback(self):
        # The word index only finds PREFIX hits — a typo can never match
        # it, so sparse index results must fall back to the bounded full
        # scan under the same structural filters (server two-stage parity).
        self.assertIn("candidates.length < 25", self.store)

    def test_flutter_tokenizer_unit_test_exists(self):
        # Real `flutter test` guard (runs on dev machines / CI).
        self.assertTrue(
            (ROOT / "Mobile/wbws_flutter_app/test/search_tokenizer_test.dart").exists())


class UnifiedSearchFixTests(unittest.TestCase):
    """Patch 27 (2026-09-01): the search chain actually reaches the user.

    File-by-file line-by-line audit findings, both root causes of the
    user's "search still not working":
    - mezmur_hymns.dart:127 searched ONLY the on-device SQLite; lyrics
      blobs download lazily (15/sync cycle), so most cached rows had no
      lyrics locally -> searching a lyrics word returned nothing even
      ONLINE (the P25 server engine was never asked).
    - layouts/base.php:203 loaded the page JS with NO cache-buster
      (the CSS lines above had one) -> browsers could run a stale
      mezmur.js forever, keeping pre-P22 keystroke-dropping behavior.

    Fix (Telegram/Spotify model): searchHymnsUnified merges the instant
    local index with the server word index while online (dedupe by id,
    pending-edit rows authoritative, server-discovered rows upserted),
    with a stale-response guard in the screen; the page JS include now
    carries ?v=filemtime like the CSS.
    """

    @classmethod
    def setUpClass(cls):
        cls.store = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart"
        ).read_text(encoding="utf-8")
        cls.api = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/api_service.dart"
        ).read_text(encoding="utf-8")
        cls.lib = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymns.dart"
        ).read_text(encoding="utf-8")
        cls.layout = (ROOT / "frontend/layouts/base.php").read_text(encoding="utf-8")

    def test_store_merges_local_and_server_search(self):
        self.assertIn("Future<List<Map<String, dynamic>>> searchHymnsUnified(", self.store)
        self.assertIn("ConnectivityService().hasLink", self.store)
        self.assertIn("await _api.getMezmurHymns(", self.store)
        # queued local edits stay authoritative (same rule as delta pulls)
        self.assertIn("await _db.upsertHymns(serverItems, protectIds: protect);", self.store)
        # 1-char queries never search
        self.assertIn("if (q.length < 2) return local;", self.store)

    def test_screen_routes_search_through_unified_store(self):
        self.assertIn("await _store.searchHymnsUnified(", self.lib)
        # stale guard: a slower server response can't clobber a newer query
        self.assertIn("if (searching && _searchCtrl.text.trim() != query.trim()) return;", self.lib)

    def test_api_client_carries_the_filters(self):
        self.assertIn("'per_page': '$perPage'", self.api)
        self.assertIn("params['category_id'] = '$categoryId';", self.api)
        self.assertIn("params['zemarian_id'] = '$zemarianId';", self.api)

    def test_page_js_is_cache_busted(self):
        self.assertIn(".js?v=<?= filemtime(ROOT_PATH", self.layout)



class ZemarianImagesAndCatalogCollapseTests(unittest.TestCase):
    """P34: singer cover images (web + mobile, same hardened chain as
    categories), a collapsed-mains catalog manager, and exactly ONE
    professional filter system in the hymn library (dropdowns only)."""

    @classmethod
    def setUpClass(cls):
        R = lambda rel: (ROOT / rel).read_text(encoding="utf-8")  # noqa: E731
        cls.js = R("frontend/js/mezmur.js")
        cls.page = R("frontend/pages/mezmur_dept.php")
        cls.css = R("themes/components.css")
        cls.svc = R("admin/backend/services/MezmurHymnService.php")
        cls.api = R("admin/api_mezmur.php")
        cls.route = R("api/v1/routes/mezmur.php")
        cls.db = R("Mobile/wbws_flutter_app/lib/services/local_db.dart")
        cls.apisvc = R("Mobile/wbws_flutter_app/lib/services/api_service.dart")
        cls.store = R("Mobile/wbws_flutter_app/lib/services/hymn_store.dart")
        cls.zems = R("Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_zemarians.dart")
        cls.hymns = R("Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_hymns.dart")
        cls.sql37 = R("sql/037_zemarian_images.sql")

    # ── web catalog: collapsed mains ────────────────────────────
    def test_catalog_mains_collapse(self):
        self.assertIn("mgrToggleOpen", self.js)
        self.assertIn("mz-cmgr-exp", self.js)
        self.assertIn("aria-expanded=", self.js)  # a11y state
        self.assertIn("mgr.open[id] = !mgr.open[id]", self.js)
        self.assertIn("if (!open) return; // collapsed: subs hidden until asked", self.js)
        self.assertIn(".mz-cmgr-exp.open i { transform: rotate(90deg); }", self.css)
        self.assertIn("prefers-reduced-motion", self.css)  # chevron anim opt-out

    def test_add_sub_forces_expansion(self):
        self.assertIn("mgr.open[mainId] = true; // adding reveals the pane", self.js)

    # ── one filter system: dropdowns only ───────────────────────
    def test_library_single_filter_system(self):
        # the old duplicate browse (tabs + chips) is gone for good
        self.assertNotIn("function tab(mode)", self.js)
        self.assertNotIn("function renderBrowse", self.js)
        self.assertNotIn("mzBrowseList", self.js)
        self.assertNotIn("mz-tab", self.page)
        self.assertNotIn('id="mzBrowse"', self.page)
        # the singer dropdown exists and drives the same lib state
        self.assertIn('id="mzZemarianFilter"', self.page)
        self.assertIn("function renderFilterSelects", self.js)
        self.assertIn("'mzZemarianFilter'", self.js)  # wired in clearFilters
        self.assertIn("lib.zemarianId = parseInt(this.value, 10) || 0", self.js)
        # view-modal browse shortcuts keep the selects in sync
        self.assertIn("function applyFilterState() {", self.js)  # shortcuts ride the same pipeline
        self.assertIn("browseCategory: browseCategory", self.js)  # still exported
        # the library toolbar is the ONLY browse surface: it holds all
        # five selects (search box + category + singer + status + length
        # + language) and the page has no other tab/chip browse markup
        toolbar = self.page.split('id="mzSearch"')[1].split('<table')[0]
        for sel_id in ("mzCategoryFilter", "mzZemarianFilter", "mzStatusFilter",
                       "mzLengthFilter", "mzLanguageFilter"):
            self.assertIn(sel_id, toolbar)

    # ── singer images: server chain ─────────────────────────────
    def test_service_image_chain_generalized(self):
        self.assertIn("private static function taxonomyImageStore(", self.svc)
        self.assertIn("private static function taxonomyImageDrop(", self.svc)
        for w in ["uploadCategoryImage", "uploadZemarianImage",
                  "removeCategoryImage", "removeZemarianImage"]:
            self.assertIn(f"public static function {w}(", self.svc)
        # singers keep their own directory + audit entity
        self.assertIn("'uploads/' . $dirName", self.svc)
        self.assertIn("'Mezmur Singer Image Updated', 'mezmur_zemarian'", self.svc)
        self.assertIn("'Mezmur Singer Image Removed', 'mezmur_zemarian'", self.svc)

    def test_list_zemarians_serves_image_url(self):
        self.assertIn("z.image_path, z.sort_order", self.svc)
        self.assertIn("self::categoryImageUrl($r['image_path'] ?? null)", self.svc)
        self.assertIn("unset($r['image_path'])", self.svc)

    def test_sql37_migration(self):
        self.assertIn("mezmur_zemarians", self.sql37)
        self.assertIn("image_path", self.sql37)

    def test_web_and_rest_routes(self):
        self.assertIn("case 'zemarian_image':", self.api)
        self.assertIn("case 'zemarian_image_remove':", self.api)
        self.assertIn("action === 'zemarian-image'", self.route)
        self.assertIn("action === 'zemarian-image-remove'", self.route)
        # multipart guard: a real uploaded file is required
        self.assertIn("is_uploaded_file($zfile['tmp_name'] ?? '')", self.route)
        # role + rate-limit gates on both new routes
        # (P66 added /mezmur/art + /mezmur/art-remove: 14 -> 16)
        self.assertEqual(self.route.count("apiRoleIs($auth, $MEZMUR_LIBRARY_WRITE_ROLES)"), 16)

    # ── singer images: mobile ───────────────────────────────────
    def test_mobile_local_schema_v19(self):
        self.assertIn("version: localDatabaseSchemaVersion,", self.db)
        self.assertIn(
            "ALTER TABLE cached_mezmur_zemarians ADD COLUMN image_url TEXT NULL", self.db)
        self.assertIn("image_url TEXT NULL,", self.db)  # fresh installs
        # sync carries the url; local rename/hide must not wipe it
        self.assertIn("'image_url': z['image_url']", self.db)
        self.assertIn("prev['image_url']", self.db)

    def test_mobile_upload_uses_shared_multipart(self):
        self.assertIn("_uploadTaxonomyImage(", self.apisvc)
        self.assertIn("uploadZemarianImage(int id, String filePath)", self.apisvc)

    def test_mobile_store_and_screens(self):
        self.assertIn("Future<String?> setZemarianImage(", self.store)
        self.assertIn("Future<String?> removeZemarianImage(", self.store)
        self.assertIn("upsertZemarianLocal({'id': id, 'image_url': url})", self.store)
        self.assertIn("Future<void> _pickImage(", self.zems)
        self.assertIn("_removeImage(", self.zems)
        self.assertIn("PopupMenuButton<String>", self.zems)
        self.assertIn("case 'removeimg':", self.zems)
        # browse tiles show singer covers now
        self.assertIn("singers carry covers too (P34)", self.hymns)

    def test_no_popup_dialogs_added(self):
        for f in (self.js, self.zems):
            for banned in ("window.prompt(", "window.confirm(", "window.alert("):
                self.assertNotIn(banned, f)
            self.assertNotIn(" alert(", f)
            self.assertNotIn(" confirm(", f)
            self.assertNotIn(" prompt(", f)


class AmharicOnlySingerNamesAndFilterRaceFixTests(unittest.TestCase):
    """P35: ONE Amharic name field for singers everywhere (mirrored into
    name_am server-side) and the library singer-filter race fix (the
    dropdown populated from the categories promise while its own data
    arrived in a parallel one, so it never filled)."""

    @classmethod
    def setUpClass(cls):
        R = lambda rel: (ROOT / rel).read_text(encoding="utf-8")  # noqa: E731
        cls.js = R("frontend/js/mezmur.js")
        cls.page = R("frontend/pages/mezmur_dept.php")
        cls.svc = R("admin/backend/services/MezmurHymnService.php")
        cls.zems = R("Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_zemarians.dart")

    def test_filter_state_machine_architecture(self):
        # P37: one loadCatalog -> Promise.allSettled -> ONE render pass
        # -> visible reconcile. The dropdowns can never silently fail.
        self.assertNotIn("renderCatalogList();", self.js)  # P31 dead call (comment may mention it)
        lc = self.js[self.js.index("function loadCatalog"):][:1200]
        self.assertIn("Promise.allSettled([catsP, zemsP])", lc)
        self.assertEqual(lc.count("renderFilterSelects();"), 1)
        self.assertIn("if (reconcileFilters()) loadList();", lc)
        # pure render: no reloads from inside the renderer
        rf = self.js[self.js.index("function renderFilterSelects"):self.js.index("function activeCategory")]
        self.assertNotIn("loadList()", rf)
        # reconcile is the only automatic drop, and it announces itself
        rec = self.js[self.js.index("function reconcileFilters"):self.js.index("function applyFilterState")]
        self.assertIn("window.toast('Filter cleared", rec)
        # view-modal shortcuts ride the same pipeline
        self.assertIn("function applyFilterState() {", self.js)

    def test_single_amharic_name_field_web(self):
        self.assertNotIn("mzMgrZemNameAm", self.page)
        self.assertNotIn("mzMgrEditNameAm", self.js)
        self.assertNotIn("ስም በአማርኛ</th>", self.page)  # no twin column
        self.assertIn('class="school-input amharic" maxlength="100" placeholder="የዘማሪያን ስም', self.page)
        # both save paths mirror the single value into name_am
        self.assertIn("{ action: 'save_zemarian', name: name, name_am: name }", self.js)
        self.assertIn("{ action: 'save_zemarian', id: id, name: name, name_am: name }", self.js)

    def test_single_amharic_name_field_mobile(self):
        self.assertNotIn("amCtrl", self.zems)
        self.assertIn("'name_am': nameCtrl.text,", self.zems)
        self.assertIn("labelText: 'ስም — singer name (Amharic)'", self.zems)

    def test_service_mirrors_name_am(self):
        self.assertIn("if ($nameAm === '') $nameAm = $name;", self.svc)
        # NULL default is gone (name_am can no longer drift empty)
        self.assertNotIn("$nameAm = $nameAm === '' ? null : $nameAm;", self.svc)


class LatestUpdatesAuditTests(unittest.TestCase):
    """P36 audit guards: findings from the detailed audit of P34+P35."""

    @classmethod
    def setUpClass(cls):
        R = lambda rel: (ROOT / rel).read_text(encoding="utf-8")  # noqa: E731
        cls.js = R("frontend/js/mezmur.js")
        cls.sheet = R("Mobile/wbws_flutter_app/lib/widgets/taxonomy_pick_sheet.dart")
        cls.sql37 = R("sql/037_zemarian_images.sql")

    def test_picker_no_duplicate_subtitle_for_mirrored_names(self):
        # singers have ONE Amharic name (name_am == name) — the sheet
        # must not print the same word twice
        self.assertIn("it['name_am'] == it['name']", self.sheet)

    def test_editor_picker_singer_counts(self):
        # singers carry hymn_count (no hymn_count_total/parent_id) —
        # the count shown must fall back to it, never a hardcoded 0
        self.assertIn(
            "item.hymn_count_total != null ? item.hymn_count_total : (item.hymn_count || 0)",
            self.js)

    def test_sql37_comment_matches_reality(self):
        # singers have their OWN upload dir (not the categories')
        self.assertIn("uploads/mezmur_zemarians/", self.sql37)
        self.assertNotIn("share the uploads/mezmur_categories", self.sql37)


class FilteringDeepAuditTests(unittest.TestCase):
    """P36 deep audit: the library filter matrix and every singer
    endpoint, end to end (admin + REST + mobile local query)."""

    @classmethod
    def setUpClass(cls):
        R = lambda rel: (ROOT / rel).read_text(encoding="utf-8")  # noqa: E731
        cls.js = R("frontend/js/mezmur.js")
        cls.admin = R("admin/api_mezmur.php")
        cls.svc = R("admin/backend/services/MezmurHymnService.php")
        cls.route = R("api/v1/routes/mezmur.php")
        cls.db = R("Mobile/wbws_flutter_app/lib/services/local_db.dart")

    def test_status_all_is_honest(self):
        # '' must NOT force active-only on the admin surface (the web
        # dropdown's "All" option sends '') — REST semantics win
        self.assertNotIn("default view: active only", self.admin)

    def test_selects_self_heal(self):
        # both filter selects sync from lib state and drop a filter
        # whose row vanished (hidden/removed) instead of silently
        # filtering under an "All …" label
        self.assertIn("function reconcileFilters()", self.js)
        self.assertIn("!activeCategory(lib.categoryId)", self.js)
        self.assertIn("!activeZemarian(lib.zemarianId)", self.js)
        self.assertIn("if (reconcileFilters()) loadList();", self.js)

    def test_filter_semantics_parity_admin_rest_local(self):
        # roll-up + singer EXISTS filters present in all three layers
        for blob, name in ((self.admin, "admin"), (self.svc, "service")):
            self.assertIn("mc2.id = ? OR mc2.parent_id = ?", blob, name)
            self.assertIn("mhz.zemarian_id = ?", blob, name)
        self.assertIn(
            "cc.category_id = ? OR cc.category_id IN (SELECT id FROM cached_mezmur_categories WHERE parent_id = ?)",
            self.db, "mobile local")
        self.assertIn("cz.zemarian_id = ?", self.db, "mobile local")

    def test_rest_passes_filters_through(self):
        self.assertIn("MezmurHymnService::listHymns($conn, $_GET)", self.route)

    def test_singer_routes_gated(self):
        # every singer write route: role gate + rate limit; reads limited
        for a in ("'zemarian'", "'zemarian-status'", "'zemarian-image'", "'zemarian-image-remove'"):
            i = self.route.index(f"=== {a}")
            block = self.route[i:i + 700]
            self.assertIn("apiRoleIs($auth, $MEZMUR_LIBRARY_WRITE_ROLES)", block, a)
            self.assertIn("isApiRateLimited(", block, a)
        i = self.route.index("=== 'zemarians'")
        self.assertIn("isApiRateLimited('mezmur_api_read'", self.route[i:i + 400])

    def test_taxonomy_propagates_via_full_refresh(self):
        # singer/category changes reach devices through the per-pull
        # full-list refresh (the hymn delta feed carries only hymns)
        store = (ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart").read_text(encoding="utf-8")
        pull = store[store.index("Future<void> pullChanges"):][:1600]
        self.assertIn("getMezmurZemarians()", pull)
        self.assertIn("upsertZemarians", pull)

    def test_zemarian_cap_documented(self):
        self.assertIn("LIMIT 500: singers are a small canonical list", self.svc)

    def test_smoke_script_json_quoting(self):
        # bash eats bare inner quotes in double-quoted JSON args — such
        # requests become silent no-ops and their checks pass vacuously
        # (the P35 rename hollow-check incident)
        smoke = (ROOT / "tests/e2e/run_smoke.sh").read_text(encoding="utf-8")
        for ln in smoke.splitlines():
            if "ssms_api" in ln and '"{"' in ln:
                self.fail(f"double-quoted JSON body in smoke: {ln[:80]}")

if __name__ == "__main__":
    unittest.main()


class MezmurDeploymentResilienceTests(unittest.TestCase):
    """Root-cause fixes for the recurring production failures:

    1. A server whose migrations were never run (e.g. sql/024) must
       DEGRADE, never HTTP-500: PHP 8.1 mysqli throws on a missing
       table, so every mezmur_submissions read is wrapped in
       try/catch Throwable.
    2. Clients detect a stale backend via the server_meta version
       marker and show an actionable "update the server" message
       instead of a dead-end error.
    3. POSTs are bounded so the Save button can never hang forever.
    4. action=ping gives administrators a one-request deployment
       health check (code version + every migration + session_id).
    """

    @classmethod
    def setUpClass(cls):
        cls.sub_service = (
            ROOT / "admin/backend/services/MezmurSubmissionService.php"
        ).read_text(encoding="utf-8")
        cls.att_service = (
            ROOT / "admin/backend/services/MezmurAttendanceService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.response = (ROOT / "api/v1/core/response.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.screen = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_attendance.dart"
        ).read_text(encoding="utf-8")

    # ── 1. missing-migration resilience (reads degrade, no 500) ──
    def test_submission_reads_are_exception_safe(self):
        # PHP >= 8.1 throws mysqli_sql_exception from prepare() on a
        # missing table; every packet-table READ must catch Throwable.
        self.assertGreaterEqual(self.sub_service.count("catch (\\Throwable $e)"), 8)
        # the classic read entry points all return a safe fallback
        self.assertIn("ORDER BY id DESC LIMIT 1", self.sub_service)

    def test_missing_table_gives_actionable_save_message(self):
        self.assertIn(
            "sql/024_mezmur_submissions.sql",
            self.sub_service,
            "upsert/review failures must tell admins which migration to run",
        )

    def test_section_sheet_marks_query_is_exception_safe(self):
        # fetchSectionSheet: marks read wrapped so a legacy DB degrades
        # to an unmarked sheet instead of WBSS-U01.
        chunk = self.att_service.split("fetchSectionSheet")[1][:2200]
        self.assertIn("try {", chunk)
        self.assertIn("catch (\\Throwable $e)", chunk)
        self.assertIn("$marks = [];", chunk)

    def test_overview_degrades_to_zeros(self):
        ov = self.api.split("case 'overview'")[1].split("case 'submissions_list'")[0]
        self.assertIn("catch (\\Throwable $e)", ov)
        self.assertIn("'days' => 0, 'marked' => 0, 'attended' => 0", ov)

    def test_schema_probes_handle_php7_false_return(self):
        # PHP 7 returns false from query() instead of throwing.
        self.assertIn("if ($probe === false)", self.api)

    # ── 2. version handshake (detect stale deployments) ─────────
    def test_admin_api_stamps_every_response(self):
        self.assertIn("MEZMUR_API_VERSION", self.api)
        self.assertIn("server_meta", self.api)
        self.assertIn("MEZMUR_SCHEMA_MIN", self.api)

    def test_mobile_route_stamps_responses(self):
        self.assertIn("define('MEZMUR_API_VERSION'", self.route)
        self.assertIn("server_meta", self.response)
        self.assertIn("defined('MEZMUR_API_VERSION')", self.response)

    def test_web_js_explains_generic_server_errors(self):
        self.assertIn("staleHint", self.js)
        self.assertIn("sql/024_mezmur_submissions.sql", self.js)

    def test_app_detects_and_warns_about_stale_server(self):
        # 2026-08-28 UX decision: the ALWAYS-visible "older backend"
        # banner was removed (it covered roster data and went stale);
        # the outdated-server hint now lives only in the error path,
        # and the warning banner family is still used for returns/lock.
        self.assertNotIn("_staleServer", self.screen)
        self.assertIn("sql/024_mezmur_submissions.sql", self.screen)
        self.assertIn("StatusBanner.warning", self.screen)

    # ── 3. bounded POSTs (no more Saving… forever) ──────────────
    def test_post_requests_are_bounded(self):
        self.assertIn("POST_TIMEOUT", self.js)
        self.assertIn("var POST_TIMEOUT = 20000", self.js)
        # apiPost now races against its own timeout
        post_fn = self.js.split("function apiPost")[1][:900]
        self.assertIn("setTimeout", post_fn)

    # ── 4. deployment health endpoint ───────────────────────────
    def test_ping_action_exists_and_checks_all_migrations(self):
        self.assertIn("case 'ping'", self.api)
        ping = self.api.split("case 'ping'")[1].split("case 'stats'")[0]
        for tbl in [
            "mezmur_hymns",
            "mezmur_days",
            "mezmur_attendance",
            "mezmur_attendance_audit",
            "mezmur_submissions",
        ]:
            self.assertIn(tbl, ping)
        self.assertIn("code_version", ping)
        self.assertIn("missing_tables", ping)
        self.assertIn("session_id_nullable", ping)


class MezmurProdDiagTests(unittest.TestCase):
    """Production incident #2 (2026-08-28, evening): the host runs a
    handler that masks any failure as
    {"status":"error","message":"Server error. Please try again.","ref":"#N"}
    — it hijacked even action=ping. Two structural defenses:

    1. backend/api/mezmur.php?diag=1 — dependency-free diagnostic that
       always answers HTTP 200 (unmaskable), reports PHP version, OPcache
       state, parse-checks every mezmur file under the server's own PHP,
       probes every table and the feature constant.
    2. The mezmur controller answers EVERY operational outcome with
       HTTP 200 + a status field, because the host demonstrably mangles
       non-2xx responses (401 came back as a 302 with a plain-text body).
    """

    @classmethod
    def setUpClass(cls):
        cls.shim = (ROOT / "backend/api/mezmur.php").read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")

    def test_diag_endpoint_exists_and_is_unmaskable(self):
        self.assertIn("isset($_GET['diag'])", self.shim)
        self.assertIn("TOKEN_PARSE", self.shim)
        self.assertIn("opcache_get_status", self.shim)
        self.assertIn("MEZMUR_API_VERSION", self.shim)  # disk-version probe
        self.assertIn("FEATURE_MEZMUR", self.shim)
        # diag runs BEFORE the real controller is required
        self.assertLess(
            self.shim.index("isset($_GET['diag'])"),
            self.shim.index("admin/api_mezmur.php"),
        )

    def test_controller_is_200_only(self):
        import re
        bad = re.findall(r"mezmur_respond\([^;]*?,\s*[1-5]\d\d\);", self.api, re.S)
        self.assertEqual(bad, [], "mezmur API must never emit non-2xx (host mangles them)")

    def test_controller_requires_rate_limiter_class(self):
        # The web-failure root cause: the admin bootstrap never loaded
        # SecurityRateLimiter (only api/v1 middleware did), so every
        # request fatals with class-not-found before the try/catch.
        self.assertIn(
            "require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';",
            self.api,
        )

    def test_controller_owns_exception_handler(self):
        self.assertIn("set_exception_handler", self.api)

    def test_diag_checks_class_wiring(self):
        self.assertIn("class_wiring", self.shim)
        self.assertIn("controller_requires_rate_limiter", self.shim)

    def test_diag_endpoint_is_super_admin_only(self):
        """The diagnostic deliberately runs BEFORE the controller, so it
        needs its own gate. Ungated it handed any anonymous visitor the
        PHP version, extension list, OPcache state, internal file map,
        class wiring and — on ?diag=2 — the database name, migration state
        and feature flags."""
        gate_start = self.shim.index("isset($_GET['diag'])")
        body_start = self.shim.index("$root = dirname(dirname(__DIR__))")
        gate = self.shim[gate_start:body_start]
        # the gate sits between the diag entry and the first disclosure
        self.assertIn("$_SESSION['admin_logged_in']", gate)
        self.assertIn("'super_admin'", gate)
        self.assertIn("$diagRole !== 'super_admin'", gate)
        # a rejection must not confirm that a diagnostic lives here
        self.assertIn("Unknown endpoint.", gate)
        self.assertIn("http_response_code(404)", gate)
        # and it must run before the controller's own session gate,
        # otherwise the controller (not this block) is what answered
        self.assertLess(gate_start, self.shim.index("admin/api_mezmur.php"))

    def test_shim_php_lint(self):
        if shutil.which("php") is None:
            self.skipTest("php CLI not available")
        r = subprocess.run(
            ["php", "-l", str(ROOT / "backend/api/mezmur.php")],
            capture_output=True, text=True, timeout=60,
        )
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


class MezmurSchemaReconcilerTests(unittest.TestCase):
    """Schema-drift killer: legacy tables (created before the repo)
    are never upgraded by CREATE TABLE IF NOT EXISTS, and migrations
    lag the cron code pull. The reconciler reports and closes drift
    with idempotent guarded DDL; admins trigger it with one click.
    """

    @classmethod
    def setUpClass(cls):
        cls.rec = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.shell = (ROOT / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.shim = (ROOT / "backend/api/mezmur.php").read_text(encoding="utf-8")

    def test_reconciler_covers_every_mezmur_table(self):
        for tbl in ["mezmur_hymns", "mezmur_days", "mezmur_attendance",
                    "mezmur_attendance_audit", "mezmur_submissions",
                    # 038 (P0 audio plane) — reserved by the migration
                    "mezmur_play_stats", "mezmur_user_favorites"]:
            self.assertIn("'" + tbl + "'", self.rec)

    def test_reconciler_closes_the_038_audio_drift(self):
        """The P0 media service fails closed without sql/038 and its error
        message sends admins to the "Sync DB schema" button — so the
        reconciler must actually be able to close that drift. If these
        columns leave the contract, that message becomes a lie again."""
        for col in ["audio_key", "audio_duration_s", "audio_size", "audio_format",
                    "audio_status", "audio_uploaded_by", "audio_updated_at",
                    "lyrics_synced", "lyrics_synced_at", "lyrics_synced_by"]:
            self.assertIn(f"'{col}'", self.rec, col)
        self.assertIn("ENUM('none','pending','ready','rejected')", self.rec)
        self.assertIn("idx_mz38_audio_status", self.rec)
        # every column the reconciler promises must exist in the migration
        # it claims to mirror — the two contracts must not drift apart
        sql038 = (ROOT / "sql/038_mezmur_audio_media.sql").read_text(encoding="utf-8")
        for col in ["audio_key", "audio_status", "lyrics_synced", "lyrics_synced_by"]:
            self.assertIn(f"`{col}`", sql038, col)
        # and the error text must point at something that now works
        media = (ROOT / "admin/backend/services/MezmurMediaService.php").read_text(encoding="utf-8")
        self.assertIn("Sync DB schema", media)

    def test_reconciler_is_guarded_and_idempotent(self):
        self.assertIn("SHOW COLUMNS FROM", self.rec)
        self.assertIn("ALTER TABLE", self.rec)
        self.assertIn("catch (\\Throwable", self.rec)

    def test_reconciler_extends_legacy_enum_and_nullability(self):
        self.assertIn("excused", self.rec)
        self.assertIn("MODIFY COLUMN session_id BIGINT UNSIGNED DEFAULT NULL", self.rec)

    def test_api_exposes_report_and_guarded_apply(self):
        self.assertIn("case 'schema'", self.api)
        self.assertIn("case 'migrate'", self.api)
        # migrate is POST-enforced and write-rate-limited
        post_actions = re.search(r"\$__postActions = \[(.*?)\];", self.api, re.S).group(1)
        self.assertIn("'migrate'", post_actions)
        # MZ-13: schema reconciliation stays admin-only even though the
        # reconciler itself emits nothing but guarded mezmur DDL.
        migrate_block = self.api.split("case 'migrate':")[1].split("case '")[0]
        self.assertIn("['super_admin', 'school_admin']", migrate_block)

    def test_one_click_ui_exists(self):
        self.assertIn("migrateSchema", self.js)
        self.assertIn("action: 'migrate'", self.js)
        self.assertIn("Sync DB schema", self.shell)

    def test_diag_reports_drift(self):
        self.assertIn("schema_drift", self.shim)
        self.assertIn("MezmurSchemaReconciler", self.shim)


class MezmurAdvancedSearchTests(unittest.TestCase):
    """Telegram-grade hymn search (research 2026-08-28): Telegram keeps a
    local full-text index for instant as-you-type results; we mirror that
    with InnoDB FULLTEXT boolean mode (prefix wildcards), title-weighted
    ranking, lyrics snippets, highlight marks, debounce + stale-response
    guard, and LIKE fallback. Lyrics are searchable; lists never carry
    full lyrics (snippet only).
    """

    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.rec = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")

    def test_server_word_index_ranked_search(self):
        # P25: the inverted word index replaced FULLTEXT (which cannot
        # tokenize Ge'ez and once built dead — 0 matches silently).
        self.assertIn("searchWordCandidates", self.api)
        self.assertIn("$searchMode = 'word';", self.api)
        self.assertIn("id IN ($in)", self.api)
        self.assertIn("mb_stripos", self.api)  # snippet around first match
        self.assertIn("'snippet'", self.api)
        self.assertIn("'match_in'", self.api)  # title vs lyrics match marker
        # boolean operators still stripped from user input
        self.assertIn('-><()~*', self.api)

    def test_like_fallback_and_token_minimum(self):
        self.assertIn("searchMode = 'like'", self.api)
        self.assertIn("OR lyrics LIKE", self.api)

    def test_lists_never_carry_full_lyrics(self):
        self.assertIn("unset($r['lyrics'])", self.api)

    def test_reconciler_ensures_word_index(self):
        # P25: the word table replaces FULLTEXT ensures (Ge'ez cannot be
        # tokenized by InnoDB FTS; dead index builds matched nothing).
        self.assertIn("'mezmur_hymn_words'", self.rec)
        self.assertIn("backfillHymnWords", self.rec)
        self.assertIn("public const INDEXES = [];", self.rec)
        self.assertIn("missing_indexes", self.rec)

    def test_client_instant_search_ux(self):
        self.assertIn("}, 160);", self.js)          # tight debounce
        self.assertIn("var seq = ++lib.seq", self.js)  # stale-response guard
        self.assertIn("<mark>$1</mark>", self.js)   # Telegram-style highlight
        self.assertIn("h.snippet", self.js)


class MezmurAuditHardeningTests(unittest.TestCase):
    """End-to-end department audit (2026-08-28): industry-standard
    hardening applied after the full feature-by-feature review:
    complete audit trail, least-privilege lock overrides, clamped
    aggregates, calendar-real dates, and a scale-safe paginated inbox.
    """

    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.sub_service = (
            ROOT / "admin/backend/services/MezmurSubmissionService.php"
        ).read_text(encoding="utf-8")
        cls.att_service = (
            ROOT / "admin/backend/services/MezmurAttendanceService.php"
        ).read_text(encoding="utf-8")
        cls.hymn_svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")

    # ── audit trail: every decision-grade mutation is logged ──
    def test_controller_loads_audit_service(self):
        self.assertIn(
            "require_once __DIR__ . '/backend/services/SecurityAuditService.php';",
            self.api,
        )

    def test_every_entry_point_loads_audit_service(self):
        # MZ-1 regression guard (2026-09-01): the mobile route and both
        # auditing services must declare the audit dependency themselves —
        # a missing require made every mobile mezmur write silently
        # unaudited (and made mobile submission review fail AFTER the
        # packet update). Source-level guard; behaviour is covered live by
        # smoke block 3p.
        self.assertIn("SecurityAuditService.php", self.route)
        self.assertIn("SecurityAuditService.php", self.hymn_svc)
        self.assertIn("SecurityAuditService.php", self.sub_service)

    def test_hymn_library_writes_are_audited(self):
        # The canonical writer (MezmurHymnService) audits every mutation;
        # the web controller delegates to it (see api_mezmur.php 'save').
        for action in (
            "Mezmur Hymn Created",
            "Mezmur Hymn Updated",
            "Mezmur Hymn Archived",
            "Mezmur Hymn Restored",
        ):
            self.assertIn(action, self.hymn_svc)
        self.assertIn("'mezmur_hymn'", self.hymn_svc)

    def test_catalog_writes_are_audited(self):
        # MZ-7 regression guard: singer RENAME used to leave no audit
        # entry; category renames must carry before/after state.
        for action in (
            "Mezmur Singer Created",
            "Mezmur Singer Renamed",
            "Mezmur Singer Activated",
            "Mezmur Singer Deactivated",
            "Mezmur Category Renamed",
        ):
            self.assertIn(action, self.hymn_svc)
        self.assertIn("'from'", self.hymn_svc)
        self.assertIn("'to'", self.hymn_svc)

    def test_schema_reconcile_is_audited(self):
        self.assertIn("Mezmur Schema Reconciled", self.api)

    def test_review_decisions_are_audited(self):
        self.assertIn("Mezmur Submission Reviewed", self.sub_service)
        self.assertIn("previous_status", self.sub_service)

    def test_packet_lifecycle_is_audited(self):
        self.assertIn("packet_upsert", self.sub_service)
        self.assertIn("auditPacket", self.sub_service)
        # both write paths (update + insert) record the trail
        self.assertEqual(self.sub_service.count("self::auditPacket("), 2)

    # ── least privilege: lock override is an admin power ──────
    def test_write_override_is_admin_only(self):
        self.assertIn(
            "private const WRITE_OVERRIDE_ROLES = ['school_admin', 'super_admin'];",
            self.sub_service,
        )
        override_fn = self.sub_service.split("function staffCanOverride")[1].split("}")[0]
        self.assertIn("WRITE_OVERRIDE_ROLES", override_fn)
        # review stays open to the department...
        self.assertIn(
            "private const REVIEW_ROLES = ['mezmur_dept', 'school_admin', 'super_admin'];",
            self.sub_service,
        )

    def test_web_save_sheet_force_gated_to_admins(self):
        self.assertNotIn("'force' => true", self.api)
        self.assertIn(
            "in_array($mezmurRole, ['super_admin', 'school_admin'], true)",
            self.api,
        )

    def test_mobile_route_never_force_overrides(self):
        self.assertNotIn("'force'", self.route)

    # ── integrity: clamps + calendar-real dates ───────────────
    def test_packet_counts_are_clamped(self):
        self.assertIn("max(0, min(1000000, (int)($opts['member_count'] ?? 0)))", self.sub_service)
        self.assertIn("max(0, min(1000000, (int)($opts['absent'] ?? 0)))", self.sub_service)

    def test_dates_are_calendar_real(self):
        self.assertIn("checkdate($m, $d, $y)", self.att_service)
        self.assertIn("checkdate($m, $d, $y)", self.sub_service)
        # every date guard in the attendance service goes through the
        # calendar-real helper (regex lives only inside validDate itself)
        self.assertGreaterEqual(self.att_service.count("self::validDate($date)"), 4)
        self.assertEqual(
            self.att_service.count("preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)"), 1
        )

    # ── scale: inbox is paginated, bounded, total-aware ───────
    def test_inbox_is_paginated(self):
        self.assertIn("LIMIT ? OFFSET ?", self.sub_service)
        self.assertIn("COUNT(*) c FROM mezmur_submissions", self.sub_service)
        self.assertIn("if ($perPage > 100) $perPage = 100;", self.sub_service)
        self.assertNotIn("LIMIT 200", self.sub_service)
        self.assertIn("'total_pages'", self.sub_service)

    def test_submissions_endpoint_exposes_pagination(self):
        block = self.api.split("case 'submissions_list':")[1].split("case 'submission_detail':")[0]
        self.assertIn("'page' =>", block)
        self.assertIn("'per_page' =>", block)
        self.assertIn("mezmur_respond(['status' => 'success'] + $out);", block)

    def test_overview_uses_bounded_recent_packets(self):
        self.assertIn("'per_page' => 5", self.api)

    # ── deployment visibility ─────────────────────────────────
    def test_version_marker_bumped_both_surfaces(self):
        import re
        m = re.search(r"MEZMUR_API_VERSION', '([^']+)'", self.api)
        self.assertIsNotNone(m)
        # web + mobile surfaces advertise the SAME marker
        self.assertIn(m.group(1), self.route)
        self.assertNotIn("'phase5-schema24'", self.api)

    # ── prepared statements remain the only query path ────────
    def test_no_string_interpolated_user_data_in_sql(self):
        for src in (self.sub_service, self.att_service):
            # no $_GET/$_POST/$_REQUEST reaching into SQL strings
            self.assertNotIn("$_GET", src)
            self.assertNotIn("$_POST", src)
            self.assertNotIn("$_REQUEST", src)


class MezmurOfflineHymnTests(unittest.TestCase):
    """Offline-first hymn library (2026-08-28): Telegram/Drive
    local-first model — device SQLite is the read path, mutations go
    through an idempotent outbox, pulls use a change-token delta.
    Static contract tests; functional server behavior is covered by
    the hymn offline probe (revisions, conflicts, deltas, categories).
    """

    @classmethod
    def setUpClass(cls):
        cls.sql25 = (ROOT / "sql/025_mezmur_hymn_offline.sql").read_text(encoding="utf-8")
        cls.rec = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.hymn_svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        M = ROOT / "Mobile/wbws_flutter_app/lib"
        cls.store = (M / "services/hymn_store.dart").read_text(encoding="utf-8")
        cls.sync = (M / "services/sync_service.dart").read_text(encoding="utf-8")
        cls.db = (M / "services/local_db.dart").read_text(encoding="utf-8")
        cls.api = (M / "services/api_service.dart").read_text(encoding="utf-8")
        cls.screen = (M / "screens/mezmur/mezmur_hymns.dart").read_text(encoding="utf-8")
        cls.editor = (M / "screens/mezmur/mezmur_hymn_editor.dart").read_text(encoding="utf-8")
        cls.detail = (M / "screens/mezmur/mezmur_hymn_detail.dart").read_text(encoding="utf-8")
        cls.cats = (M / "screens/mezmur/mezmur_categories.dart").read_text(encoding="utf-8")

    # ── migration 025: additive, guarded, idempotent ──────────
    def test_sql025_contract(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `mezmur_categories`", self.sql25)
        self.assertIn("UNIQUE KEY `uq_mezmur_categories_name`", self.sql25)
        self.assertIn("INSERT IGNORE INTO `mezmur_categories`", self.sql25)
        # guarded ALTER via information_schema probe (MySQL-safe)
        self.assertIn("information_schema.columns", self.sql25)
        self.assertIn("`revision` INT UNSIGNED NOT NULL DEFAULT 1", self.sql25)
        self.assertIn("idx_mezmur_hymns_updated", self.sql25)
        self.assertIn("(`updated_at`, `id`)", self.sql25)

    def test_reconciler_covers_new_objects(self):
        self.assertIn("'mezmur_categories' =>", self.rec)
        self.assertIn("'revision'   =>", self.rec)
        self.assertIn("INSERT IGNORE INTO mezmur_categories", self.rec)
        # legacy tables get UNIQUE(name) + dedupe before seeding,
        # and the delta BTREE index is ensured outside the migration
        self.assertIn("uq_mezmur_categories_name", self.rec)
        self.assertIn("DELETE c1 FROM mezmur_categories c1", self.rec)
        self.assertIn("idx_mezmur_hymns_updated", self.rec)

    # ── server service: writers + conflict + delta ────────────
    def test_save_hymn_validation_and_conflict(self):
        self.assertIn("Title is required.", self.hymn_svc)
        self.assertIn("mb_strlen($lyrics) > 200000", self.hymn_svc)
        self.assertIn("LOWER(title) = LOWER(?)", self.hymn_svc)
        # revision-based conflict returns the server copy
        self.assertIn("base_revision", self.hymn_svc)
        self.assertIn("'conflict' => true", self.hymn_svc)
        self.assertIn("revision = revision + 1", self.hymn_svc)
        self.assertIn("Mezmur Hymn Created", self.hymn_svc)
        self.assertIn("Mezmur Hymn Updated", self.hymn_svc)

    def test_status_change_is_audited_revision_bumped(self):
        self.assertIn("setStatusHymn", self.hymn_svc)
        self.assertIn("Mezmur Hymn Archived", self.hymn_svc)
        self.assertIn("Mezmur Hymn Restored", self.hymn_svc)

    def test_delta_pull_is_cursor_ordered_and_bounded(self):
        self.assertIn("ORDER BY updated_at ASC, id ASC", self.hymn_svc)
        self.assertIn("next_cursor", self.hymn_svc)
        self.assertIn("has_more", self.hymn_svc)
        # archived rows travel in deltas (deletes are never silent)
        self.assertNotIn("status = 'active'", self.hymn_svc.split("listChangedSince")[1].split("categories")[0])
        # lyrics blob opt-in only
        self.assertIn("includeLyrics", self.hymn_svc)
        self.assertIn("min($limit, $includeLyrics ? 100 : 500)", self.hymn_svc.replace("max(1, ", ""))

    def test_category_service_guards(self):
        self.assertIn("saveCategory", self.hymn_svc)
        self.assertIn("setCategoryStatus", self.hymn_svc)
        self.assertIn("LOWER(name) = LOWER(?)", self.hymn_svc)
        self.assertIn("categoriesReady", self.hymn_svc)

    # ── mobile routes: gated + idempotent + rate-limited ──────
    # Every library/media WRITE action, by name. A bare count went stale
    # the moment the P0 audio plane added four routes; naming them makes a
    # new ungated write impossible to merge silently.
    WRITE_ACTIONS = (
        "hymn", "hymn-status", "category", "category-status", "category-image",
        "category-image-remove", "zemarian", "zemarian-status", "zemarian-image",
        "zemarian-image-remove",
        "audio-presign", "audio-confirm", "audio-remove", "lyrics-synced",
        # P66 hymn art (own rate bucket mezmur_art_write)
        "art", "art-remove",
    )

    def _action_block(self, action, span=900):
        marker = f"── POST /mezmur/{action} "
        i = self.route.index(marker)
        return self.route[i:i + span]

    def test_routes_gate_writes_and_keep_reads_open(self):
        self.assertIn("$MEZMUR_LIBRARY_WRITE_ROLES = ['mezmur_dept', 'school_admin', 'super_admin'];", self.route)
        for action in self.WRITE_ACTIONS:
            block = self._action_block(action)
            self.assertIn("apiRoleIs($auth, $MEZMUR_LIBRARY_WRITE_ROLES)", block, action)
            self.assertIn("isApiRateLimited(", block, action)
        self.assertEqual(
            self.route.count("apiRoleIs($auth, $MEZMUR_LIBRARY_WRITE_ROLES)"),
            len(self.WRITE_ACTIONS),
        )
        # Idempotency keys: the 12 offline-replayable writes (library,
        # taxonomy, media, lyrics, sheet, submission review).
        self.assertEqual(self.route.count("apiIdempotencyBegin("), 12)
        self.assertGreaterEqual(self.route.count("isApiRateLimited('mezmur_hymn_write'"), 4)
        # the three media writes get their own bucket
        self.assertEqual(self.route.count("isApiRateLimited('mezmur_audio_write'"), 3)

    def test_reads_stay_open_to_takers(self):
        # Reads are gated to the READ role list, never the write list —
        # a taker must keep seeing the library.
        for action, marker in (
            ("hymns", "── GET /mezmur/hymns "),
            ("hymn", "── GET /mezmur/hymn?id="),
            ("categories", "── GET /mezmur/categories "),
            ("zemarians", "── GET /mezmur/zemarians "),
        ):
            i = self.route.index(marker)
            block = self.route[i:i + 500]
            self.assertNotIn("$MEZMUR_LIBRARY_WRITE_ROLES", block, action)
            self.assertIn("isApiRateLimited(", block, action)

    def test_routes_delta_and_conflict_shapes(self):
        self.assertIn("($ROUTE['sub'] ?? '') === 'changes'", self.route)
        self.assertIn("listChangedSince(", self.route)
        # Only a coded revision conflict with a canonical server item gets
        # HTTP 409 + data.item; middleware idempotency 409s stay distinct.
        self.assertIn("is_array($item)", self.route)
        self.assertIn("$code = 'REVISION_CONFLICT'", self.route)
        self.assertIn("$code === 'REVISION_CONFLICT' ? 409", self.route)
        self.assertIn("$extra['data'] = ['item' => $item]", self.route)

    # ── client: local-first store ─────────────────────────────
    def test_store_is_local_first_and_role_gated(self):
        self.assertIn("mezmur_dept", self.store)
        self.assertIn("bool get canEdit => _writeRoles.contains(_api.userRole)", self.store)
        # optimistic local write precedes any network call
        save_block = self.store.split("Future<String?> saveHymn(")[1].split("Future<String?> setHymnStatus")[0]
        self.assertIn("_db.upsertHymns(", save_block)
        self.assertIn("_db.enqueueHymnOp('hymn_save'", save_block)

    def test_store_conflict_policy_server_copy_wins(self):
        self.assertIn("OutboxDecision.resolvedConflict", self.store)
        self.assertIn("claim.operation == 'hymn_save' && canonical != null", self.store)
        self.assertIn("conflict — server copy kept", self.store)

    def test_store_protects_pending_rows_from_deltas(self):
        self.assertIn("protectIds", self.store)
        self.assertIn("upsertHymns(items, protectIds: protect)", self.store)

    def test_store_coalesces_offline_edits_into_one_create(self):
        self.assertIn("replacePendingHymnSaveForLocalId", self.store)
        self.assertIn("row['sync_state']}' == 'in_flight'", self.db)
        self.assertIn("'depends_on': dependency", self.db)

    def test_delta_cursor_persisted_locally(self):
        self.assertIn("getHymnSyncCursor", self.store)
        self.assertIn("setHymnSyncCursor(next)", self.store)
        self.assertIn("include_lyrics", self.api)

    # ── sync engine integration ───────────────────────────────
    def test_sync_engine_drains_hymn_outbox_and_pulls(self):
        self.assertIn("HymnStore()", self.sync)
        # S2 Goal A.7: the drain now also hands the hymn push the provenance
        # of the execution, so the pin follows the call it was always about.
        self.assertIn("pushPending(source: source)", self.sync)
        self.assertIn("pullChanges()", self.sync)
        self.assertIn("pendingHymns", self.sync)
        self.assertIn("hymn change", self.sync)

    # ── local DB contract ─────────────────────────────────────
    def test_localdb_v11_hymn_tables(self):
        self.assertIn("version: localDatabaseSchemaVersion,", self.db)
        # 17 = two-level taxonomy (P30); 16 = single title
        for t in ("cached_hymns", "pending_hymn_ops", "hymn_sync_meta",
                  "cached_mezmur_categories"):
            self.assertIn(f"CREATE TABLE IF NOT EXISTS {t}", self.db)
        self.assertIn("idx_cached_hymns_title", self.db)
        # Logout boundary (product decision 2026-08-28): member data is
        # wiped, the SHARED hymn library + queued hymn edits persist.
        wipe = self.db.split("clearAllUserData")[1]
        self.assertIn("'pending_mezmur',", wipe)
        # HR attendance is member data too — wiped on logout like the rest.
        self.assertIn("'pending_hr',", wipe)
        self.assertNotIn("'cached_hymns',", wipe)
        self.assertNotIn("'pending_hymn_ops',", wipe)
        self.assertNotIn("'hymn_sync_meta',", wipe)
        # Queued hymn edits push only for curators (identity-safe).
        self.assertIn("if (!canEdit ||", self.store)

    # ── UI: instant search, curation actions, offline notes ──
    def test_library_screen_local_first(self):
        self.assertIn("_store.hymns(", self.screen)
        self.assertIn("Duration(milliseconds: 150)", self.screen)  # debounce
        self.assertIn("OfflineBanner", self.screen)
        self.assertIn("RefreshIndicator", self.screen)
        self.assertIn("_store.canEdit", self.screen)
        self.assertIn("MezmurHymnEditorScreen", self.screen)
        self.assertIn("cloud_upload_outlined", self.screen)  # pending badge

    def test_editor_offline_contract(self):
        self.assertIn("Will sync automatically", self.editor.replace("will sync automatically", "Will sync automatically"))
        self.assertIn("_store.saveHymn(hymn, baseRevision: baseRevision)", self.editor)
        self.assertIn("maxLength: 255", self.editor)

    def test_detail_reader_is_local_first_with_lazy_lyrics(self):
        self.assertIn("_store.hymn(widget.id)", self.detail)
        self.assertIn("Lyrics not downloaded yet", self.detail)
        self.assertIn("_db.upsertHymns([item])", self.detail)

    def test_categories_screen_offline_crud(self):
        self.assertIn("_store.saveCategory", self.cats)
        self.assertIn("_store.setCategoryStatus", self.cats)
        self.assertIn("maxLength: 50", self.cats)


class MezmurRenamePropagationTests(unittest.TestCase):
    """MZ-3/MZ-4 regression guards (Patch 17, 2026-09-01).

    Live behaviour is covered by smoke block 3q; these source guards keep
    the contracts from being quietly removed later:

    - A category rename must relabel every hymn that carries the label and
      touch updated_at so the delta cursor propagates it — WITHOUT bumping
      revision (a relabel is not a content change; bumping would force
      offline editors into server-wins conflicts and drop their edits).
    - The category-name filter must be join-aware in BOTH list paths so a
      multi-category hymn is findable by every label it carries.
    """

    @classmethod
    def setUpClass(cls):
        cls.hymn_svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")

    def test_rename_relabels_hymns_and_touches_sync_cursor(self):
        self.assertIn(
            "UPDATE mezmur_hymns SET category=?, updated_at=NOW() WHERE category=?",
            self.hymn_svc,
        )
        # no revision bump on a relabel (see class docstring)
        self.assertNotIn(
            "UPDATE mezmur_hymns SET category=?, updated_at=NOW(), revision",
            self.hymn_svc,
        )

    def test_rename_mirror_only_runs_when_the_name_changed(self):
        # sort-order-only edits must not emit phantom hymn deltas
        self.assertIn("old['name'] !== $name", self.hymn_svc)

    def test_rename_is_transactional_and_audited_with_relabel_count(self):
        self.assertIn("hymns_relabelled", self.hymn_svc)

    def test_category_name_filter_is_join_aware_everywhere(self):
        for src in (self.hymn_svc, self.api):
            self.assertIn("category = ? OR EXISTS", src)
            self.assertIn("mc.name = ?", src)


class MezmurReadRateLimitTests(unittest.TestCase):
    """MZ-5 (Patch 18): mobile READ endpoints are rate limited.

    Writes were already bounded; reads (list, single, delta, catalogues,
    sheets) were not — any mezmur role could hammer them without limit,
    including heavy include_lyrics delta pulls. Buckets are IP-scoped by
    the shared middleware (Retry-After + X-RateLimit-Limit headers);
    budgets are generous for real humans behind NAT but stop runaway
    clients. Live behaviour: smoke block 3r.
    """

    @classmethod
    def setUpClass(cls):
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")

    def test_lightweight_reads_share_a_bounded_bucket(self):
        # days, sheet, sections, hymns list, hymn, categories, zemarians,
        # submission detail — every lightweight GET goes through the bucket.
        self.assertGreaterEqual(self.route.count("isApiRateLimited('mezmur_api_read'"), 8)

    def test_delta_sync_is_bounded_and_lyrics_aware(self):
        self.assertGreaterEqual(self.route.count("isApiRateLimited('mezmur_api_sync'"), 1)
        self.assertIn("include_lyrics", self.route)

    def test_analytics_reads_are_bounded(self):
        self.assertGreaterEqual(self.route.count("isApiRateLimited('mezmur_api_analytics'"), 1)


class MezmurConcurrencyGuardTests(unittest.TestCase):
    """MZ-6 (Patch 19): optimistic concurrency + storage-level uniqueness.

    The old SELECT-then-UPDATE / SELECT-then-INSERT pairs were
    check-then-act races: concurrent writers both passed the check and
    the loser silently won (revision check) or duplicated (title check).
    Now the guard lives in the statement itself, and sql/031's UNIQUE
    index settles title races at the storage layer. Live races are
    covered by smoke block 3s (parallel writers + parallel creators).
    """

    @classmethod
    def setUpClass(cls):
        cls.hymn_svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.reconciler = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.migration = (
            ROOT / "sql/031_mezmur_hymn_title_unique.sql"
        ).read_text(encoding="utf-8")

    def test_update_is_revision_guarded(self):
        self.assertIn("WHERE id=? AND revision = ?", self.hymn_svc)

    def test_conflict_detected_from_affected_rows(self):
        self.assertIn("$baseRevision !== null && $affected === 0", self.hymn_svc)

    def test_duplicate_key_races_map_to_friendly_error(self):
        # both mysqli modes: flagged return value (pre-rollback) and
        # mysqli_sql_exception (PHP 8.1+ default; errno is reset by the
        # rollback, so the exception itself must carry the verdict)
        self.assertIn("isDuplicateKeyValue($e)", self.hymn_svc)
        self.assertIn("isDuplicateKeyError($conn)", self.hymn_svc)

    def test_unique_index_reaches_all_deployment_paths(self):
        # sql/ file for CLI deployments AND the reconciler for the admin
        # Sync button; both refuse to run over unresolved duplicates.
        for src in (self.reconciler, self.migration):
            self.assertIn("uq_mezmur_hymns_title", src)
        self.assertIn("HAVING COUNT(*) > 1", self.migration)


class MezmurMopUpTests(unittest.TestCase):
    """Patch 20 mop-up (2026-09-01): MZ-12/13/15 (web UX + privilege),
    MZ-9 (whitelist taxonomy ids), MZ-10 (orphan-free legacy create).
    Live behaviour: smoke block 3t.
    """

    @classmethod
    def setUpClass(cls):
        cls.hymn_svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")

    def test_migrate_is_admin_only(self):
        self.assertIn("Only administrators can reconcile the database schema.", self.api)
        self.assertIn("super_admin', 'school_admin", self.api)

    def test_unknown_taxonomy_ids_are_rejected_upfront(self):
        self.assertIn("unknownTaxonomyIds", self.hymn_svc)
        self.assertIn("no longer exists", self.hymn_svc)

    def test_legacy_category_rows_are_created_inside_the_transaction(self):
        self.assertIn("resolveLegacyCategoryId($conn, $category, false)", self.hymn_svc)
        self.assertIn("resolveLegacyCategoryId($conn, $category, true)", self.hymn_svc)
        self.assertIn("$pendingLegacyCategory", self.hymn_svc)

    def test_web_empty_state_covers_every_filter_and_offers_recovery(self):
        self.assertIn("lib.categoryId || lib.zemarianId", self.js)
        self.assertIn("Clear filters", self.js)
        self.assertIn("clearFilters: clearFilters", self.js)

    def test_web_rows_render_multi_category_badges(self):
        self.assertIn("catBadges(h)", self.js)
        self.assertIn("h.categories || []", self.js)


class MobileDartParityTests(unittest.TestCase):
    """Patch 21 (2026-09-01): honest teacher empty-state + MZ-11 cleanup.

    - teacher_grades.dart surfaces the server's bootstrap 'notice' (the
      H1 honest guidance: class-teacher-without-subjects vs plain
      no-assignment) instead of a generic hardcoded line; falls back to
      the generic line offline (verified live: /grades/bootstrap returns
      the notice for the H1 fixture).
    - hymn_store.dart placeholder cleanup also drops the taxonomy join
      rows so synced offline-created hymns never orphan joins on-device.
    """

    @classmethod
    def setUpClass(cls):
        cls.grades = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/teacher/teacher_grades.dart"
        ).read_text(encoding="utf-8")
        cls.store = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/hymn_store.dart"
        ).read_text(encoding="utf-8")

    def test_teacher_subjects_empty_state_uses_server_notice(self):
        self.assertIn("_subjectsNotice", self.grades)
        self.assertIn("res.data['notice'] as String?", self.grades)
        self.assertIn("_subjectsNotice ?? 'No subjects assigned", self.grades)

    def test_placeholder_cleanup_drops_join_rows(self):
        self.assertIn("cached_hymn_categories", self.store)
        self.assertIn("cached_hymn_zemarians", self.store)
        # the join deletes live inside the placeholder cleanup
        cleanup = self.store.split("Future<void> _dropLocalPlaceholder")[1][:900]
        self.assertIn("cached_hymn_categories", cleanup)
        self.assertIn("cached_hymn_zemarians", cleanup)


class MezmurMediaPlaneTests(unittest.TestCase):
    """P0 audio plane (R2 direct upload). Findings F3/F4 from the deep
    audit: the original presign constrained nothing but the key, so a
    client could reserve 1 byte and store 5 MB, or store text/html under
    an .mp3 key on the public media domain; confirmUpload() only checked
    existence, never size; and replacing audio orphaned the old object.
    """

    @classmethod
    def setUpClass(cls):
        cls.media = (
            ROOT / "admin/backend/services/MezmurMediaService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.player = (ROOT / "frontend/js/mezmur_player.js").read_text(encoding="utf-8")

    # ── presign signs what the upload is allowed to be ────────────
    def test_presign_signs_content_type_and_length(self):
        self.assertIn("array $extraHeaders = []", self.media)
        self.assertIn("$signedHeaders = implode(';', array_keys($signed));", self.media)
        self.assertIn("$query['X-Amz-SignedHeaders'] = $signedHeaders;", self.media)
        # the canonical request must use the full signed-header list, not
        # a hardcoded 'host'
        self.assertNotIn(". 'host' . \"\\n\"", self.media)
        self.assertIn(". $signedHeaders . \"\\n\"", self.media)
        # the upload presign actually passes both headers
        presign_call = self.media.split("$uploadUrl = self::presign('PUT'")[1][:400]
        self.assertIn("'content-type' => $contentType", presign_call)
        self.assertIn("'content-length' => (string)$size", presign_call)

    def test_content_type_is_server_chosen_never_client_chosen(self):
        self.assertIn("private static function contentTypeFor(string $ext): string", self.media)
        for pair in ("'mp3'  => 'audio/mpeg'", "'m4a'  => 'audio/mp4'",
                     "'wav'  => 'audio/wav'", "'opus' => 'audio/ogg'"):
            self.assertIn(pair, self.media)
        begin = self.media.split("public static function beginUpload(")[1].split("public static function confirmUpload(")[0]
        self.assertIn("$contentType = self::contentTypeFor($ext);", begin)
        # the client's own guess must not be what gets stored/served
        self.assertNotIn("$_FILES", begin)
        self.assertNotIn("$_POST['content_type']", begin)

    def test_presign_response_tells_clients_the_headers_to_send(self):
        self.assertIn("'content_type' => $contentType", self.media)
        self.assertIn("'content_length' => $size", self.media)
        self.assertIn("'content_type' => $result['content_type'] ?? ''", self.api)
        self.assertIn("'content_type' => $result['content_type'] ?? ''", self.route)
        # and the browser sends the server-chosen type, not file.type
        self.assertIn("d.content_type || file.type", self.js)

    # ── confirm verifies SIZE, not just existence ─────────────────
    def test_http_client_captures_response_headers(self):
        self.assertIn("CURLOPT_HEADER => true", self.media)
        self.assertIn("private static function parseHeaders(string $block): array", self.media)
        self.assertIn("self::parseHeaders($headBlock)", self.media)
        # the stream-wrapper fallback must capture them too
        self.assertIn("self::parseHeaders(implode(\"\\r\\n\", $rawHeaders))", self.media)
        self.assertNotIn("'headers' => []", self.media)

    def test_confirm_rejects_a_size_mismatch(self):
        confirm = self.media.split("public static function confirmUpload(")[1].split("public static function removeAudio(")[0]
        self.assertIn("$head['headers']['content-length']", confirm)
        self.assertIn("(int)$actualRaw !== $reserved", confirm)
        self.assertIn("SET audio_status='rejected'", confirm)
        self.assertIn("Mezmur Audio Upload Rejected", confirm)
        # the reject path must return a failure, not fall through to ready
        self.assertLess(confirm.index("audio_status='rejected'"), confirm.index("audio_status='ready'"))

    # ── F4: no orphaned objects ───────────────────────────────────
    def test_begin_upload_retires_the_outgoing_object(self):
        begin = self.media.split("public static function beginUpload(")[1].split("public static function confirmUpload(")[0]
        self.assertIn("SELECT id, audio_key FROM mezmur_hymns WHERE id = ?", begin)
        self.assertIn("self::http('DELETE', self::presign('DELETE', $previousKey, 120));", begin)
        # ...and it happens BEFORE the new key is reserved
        self.assertLess(begin.index("$previousKey"), begin.index("self::keyFor("))

    # ── the internal key still never leaves the server ────────────
    def test_audio_key_never_reaches_a_client(self):
        self.assertIn("unset($r['audio_key']);", self.media)
        for src, name in ((self.api, "web controller"), (self.route, "REST route")):
            self.assertNotIn("'audio_key'", src, name)
        # and audio_url only when verified ready
        self.assertIn("($status === 'ready' && $key !== '') ? self::publicUrl($key) : ''", self.media)

    # ── web console playback must not depend on the public CDN ──
    def test_signed_get_playback_url_exists(self):
        """Upload PUTs to the signed R2 API host; confirm HEADs that
        same host. The public custom-domain URL is a separate hostname
        that 403s when the bucket is not public — which is exactly the
        0:00/0:00 player on a Ready hymn. Playback therefore mints a
        signed GET against the API host."""
        self.assertIn("public static function signedGetUrl(string $key, int $expiresSeconds = 3600): string", self.media)
        self.assertIn("return self::presign('GET', $key, $expiresSeconds);", self.media)
        self.assertIn("public static function playUrl(\\mysqli $conn, int $hymnId): array", self.media)
        play = self.media.split("public static function playUrl(")[1].split("public static function")[0]
        self.assertIn("$status !== 'ready'", play)
        self.assertIn("self::signedGetUrl($key, $expires)", play)
        # GET action, not a CSRF-gated write
        self.assertIn("case 'audio_stream':", self.api)
        self.assertNotIn("'audio_stream'", re.search(r"\$__postActions = \[(.*?)\];", self.api, re.S).group(1))
        self.assertIn("MezmurMediaService::playUrl(", self.api)
        # manage-modal helpers stay for upload/replace; listening uses the dock
        self.assertIn("function attachAudioStream(", self.js)
        self.assertIn("action=audio_stream&id=", self.js)
        self.assertIn("function bindAudioSrc(", self.js)
        self.assertIn("player.load();", self.js)
        self.assertIn("player.addEventListener('error'", self.js)
        self.assertIn("action=audio_stream&id=", self.player)
        self.assertIn("MezmurPlayer", self.player)
        self.assertNotIn("crossOrigin", self.player)
        self.assertIn("window.MezmurPlayer.init", self.js)
        self.assertIn("audioPlay: audioPlay", self.js)


class MezmurMobileAudioPlatformTests(unittest.TestCase):
    """Android/iOS wiring for P0 background playback (findings F7/F8) and
    the dependency lock the mobile build resolves against."""

    @classmethod
    def setUpClass(cls):
        app = ROOT / "Mobile/wbws_flutter_app"
        cls.manifest = (app / "android/app/src/main/AndroidManifest.xml").read_text(encoding="utf-8")
        cls.pubspec = (app / "pubspec.yaml").read_text(encoding="utf-8")
        cls.lock = (app / "pubspec.lock").read_text(encoding="utf-8")
        cls.player = (app / "lib/services/mezmur_audio_player.dart").read_text(encoding="utf-8")

    # ── F7: the media service must not be world-bindable ──────────
    def test_audio_service_is_not_exported(self):
        svc = self.manifest.split('android:name="com.ryanheise.audioservice.AudioService"')[1][:400]
        self.assertIn('android:exported="false"', svc)
        self.assertNotIn('android:exported="true"', svc)
        # media buttons still work: the receiver stays exported
        rcv = self.manifest.split('android:name="com.ryanheise.audioservice.MediaButtonReceiver"')[1][:400]
        self.assertIn('android:exported="true"', rcv)

    # ── F8: POST_NOTIFICATIONS is declared AND requested ──────────
    def test_notification_permission_is_declared_and_requested(self):
        self.assertIn('android:name="android.permission.POST_NOTIFICATIONS"', self.manifest)
        self.assertIn("permission_handler:", self.pubspec)
        self.assertIn("import 'package:permission_handler/permission_handler.dart';", self.player)
        self.assertIn("Permission.notification.request()", self.player)
        # asked once, before the first play, and a refusal is survivable
        self.assertIn("_ensureNotificationPermission()", self.player)
        self.assertIn("if (_notificationAsked) return;", self.player)
        ensure = self.player.split("Future<void> _ensureConfigured()")[1].split("bool _notificationAsked")[0]
        self.assertIn("await _ensureNotificationPermission();", ensure)

    # ── the mobile build must actually be able to resolve its deps ─
    def test_pubspec_lock_contains_the_audio_dependencies(self):
        """pubspec.lock is what `flutter build` resolves against. If a
        dependency is added to pubspec.yaml without re-running pub get,
        the committed lock has no entry for it and every `package:` import
        fails to resolve — the app cannot build at all."""
        for pkg in ("just_audio", "just_audio_background", "audio_service",
                    "audio_session", "permission_handler"):
            self.assertIn(pkg + ":", self.pubspec, f"{pkg} missing from pubspec.yaml")
            self.assertRegex(self.lock, r"(?m)^  " + pkg + r":",
                             f"{pkg} is in pubspec.yaml but NOT in pubspec.lock — "
                             f"run `flutter pub get` in Mobile/wbws_flutter_app and commit the lock")


class MezmurSyncedLyricsContractTests(unittest.TestCase):
    """F5 convergence + canonical dialect.

    The canonicalizer is pure and DB-free, so these tests EXECUTE the
    real PHP code path (php -r) instead of re-implementing it; the
    delta wiring is asserted at source level (it needs mysqli)."""

    @classmethod
    def setUpClass(cls):
        cls.media = (ROOT / "admin/backend/services/MezmurMediaService.php").read_text(encoding="utf-8")
        cls.hymn = (ROOT / "admin/backend/services/MezmurHymnService.php").read_text(encoding="utf-8")
        cls.local_db = (ROOT / "Mobile/wbws_flutter_app/lib/services/local_db.dart").read_text(encoding="utf-8")

    @staticmethod
    def canon(lrc):
        import json as _json
        import os as _os
        env = dict(_os.environ)
        env["LRC"] = lrc
        env["SRV"] = str(ROOT / "admin/backend/services/MezmurMediaService.php")
        p = subprocess.run(
            ["php", "-r",
             'require getenv("SRV"); '
             '$r = App\\Services\\MezmurMediaService::canonicalizeLrc(getenv("LRC")); '
             'echo json_encode($r, JSON_UNESCAPED_UNICODE);'],
            capture_output=True, text=True, env=env, timeout=60)
        if p.returncode != 0:
            raise AssertionError("php canonicalizeLrc crashed: " + p.stderr[:400])
        return _json.loads(p.stdout)

    @unittest.skipUnless(shutil.which("php"), "php CLI not installed")
    def test_bracket_runs_are_expanded_not_lost(self):
        r = self.canon("[ti:Test]\n[00:09.00][00:01.00]መዝሙር\n[00:75]ቅጥያ")
        self.assertTrue(r["ok"], r.get("message"))
        self.assertEqual(r["timed"], 3)
        self.assertEqual(
            r["doc"],
            "[ti:Test]\n"
            "[00:01.000] መዝሙር\n"
            "[00:09.000] መዝሙር\n"
            "[01:15.000] ቅጥያ")

    @unittest.skipUnless(shutil.which("php"), "php CLI not installed")
    def test_hand_made_quirks_are_repaired(self):
        r = self.canon("[00:01.5]a\n[00:02]b")
        self.assertTrue(r["ok"])
        self.assertEqual(r["doc"], "[00:01.500] a\n[00:02.000] b")

    @unittest.skipUnless(shutil.which("php"), "php CLI not installed")
    def test_unrepairable_lines_are_rejected_loudly(self):
        for bad in ("[Verse 1] chorus", "[01:02:03.00] hour format", "plain text"):
            r = self.canon(bad)
            self.assertFalse(r["ok"], f"should reject: {bad}")
            self.assertIn("not a timestamp line", r["message"])

    @unittest.skipUnless(shutil.which("php"), "php CLI not installed")
    def test_empty_document_is_rejected(self):
        r = self.canon("   \n  ")
        self.assertFalse(r["ok"])
        self.assertIn("at least one timed line", r["message"])

    def test_save_synced_lyrics_stores_the_canonical_form(self):
        self.assertIn("self::canonicalizeLrc($lrc)", self.media)
        # the canonical doc, not the raw input, is what gets persisted
        save = self.media.split("public static function saveSyncedLyrics")[1].split("public static function")[0]
        self.assertIn("$lrc = (string)$canon['doc'];", save)

    def test_delta_pull_carries_synced_lyrics(self):
        delta = self.hymn.split("public static function listChangedSince")[1].split("public static function attachTaxonomyBulk")[0]
        self.assertIn("$syncedCols = self::syncedColExpr($conn) . ', ' . self::syncedAtColExpr($conn);", delta)
        # P66: art columns ride the same delta cursor between media and
        # synced lyrics — a cover change converges like every other edit.
        self.assertEqual(delta.count("$mediaCols, $artCols, $syncedCols"), 2,
                         "both delta SELECT branches (cursor + bootstrap) must carry the media + art + synced columns")

    def test_flutter_delta_upsert_still_applies_synced_keys(self):
        # the client side of the convergence contract (regression guard)
        self.assertIn("h.containsKey('lyrics_synced')", self.local_db)
        self.assertIn("'lyrics_synced_at'", self.local_db)


class MezmurMobilePlayerChromeTests(unittest.TestCase):
    """Now-playing chrome: lyrics live in the painted ornamental box,
    the transport sits in the band under it, and the parchment asset is
    the full-bleed backdrop. Lyrics fade at both edges so scroll eases
    in and out instead of clipping on the frame."""

    @classmethod
    def setUpClass(cls):
        app = ROOT / "Mobile/wbws_flutter_app"
        cls.player = (app / "lib/screens/mezmur/mezmur_player_screen.dart").read_text(encoding="utf-8")
        cls.lyrics = (app / "lib/screens/mezmur/mezmur_lyrics_screen.dart").read_text(encoding="utf-8")
        cls.style = (app / "lib/screens/mezmur/parchment_style.dart").read_text(encoding="utf-8")
        cls.engine = (app / "lib/services/mezmur_audio_player.dart").read_text(encoding="utf-8")
        cls.hymns = (app / "lib/screens/mezmur/mezmur_hymns.dart").read_text(encoding="utf-8")
        cls.cat = (app / "lib/screens/mezmur/mezmur_category_screen.dart").read_text(encoding="utf-8")
        cls.mini = (app / "lib/screens/mezmur/mezmur_mini_player.dart").read_text(encoding="utf-8")
        cls.shell = (app / "lib/screens/shell/app_shell.dart").read_text(encoding="utf-8")
        cls.bg = app / "assets/parchment_hymn_bg.jpg"

    def test_parchment_backdrop_is_present(self):
        self.assertTrue(self.bg.is_file())
        self.assertGreater(self.bg.stat().st_size, 50_000)
        self.assertIn("assets/parchment_hymn_bg.jpg", self.engine)

    def test_lyrics_sit_in_the_painted_box(self):
        self.assertIn("class ParchmentArt", self.style)
        self.assertIn("boxTop", self.style)
        self.assertIn("playerTop", self.style)
        self.assertIn("ParchmentArt.boxTop", self.player)
        self.assertIn("MezmurLyricsScreen(", self.player)
        self.assertNotIn("builder: (_) => MezmurLyricsScreen", self.player)

    def test_lyrics_fade_in_and_out_both_edges(self):
        self.assertIn("class ParchmentFade", self.style)
        self.assertIn("ShaderMask", self.style)
        self.assertIn("ParchmentFade", self.lyrics)
        self.assertIn("alignment: 0.5", self.lyrics)
        self.assertIn("Scrollable.ensureVisible", self.lyrics)

    def test_transport_has_seek_skip_shuffle_repeat(self):
        self.assertIn("seekBy", self.engine)
        self.assertIn("cycleLoop", self.engine)
        self.assertIn("toggleShuffle", self.engine)
        self.assertIn("setLoopMode", self.engine)
        self.assertIn("setShuffleModeEnabled", self.engine)
        self.assertIn("_c.seekBy", self.player)
        self.assertIn("_c.cycleLoop", self.player)
        self.assertIn("_c.toggleShuffle", self.player)
        self.assertIn("_c.previous", self.player)
        self.assertIn("_c.next", self.player)
        self.assertIn("_c.toggle", self.player)

    def test_no_company_names_on_player_surfaces(self):
        for src in (self.player, self.lyrics, self.style):
            self.assertNotIn("Spotify", src)
            self.assertNotIn("Genius", src)

    def test_audio_key_stays_off_the_client(self):
        self.assertNotIn("'audio_key'", self.player)
        self.assertNotIn("'audio_key'", self.lyrics)
        self.assertNotIn("'audio_key'", self.engine)

    def test_list_tap_opens_parchment_even_without_audio(self):
        self.assertIn("openFromRows", self.player)
        self.assertIn("MezmurPlayerScreen.openFromRows", self.hymns)
        self.assertIn("MezmurPlayerScreen.openFromRows", self.cat)
        self.assertIn("There is no audio for this hymn currently.", self.player)
        # curator details stay reachable so the offline-detail contract holds
        self.assertIn("MezmurHymnDetailScreen", self.hymns)

    def test_frosted_glass_under_transport(self):
        self.assertIn("BackdropFilter", self.player)
        self.assertIn("ImageFilter.blur", self.player)
        self.assertIn("class _GlassPanel", self.player)

    def test_header_is_empty_with_high_contrast_back(self):
        header = self.player.split("Widget _buildHeader")[1].split("Widget _buildTitle")[0]
        self.assertNotIn("☩", header)
        self.assertNotIn("መዝሙር", header)
        self.assertNotIn("more_horiz", header)
        self.assertIn("Icons.tune_rounded", header)
        self.assertIn("Playback settings", self.player)
        self.assertIn("0xFF1A0C06", self.player)
        self.assertIn("0xF5F8EBCB", self.player)

    def test_lyrics_paint_before_audio_opens(self):
        # Lyrics box is never gated on _opening / currentTrack.
        box = self.player.split("Widget _buildLyricsBox()")[1].split("Widget _buildConsole")[0]
        self.assertNotIn("_opening", box)
        self.assertNotIn("CircularProgressIndicator", box)
        self.assertIn("MezmurLyricsScreen(", box)
        self.assertIn("_paintFrom", self.lyrics)
        self.assertIn("widget.track.lyrics", self.lyrics)

    def test_play_button_does_not_spin_while_playing(self):
        self.assertIn("final loading = !_c.playing && (_opening || _c.buffering);", self.player)
        self.assertNotIn("loading: _opening || _c.buffering", self.player)
        self.assertIn("setRate", self.engine)
        self.assertIn("setSpeed", self.engine)

    def test_catalog_skip_visits_hymns_without_audio(self):
        self.assertIn("openCatalog", self.engine)
        self.assertIn("skipView", self.engine)
        self.assertIn("moveTo", self.engine)
        self.assertIn("_c.openCatalog", self.player)
        self.assertIn("PageView.builder", self.player)
        self.assertIn("onPageChanged", self.player)
        # Skip buttons stay wired even when the hymn has no audio.
        self.assertGreaterEqual(self.player.count("_c.previous"), 2)
        self.assertGreaterEqual(self.player.count("_c.next"), 2)

    def test_mini_player_returns_to_session(self):
        self.assertIn("class MezmurMiniPlayer", self.mini)
        self.assertIn("openSession", self.mini)
        self.assertIn("showMiniPlayer", self.engine)
        self.assertIn("setPlayerVisible", self.engine)
        self.assertIn("MezmurMiniPlayer", self.shell)
        self.assertIn("JustAudioBackground.init", (
            ROOT / "Mobile/wbws_flutter_app/lib/main.dart"
        ).read_text(encoding="utf-8"))
        self.assertNotIn("'audio_key'", self.mini)

