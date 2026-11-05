"""Bounded regressions for source-truth audit findings, not an exhaustive audit.

Only synthetic, process-local token helpers execute. No config bootstrap,
network, database, installed device or hosted state is exercised.
"""
import base64
import hashlib
import hmac
import json
from pathlib import Path
import re
import subprocess

import pytest

ROOT = Path(__file__).resolve().parents[2]


def text(path):
    return (ROOT / path).read_text(encoding="utf-8")


@pytest.mark.parametrize("kind", ["access", "refresh"])
def test_actual_token_helpers_emit_two_part_hmac_and_check_current_key(kind):
    # A missing PHP executable is a failure, not silently skipped coverage.
    script = r'''
    define('JWT_SECRET', $argv[1]);
    require $argv[2];
    if ($argv[3] === 'verify') {
        echo json_encode(verifyTokenState($argv[4]));
    } else {
        $token = $argv[3] === 'access'
            ? createToken(7, 'synthetic', 'synthetic_role', 'Synthetic Fixture')
            : createRefreshToken(7, 'synthetic', 'synthetic_role', 'Synthetic Fixture',
                1, 'synthetic-session', 'synthetic-family', time() + 120);
        echo json_encode(['token' => $token, 'state' => verifyTokenState($token)['state']]);
    }
    '''

    def run(key, mode, token=""):
        result = subprocess.run(
            ["php", "-r", script, key, str(ROOT / "api/v1/core/auth.php"), mode, token],
            capture_output=True, text=True, check=True, timeout=10,
        )
        return json.loads(result.stdout)

    key = "synthetic-source-audit-key-not-for-deployment"
    result = run(key, kind)
    assert result["state"] == "valid"
    payload, signature = result["token"].split(".")
    assert re.fullmatch(r"[a-f0-9]{64}", signature)
    assert signature == hmac.new(key.encode(), payload.encode(), hashlib.sha256).hexdigest()
    assert json.loads(base64.b64decode(payload, validate=True))["typ"] == kind
    assert run("different-synthetic-key-not-for-deployment", "verify", result["token"]) == {"state": "invalid"}
    for path in ("docs/CHANGE_IMPACT.md", "docs/api/LEDGER_ACTIONS.md", "docs/architecture/SECURITY_MODEL.md"):
        assert "not JWT" in text(path), path


def test_secret_loader_fallback_is_not_described_as_enforced_private_placement():
    source = text("config.php")
    directories = re.search(r"\$_envBaseDirs = \[(.*?)\];", source, re.S)[1]
    assert directories.index("dirname(__DIR__),") < directories.index("dirname(__DIR__, 2),")
    assert "__DIR__," in directories
    document = text("docs/SECURITY.md")
    assert "project-directory fallback" in document
    assert "not enforced by this search" in document
    assert "Loaded from a PHP file outside the web root." not in document
    assert "logs out every mobile session" not in document


def test_backup_key_selection_preserves_defined_constant_precedence():
    source = text("admin/backend/services/BackupService.php")
    method = source.split("private static function encryptionSecret(): string", 1)[1].split(
        "private static function privateStorageRoot", 1
    )[0]
    assert "defined('BACKUP_ENCRYPTION_KEY')" in method
    assert "? (string)constant('BACKUP_ENCRYPTION_KEY')" in method
    assert ": (defined('BACKUP_KEY') ? (string)constant('BACKUP_KEY') : '')" in method
    for path in ("docs/SECURITY.md", "docs/OPERATIONS.md"):
        assert "otherwise `BACKUP_KEY`" in text(path), path
    assert "Without `BACKUP_ENCRYPTION_KEY` the backups are unusable" not in text("docs/OPERATIONS.md")


def test_signing_selection_is_not_an_every_device_reinstall_guarantee():
    source = text("Mobile/wbws_flutter_app/android/app/build.gradle")
    assert "signingConfig keystorePropertiesFile.exists() ? signingConfigs.release : signingConfigs.debug" in source
    document = text("docs/OPERATIONS.md")
    assert "requires every device to uninstall and reinstall" not in document
    assert "stops in-place updates for every device" not in document
    assert "not an approved migration procedure" in document
    assert "actual offered/installed certificates" in document


def test_historical_hosting_observations_are_not_current_verified_results():
    document = text("docs/OPERATIONS.md")
    backups = document.split("## 4. Backups", 1)[1].split("## 5. Mobile release", 1)[0]
    assert "Historical provenance" in backups
    assert 'earlier "Verified" labels' in backups
    assert "hosted/off-site state UNKNOWN" in backups
    assert "**Never performed.**" not in backups
    protection = next(line for line in document.splitlines() if line.startswith("| L-6 |"))
    assert "were not verified by this audit" in protection
    php = next(line for line in document.splitlines() if line.startswith("| L-8 |"))
    assert "explicit vendor exclusions" in php
    assert "current production PHP was not inspected" in php


def test_private_register_policy_is_not_evidence_of_operational_existence():
    security = text("docs/SECURITY.md")
    assert "Private-handling policy" in security
    assert "has not verified the register's existence, contents or maintenance" in security
    assert "This repository records only that the register exists" not in security
    assert "not verification of a private register" in text("docs/OPERATIONS.md")
