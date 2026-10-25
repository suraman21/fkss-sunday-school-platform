"""
Release system: the published version is read from the uploaded APK, phones
are never blocked without a downloadable file, settings are saved to the file
the server reads, uploads keep Force update / minimum / background sync, and
uploads are checked (right app, not older, per-device files match).

Each test builds a throw-away server tree:
    <tmp>/home/public_html            ROOT_PATH
    <tmp>/home/public_html/releases   APK files
    <tmp>/home/.fkss_app_release.php  or public_html/api/v1/app_release.local.php
with the real PHP code linked in.
"""
from __future__ import annotations

import base64
import hashlib
import json
import os
import shutil
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

from apk_fixture import write_apk

ROOT = Path(__file__).resolve().parents[2]

HARNESS = r"""<?php
define('ROOT_PATH', $argv[1]);
require ROOT_PATH . '/admin/backend/services/AppReleaseManager.php';
use App\Services\AppReleaseManager as M;
$op = json_decode($argv[2], true);
try {
    switch ($op['op']) {
        case 'load':
            require_once ROOT_PATH . '/api/v1/core/app_release.php';
            $r = fkssLoadAppRelease();
            $out = ['rel' => $r, 'phone' => fkssPhoneUpdatePolicy($r)];
            break;
        case 'save':
            $out = M::saveConfig(ROOT_PATH, $op['input']);
            break;
        case 'inspect':
            $out = M::inspectApk(ROOT_PATH, $op['file'], $op['abi']);
            break;
        case 'upload':
            $data = base64_decode($op['b64']);
            $id = 'test' . bin2hex(random_bytes(6));
            M::handleChunkUpload(ROOT_PATH, null, $id, 0, 1, strlen($data), strlen($data), $op['b64']);
            $out = M::assembleChunks(ROOT_PATH, $id, 1, strlen($data), 'app.apk', $op['abi']);
            break;
        case 'delete':
            $out = M::deleteApk(ROOT_PATH, $op['abi']);
            break;
        case 'compare':
            require_once ROOT_PATH . '/api/v1/core/app_release.php';
            $out = array_map(fn ($c) => fkssVersionCompare($c[0], $c[1], $c[2], $c[3]), $op['cases']);
            break;
    }
    echo json_encode(['ok' => true, 'out' => $out]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'class' => get_class($e), 'message' => $e->getMessage()]);
}
"""


def php_config(values: dict) -> str:
    def lit(v):
        if isinstance(v, bool):
            return "true" if v else "false"
        if isinstance(v, int):
            return str(v)
        return "'" + str(v).replace("\\", "\\\\").replace("'", "\\'") + "'"
    body = ",\n".join(f"    '{k}' => {lit(v)}" for k, v in values.items())
    return f"<?php\nreturn [\n{body}\n];\n"


@unittest.skipUnless(shutil.which("php"), "php-cli is required")
class ReleaseFromApkTests(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.home = self.tmp / "home"
        self.web = self.home / "public_html"
        (self.web / "admin/backend").mkdir(parents=True)
        (self.web / "api/v1").mkdir(parents=True)
        (self.web / "releases").mkdir(parents=True)
        os.symlink(ROOT / "admin/backend/services", self.web / "admin/backend/services")
        os.symlink(ROOT / "api/v1/core", self.web / "api/v1/core")
        self.harness = self.tmp / "harness.php"
        self.harness.write_text(HARNESS)
        self.local_cfg = self.web / "api/v1/app_release.local.php"
        self.private_cfg = self.home / ".fkss_app_release.php"
        self.universal = self.web / "releases/fkss.apk"

    def tearDown(self):
        for p in self.tmp.rglob("*"):
            try:
                if not p.is_symlink():
                    p.chmod(0o755 if p.is_dir() else 0o644)
            except OSError:
                pass
        shutil.rmtree(self.tmp, ignore_errors=True)

    def run_op(self, **op):
        proc = subprocess.run(["php", str(self.harness), str(self.web), json.dumps(op)],
                              capture_output=True, text=True, timeout=60)
        self.assertEqual(proc.returncode, 0, proc.stderr + proc.stdout)
        try:
            return json.loads(proc.stdout)
        except json.JSONDecodeError:
            raise AssertionError("harness output was not JSON: " + proc.stdout[:2000] + proc.stderr[:2000])

    def ok(self, **op):
        res = self.run_op(**op)
        self.assertTrue(res["ok"], f"expected success, got {res}")
        return res["out"]

    def fails(self, **op):
        res = self.run_op(**op)
        self.assertFalse(res["ok"], f"expected a refusal, got success")
        return res

    def cfg(self, path=None, **values):
        base = {"latest_version": "1.6.10", "latest_build": 36, "min_version": "1.0.0", "min_build": 1,
                "force_update": False, "background_drains_enabled": True, "apk_path": ""}
        base.update(values)
        (path or self.local_cfg).write_text(php_config(base))

    def saved(self, path=None):
        code = f"var_export(json_encode(include '{path or self.local_cfg}'));"
        out = subprocess.run(["php", "-r", f"echo json_encode(include '{path or self.local_cfg}');"],
                             capture_output=True, text=True, timeout=30).stdout
        return json.loads(out)

    # ── B1: the version comes from the file ─────────────────────────────
    def test_latest_version_is_read_from_the_published_apk(self):
        self.cfg(latest_version="1.6.10", latest_build=36)
        write_apk(self.universal, version_name="1.7.4", version_code=41)
        out = self.ok(op="load")
        self.assertEqual((out["rel"]["latest_version"], out["rel"]["latest_build"]), ("1.7.4", 41))
        self.assertEqual(out["rel"]["version_source"], "apk")
        self.assertEqual(out["rel"]["apk_package"], "com.arkeonethiopia.fkss")
        self.assertEqual((out["phone"]["latest_version"], out["phone"]["latest_build"]), ("1.7.4", 41))

    def test_unreadable_apk_falls_back_to_typed_version(self):
        self.cfg(latest_version="1.6.10", latest_build=36)
        self.universal.write_bytes(b"not a zip" * 500)
        out = self.ok(op="load")
        self.assertEqual(out["rel"]["version_source"], "config")
        self.assertEqual((out["rel"]["latest_version"], out["rel"]["latest_build"]), ("1.6.10", 36))
        self.assertTrue(out["rel"]["download_available"])

    def test_manifest_cache_is_keyed_by_file_hash(self):
        self.cfg()
        write_apk(self.universal, version_name="1.7.4", version_code=41)
        self.ok(op="load")
        sidecar = Path(str(self.universal) + ".manifest.json")
        self.assertTrue(sidecar.is_file())
        cached = json.loads(sidecar.read_text())
        self.assertEqual(cached["sha256"], hashlib.sha256(self.universal.read_bytes()).hexdigest())
        # A replaced file is re-read even though an old sidecar exists.
        write_apk(self.universal, version_name="1.7.5", version_code=42, padding=4096)
        out = self.ok(op="load")
        self.assertEqual(out["rel"]["latest_build"], 42)

    def test_same_size_replacement_is_rehashed(self):
        self.cfg()
        write_apk(self.universal, version_name="1.7.4", version_code=41)
        first = self.ok(op="load")["rel"]
        write_apk(self.universal, version_name="1.7.5", version_code=42)
        os.utime(self.universal, (first_time := self.universal.stat().st_mtime + 5, first_time))
        self.assertEqual(self.universal.stat().st_size, first["apk_size_bytes"], "fixture must keep the size")
        second = self.ok(op="load")["rel"]
        self.assertEqual(second["apk_sha256"], hashlib.sha256(self.universal.read_bytes()).hexdigest())
        self.assertNotEqual(second["apk_sha256"], first["apk_sha256"])
        self.assertEqual(second["latest_build"], 42)

    def test_stale_per_device_file_is_not_offered(self):
        self.cfg(apk_arm64_path=str(self.web / "releases/fkss-arm64-v8a.apk"),
                 apk_arm32_path=str(self.web / "releases/fkss-armeabi-v7a.apk"))
        write_apk(self.universal, version_code=41)
        write_apk(self.web / "releases/fkss-arm64-v8a.apk", version_code=40)   # old split
        write_apk(self.web / "releases/fkss-armeabi-v7a.apk", version_code=41)  # matches
        arts = self.ok(op="load")["rel"]["apk_artifacts"]
        self.assertNotIn("arm64-v8a", arts)
        self.assertIn("armeabi-v7a", arts)

    # ── B2: never block without a downloadable file ─────────────────────
    def test_no_apk_means_no_prompt_and_no_block(self):
        self.cfg(latest_version="9.9.9", latest_build=999, min_version="9.0.0", min_build=900, force_update=True)
        phone = self.ok(op="load")["phone"]
        self.assertEqual(phone, {"latest_version": "1.0.0", "latest_build": 1, "min_version": "1.0.0",
                                 "min_build": 1, "force_update": False})

    def test_no_settings_file_and_no_apk_does_not_advertise_code_defaults(self):
        out = self.ok(op="load")
        self.assertFalse(out["rel"]["download_available"])
        self.assertEqual(out["phone"]["latest_build"], 1)
        self.assertFalse(out["phone"]["force_update"])

    def test_minimum_above_the_published_file_is_lowered_to_it(self):
        self.cfg(min_version="2.0.0", min_build=50)
        write_apk(self.universal, version_name="1.7.4", version_code=41)
        phone = self.ok(op="load")["phone"]
        self.assertEqual((phone["min_version"], phone["min_build"]), ("1.7.4", 41))

    def test_minimum_and_force_pass_through_when_a_file_exists(self):
        self.cfg(min_version="1.6.0", min_build=30, force_update=True)
        write_apk(self.universal, version_name="1.7.4", version_code=41)
        phone = self.ok(op="load")["phone"]
        self.assertEqual(phone, {"latest_version": "1.7.4", "latest_build": 41, "min_version": "1.6.0",
                                 "min_build": 30, "force_update": True})

    def test_version_compare_matches_the_phone(self):
        cases = [["1.6.10", 36, "1.6.9", 99], ["1.7.4", 41, "1.7.4", 41], ["1.7.4", 40, "1.7.4", 41],
                 ["2.0", 1, "1.9.9", 9]]
        self.assertEqual(self.ok(op="compare", cases=cases), [1, 0, -1, 1])

    # ── B3: save to the file the server reads ───────────────────────────
    def test_save_goes_to_the_file_that_was_loaded(self):
        self.cfg(path=self.private_cfg, release_notes="old")
        self.ok(op="save", input={"release_notes": "new notes"})
        self.assertEqual(self.saved(self.private_cfg)["release_notes"], "new notes")
        self.assertFalse(self.local_cfg.exists())

    @unittest.skipIf(hasattr(os, "geteuid") and os.geteuid() == 0, "root ignores file permissions")
    def test_read_only_settings_file_is_reported_not_shadowed(self):
        self.cfg(path=self.private_cfg, release_notes="old")
        self.private_cfg.chmod(0o444)
        res = self.fails(op="save", input={"release_notes": "new"})
        self.assertTrue("read-only" in res["message"], res["message"])
        self.assertFalse(self.local_cfg.exists(), "a second, shadowed settings file was written")
        self.assertEqual(self.saved(self.private_cfg)["release_notes"], "old")

    def test_typed_latest_version_is_ignored_when_the_file_has_one(self):
        self.cfg()
        write_apk(self.universal, version_name="1.7.4", version_code=41)
        self.ok(op="save", input={"latest_version": "5.0.0", "latest_build": 500})
        s = self.saved()
        self.assertEqual((s["latest_version"], s["latest_build"]), ("1.7.4", 41))

    # ── B4/B5: uploads and saves keep the other settings ────────────────
    def test_save_without_force_or_drains_keeps_them(self):
        self.cfg(force_update=True, background_drains_enabled=False, min_version="1.6.0", min_build=30)
        self.ok(op="save", input={"apk_path": ""})
        s = self.saved()
        self.assertTrue(s["force_update"])
        self.assertFalse(s["background_drains_enabled"])
        self.assertEqual((s["min_version"], s["min_build"]), ("1.6.0", 30))

    def test_drains_can_be_switched_off_and_on(self):
        self.cfg()
        self.ok(op="save", input={"background_drains_enabled": False})
        self.assertFalse(self.saved()["background_drains_enabled"])
        self.ok(op="save", input={"background_drains_enabled": True})
        self.assertTrue(self.saved()["background_drains_enabled"])

    def test_upload_keeps_force_minimum_and_drains(self):
        self.cfg(force_update=True, background_drains_enabled=False, min_version="1.6.0", min_build=30)
        write_apk(self.universal, version_code=41)
        new = write_apk(self.tmp / "new.apk", version_name="1.7.5", version_code=42)
        out = self.ok(op="upload", abi="universal", b64=base64.b64encode(new.read_bytes()).decode())
        self.assertEqual((out["latest_version"], out["latest_build"]), ("1.7.5", 42))
        s = self.saved()
        self.assertTrue(s["force_update"])
        self.assertFalse(s["background_drains_enabled"])
        self.assertEqual((s["min_version"], s["min_build"]), ("1.6.0", 30))

    # ── B6: upload checks ───────────────────────────────────────────────
    def inspect(self, path, abi="universal"):
        return self.run_op(op="inspect", file=str(path), abi=abi)

    def test_upload_checks(self):
        self.cfg()
        write_apk(self.universal, version_code=41)
        other = write_apk(self.tmp / "other.apk", package="com.example.other", version_code=50)
        beta = write_apk(self.tmp / "beta.apk", package="com.arkeonethiopia.fkss.beta", version_code=50)
        older = write_apk(self.tmp / "older.apk", version_code=40)
        same = write_apk(self.tmp / "same.apk", version_code=41)
        newer = write_apk(self.tmp / "newer.apk", version_code=42)
        junk = self.tmp / "junk.apk"
        junk.write_bytes(b"junk" * 1000)
        for path, word in ((other, "not an FKSS app"), (beta, "delete the current APK first"),
                           (older, "older than"), (junk, "not a readable Android app")):
            res = self.inspect(path)
            self.assertFalse(res["ok"], path.name)
            self.assertEqual(res["class"], "InvalidArgumentException")
            self.assertTrue(word in res["message"], res["message"])
        self.assertTrue(self.inspect(same)["ok"])
        self.assertEqual(self.inspect(newer)["out"]["version_code"], 42)
        # per-device files must match the universal file
        self.assertFalse(self.inspect(newer, "arm64-v8a")["ok"])
        self.assertTrue(self.inspect(same, "arm64-v8a")["ok"])

    def test_first_upload_and_switching_apps_after_delete(self):
        self.cfg()
        beta = write_apk(self.tmp / "beta.apk", package="com.arkeonethiopia.fkss.beta", version_code=5)
        self.assertFalse(self.inspect(beta, "armeabi-v7a")["ok"], "split without a universal file")
        self.assertTrue(self.inspect(beta)["ok"], "first upload of any FKSS app")
        write_apk(self.universal, version_code=41)
        self.assertFalse(self.inspect(beta)["ok"])
        self.ok(op="delete", abi="universal")
        self.assertFalse(self.universal.exists())
        self.assertFalse(Path(str(self.universal) + ".manifest.json").exists())
        self.assertTrue(self.inspect(beta)["ok"])

    def test_refused_upload_leaves_the_published_file_untouched(self):
        self.cfg(force_update=True)
        write_apk(self.universal, version_code=41)
        before = hashlib.sha256(self.universal.read_bytes()).hexdigest()
        older = write_apk(self.tmp / "older.apk", version_code=40)
        res = self.fails(op="upload", abi="universal", b64=base64.b64encode(older.read_bytes()).decode())
        self.assertTrue("older than" in res["message"], res["message"])
        self.assertEqual(hashlib.sha256(self.universal.read_bytes()).hexdigest(), before)
        leftovers = [p.name for p in (self.web / "releases").iterdir() if ".assembling." in p.name]
        self.assertEqual(leftovers, [])
        self.assertTrue(self.saved()["force_update"])


class ReleaseWiringTests(unittest.TestCase):
    """Source wiring that the behaviour tests above cannot reach."""

    def test_app_config_route_uses_the_phone_policy(self):
        src = (ROOT / "api/v1/routes/app.php").read_text(encoding="utf-8")
        self.assertTrue("$phone = fkssPhoneUpdatePolicy($rel);" in src)
        for key in ("latest_version", "latest_build", "min_version", "min_build", "force_update"):
            self.assertTrue(f"'{key}' => $phone['{key}']," in src, key)

    def test_dashboard_loads_the_updated_script(self):
        # A new cache tag makes browsers fetch the script that sends the
        # background-sync checkbox and shows the version read from the APK.
        page = (ROOT / "admin/dashboards/super-admin.php").read_text(encoding="utf-8")
        self.assertTrue('src="/admin/js/app_release.js?v=20261026rel"' in page)

    def test_drains_checkbox_is_always_sent_and_read(self):
        js = (ROOT / "admin/js/app_release.js").read_text(encoding="utf-8")
        self.assertTrue("formData.set('background_drains_enabled', drainsEl && drainsEl.checked ? '1' : '0');" in js)
        api = (ROOT / "admin/api_app_release.php").read_text(encoding="utf-8")
        self.assertTrue("$input['background_drains_enabled'] = (string)$_POST['background_drains_enabled'] === '1';" in api)
        self.assertFalse("isset($_POST['background_drains_enabled']) ? (bool)" in api)


@unittest.skipUnless(shutil.which("node"), "node is required")
class ReleaseDashboardJsTests(unittest.TestCase):
    def run_js(self, body: str):
        script = textwrap.dedent("""
            const vm = require('vm');
            const fs = require('fs');
            const els = {};
            const el = (id) => (els[id] = els[id] || { id, value: '', checked: false, readOnly: false, textContent: '' });
            const document = { readyState: 'loading', addEventListener() {}, getElementById: el,
                               querySelector() { return null; }, querySelectorAll() { return []; } };
            const window = { document };
            vm.runInNewContext(fs.readFileSync(process.argv[1], 'utf8'), { window, document, console });
            const UI = window.AppReleaseUI;
        """) + body
        proc = subprocess.run(["node", "-e", script, str(ROOT / "admin/js/app_release.js")],
                              capture_output=True, text=True, timeout=30)
        self.assertEqual(proc.returncode, 0, proc.stderr)
        return json.loads(proc.stdout)

    def test_latest_fields_are_read_only_when_taken_from_the_apk(self):
        out = self.run_js("""
            UI.populateForm({ latest_version: '1.7.4', latest_build: 41, version_source: 'apk',
                              apk_package: 'com.arkeonethiopia.fkss', min_version: '1.0.0', min_build: 1 });
            const a = [els['cfg-latest-version'].readOnly, els['cfg-latest-build'].readOnly, els['cfg-version-source'].textContent];
            UI.populateForm({ latest_version: '1.6.10', latest_build: 36, version_source: 'config', min_version: '1.0.0', min_build: 1 });
            a.push(els['cfg-latest-version'].readOnly);
            console.log(JSON.stringify(a));
        """)
        self.assertEqual(out[:2], [True, True])
        self.assertTrue("com.arkeonethiopia.fkss" in out[2])
        self.assertFalse(out[3])

    def test_phone_policy_text(self):
        out = self.run_js("""
            console.log(JSON.stringify([
              UI._renderPhonePolicy({ download_available: false, phone_policy: { min_version: '1.0.0', min_build: 1 } }),
              UI._renderPhonePolicy({ download_available: true, phone_policy: { latest_version: '1.7.4', latest_build: 41,
                                      min_version: '1.6.0', min_build: 30, force_update: true } }),
            ]));
        """)
        self.assertTrue("nobody is blocked" in out[0])
        self.assertTrue("v1.6.0 (build 30) must update" in out[1] and "Force update also blocks" in out[1])


if __name__ == "__main__":
    unittest.main()
