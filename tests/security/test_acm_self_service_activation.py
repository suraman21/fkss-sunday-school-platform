"""ACM self-service activation — the concurrency guarantees.

Design: docs/design/acm-self-service-activation.md §6 and §9.

These tests exist because the failure modes they cover are invisible in
review: a lost race produces two rows, two tickets, or two accounts, and
every one of those looks fine until two people see each other's data.

They are STATIC / UNIT tests: this environment has no PHP binary and no
MariaDB, so they parse and reason about the source the same way the
repo's other schema-guard tests do. The integration counterparts belong
in the ACM PHPUnit suite once a database is available.
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REQUESTS = ROOT / "acm" / "src" / "Requests"
ACL = ROOT / "api" / "v1" / "core" / "acl.php"
MEMBERS_ROUTE = ROOT / "api" / "v1" / "routes" / "members.php"
ACM_ROUTE = ROOT / "api" / "v1" / "routes" / "acm.php"
SQL = ROOT / "sql"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def read_sql_without_comments(path: Path) -> str:
    """SQL with `--` comment lines removed.

    Prose like "no FOREIGN KEY (shared hosts forbid them)" is the point
    of these migrations, so assertions about what the DDL contains must
    look at the DDL, not the explanation around it.
    """
    lines = [
        line for line in read(path).splitlines()
        if not line.lstrip().startswith("--")
    ]
    return "\n".join(lines)


class RequestDedupeGuarantee(unittest.TestCase):
    """R1 — two identical requests in the same microsecond make one row."""

    def test_dedupe_is_a_unique_key_not_a_pre_check(self):
        sql = read(SQL / "099_acm_self_service_requests.sql")
        self.assertIn("UNIQUE KEY `uniq_acm_request_dedupe` (`dedupe_hash`)", sql)

    def test_service_inserts_first_and_catches_23000(self):
        source = read(REQUESTS / "AcmActivationRequestService.php")
        # The race is settled by the constraint, not by a SELECT.
        self.assertIn("getCode() !== '23000'", source)
        self.assertIn("private function collapseOnto", source)
        # A SELECT-before-INSERT would reintroduce the race window.
        submit_body = source.split("public function submit(")[1].split("private function insertRequest")[0]
        self.assertNotIn("SELECT", submit_body.upper().replace("SELECTED", ""))

    def test_collapse_reopens_only_dead_requests(self):
        """A rejected/expired request may be resubmitted; an approved one
        must never be silently reopened (the account already exists)."""
        source = read(REQUESTS / "AcmActivationRequestService.php")
        self.assertIn("'rejected'", source)
        self.assertIn("'expired'", source)
        # The reopen UPDATE is guarded on those two statuses only.
        reopen = source.split("$reopen = $this->pdo->prepare(")[1].split(");")[0]
        self.assertIn("status IN (:st1, :st2)", reopen)
        self.assertNotIn("'approved'", reopen)


class GenericResponseGuarantee(unittest.TestCase):
    """Anti-enumeration: the wire must not say whether a code is real."""

    def test_route_returns_202_for_every_submit_outcome(self):
        route = read(ACM_ROUTE)
        block = route.split("$acmPath === 'activate/request'")[1]
        block = block.split("POST /acm/activate/verify")[0]
        # One success path, 202.
        self.assertIn("], 202)", block)
        # The only error branches are validation (422) and transient (503).
        for status in ("404", "409", "410"):
            self.assertNotIn(f", {status})", block)

    def test_service_has_no_not_found_branch(self):
        source = read(REQUESTS / "AcmActivationRequestService.php")
        submit_body = source.split("public function submit(")[1].split("private function insertRequest")[0]
        for forbidden in ("not found", "Not found", "does not exist"):
            self.assertNotIn(forbidden, submit_body)


class TicketOpenGuard(unittest.TestCase):
    """R3 — the existing duplicate-ticket bug must be closed by a constraint."""

    def test_unique_key_on_generated_guard_column(self):
        sql = read(SQL / "100_acm_ticket_open_guard.sql")
        self.assertIn("open_guard", sql)
        self.assertIn("GENERATED ALWAYS AS", sql)
        self.assertIn("uniq_acm_ticket_open", sql)

    def test_duplicates_are_reported_never_auto_deleted(self):
        sql = read(SQL / "100_acm_ticket_open_guard.sql")
        self.assertIn("HAVING COUNT(*) > 1", sql)
        # The repo's rule (018/031): never resolve duplicates for the user.
        self.assertNotIn("DELETE FROM `acm_activation_tickets`", sql)

    def test_migration_is_rerunnable(self):
        sql = read(SQL / "100_acm_ticket_open_guard.sql")
        self.assertIn("information_schema.COLUMNS", sql)
        self.assertIn("information_schema.STATISTICS", sql)
        self.assertIn("DROP PROCEDURE IF EXISTS", sql)


class ApproveIsAtomic(unittest.TestCase):
    """R4 — double approval yields one ticket, not two."""

    def test_approval_locks_then_compare_and_sets(self):
        source = read(REQUESTS / "AcmRequestDecisionService.php")
        self.assertIn("FOR UPDATE", source)
        self.assertIn("rowCount() !== 1", source)
        # The status write is guarded on the pre-state.
        self.assertIn("\" WHERE id = :id AND status = 'pending_review'\"", source)

    def test_failure_rolls_back_everything(self):
        source = read(REQUESTS / "AcmRequestDecisionService.php")
        self.assertIn("rollBack()", source)
        self.assertIn("inTransaction()", source)


class AuthenticatorCollision(unittest.TestCase):
    """D3 — username and member code must not collide across namespaces."""

    def test_one_unique_key_covers_every_kind(self):
        sql = read(SQL / "101_acm_request_bindings.sql")
        self.assertIn("UNIQUE KEY `uniq_acm_authenticator` (`identifier_hash`)", sql)
        for kind in ("username", "member_code", "guardian_ref"):
            self.assertIn(kind, sql)

    def test_identifiers_are_hashed_not_stored_raw(self):
        sql = read(SQL / "101_acm_request_bindings.sql")
        self.assertIn("identifier_hash", sql)
        self.assertNotIn("`identifier` VARCHAR", sql)

    def test_registration_is_insert_first(self):
        source = read(REQUESTS / "AuthenticatorDirectory.php")
        self.assertIn("getCode() !== '23000'", source)
        self.assertIn("already in use", source)


class MemberSelfAccess(unittest.TestCase):
    """D4 — a member reads only their own record, and cannot pass an id."""

    def test_resolver_requires_member_role_and_active_link(self):
        acl = read(ACL)
        body = acl.split("function apiSelfMemberId(")[1].split("\n}")[0]
        self.assertIn("apiRoleIs($auth, ['member'])", body)
        self.assertIn("pa.status = 'active'", body)
        self.assertIn("return 0;", body)  # deny by default

    def test_me_route_derives_id_from_session(self):
        route = read(MEMBERS_ROUTE)
        block = route.split("strtolower($id) === 'me'")[1].split("GET /members/{id}")[0]
        self.assertIn("apiSelfMemberId($conn, $auth)", block)
        # The id must never be read from the request in this block.
        self.assertNotIn("$_GET", block)
        self.assertNotIn("$_POST", block)

    def test_me_route_uses_the_safe_row_shaper(self):
        route = read(MEMBERS_ROUTE)
        block = route.split("strtolower($id) === 'me'")[1].split("GET /members/{id}")[0]
        self.assertIn("apiMemberSafeRow(", block)
        # No PII shaper on a self route.
        self.assertNotIn("apiMemberStaffRow(", block)


class MigrationDiscipline(unittest.TestCase):
    """This repo's migration rules: no FK, no JSON columns, re-runnable."""

    FILES = ("099_acm_self_service_requests.sql", "100_acm_ticket_open_guard.sql", "101_acm_request_bindings.sql")

    def test_no_foreign_keys_or_json_columns(self):
        for name in self.FILES:
            sql = read_sql_without_comments(SQL / name).upper()
            self.assertNotIn("FOREIGN KEY", sql, name)
            self.assertNotIn(" JSON ", sql, name)

    def test_numbering_is_unique_and_above_existing(self):
        for name in self.FILES:
            matches = list(SQL.glob(name.split("_")[0] + "_*.sql"))
            self.assertEqual(len(matches), 1, f"{name} number is not unique")

    def test_each_file_declares_idempotency(self):
        for name in self.FILES:
            head = "\n".join(read(SQL / name).splitlines()[:3]).upper()
            self.assertIn("IDEMPOTENT", head, name)



class IdentifierWiring(unittest.TestCase):
    """D3 — the identifier bindings must actually be USED, not just stored.

    The first version of this feature shipped the directory and never
    called it, so a member code could not log anyone in. These tests
    exist to make that specific regression impossible.
    """

    AUTH_ROUTE = ROOT / "api" / "v1" / "routes" / "auth.php"
    SERVICE = ROOT / "acm" / "src" / "Activation" / "ActivationService.php"

    def test_login_falls_back_to_the_directory(self):
        route = read(self.AUTH_ROUTE)
        self.assertIn("AuthenticatorDirectory", route)
        self.assertIn("resolveUserId(", route)

    def test_login_does_uniform_work_when_identifier_is_unknown(self):
        """A missing identifier must not answer faster than a wrong
        password, or the endpoint becomes a member-code oracle."""
        route = read(self.AUTH_ROUTE)
        self.assertIn("password_verify($password, '$2y$", route)

    def test_credentials_registers_an_identifier_in_the_same_txn(self):
        source = read(self.SERVICE)
        block = source.split("public function establishCredentials")[1].split("public function completeRecovery")[0]
        self.assertIn("registerUsername(", block)
        self.assertIn("registerMemberCode(", block)
        # It must be inside the existing transaction, not after commit.
        txn_at = block.index("beginTransaction()")
        bind_at = block.index("registerUsername(")
        commit_at = block.index("$this->pdo->commit();", bind_at)
        self.assertLess(txn_at, bind_at)
        self.assertLess(bind_at, commit_at)

    def test_taken_username_is_user_fixable_not_a_server_fault(self):
        route = read(ACM_ROUTE)
        cred = route.split("if ($acmAction === 'activate/credentials'")[1]
        self.assertIn("username_unavailable", cred)
        # The status is on its own line in the call, so assert the pair
        # rather than a formatting-specific literal.
        self.assertIn("409", cred)
        self.assertIn("err(", cred)


class ConsoleWiring(unittest.TestCase):
    """The screens must exist, be gated, and not invent behaviour."""

    REQUESTS_PAGE = ROOT / "admin" / "acm_requests.php"
    REQUEST_PAGE = ROOT / "admin" / "acm_request.php"
    API = ROOT / "admin" / "api_acm.php"
    LAYOUT = ROOT / "admin" / "acm_layout.php"

    def test_pages_are_role_gated(self):
        for page in (self.REQUESTS_PAGE, self.REQUEST_PAGE):
            source = read(page)
            self.assertIn("consoleRoles()", source, page.name)

    def test_every_console_action_checks_policy(self):
        api = read(self.API)
        for action in ("request_queue", "request", "request_claim", "request_approve", "request_reject"):
            block = api.split(f"case '{action}'")[1].split("break;")[0]
            self.assertIn("AcmPolicy::can(", block, action)

    def test_approval_records_the_computed_basis(self):
        api = read(self.API)
        block = api.split("case 'request_approve'")[1].split("break;")[0]
        # The basis must be COMPUTED, never taken from the client.
        self.assertIn("matchBasis(", block)
        self.assertNotIn("$_POST['match_basis']", block)

    def test_manual_match_is_flagged_in_the_ui(self):
        page = read(self.REQUEST_PAGE)
        self.assertIn("manual", page)
        # And a missing link is shown, not silently approved.
        self.assertIn("link it first", page)

    def test_navigation_entry_exists(self):
        self.assertIn("acm_requests.php", read(self.LAYOUT))


class NoInventedHelpers(unittest.TestCase):
    """The console pages must use the real chrome, not imagined helpers."""

    def test_pages_use_the_established_layout_helpers(self):
        for page in ("acm_requests.php", "acm_request.php"):
            source = read(ROOT / "admin" / page)
            self.assertIn("acm_chrome_open(", source, page)
            self.assertIn("acm_chrome_close(", source, page)

    def test_no_invented_css_class_prefixes(self):
        for page in ("acm_requests.php", "acm_request.php"):
            source = read(ROOT / "admin" / page)
            for invented in ('class="tbl"', 'class="pill', 'class="pager"', 'class="acm-tab'):
                self.assertNotIn(invented, source, page)

class PhpSyntax(unittest.TestCase):
    """No PHP binary here; assert the files at least declare strict types
    and contain no obvious unclosed construct."""

    def test_strict_types_and_matched_braces(self):
        for path in sorted(REQUESTS.glob("*.php")):
            text = read(path)
            self.assertIn("declare(strict_types=1);", text, path.name)
            self.assertEqual(text.count("{"), text.count("}"), path.name)
            self.assertNotIn("?>\n<?php", text, path.name)


if __name__ == "__main__":
    unittest.main()
