"""Security & integrity tests for the P66 Hymn Art plane (መዝሙር cover art).

"Every hymn gets its own image/banner, Spotify-style." Pins every guard
the feature relies on so a future refactor cannot silently weaken it:

  - sql/040: information_schema-guarded, idempotent, NON-destructive
  - schema reconciler carries the same columns (Sync DB Schema path)
  - MezmurArtService: the full OWASP upload chain the repo's own audit
    certifies for taxonomy covers (is_uploaded_file -> size -> magic
    bytes -> getimagesize bounds -> GD decode -> RE-ENCODE strip ->
    random server-chosen path -> images-only .htaccess), prepared
    statements, art_key never serialized, immutable ?v= URLs,
    probe-guarded degradation on a pre-040 database, audit trail
  - web controller: art actions are POST-only + CSRF-gated (the
    $__postActions list), version handshake + schema floor bumped
    together, ping probes the art columns (the P46 lesson)
  - mobile routes: same service, role-gated by the library-write roles,
    own rate bucket, is_uploaded_file re-check
  - read paths: art payload rides list/get/delta through the ONE
    decoration point (applyMedia) — no divergence between surfaces
  - web UI: art cell + view hero, new actions exported, upload flow
    reuses the hardened image dialog
  - cross-language color contract: the JS hashCode algorithm is pinned
    AND must agree with the Dart port (D2 fix) — the palette indexes
    asserted here are the same constants test/hymn_art_test.dart pins
  - Flutter cache: v25 migration + upsert preserve semantics + offline
    art pinning + version bump parity
"""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
FLUTTER = ROOT / "Mobile" / "wbws_flutter_app"


def js_hash(name: str) -> int:
    """The EXACT mezmur.js hashCode algorithm (the parity oracle)."""
    h = 0
    for ch in name:
        h = (h << 5) - h + ord(ch)
        h = ((h + 2**31) % 2**32) - 2**31  # JS |0 signed 32-bit wrap
    return abs(h)


class MezmurArtTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sql = (ROOT / "sql/040_mezmur_hymn_art.sql").read_text(encoding="utf-8")
        cls.reconciler = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.svc = (
            ROOT / "admin/backend/services/MezmurArtService.php"
        ).read_text(encoding="utf-8")
        cls.hymn_svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.routes = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.page = (ROOT / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.css = (ROOT / "themes/components.css").read_text(encoding="utf-8")
        cls.local_db = (FLUTTER / "lib/services/local_db.dart").read_text(encoding="utf-8")
        cls.dl = (FLUTTER / "lib/services/mezmur_download_manager.dart").read_text(encoding="utf-8")
        cls.track = (FLUTTER / "lib/services/mezmur_audio_player.dart").read_text(encoding="utf-8")
        cls.palette = (FLUTTER / "lib/utils/cover_palette.dart").read_text(encoding="utf-8")
        cls.dart_test = (FLUTTER / "test/hymn_art_test.dart").read_text(encoding="utf-8")

    # ── migration 040 ──────────────────────────────────────────
    def test_040_is_guarded_idempotent_and_non_destructive(self):
        self.assertIn("information_schema", self.sql)
        self.assertIn("PREPARE", self.sql)
        self.assertIn("DEALLOCATE", self.sql)
        for destructive in ["DROP TABLE", "DROP COLUMN", "TRUNCATE", "DELETE FROM"]:
            self.assertNotIn(destructive, self.sql.upper().replace("DROP TABLE IF EXISTS tmp", ""))
            self.assertNotIn(destructive, self.sql)

    def test_040_defines_the_art_columns(self):
        for col in ["art_key", "art_status", "art_color", "art_uploaded_by", "art_updated_at"]:
            self.assertIn(col, self.sql)
        self.assertIn("idx_mz40_art_status", self.sql)

    def test_reconciler_carries_the_same_columns(self):
        block = self.reconciler.split("'mezmur_hymns' => [", 1)[1].split("],", 1)[0]
        for col in ["art_key", "art_status", "art_color", "art_uploaded_by", "art_updated_at"]:
            self.assertIn(f"'{col}'", block,
                          "F1 lesson: a column missing from the reconciler drifts on Sync DB Schema")

    # ── MezmurArtService hardening ────────────────────────────
    def test_owasp_upload_chain_is_complete(self):
        for needle in [
            "is_uploaded_file",
            "finfo",
            "getimagesizefromstring",
            "imagecreatefromstring",
            "imagejpeg",  # re-encode strips EXIF/GPS/polyglots
            "random_bytes",  # server-chosen path, never user input
            "UPLOAD_ERR_OK",
        ]:
            self.assertIn(needle, self.svc, f"missing hardening step: {needle}")

    def test_upload_size_and_dimension_caps(self):
        self.assertIn("MAX_UPLOAD_BYTES", self.svc)
        self.assertIn("4 * 1024 * 1024", self.svc)
        self.assertIn("MIN_DIM", self.svc)
        self.assertIn("MAX_DIM", self.svc)

    def test_art_key_never_leaves_the_server(self):
        self.assertIn("unset($row['art_key']", self.svc)
        # The RETURN ARRAY of artPayload is what gets serialized — the
        # storage key may be READ there (to build URLs) but never emitted.
        body = self.svc.split("function artPayload", 1)[1]
        body = body.split("return [", 1)[1].split("];", 1)[0]
        self.assertNotIn("'art_key' =>", body,
                         "artPayload must serialize URLs, never the storage key")
        for key in ["'art_status' =>", "'art_color' =>", "'art_url' =>",
                    "'art_url_medium' =>", "'art_url_small' =>"]:
            self.assertIn(key, body)

    def test_urls_are_version_tagged_and_relative_rooted(self):
        self.assertIn("?v=' . $ts", self.svc.replace('"', "'"))
        self.assertIn("_640.jpg", self.svc)
        self.assertIn("_320.jpg", self.svc)
        self.assertIn("_160.jpg", self.svc)

    def test_sql_uses_prepared_statements(self):
        for m in re.finditer(r"\$conn->prepare\(\s*(['\"])(.*?)\1\s*\)", self.svc, re.S):
            sql = m.group(2)
            self.assertNotIn("$", sql.replace("$stmt", ""),
                             "interpolated variable inside SQL: " + sql[:60])

    def test_path_traversal_guard_on_cleanup(self):
        self.assertIn("str_contains($key, '..')", self.svc)

    def test_images_only_htaccess_and_cache_headers(self):
        self.assertIn("Require all denied", self.svc)
        self.assertIn("max-age=31536000", self.svc)

    def test_probe_guarded_degradation(self):
        self.assertIn("artColumnsReady", self.svc)
        self.assertIn("'none' AS art_status", self.svc,
                      "pre-040 servers must degrade to 'no art', not fatal 1054")

    def test_renditions_are_square_and_fixed(self):
        self.assertIn("RENDITIONS = [160, 320, 640]", self.svc)
        self.assertIn("centerCropSquare", self.svc)

    def test_dominant_color_has_luminance_floor(self):
        self.assertIn("luma", self.svc)
        self.assertIn("sat", self.svc,
                      "Spotify-style vibrancy: frequency AND saturation score")

    def test_audit_trail_on_both_mutations(self):
        self.assertIn("Mezmur Hymn Art Updated", self.svc)
        self.assertIn("Mezmur Hymn Art Removed", self.svc)
        self.assertIn("SecurityAuditService::record", self.svc)

    def test_revision_discipline_on_art_changes(self):
        for op in ["uploadArt", "removeArt"]:
            body = self.svc.split(f"function {op}", 1)[1].split("private static", 1)[0]
            self.assertIn("revision = revision + 1", body,
                          f"{op} must bump revision so the delta cursor converges art")

    # ── web controller ────────────────────────────────────────
    def test_art_actions_are_post_only_and_csrf_gated(self):
        line = next((l for l in self.api.splitlines() if "$__postActions" in l), None)
        self.assertIsNotNone(line)
        self.assertIn("'art_upload'", line)
        self.assertIn("'art_remove'", line)

    def test_version_and_schema_floor_bumped_together(self):
        self.assertIn("'phase7-art01'", self.api)
        self.assertIn("MEZMUR_SCHEMA_MIN', 40", self.api)

    def test_ping_probes_art_columns(self):
        block = self.api.split("$requiredCols", 1)[1]
        self.assertIn("'art_key'           => 'sql/040_mezmur_hymn_art.sql'", block)
        self.assertIn("'art_status'        => 'sql/040_mezmur_hymn_art.sql'", block)

    def test_web_controller_uses_the_service(self):
        self.assertIn("MezmurArtService::uploadArt", self.api)
        self.assertIn("MezmurArtService::removeArt", self.api)
        self.assertIn("MezmurArtService.php", self.api)

    def test_controllers_import_the_service_namespace(self):
        # Regression: `MezmurArtService::uploadArt(...)` inside a file
        # WITHOUT the use statement resolves to the GLOBAL class and
        # fatals at runtime — string-grepping the call alone cannot
        # catch it. Both controllers must import the namespace.
        self.assertIn("use App\\Services\\MezmurArtService;", self.api)
        self.assertIn("use App\\Services\\MezmurArtService;", self.routes)

    # ── mobile routes ─────────────────────────────────────────
    def test_mobile_routes_match_web_version(self):
        self.assertIn("'phase7-art01'", self.routes)

    def test_mobile_art_endpoints_role_gated_and_rate_limited(self):
        for action in ["'art'", "'art-remove'"]:
            i = self.routes.index(f"$action === {action}")
            block = self.routes[i - 200: i + 700]
            self.assertIn("$MEZMUR_LIBRARY_WRITE_ROLES", block,
                          f"{action} must be gated by the library-write roles")
            self.assertIn("mezmur_art_write", block,
                          f"{action} needs its own rate bucket")
        self.assertIn("is_uploaded_file", self.routes)
        self.assertIn("MezmurArtService::uploadArt", self.routes)
        self.assertIn("MezmurArtService::removeArt", self.routes)

    # ── read paths: one decoration point ──────────────────────
    def test_art_rides_all_three_read_paths(self):
        self.assertEqual(self.hymn_svc.count("MezmurArtService::artColsExpr($conn)"), 3,
                         "list + get + delta must all select the art fragment")
        self.assertIn("MezmurArtService::decorateRow($item)", self.hymn_svc,
                      "art payload merges at applyMedia — the single point audio uses")

    # ── web UI ────────────────────────────────────────────────
    def test_list_table_has_art_column(self):
        self.assertIn('<th class="th-cover" aria-label="Cover art">Art</th>', self.page)
        self.assertIn('colspan="7"', self.page)

    def test_view_modal_has_hero(self):
        self.assertIn('id="mzViewArt"', self.page)
        self.assertNotIn('style="', self.page.split('id="mzViewArt"')[1].split("</div>")[0],
                         "P0 rule: no inline styles in the shell (JS sets them at runtime)")

    def test_js_exports_and_upload_flow(self):
        exports = self.js.split("window.Mezmur", 1)[1]
        for fn in ["mgrArt:", "mgrArtRemove:", "viewArtSet:", "viewArtRemove:"]:
            self.assertIn(fn, exports)
        self.assertIn("'art_upload'", self.js)
        self.assertIn("refreshAfterArtChange", self.js)
        # art accepts the same 4 MB the server allows; taxonomy stays 2 MB
        self.assertIn("var capMb = art ? 4 : 2;", self.js)
        # fallback gradient shares the palette + hash with mobile
        self.assertIn("hymnCoverGradient", self.js)

    def test_js_hash_algorithm_is_pinned(self):
        self.assertIn("h = ((h << 5) - h + str.charCodeAt(i)) | 0;", self.js)
        self.assertIn("return Math.abs(h);", self.js)

    def test_css_art_classes_exist(self):
        for cls in [".mz-art-btn", ".mz-view-art-title", ".mz-view-art-actions"]:
            self.assertIn(cls, self.css)
        # Accessibility: the thumb's motion is disabled under reduced motion.
        self.assertIn("{ .mz-art-btn { transition: none; }", self.css)

    # ── cross-language color contract (D2) ────────────────────
    def test_dart_parity_constants_match_js_hash(self):
        # The names + indexes pinned in the Flutter test must be the
        # REAL JS hash buckets — computed here with the exact algorithm.
        m = re.search(r"const jsCases = <String, int>\{(.*?)\};", self.dart_test, re.S)
        self.assertIsNotNone(m, "test/hymn_art_test.dart must pin the parity table")
        cases = re.findall(r"'((?:[^'\\]|\\.)*)':\s*(\d+)", m.group(1))
        self.assertGreaterEqual(len(cases), 8)
        for name, idx in cases:
            expected = js_hash(name) % 6
            self.assertEqual(int(idx), expected,
                             f"Dart parity constant wrong for {name!r}: "
                             f"JS says {expected}, test says {idx}")

    def test_dart_hash_uses_signed32_wrap_and_final_abs(self):
        body = self.palette.split("List<Color> _autoPalette", 1)[1]
        self.assertIn(".toSigned(32)", body)
        self.assertIn("h.abs()", body)
        self.assertNotIn("& 0x7fffffff", body,
                         "the old per-step mask is the D2 bug — JS wraps signed, abses at the END")

    # ── Flutter cache & offline ───────────────────────────────
    def test_local_db_v25_art_columns(self):
        # Schema version moved 25 -> 26 in O1 (offline-first comm
        # tables), 26 -> 27 in P1-B (notification center), 27 -> 28 in
        # P1-C (mezmur home days), 28 -> 29 in P1-D (review inbox),
        # 29 -> 30 in P1-E (education classes), 30 -> 31 in P1-F,
        # 31 -> 32 in P1-G, 32 -> 33 in P1-H (Mezmur analytics).
        # The current version is centralized in the v34 schema contract.
        self.assertIn("version: localDatabaseSchemaVersion,", self.local_db)
        self.assertIn("if (oldVersion < 25)", self.local_db)
        # O1: comm store tables ship in v26 — created idempotently for
        # both fresh installs and upgrades, and wiped on logout (PII).
        self.assertIn("if (oldVersion < 26)", self.local_db)
        self.assertIn("_createCommTables(db)", self.local_db)
        for t in ["comm_threads", "comm_messages", "comm_outbox", "comm_drafts", "comm_meta"]:
            self.assertIn(f"CREATE TABLE IF NOT EXISTS {t} (", self.local_db)
            self.assertIn(f"'{t}',", self.local_db)  # logout wipe list
        for col in ["art_status", "art_color", "art_url_medium", "art_url_small"]:
            self.assertIn(f"'{col}'", self.local_db.split("upsertHymns", 1)[1][:6000],
                          f"{col} must be in the upsert probe/merge")

    def test_download_manager_pins_art_offline(self):
        self.assertIn("_pinArtwork", self.dl)
        self.assertIn("mz_${hymnId}_art.jpg", self.dl)
        self.assertIn("artPathFor", self.dl)
        # the pin is best effort — it must never fail the audio download
        pin = self.dl.split("Future<void> _pinArtwork", 1)[1]
        self.assertIn("catch (_)", pin)

    def test_track_model_carries_art(self):
        for f in ["artStatus", "artColor", "artUrl", "artUrlMedium", "artUrlSmall"]:
            self.assertIn(f"this.{f}", self.track)
        self.assertIn("bool get hasArt", self.track)

    def test_app_version_bumped_in_both_places(self):
        # Build metadata is mirrored for request headers/update decisions and
        # must move with pubspec on every release (now 1.7.4+41,
        # attendance warnings, Phase 2 mobile).
        self.assertIn("appVersion = '1.7.4'", (FLUTTER / "lib/utils/config.dart").read_text(encoding="utf-8"))
        self.assertIn("appBuild = 41", (FLUTTER / "lib/utils/config.dart").read_text(encoding="utf-8"))
        self.assertIn("version: 1.7.4+41", (FLUTTER / "pubspec.yaml").read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
