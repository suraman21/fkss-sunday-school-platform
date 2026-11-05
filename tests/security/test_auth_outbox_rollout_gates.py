"""Static release gates for the build-24 auth/outbox staged rollout."""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
MOBILE = ROOT / "Mobile" / "wbws_flutter_app"
RUNBOOK = ROOT / "docs" / "audits" / "AUTH_OUTBOX_BUILD24_ROLLOUT_RUNBOOK.md"
PREFLIGHT = ROOT / "sql" / "preflight" / "auth_outbox_preflight.sql"
VERIFY = ROOT / "sql" / "preflight" / "auth_outbox_verify.sql"


class Build24ReleaseGateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.runbook = RUNBOOK.read_text(encoding="utf-8")
        cls.preflight = PREFLIGHT.read_text(encoding="utf-8")
        cls.verify = VERIFY.read_text(encoding="utf-8")
        cls.release = (ROOT / "api/v1/core/app_release.php").read_text(encoding="utf-8")
        cls.release_example = (ROOT / "api/v1/app_release.example.php").read_text(encoding="utf-8")
        cls.app_route = (ROOT / "api/v1/routes/app.php").read_text(encoding="utf-8")
        cls.update = (MOBILE / "lib/services/app_update_service.dart").read_text(encoding="utf-8")
        cls.session = (MOBILE / "lib/services/session_service.dart").read_text(encoding="utf-8")
        cls.sync = (MOBILE / "lib/services/sync_service.dart").read_text(encoding="utf-8")
        cls.comm = (MOBILE / "lib/services/comm_outbox_service.dart").read_text(encoding="utf-8")
        cls.hymn = (MOBILE / "lib/services/hymn_store.dart").read_text(encoding="utf-8")

    def test_release_version_sources_are_build_41(self):
        # Build 41: mobile offline class-day attendance gating.
        pubspec = (MOBILE / "pubspec.yaml").read_text(encoding="utf-8")
        config = (MOBILE / "lib/utils/config.dart").read_text(encoding="utf-8")
        notes = (MOBILE / "RELEASE_NOTES.md").read_text(encoding="utf-8")
        self.assertRegex(pubspec, r"(?m)^version:\s*1\.7\.4\+41\s*$")
        self.assertIn("appVersion = '1.7.4'", config)
        self.assertIn("appBuild = 41", config)
        self.assertIn("## 1.7.4 (build 41)", notes)
        for source in (self.release, self.release_example):
            self.assertRegex(source, r"'latest_version'\s*=>\s*'1\.7\.4'")
            self.assertRegex(source, r"'latest_build'\s*=>\s*41")

    def test_release_config_exposes_a_strict_drain_switch(self):
        self.assertIn("'background_drains_enabled' => true", self.release)
        self.assertIn(
            "$out['background_drains_enabled'] === true", self.release
        )
        self.assertIn("'background_drains_enabled' => true", self.release_example)
        self.assertIn(
            "$features['background_outbox_drain'] = $rel['background_drains_enabled']",
            self.app_route,
        )
        self.assertIn("'features' => $features", self.app_route)

    def test_mobile_holds_drains_until_release_check_and_caches_flag(self):
        self.assertIn("backgroundDrainFeature = 'background_outbox_drain'", self.update)
        self.assertGreaterEqual(self.update.count("_releaseCheckCompleted = false"), 2)
        self.assertIn("_releaseCheckCompleted = true", self.update)
        self.assertIn("_releaseCheckCompleted && featureEnabled", self.update)
        self.assertIn("_saveCapabilityCache(config!)", self.update)
        self.assertIn("Future<void>? _checkFuture", self.update)

    def test_all_outbound_workers_are_gated_between_claims(self):
        for source in (self.sync, self.comm, self.hymn):
            self.assertIn("bool Function()? drainEnabledGate", source)
            self.assertIn("bool get _drainsAllowed", source)
            self.assertIn("_drainsAllowed", source)
        self.assertRegex(
            self.sync,
            r"for \(var guard = 0; guard < 100; guard\+\+\) \{[\s\S]{0,400}if \(!_drainsAllowed\) break;",
        )
        comm_preclaim = self.comm.split("Future<void> _drainOnce", 1)[1].split(
            "claimNextDueHead", 1
        )[0]
        self.assertIn("_drainsAllowed", comm_preclaim)
        self.assertRegex(
            self.hymn,
            r"while \(_ownsGeneration\(generation\) && _drainsAllowed && scans < 100\)",
        )

    def test_release_compile_contracts_match_worker_and_database_helpers(self):
        local_db = (MOBILE / "lib/services/local_db.dart").read_text(encoding="utf-8")
        self.assertIn("static int _asIntLocal(dynamic v)", local_db)
        schedule = self.comm.split("void _scheduleNextRetry", 1)[1].split(
            ".then((due)", 1
        )[0]
        self.assertIn("CommStore.instance.outboxNextDue()", schedule)
        self.assertNotIn("ownerUserId:", schedule)
        self.assertNotIn("authorizationVersion:", schedule)

    def test_session_switch_stops_only_outbound_workers(self):
        self.assertGreaterEqual(self.session.count("drainEnabledGate ="), 3)
        gate = self.session.split("void reconcileReleaseGates()", 1)[1].split(
            "void _startPrivateServices()", 1
        )[0]
        self.assertIn("SyncService().startAutoSync()", gate)
        self.assertIn("CommOutboxService.instance.start()", gate)
        self.assertIn("SyncService().stopAutoSync()", gate)
        self.assertIn("CommOutboxService.instance.stop()", gate)
        for forbidden in ("delete", "purge", "clearCredentials", "clearDatabase"):
            self.assertNotIn(forbidden, gate)

    def test_preflight_covers_all_required_migrations_and_blocks(self):
        for migration in ("009", "010", "044", "045", "046", "048"):
            self.assertIn(f"'{migration}'", self.preflight)
            self.assertIn(f"'{migration}'", self.verify)
        for script in (self.preflight, self.verify):
            self.assertIn("information_schema", script)
            self.assertIn("SQLSTATE '45000'", script)
            self.assertIn("'BLOCK'", script)
        self.assertIn("duplicate non-null message client tags", self.preflight)
        self.assertIn("duplicate non-null message client tags", self.verify)
        self.assertIn("authorization_version values below one", self.verify)
        for trigger in (
            "trg_users_authorization_bu",
            "trg_teacher_assignments_authorization_ai",
            "trg_teacher_assignments_authorization_au",
            "trg_teacher_assignments_authorization_ad",
        ):
            self.assertIn(trigger, self.verify)

    def test_runbook_pins_rollout_order_and_compatibility_window(self):
        required = (
            "Build 23 remains usable during the compatibility window",
            "Phase 3 — controlled build 24 pilot",
            "Phase 4 — broader rollout, build 23 still compatible",
            "minimum build, then global live enforcement",
            "min_build=24",
            "API_AUTHZ_LEGACY_COMPAT_UNTIL",
            "Remove it only in a later reviewed cleanup",
            "background_drains_enabled=false",
            "SQLite v34 is forward-only",
            "Never tell a user to reinstall or downgrade to build 23",
            "ship build 25 (or later) as a v34-aware corrective patch",
        )
        for phrase in required:
            self.assertIn(phrase, self.runbook)
        min_pos = self.runbook.index("Set production release metadata to `min_build=24`")
        auth_pos = self.runbook.index("Only now set `API_AUTHZ_LEGACY_COMPAT_UNTIL`")
        self.assertLess(min_pos, auth_pos)

    def test_runbook_contains_every_physical_device_drill(self):
        for heading in (
            "Reauthentication and crash recovery",
            "Owner mismatch",
            "Live role/scope downgrade",
            "Queue policy matrix",
            "Communication FIFO",
            "Hymn durability/dependencies",
            "Exact legacy-operation races",
            "Explicit logout and forgot PIN",
            "Kill-switch drill",
            "Low-memory hardware",
        ):
            self.assertIn(heading, self.runbook)
        for operation in ("attendance", "grades", "Mezmur attendance", "HR attendance"):
            self.assertIn(operation, self.runbook)

    def test_runbook_prohibits_sensitive_observability(self):
        self.assertIn("aggregate-only", self.runbook)
        for forbidden_detail in (
            "tokens",
            "payload JSON",
            "attendance or grade values",
            "message bodies",
            "full client operation ids",
        ):
            self.assertIn(forbidden_detail, self.runbook)
        allowed = re.search(
            r"Allowed aggregate counters/reason-code groupings include:\s*```text(.*?)```",
            self.runbook,
            re.S,
        )
        self.assertIsNotNone(allowed)
        self.assertIn("superseded_local_count_by_kind", allowed.group(1))


if __name__ == "__main__":
    unittest.main()
