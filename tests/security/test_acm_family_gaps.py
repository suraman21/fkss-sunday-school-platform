"""Static pins for the ACM family-linking gaps (G3 -> G2 -> G1).

G3: ticket link-suggestions must query the real 069 columns
    (guardian_a/guardian_b), never the nonexistent guardian_id.
G2: verification stamps only on explicit office decisions; auto paths
    stay NULL; 092 grandfathers office-created links only (zero DDL).
G1 asymmetry: a parent resolves through acm_persons.guardian_id and a member
    through acm_persons.member_id -- deliberately NOT users.guardian_id.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]


class AcmFamilyGapsTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tickets = (ROOT / "acm/src/Accounts/AcmTicketService.php").read_text(encoding="utf-8")
        cls.migration = (ROOT / "sql/069_guardian_linkage.sql").read_text(encoding="utf-8")
        cls.guardians = (ROOT / "admin/backend/services/GuardianService.php").read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_guardians.php").read_text(encoding="utf-8")
        cls.migration092 = (ROOT / "sql/092_acm_guardian_link_verification.sql").read_text(encoding="utf-8")
        cls.js = (ROOT / "admin/js/family_link.js").read_text(encoding="utf-8")
        cls.acl = (ROOT / "api/v1/core/acl.php").read_text(encoding="utf-8")
        cls.users_route = (ROOT / "api/v1/routes/users.php").read_text(encoding="utf-8")
        cls.members_route = (ROOT / "api/v1/routes/members.php").read_text(encoding="utf-8")

    def test_suggestions_query_uses_pair_columns(self):
        method = self.tickets[self.tickets.index("private function linkSuggestions"):]
        method = method[: method.index("\n    }\n")]
        self.assertIn("guardian_a", method)
        self.assertIn("guardian_b", method)
        self.assertIn("guardian_link_suggestions", method)

    def test_suggestions_query_has_no_bare_guardian_id_column(self):
        method = self.tickets[self.tickets.index("private function linkSuggestions"):]
        method = method[: method.index("\n    }\n")]
        # The $guardianId parameter may stay; a guardian_id COLUMN must not appear.
        self.assertNotRegex(method, r"SELECT[^;]*\bguardian_id\b")
        self.assertNotRegex(method, r"WHERE[^;]*\bguardian_id\b")

    def test_suggestions_failure_stays_visible(self):
        method = self.tickets[self.tickets.index("private function linkSuggestions"):]
        method = method[: method.index("\n    }\n")]
        self.assertIn("catch", method)
        self.assertIn("error_log", method)

    def test_schema_has_pair_columns_and_no_single_column(self):
        table = self.migration[self.migration.index("CREATE TABLE IF NOT EXISTS `guardian_link_suggestions`"):]
        table = table[: table.index(";", table.index("PRIMARY KEY"))]
        self.assertIn("`guardian_a`", table)
        self.assertIn("`guardian_b`", table)
        self.assertNotRegex(table, r"`guardian_id`")

    def test_link_member_defaults_to_unverified(self):
        sig = re.search(r"function linkMember\([^)]*\)", self.guardians).group(0)
        self.assertIn("bool $officeVerified = false", sig)
        # The derived path keeps the historical statement (no verified cols).
        self.assertIn(
            "ON DUPLICATE KEY UPDATE relation=VALUES(relation), contact_priority=VALUES(contact_priority), is_primary=VALUES(is_primary)",
            self.guardians,
        )

    def test_only_explicit_calls_stamp_verification(self):
        stamped = re.findall(r"linkMember\([^;]*?,\s*true,\s*true\)", self.guardians)
        # picked branch only (api link action lives in api_guardians.php).
        self.assertEqual(1, len(stamped))
        self.assertIn("linkMember($conn, $gid, $mid, $rel, $prio, $prim, true, true)", self.api)

    def test_verify_and_clear_exist_and_audit(self):
        self.assertIn("function verifyLink(", self.guardians)
        self.assertIn("function clearVerification(", self.guardians)
        self.assertIn("Guardian Link Verified", self.guardians)
        self.assertIn("Guardian Link Unverified", self.guardians)

    def test_verify_actions_use_write_gate(self):
        writes = re.search(r"\$__writeActions = \[(.*?)\];", self.api, re.S).group(1)
        self.assertIn("'verify_link'", writes)
        self.assertIn("'unverify_link'", writes)
        self.assertIn("case 'verify_link'", self.api)
        self.assertIn("case 'unverify_link'", self.api)

    def test_children_reports_verification_flag(self):
        self.assertIn("mg.verified_by IS NOT NULL AS is_verified", self.guardians)

    def test_092_grandfathers_office_links_only_and_has_zero_ddl(self):
        body = re.sub(r"--.*", "", self.migration092)
        self.assertNotRegex(body.upper(), r"\bCREATE\s+TABLE\b")
        self.assertNotRegex(body.upper(), r"\bALTER\s+TABLE\b")
        self.assertIn("verified_by = created_by", self.migration092)
        self.assertIn("verified_by IS NULL AND created_by > 0", self.migration092)

    def test_drawer_shows_verify_controls(self):
        self.assertIn("FamilyLink.verify(", self.js)
        self.assertIn("FamilyLink.unverify(", self.js)
        self.assertIn("verify: verify, unverify: unverify", self.js)
        self.assertIn("links_unverified", self.js)

    def test_parent_resolver_requires_active_parent_link(self):
        fn = self.acl[self.acl.index("function apiParentGuardianId"):]
        fn = fn[: fn.index("\n}\n")]
        self.assertIn("apiRoleIs($auth, ['parent'])", fn)
        self.assertIn("pa.account_type = 'parent'", fn)
        self.assertIn("pa.status = 'active'", fn)
        self.assertIn("p.guardian_id IS NOT NULL", fn)
        self.assertIn("catch (\\mysqli_sql_exception", fn)

    def test_parent_scope_requires_verified_link(self):
        fn = self.acl[self.acl.index("function apiParentCanSeeMember"):]
        fn = fn[: fn.index("\n}\n")]
        self.assertIn("verified_by IS NOT NULL", fn)

    def test_parent_children_verified_non_archived_safe_shaped(self):
        fn = self.acl[self.acl.index("function apiParentChildren"):]
        fn = fn[: fn.index("\n}\n")]
        self.assertIn("mg.verified_by IS NOT NULL", fn)
        self.assertIn("m.status != 'archived'", fn)
        self.assertIn("apiMemberSafeRow($row)", fn)
        self.assertIn("$child['relation']", fn)
        self.assertNotIn("apiMemberStaffRow", fn)

    def test_children_route_is_parent_only_and_rate_limited(self):
        self.assertIn("$subAction === 'children'", self.users_route)
        self.assertIn("Your children list is only for parent accounts.", self.users_route)
        self.assertIn("isApiRateLimited('parent_children', 60)", self.users_route)
        self.assertIn("apiParentChildren($conn, $auth)", self.users_route)

    def test_member_detail_admits_parents_for_own_children_only(self):
        self.assertIn("apiParentCanSeeMember($conn, $auth, $id)", self.members_route)
        self.assertIn("You can only open your own linked children.", self.members_route)
        # Parents still get the safe row; staff shaping stays PII-gated.
        self.assertIn(
            "ok($canPii ? apiMemberStaffRow($member) : apiMemberSafeRow($member));",
            self.members_route,
        )

    def test_members_list_stays_staff_only(self):
        self.assertIn(
            "err('Members list is only for Information and Education staff.', 403);",
            self.members_route,
        )
        browse = self.acl[self.acl.index("function apiCanBrowseMembers"):]
        browse = browse[: browse.index("\n}\n")]
        self.assertNotIn("parent", browse)


if __name__ == "__main__":
    unittest.main()


class NoUsersGuardianIdTests(unittest.TestCase):
    """Why `users` has member_id but no guardian_id -- and must not get one.

    HISTORY: `users.member_id` predates ACM. It was added by
    sql/012_runtime_schema_baseline.sql for the SCHOOL side (teacher and
    education features join it). ACM never used it for authorization: both
    resolvers read acm_persons, because that is the table that also carries the
    account STATUS.

    THE TRAP THIS PREVENTS
        A "member_id but no guardian_id" asymmetry looks like an oversight, so
        the tempting fix is `ALTER TABLE users ADD COLUMN guardian_id`. But
        AccountProvisioner:75 writes member_id as NULL for a parent account ON
        PURPOSE, and apiParentGuardianId() refuses unless the account row is
        account_type='parent' AND status='active'. Copying the guardian id onto
        `users` would hand the resolver a link that survives suspension -- the
        exact thing sql/089 exists to stop. Two places to revoke an account is
        one place too many.

    The asymmetry is therefore real but harmless: guardian_id lives on
    acm_persons, one join away, next to the status that gates it.
    """

    @classmethod
    def setUpClass(cls):
        root = ROOT
        cls.acl = (root / "api/v1/core/acl.php").read_text(encoding="utf-8")
        cls.provisioner = (root / "acm/src/Accounts/AccountProvisioner.php").read_text(encoding="utf-8")
        cls.baseline = (root / "sql/012_runtime_schema_baseline.sql").read_text(encoding="utf-8")

    def test_users_member_id_is_legacy_school_schema_not_acm(self):
        # It predates ACM: it is added in the runtime baseline, not in 079+.
        self.assertIn("ADD COLUMN `member_id` INT UNSIGNED", self.baseline)
        self.assertIn("ALTER TABLE `users`", self.baseline)

    def test_parent_accounts_do_not_store_a_member_id(self):
        # member_id is written only for member accounts; parents get NULL.
        self.assertIn("$accountType === 'member' ? (int)$ticket['member_id'] : null", self.provisioner)

    def test_both_resolvers_read_acm_persons_and_gate_on_status(self):
        for fn in ("function apiParentGuardianId", "function apiSelfMemberId"):
            body = self.acl[self.acl.index(fn):]
            body = body[:body.index("\n}")]
            self.assertIn("acm_person_accounts", body, fn)
            self.assertIn("acm_persons", body, fn)
            self.assertIn("status = 'active'", body, fn)

    def test_no_resolver_authorizes_off_users_member_id(self):
        """A resolver reading users.member_id would ignore suspension."""
        for fn in ("function apiParentGuardianId", "function apiSelfMemberId"):
            body = self.acl[self.acl.index(fn):]
            body = body[:body.index("\n}")]
            self.assertNotIn("FROM users", body, fn)
            self.assertNotIn("users.member_id", body, fn)

    def test_the_two_resolvers_are_mutually_exclusive_by_design(self):
        """One role string, one account row -- so at most one resolves.

        users.role is a single VARCHAR, not a list, and acm_person_accounts has
        UNIQUE(user_id). A person who is both a member and a parent therefore
        holds ONE account: either the guardian link or the member link is
        reachable, never both. That is a product consequence, not a bug, and it
        is recorded here so nobody discovers it from a support ticket.
        """
        self.assertIn("UNIQUE KEY `uniq_acm_pa_user` (`user_id`)",
                      (ROOT / "sql/079_acm_p0_foundation.sql").read_text(encoding="utf-8"))
        self.assertIn("AND pa.account_type = 'parent'", self.acl)
        self.assertIn("AND pa.account_type = 'member'", self.acl)
