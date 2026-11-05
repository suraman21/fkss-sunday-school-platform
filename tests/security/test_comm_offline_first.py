"""
Offline-first comm (O-series, 1.4.0) — regression & quality gates
═════════════════════════════════════════════════════════════════════════════
Pins the exactly-once send contract and the offline-first client wiring:

  • sql/046 — messages.client_tag + UNIQUE uk_client_tag, guarded and
    re-runnable like 040/043/044/045; NULL stays legal (web + legacy)
  • NotificationCenterService.sendMessage — tag normalization, the
    pre-check replay fast path, the 1062 race catch, and fail-closed
    tagged sends when migration 046 is unavailable
  • api route — client_tag passes through; a replay answers success
    with `replayed` so the phone's outbox deletes its row
  • mobile — client_tag rides the send payload (O3), the local store
    exists and trims (O4), and the send path never hand-builds a
    bubble for a queued send (the row IS the bubble)
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MOBILE = ROOT / "Mobile/wbws_flutter_app/lib"


class Migration046Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sql = (ROOT / "sql/046_message_client_tag.sql").read_text(encoding="utf-8")

    def test_migration_exists_and_is_guarded(self):
        # Both statements guarded on information_schema → re-runnable.
        self.assertIn("information_schema.COLUMNS", self.sql)
        self.assertIn("information_schema.STATISTICS", self.sql)
        self.assertEqual(self.sql.count("PREPARE mz46_stmt FROM"), 2)
        self.assertEqual(self.sql.count("DEALLOCATE PREPARE"), 2)

    def test_adds_column_and_unique_index(self):
        self.assertIn("ADD COLUMN `client_tag` VARCHAR(64) DEFAULT NULL", self.sql)
        self.assertIn("ADD UNIQUE INDEX `uk_client_tag` (`client_tag`)", self.sql)

    def test_null_tags_stay_legal(self):
        # Web sends and pre-1.4.0 clients have no tag: the column must
        # be NULLable and the doc must say NULLs don't participate.
        self.assertIn("DEFAULT NULL", self.sql)


class SendMessageExactlyOnceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.svc = (ROOT / "admin/backend/services/NotificationCenterService.php").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/notifications.php").read_text(encoding="utf-8")

    def test_signature_accepts_client_tag(self):
        self.assertIn("?string $clientTag = null", self.svc)

    def test_replay_fast_path_returns_success_with_id(self):
        # A retried drain (the common replay: timeout after commit)
        # must answer ok — never a duplicate insert.
        self.assertIn("SELECT id FROM messages WHERE client_tag = ? LIMIT 1", self.svc)
        self.assertIn("['ok' => true, 'replayed' => true, 'id' =>", self.svc)

    def test_race_window_is_caught_by_unique_index(self):
        # Check-then-insert has a race; the 1062 duplicate from the
        # unique index is exactly-once success, not an error.
        self.assertIn("1062", self.svc)
        self.assertIn("['ok' => true, 'replayed' => true]", self.svc)

    def test_tagged_send_fails_closed_without_migration_046(self):
        # A queued mobile send must never silently lose its exactly-once
        # identity by falling back to a tagless insert.
        self.assertIn("messagesHaveClientTag", self.svc)
        self.assertIn("SHOW COLUMNS FROM `messages` LIKE 'client_tag'", self.svc)
        self.assertIn(
            "$clientTag !== null && !self::messagesHaveClientTag($conn)",
            self.svc)
        self.assertIn("Message retry protection is temporarily unavailable.",
                      self.svc)
        self.assertIn("'code' => 'MESSAGE_SEND_UNAVAILABLE'", self.svc)
        # Untagged web/legacy sends retain their established behavior.
        self.assertIn(
            'INSERT INTO messages (thread_id, sender_id, body) VALUES (?, ?, ?)',
            self.svc)

    def test_bad_tags_degrade_never_fail(self):
        # A malformed tag is ignored (tagless send), not a hard error.
        self.assertIn("normalizeClientTag", self.svc)
        self.assertIn("strlen($tag) > 64", self.svc)
        self.assertIn("preg_match('/^[A-Za-z0-9._-]+$/', $tag)", self.svc)

    def test_replay_does_not_bump_last_message_at(self):
        # The replay branch returns BEFORE the last_message_at UPDATE:
        # an old message retried hours later must not resurrect the
        # thread's sort position.
        send = self.svc.split("function sendMessage", 1)[1]
        update_pos = send.find("UPDATE message_threads SET last_message_at")
        replay_pos = send.find("'replayed' => true, 'id'")
        self.assertGreater(update_pos, 0)
        self.assertGreater(replay_pos, 0)
        self.assertLess(replay_pos, update_pos)

    def test_route_passes_tag_and_surfaces_replay(self):
        self.assertIn("isset($body['client_tag']) ? (string)$body['client_tag'] : null", self.route)
        self.assertIn("'replayed' => true", self.route)

    def test_probe_is_cached_per_request(self):
        self.assertIn("private static ?bool $messagesClientTag = null", self.svc)


class OfflineFirstClientTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.store = (MOBILE / "services/comm_store.dart").read_text(encoding="utf-8")
        cls.api = (MOBILE / "services/api_service.dart").read_text(encoding="utf-8")
        cls.worker = (MOBILE / "services/comm_outbox_service.dart").read_text(encoding="utf-8")
        cls.screen = (MOBILE / "screens/notifications/messages_screen.dart").read_text(encoding="utf-8")

    def test_client_tag_rides_the_send_payload(self):
        self.assertIn("'client_tag': clientTag", self.api)
        self.assertIn("clientTag: claim.clientTag", self.worker)

    def test_local_history_is_trimmed_with_hysteresis(self):
        self.assertIn("_trimKeep = 500", self.store)
        self.assertIn("_trimAbove = 600", self.store)
        self.assertIn("ORDER BY id DESC LIMIT ?", self.store)

    def test_the_row_is_the_bubble_no_hand_built_sends(self):
        # O3's race-proof design: _send enqueues and renders FROM the
        # row; the old direct-POST _deliver is gone.
        self.assertIn("await _syncOutboxTail(); // the bubble IS the row", self.screen)
        self.assertNotIn("Future<void> _deliver(", self.screen)

    def test_outbox_tables_are_wiped_on_sign_out(self):
        local_db = (MOBILE / "services/local_db.dart").read_text(encoding="utf-8")
        for t in ["comm_threads", "comm_messages", "comm_outbox", "comm_drafts", "comm_meta"]:
            self.assertIn(f"'{t}',", local_db, f"{t} must be in the logout wipe")

    def test_authoritative_empty_thread_list_reaches_the_store(self):
        """An authoritative response with zero threads must be persisted,
        not skipped.

        The screen already replaces the VISIBLE rows whenever the server
        returned a well-formed thread list (`okRows != null`). The
        persistence call must use that identical condition, or the two
        disagree: the UI shows nothing while SQLite still holds the old
        threads, and the next cold start or offline reopen brings them
        back. Guarding persistence on `isNotEmpty` was exactly that bug.
        """
        import re
        self.assertRegex(
            self.screen,
            r"if \(okRows != null\)\s*\{\s*await CommStore\.instance\.replaceAllThreads\(okRows\);",
            "persistence must run for an authoritative empty result too",
        )
        del re
        self.assertNotIn(
            "okRows != null && okRows.isNotEmpty",
            self.screen,
            "an empty authoritative response must not skip the store write",
        )

    def test_thread_replacement_leaves_pending_work_alone(self):
        """Clearing the thread window must not touch queued sends,
        drafts, cached messages or ETag/cursor state.

        comm_threads is a server-owned cache: the only writer is
        replaceAllThreads and the only reader is threads(). Pending work
        lives in comm_outbox/comm_drafts and is unaffected.
        """
        replace = self.store.split("Future<void> replaceAllThreads(")[1].split("}\n")[0]
        for table in ("comm_outbox", "comm_drafts", "comm_messages", "comm_meta"):
            self.assertNotIn(
                table, replace,
                f"replaceAllThreads must not touch {table}",
            )
        self.assertIn("txn.delete('comm_threads')", replace)

    def test_version_is_162_build_28(self):
        # Bumped 2026-10-19 for attendance warnings, Phase 2 mobile (build 37).
        config = (MOBILE / "utils/config.dart").read_text(encoding="utf-8")
        pubspec = (ROOT / "Mobile/wbws_flutter_app/pubspec.yaml").read_text(encoding="utf-8")
        self.assertIn("appVersion = '1.7.4'", config)
        self.assertIn("appBuild = 41", config)
        self.assertIn("version: 1.7.4+41", pubspec)


if __name__ == "__main__":
    unittest.main()
