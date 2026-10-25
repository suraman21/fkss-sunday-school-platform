"""
api/v1/core/apk_manifest.php reads package, versionName and versionCode from
an APK (ZIP + Android binary XML) in plain PHP, so the release dashboard can
take the published version from the uploaded file itself.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import tempfile
import unittest
import zipfile
from pathlib import Path

from apk_fixture import axml_manifest, write_apk

ROOT = Path(__file__).resolve().parents[2]
LIB = ROOT / "api/v1/core/apk_manifest.php"


def manifest_of(path: Path):
    code = f"require '{LIB}'; echo json_encode(fkssApkManifest('{path}'));"
    proc = subprocess.run(["php", "-r", code], capture_output=True, text=True, timeout=30)
    if proc.returncode != 0:
        raise AssertionError(proc.stderr or proc.stdout)
    return json.loads(proc.stdout)


@unittest.skipUnless(shutil.which("php"), "php-cli is required")
class ApkManifestTests(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def expect(self, path, package="com.arkeonethiopia.fkss", name="1.7.4", code=41):
        self.assertEqual(manifest_of(path), {"package": package, "version_name": name, "version_code": code})

    def test_utf16_pool_deflated(self):
        self.expect(write_apk(self.tmp / "a.apk"))

    def test_utf8_pool_stored(self):
        self.expect(write_apk(self.tmp / "b.apk", deflate=False, utf8=True))

    def test_attribute_names_from_resource_ids(self):
        self.expect(write_apk(self.tmp / "c.apk", names_by_resource_id_only=True))

    def test_beta_package_and_large_build(self):
        self.expect(write_apk(self.tmp / "d.apk", "com.arkeonethiopia.fkss.beta", "1.10.0", 1234),
                    "com.arkeonethiopia.fkss.beta", "1.10.0", 1234)

    def test_not_a_zip(self):
        p = self.tmp / "e.apk"
        p.write_bytes(b"not an apk at all" * 100)
        self.assertIsNone(manifest_of(p))

    def test_zip_without_manifest(self):
        p = self.tmp / "f.apk"
        with zipfile.ZipFile(p, "w") as z:
            z.writestr("classes.dex", b"x" * 100)
        self.assertIsNone(manifest_of(p))

    def test_corrupt_manifest_and_wrong_root(self):
        self.assertIsNone(manifest_of(write_apk(self.tmp / "g.apk", manifest=b"\x03\x00\x08\x00garbage!")))
        wrong = axml_manifest("com.arkeonethiopia.fkss", "1.0.0", 1, root_element="application")
        self.assertIsNone(manifest_of(write_apk(self.tmp / "h.apk", manifest=wrong)))

    def test_crc_mismatch_is_refused(self):
        p = write_apk(self.tmp / "i.apk", deflate=False)
        data = bytearray(p.read_bytes())
        i = data.find(b"com.arkeonethiopia")
        if i < 0:  # UTF-16 pool: find the UTF-16 bytes
            i = data.find("com.arkeonethiopia".encode("utf-16-le"))
        data[i] ^= 0x01
        p.write_bytes(bytes(data))
        self.assertIsNone(manifest_of(p))

    def test_missing_file(self):
        self.assertIsNone(manifest_of(self.tmp / "missing.apk"))


if __name__ == "__main__":
    unittest.main()
