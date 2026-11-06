"""S2 Goal A.1 — architectural invariants of the background sync core.

WHY THESE ARE PYTHON TESTS

The Dart tests in `test/sync_execution_test.dart` prove the behaviour of the
coordinator, but they cannot prove a *negative* about the whole codebase, and
they cannot run in this environment at all (no Flutter SDK, and `pubspec.yaml`
is frozen by F-19 so no sqflite test binding can be added).

The single most important property of this increment is a negative one:

    there is exactly ONE authoritative drain, and background execution
    cannot acquire its own path to the outbox.

That is a whole-repository claim, so it is pinned the same way this suite
already pins other architectural contracts (see test_section_source_of_truth).
These run locally today and in CI.
"""

import os
import re
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
APP = os.path.join(ROOT, "Mobile", "wbws_flutter_app")
LIB = os.path.join(APP, "lib", "services")


def read(*parts):
    with open(os.path.join(*parts), "r", encoding="utf-8") as handle:
        return handle.read()


def strip_comments(source):
    """Remove // and /* */ comments so prose can never satisfy a test."""
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    return "\n".join(
        re.sub(r"//.*$", "", line) for line in source.splitlines()
    )


class BackgroundSyncCoreArchitecture(unittest.TestCase):
    def setUp(self):
        self.execution = read(LIB, "sync_execution.dart")
        self.sync_service = read(LIB, "sync_service.dart")
        self.local_db = read(LIB, "local_db.dart")
        self.schema = read(LIB, "local_schema_v34.dart")
        self.attempts = read(LIB, "sync_attempt_models.dart")

    # ── one authoritative path ──────────────────────────────────────────
    def test_exactly_one_legacy_claim_call_site(self):
        """A second claim site would be a second engine reaching the outbox."""
        hits = []
        for folder, _dirs, files in os.walk(os.path.join(APP, "lib")):
            for name in files:
                if not name.endswith(".dart"):
                    continue
                body = strip_comments(read(folder, name))
                for match in re.finditer(r"claimNextLegacyOperation\s*\(", body):
                    # the declaration itself is not a call site
                    prefix = body[max(0, match.start() - 40):match.start()]
                    if "Future<" in prefix:
                        continue
                    hits.append(os.path.join(folder, name))
        self.assertEqual(
            len(hits), 1,
            "expected a single claimNextLegacyOperation call site, found: %r" % hits,
        )
        self.assertTrue(hits[0].endswith("sync_service.dart"))

    def test_sync_all_delegates_to_the_unified_entry_point(self):
        body = strip_comments(self.sync_service)
        self.assertIn("Future<SyncResult> runSyncNow(", body)
        # syncAll must not re-implement the drain; it must call runSyncNow.
        match = re.search(
            r"Future<SyncResult>\s+syncAll\s*\([^)]*\)\s*=>\s*([^;]+);", body, re.S
        )
        self.assertIsNotNone(match, "syncAll should be a thin delegation")
        self.assertIn("runSyncNow", match.group(1))

    def test_no_second_drain_implementation(self):
        body = strip_comments(self.sync_service)
        self.assertEqual(
            len(re.findall(r"Future<SyncResult>\s+_drain\s*\(", body)), 1,
            "more than one _drain implementation would fork the engine",
        )

    # ── execution source reaches the durable ledger ─────────────────────
    def test_execution_source_is_threaded_into_the_claim(self):
        service = strip_comments(self.sync_service)
        self.assertIn("executionSource: source", service,
                      "the drain must hand its source to the claim")
        db = strip_comments(self.local_db)
        # S2 Goal A.7 added a SECOND ledger call site (the hymn claim), at
        # which point a bare substring check became satisfiable by either one
        # alone — deleting the legacy propagation stopped being caught. Both
        # claims that open an attempt must hand the source down.
        self.assertEqual(
            db.count("executionSource: executionSource"), 2,
            "the legacy claim AND the hymn claim must each hand the source "
            "to the attempt ledger",
        )
        self.assertIn("'execution_source': executionSource.storageValue", db,
                      "the source must be written as a durable column value")

    def test_schema_version_and_column(self):
        self.assertIn("const localDatabaseSchemaVersion = 42;", self.schema)
        self.assertIn("execution_source TEXT NOT NULL DEFAULT 'foreground'",
                      self.schema)

    def test_migration_is_guarded_and_additive(self):
        db = strip_comments(self.local_db)
        self.assertIn("if (oldVersion < 37)", db)
        self.assertIn(
            "ALTER TABLE sync_attempts ADD COLUMN execution_source", db
        )
        self.assertIn("columns.contains('execution_source')", db,
                      "adding the column twice must be impossible")
        # An additive migration must never rewrite or drop lineage.
        window = db.split("if (oldVersion < 37)", 1)[1][:1200]
        for forbidden in ("DROP TABLE", "DELETE FROM sync_attempts", "UPDATE sync_attempts"):
            self.assertNotIn(forbidden, window)

    def test_operation_identity_is_still_client_op_id(self):
        self.assertIn("clientOpId", self.attempts)
        body = strip_comments(self.execution)
        for invented in ("operationUuid", "backgroundOpId", "jobId"):
            self.assertNotIn(invented, body,
                             "operation identity must remain client_op_id")

    # ── the boundary stays a boundary ───────────────────────────────────
    def test_execution_core_is_dependency_free(self):
        imports = re.findall(r"^\s*import\s+'([^']+)'", self.execution, re.M)
        self.assertEqual(
            imports, [],
            "sync_execution.dart must import nothing so it stays VM-testable "
            "and platform-agnostic; found %r" % imports,
        )

    def test_no_scheduling_package_was_added(self):
        pubspec = read(APP, "pubspec.yaml")
        for banned in ("workmanager", "android_alarm_manager", "background_fetch"):
            self.assertNotIn(banned, pubspec,
                             "F-19: pubspec must not gain a scheduler package")

    def test_scheduler_request_cannot_carry_payload_or_credentials(self):
        body = strip_comments(self.execution)
        request = body.split("class BackgroundSyncRequest", 1)[1]
        request = request.split("abstract class", 1)[0]
        fields = re.findall(r"final\s+([\w<>,?\s]+?)\s+(\w+);", request)
        names = sorted(name for _type, name in fields)
        self.assertEqual(
            names, ["notBefore", "requiresNetwork"],
            "a scheduler request may describe WHEN to wake, never WHAT to send",
        )
        for leak in ("token", "password", "payload", "records", "userId",
                     "authorization"):
            self.assertNotIn(leak.lower(), request.lower())

    def test_coordinator_owns_no_queue(self):
        body = strip_comments(self.execution)
        coordinator = body.split("class BackgroundSyncCoordinator", 1)[1]
        for queue_like in ("List<", "Queue<", "Map<", "insert(", "sqflite"):
            self.assertNotIn(
                queue_like, coordinator,
                "the coordinator must not hold a second copy of outbox work",
            )

    def test_no_second_retry_clock(self):
        body = strip_comments(self.execution)
        # Backoff maths belongs to outbox_policy, not to the scheduler.
        for banned in ("pow(", "backoff", "retryLadder", "Duration(seconds: 2)"):
            self.assertNotIn(banned, body)

    def test_claim_still_enforces_eligibility_and_isolation(self):
        """This increment must not have weakened the authoritative gate.

        Pinned against the *candidates* SELECT specifically rather than a
        loose window: mutation testing showed that a window-wide search still
        passed when a predicate was deleted from the SELECT, because the same
        text occurs again in the later UPDATE. The precise anchor is what
        makes M5/M6 detectable.
        """
        db = strip_comments(self.local_db)
        claim = db.split("claimNextLegacyOperation", 1)[1]
        start = claim.index("SELECT client_op_id, MIN(id) AS first_id")
        candidates = claim[start:claim.index("ORDER BY", start)]

        self.assertIn(
            "AND (next_attempt_at IS NULL OR next_attempt_at <= ?)", candidates,
            "the claim must admit only work whose retry time has arrived",
        )
        self.assertIn("AND owner_user_id = ?", candidates,
                      "account isolation must be enforced in the claim itself")
        self.assertIn("AND created_authorization_version = ?", candidates,
                      "a stale authorization must not be able to claim work")
        self.assertIn("activeSessionMatches", claim)

    def test_no_native_android_claim_in_dart(self):
        body = strip_comments(self.execution)
        for native in ("MethodChannel", "WorkManager", "androidx"):
            self.assertNotIn(
                native, body,
                "the Dart core must not reference an unverified native layer",
            )


class TriggerWiringA2(unittest.TestCase):
    """S2 Goal A.2 — every legitimate trigger reaches the one boundary.

    Dart-level tests cannot cover this: SyncService initialises ApiService()
    and LocalDb() as field initialisers, so constructing it requires sqflite,
    and no test in this repository opens a database. These pins are therefore
    the real coverage for the wiring itself; the coordinator's own behaviour
    is covered by test/sync_execution_test.dart in CI.
    """

    def setUp(self):
        self.sync_service = strip_comments(read(LIB, "sync_service.dart"))

    def _method(self, name):
        """Extract one method by brace matching, skipping the parameter list.

        Dart named parameters are themselves brace-delimited, so the body
        brace can only be located after the signature's parentheses close.
        """
        body = self.sync_service
        start = body.index(name)
        i, depth = body.index("(", start), 0
        while i < len(body):
            if body[i] == "(":
                depth += 1
            elif body[i] == ")":
                depth -= 1
                if depth == 0:
                    break
            i += 1
        i += 1
        brace, semi = body.find("{", i), body.find(";", i)
        if semi != -1 and (brace == -1 or semi < brace):
            return body[start:semi + 1]          # arrow-bodied member
        depth, j = 0, brace
        while j < len(body):
            if body[j] == "{":
                depth += 1
            elif body[j] == "}":
                depth -= 1
                if depth == 0:
                    return body[start:j + 1]
            j += 1
        raise AssertionError("unbalanced braces for " + name)

    def _body(self, name):
        """Statements inside a method, excluding its (brace-using) params."""
        text = self._method(name)
        i, depth = text.index("("), 0
        while i < len(text):
            if text[i] == "(":
                depth += 1
            elif text[i] == ")":
                depth -= 1
                if depth == 0:
                    break
            i += 1
        return text[text.index("{", i) + 1:]

    # -- A: startAutoSync ------------------------------------------------
    def test_start_auto_sync_reaches_the_boundary(self):
        body = self._method("void startAutoSync()")
        self.assertIn("nudge(", body)
        self.assertNotIn("_syncAllForGeneration", body)
        self.assertNotIn("_drain(", body)

    # -- B: nudge --------------------------------------------------------
    def test_nudge_goes_through_run_sync_now_not_the_private_drain(self):
        body = self._method("void nudge(")
        self.assertIn("runSyncNow(", body)
        self.assertNotIn("_syncAllForGeneration(", body,
                         "nudge must not reach around the boundary")

    def test_nudge_hands_over_its_captured_generation(self):
        body = self._method("void nudge(")
        self.assertIn("final generation = sessionGenerationProvider", body)
        self.assertIn("generation: generation", body)

    # -- C: one authoritative drain --------------------------------------
    def test_only_the_private_executor_calls_the_drain(self):
        calls = re.findall(r"await _drain\(", self.sync_service)
        self.assertEqual(len(calls), 1, "exactly one _drain call site")
        self.assertIn("await _drain(",
                      self._method("Future<SyncResult> _syncAllForGeneration("))

    def test_every_trigger_converges_on_run_sync_now(self):
        self.assertIn("return _coordinator.execute(source);",
                      self._method("Future<SyncResult> runSyncNow("))
        self.assertIn("_syncAllForGeneration(generation",
                      self._method("Future<SyncResult> _executeCoordinatedDrain("))

    def test_no_trigger_calls_the_executor_directly(self):
        """Declaration + the coordinator's runner + internal recursion."""
        hits = re.findall(r"_syncAllForGeneration\(", self.sync_service)
        self.assertEqual(len(hits), 3,
                         "a new direct call bypasses the boundary")

    # -- D: coalescing is the EXISTING mechanism, not a second guard ------
    def test_existing_inflight_coalescing_is_intact(self):
        executor = self._method("Future<SyncResult> _syncAllForGeneration(")
        self.assertIn("if (_inflight != null)", executor)
        self.assertIn("_queued = true", executor)
        self.assertIn("await _inflight!.future", executor)

    def test_coordinator_adds_no_second_execution_guard(self):
        """A 'busy, drop it' guard here would swallow work saved mid-drain.

        The existing mechanism deliberately does the opposite: a request
        arriving during a drain sets _queued so another pass runs.
        """
        execution = strip_comments(read(LIB, "sync_execution.dart"))
        coordinator = execution.split("class BackgroundSyncCoordinator", 1)[1]
        execute = coordinator.split("Future<R> execute(", 1)[1].split("\n  }", 1)[0]
        for guard in ("_running", "_busy", "inflight", "if (_active"):
            self.assertNotIn(guard, execute)

    # -- E: execution source ---------------------------------------------
    def test_foreground_triggers_are_labelled_foreground(self):
        self.assertIn(
            "runSyncNow(source: SyncExecutionSource.foreground, force: force)",
            self.sync_service, "syncAll must stay foreground")
        self.assertIn("source: SyncExecutionSource.foreground",
                      self._method("void nudge("))

    def test_nothing_in_the_engine_claims_to_be_background(self):
        self.assertNotIn(
            "SyncExecutionSource.background", self.sync_service,
            "no foreground trigger may mislabel itself as background; that "
            "label belongs to the future native caller")

    # -- F: no duplicated eligibility ------------------------------------
    def test_the_boundary_does_not_re_decide_eligibility(self):
        execution = strip_comments(read(LIB, "sync_execution.dart"))
        for predicate in ("next_attempt_at", "owner_user_id",
                          "created_authorization_version", "SELECT",
                          "claimNext"):
            self.assertNotIn(predicate, execution)
        runner = self._method("Future<SyncResult> _executeCoordinatedDrain(")
        for predicate in ("next_attempt_at", "owner_user_id", "claimNext"):
            self.assertNotIn(predicate, runner)

    # -- G: account / session safety -------------------------------------
    def test_logout_cancels_the_wake_up_and_not_the_work(self):
        body = self._method("void stopAutoSync()")
        self.assertIn("cancelOpportunity()", body)
        for destructive in ("delete", "DELETE", "clearOutbox", "purge"):
            self.assertNotIn(destructive, body)

    def test_ownership_guard_still_precedes_execution(self):
        """The guard must be the FIRST statement, not merely present.

        Mutation M21 survived an earlier version of this assertion: the
        method re-checks ownership at several later points, so a substring
        search still matched after the ENTRY guard had been deleted. The
        entry guard is the one that stops a drain beginning at all under a
        superseded session.
        """
        first = self._body(
            "Future<SyncResult> _syncAllForGeneration(").strip().splitlines()[0].strip()
        self.assertEqual(
            first, "if (!_ownsGeneration(generation) || !_api.isLoggedIn) {",
            "the ownership + auth guard must open the executor")

    # -- H: compatibility -------------------------------------------------
    def test_sync_all_signature_is_unchanged_for_existing_callers(self):
        self.assertIn("Future<SyncResult> syncAll({bool force = false})",
                      self.sync_service)

    def test_existing_ui_callers_were_not_rewritten(self):
        """A.2 is architectural: no screen should have been touched."""
        callers = 0
        for folder, _dirs, files in os.walk(os.path.join(APP, "lib", "screens")):
            for name in files:
                if name.endswith(".dart"):
                    callers += strip_comments(read(folder, name)).count("syncAll(")
        # 15 callers until HR attendance was retired (2026-10-07): the
        # deleted hr_attendance.dart carried one syncAll trigger. The
        # remaining callers are untouched.
        self.assertGreaterEqual(callers, 14,
                                "existing syncAll callers must keep working")


class OpportunitySchedulingA4(unittest.TestCase):
    """S2 Goal A.4 — the opportunity scheduler is actually reachable.

    A.3 finding B-1: `requestOpportunity()` existed but had ZERO production
    callers, so the scheduling half of the coordinator was inert and a native
    scheduler would never have been asked for anything. These pins close that
    and keep it closed.

    SUPERSEDED NOTE (A.5): this class previously recorded that the wiring was
    unreachable by any Dart test because SyncService built ApiService()/
    LocalDb() as field initialisers. A.5 added `SyncService.withCollaborators`,
    and test/sync_service_behavior_test.dart now executes this wiring for real.
    These source pins are deliberately KEPT: behavioural tests and source/
    mutation invariants protect different things, and the mutation harness
    anchors on this text.
    """

    def setUp(self):
        self.sync_service = strip_comments(read(LIB, "sync_service.dart"))
        self.execution = strip_comments(read(LIB, "sync_execution.dart"))

    # -- Test A: the request exists and reaches the coordinator ----------
    def test_b1_is_closed_a_production_caller_exists(self):
        self.assertIn(
            "_coordinator\n            .requestOpportunity(",
            self.sync_service,
            "B-1: requestOpportunity() must have a production caller",
        )

    def test_the_request_goes_through_the_coordinator_not_the_scheduler(self):
        """The trigger must never talk to the platform seam directly."""
        self.assertNotIn("_backgroundScheduler.ensureScheduled", self.sync_service)
        self.assertNotIn("_backgroundScheduler.cancel", self.sync_service)
        calls = re.findall(r"\.requestOpportunity\(", self.sync_service)
        self.assertEqual(len(calls), 1, "exactly one opportunity request site")

    def test_the_request_uses_the_authoritative_outbox_time(self):
        """notBefore must come from the outbox, not from a guessed literal."""
        self.assertRegex(
            self.sync_service,
            r"requestOpportunity\(\s*notBefore:\s*nextAttempt\s*\)",
            "the opportunity must be scheduled from the outbox's own "
            "next_attempt_at, never from an invented delay",
        )
        self.assertIn("final nextAttempt = await _db.nextOutboxAttemptAt(",
                      self.sync_service)
        # and it must not invent its own clock
        block = self.sync_service.split("if (nextAttempt != null)", 1)[1]
        block = block.split("if (force ||", 1)[0]
        for clock in ("Duration(", "pow(", "backoff", "retryLadder"):
            self.assertNotIn(clock, block)

    def test_the_request_is_not_gated_on_connectivity(self):
        """The offline case is the one the in-app timer cannot cover.

        The foreground nudge is correctly skipped without a link. An
        opportunity must still be requested, because `requiresNetwork: true`
        lets the platform run us when connectivity returns — possibly after
        this process is gone.
        """
        body = self.sync_service.split("if (nextAttempt != null)", 1)[1]
        request = body.index("requestOpportunity(")
        gate = body.index("if (force || ConnectivityService().hasLink)")
        self.assertLess(
            request, gate,
            "the opportunity request must precede (and sit outside) the "
            "connectivity gate",
        )

    def test_the_request_cannot_fail_or_delay_the_drain(self):
        block = self.sync_service.split("if (nextAttempt != null)", 1)[1]
        block = block.split("if (force ||", 1)[0]
        self.assertIn("unawaited(", block)
        self.assertIn("catchError", block)

    # -- Test B: no second coalescing mechanism --------------------------
    def test_no_new_coalescing_flag_was_introduced(self):
        """Opportunity de-duplication belongs to the coordinator alone."""
        for invented in ("_opportunityRequested", "_opportunityQueued",
                         "_scheduledPending", "_wakeupPending",
                         "_backgroundQueued"):
            self.assertNotIn(invented, self.sync_service)
        self.assertIn("bool _opportunityPending = false;", self.execution)

    def test_existing_execution_coalescing_is_untouched(self):
        for existing in ("if (_inflight != null)", "_queued = true",
                         "await _inflight!.future"):
            self.assertIn(existing, self.sync_service)

    # -- Test C: the scheduler contract --------------------------------
    def test_the_scheduler_is_still_the_noop_implementation(self):
        self.assertIn(
            "BackgroundSyncScheduler _backgroundScheduler =\n"
            "      const NoopBackgroundSyncScheduler();",
            self.sync_service,
            "A.4 must not install a native scheduler",
        )

    def test_no_native_scheduling_leaked_into_this_phase(self):
        for forbidden in ("workmanager", "WorkManager", "MethodChannel",
                          "platform_channel", "AndroidIntent", "Kotlin"):
            self.assertNotIn(forbidden, self.sync_service)
            self.assertNotIn(forbidden, self.execution)

    def test_the_request_carries_no_payload_or_identity(self):
        """A scheduler request may outlive the session that created it."""
        block = self.sync_service.split("if (nextAttempt != null)", 1)[1]
        block = block.split("if (force ||", 1)[0]
        for leak in ("userId", "token", "ownerUserId", "generation",
                     "clientOpId", "payload"):
            self.assertNotIn(leak, block)

    # -- Test D/E: no bypass of drain or claim ---------------------------
    def test_the_trigger_does_not_drain_directly(self):
        block = self.sync_service.split("if (nextAttempt != null)", 1)[1]
        block = block.split("if (force ||", 1)[0]
        for bypass in ("_drain(", "_syncAllForGeneration(", "runSyncNow("):
            self.assertNotIn(bypass, block)

    def test_the_trigger_does_not_claim_directly(self):
        block = self.sync_service.split("if (nextAttempt != null)", 1)[1]
        block = block.split("if (force ||", 1)[0]
        for bypass in ("claimNext", "settleLegacyOperation", "rawQuery",
                       "next_attempt_at", "owner_user_id"):
            self.assertNotIn(bypass, block)

    def test_still_exactly_one_drain_and_one_claim(self):
        self.assertEqual(len(re.findall(r"await _drain\(", self.sync_service)), 1)
        claim_sites = 0
        for folder, _dirs, files in os.walk(os.path.join(APP, "lib")):
            for name in files:
                if name.endswith(".dart"):
                    claim_sites += strip_comments(
                        read(folder, name)
                    ).count("claimNextLegacyOperation(")
        self.assertEqual(claim_sites, 2, "one definition + one call site")

    # -- Test F: the foreground contract is unchanged --------------------
    def test_foreground_retry_timing_is_unchanged(self):
        """The nudge condition and delay must be byte-identical to A.3."""
        self.assertIn(
            "if (force || ConnectivityService().hasLink) {\n"
            "        final wait = nextAttempt.difference(DateTime.now().toUtc());\n"
            "        nudge(delay: wait <= Duration.zero ? Duration.zero : wait);",
            self.sync_service,
        )



class ConstructorInjectionA5(unittest.TestCase):
    """S2 Goal A.5 — the behavioural test seam, and its limits.

    Through A.4 every statement about runSyncNow/nudge/_drain/the opportunity
    request was source-verified only, because SyncService constructed its
    ApiService and LocalDb as field initialisers and therefore could not be
    instantiated by a test at all. A.5 introduces exactly one seam so the REAL
    class can be driven by test/sync_service_behavior_test.dart.

    These pins exist to stop the seam from quietly becoming something else: a
    DI framework, a second construction path, or a hole through which the
    production default disappears.
    """

    def setUp(self):
        self.raw = read(LIB, "sync_service.dart")
        self.sync_service = strip_comments(self.raw)
        self.behavior_test = read(APP, "test", "sync_service_behavior_test.dart")
        # Comment-stripped: the file's own doc comment HONESTLY names
        # sqflite_common_ffi/mockito to explain what it does not use, and a
        # raw substring scan would read that prose as a dependency. The A.3
        # lesson applies to this suite as much as to the source it pins.
        self.behavior_code = strip_comments(self.behavior_test)
        self.pubspec = read(APP, "pubspec.yaml")

    # -- A: the seam exists and is a constructor, not a framework --------
    def test_an_injecting_constructor_exists(self):
        self.assertRegex(
            self.sync_service,
            r"SyncService\.withCollaborators\(\s*\{\s*ApiService\?\s+api,"
            r"\s*LocalDb\?\s+db,?\s*\}\s*\)",
            "A.5 requires a named constructor taking both collaborators",
        )

    def test_both_collaborators_keep_their_production_defaults(self):
        self.assertIn("_api = api ?? ApiService()", self.sync_service)
        self.assertIn("_db = db ?? LocalDb()", self.sync_service)

    def test_the_fields_are_final_and_explicitly_typed(self):
        self.assertRegex(self.sync_service, r"final\s+ApiService\s+_api\s*;")
        self.assertRegex(self.sync_service, r"final\s+LocalDb\s+_db\s*;")

    def test_no_dependency_injection_framework_was_introduced(self):
        for banned in ("get_it", "GetIt", "riverpod", "Riverpod",
                       "provider.dart", "ServiceLocator", "serviceLocator"):
            self.assertNotIn(
                banned, self.sync_service,
                f"A.5 is constructor injection, not a framework ({banned})",
            )

    def test_no_abstract_interface_was_invented_for_the_collaborators(self):
        for banned in ("abstract class ApiService", "abstract class LocalDb",
                       "ApiServiceInterface", "LocalDbInterface"):
            self.assertNotIn(banned, self.sync_service)

    # -- B: production construction is untouched -------------------------
    def test_the_production_singleton_survives(self):
        self.assertIn("static final SyncService _instance", self.sync_service)
        self.assertIn("factory SyncService() => _instance;", self.sync_service)

    def test_the_private_constructor_still_builds_the_real_collaborators(self):
        self.assertRegex(
            self.sync_service,
            r"SyncService\._internal\(\)\s*:\s*_api\s*=\s*ApiService\(\)\s*,"
            r"\s*_db\s*=\s*LocalDb\(\)\s*;",
            "SyncService() must still produce the real ApiService and LocalDb",
        )

    def test_the_factory_did_not_gain_parameters(self):
        # A factory returning a cached instance would silently ignore them,
        # which is precisely the trap A.5 avoids by using a second constructor.
        self.assertNotRegex(
            self.sync_service, r"factory SyncService\(\s*\{")

    # -- C: no hidden second construction path ---------------------------
    def test_collaborators_are_constructed_only_as_constructor_defaults(self):
        api_sites = re.findall(r"ApiService\(\)", self.sync_service)
        db_sites = re.findall(r"LocalDb\(\)", self.sync_service)
        self.assertEqual(
            len(api_sites), 2,
            "exactly two ApiService() constructions: _internal and the "
            "withCollaborators default",
        )
        self.assertEqual(len(db_sites), 2, "same for LocalDb()")

    def test_no_method_body_rebuilds_a_collaborator(self):
        # Delete the two constructors' initialiser lists outright; whatever is
        # left is, by definition, a construction in a method body, which would
        # bypass the injected instance. A per-line heuristic cannot do this:
        # an initialiser list spans lines, so `_db = LocalDb();` sits on a line
        # carrying neither ':' nor '??'.
        body = re.sub(
            r"SyncService\._internal\(\)\s*:.*?;", "", self.sync_service,
            flags=re.S)
        body = re.sub(
            r"SyncService\.withCollaborators\([^)]*\)\s*:.*?;", "", body,
            flags=re.S)
        self.assertNotIn(
            "ApiService()", body,
            "ApiService is constructed outside the constructor defaults")
        self.assertNotIn(
            "LocalDb()", body,
            "LocalDb is constructed outside the constructor defaults")

    # -- D: the behavioural test drives the REAL objects -----------------
    def test_the_behavioural_test_uses_the_real_sync_service(self):
        self.assertIn("SyncService.withCollaborators(", self.behavior_code)

    def test_the_behavioural_test_does_not_reimplement_the_coordinator(self):
        for banned in ("class FakeCoordinator", "class _FakeCoordinator",
                       "BackgroundSyncCoordinator<SyncResult>("):
            self.assertNotIn(
                banned, self.behavior_code,
                "A.5 must exercise the real coordinator, not a copy of it",
            )

    def test_the_behavioural_test_exercises_the_a4_request(self):
        self.assertIn("requiresNetwork", self.behavior_code)
        self.assertIn("notBefore", self.behavior_code)
        self.assertIn("cancelCount", self.behavior_code)

    # -- E: A.11 adds only the documented SQLite test binding --------------
    def test_only_the_documented_sqlite_test_binding_is_added(self):
        self.assertIn("sqflite: ^2.3.0", self.pubspec)
        dev_dependencies = self.pubspec.split("dev_dependencies:", 1)[1]
        self.assertIn("sqflite_common_ffi: 2.3.4+4", dev_dependencies)
        self.assertIn("sqlite3: 2.9.4", dev_dependencies)
        for banned in ("mockito", "mocktail", "workmanager", "get_it"):
            self.assertNotIn(
                banned, self.pubspec,
                f"A.11 must not add an unrelated dependency ({banned})",
            )
            self.assertNotIn(banned, self.behavior_code)

    def test_the_behavioural_test_opens_no_database(self):
        for banned in ("databaseFactory", "openDatabase", "sqflite"):
            self.assertNotIn(banned, self.behavior_code)

    # -- F: A.4 and A.2 invariants survived the refactor -----------------
    def test_the_opportunity_request_is_still_the_only_one(self):
        self.assertEqual(
            len(re.findall(r"\.requestOpportunity\(", self.sync_service)), 1)

    def test_there_is_still_exactly_one_drain(self):
        self.assertEqual(
            len(re.findall(r"await _drain\(", self.sync_service)), 1)

    def test_both_queued_assignments_survive(self):
        # The pre-A.5 pins asserted the SUBSTRING "_queued = true", which two
        # separate sites satisfy. Deleting either one individually therefore
        # went unnoticed (mutations M33/M34 proved it). Pin each site.
        self.assertEqual(
            self.sync_service.count("if (hasMoreDueLegacy) _queued = true;"), 1,
            "a due backlog must still queue another pass",
        )
        self.assertEqual(
            self.sync_service.count("if (sameGeneration) _queued = true;"), 1,
            "a request arriving mid-drain must be queued, never swallowed",
        )

    def test_the_coalescing_flags_were_not_multiplied(self):
        self.assertIn("_inflight", self.sync_service)
        self.assertIn("_queued", self.sync_service)
        for banned in ("_draining", "_busy", "_opportunityQueued",
                       "_secondPass"):
            self.assertNotIn(banned, self.sync_service)

    def test_the_noop_scheduler_is_still_the_default_seam(self):
        self.assertIn("const NoopBackgroundSyncScheduler()", self.sync_service)


if __name__ == "__main__":
    unittest.main()
