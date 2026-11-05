"""Dataset validator is inactive pure Dart; no caller, no storage, no HMAC verification."""
import json
import pathlib
import re


def test_fixture_is_complete_valid_payload():
    p = pathlib.Path("tests/fixtures/attendance_preparation_dataset_v1.json")
    data = json.loads(p.read_text())
    payload = data["payload"]
    assert payload["preparation_version"] == 1
    assert payload["complete"] is True
    assert payload["owner_user_id"] == 7
    assert payload["write_year"]["status"] == "active"
    assert len(payload["classes"][0]["days"]) == 3
    # Must be complete empty distinguishable: not a failure fixture
    assert payload["classes"][0]["days"][0]["date"] == "2026-11-02"


def test_dataset_decoder_has_no_caller():
    # No production Dart file may import the dataset validator yet
    lib = pathlib.Path("Mobile/wbws_flutter_app/lib")
    offenders = []
    for path in lib.rglob("*.dart"):
        if path.name == "attendance_preparation_dataset.dart":
            continue
        text = path.read_text()
        if "attendance_preparation_dataset" in text or "AttendancePreparationDataset" in text:
            offenders.append(str(path))
    assert offenders == [], f"Dataset decoder must remain inactive, found imports in {offenders}"


def test_dataset_does_not_verify_hmac():
    src = pathlib.Path("Mobile/wbws_flutter_app/lib/services/attendance_preparation_dataset.dart").read_text()
    # Validator is shape-only, not cryptographic verification
    assert "AttendancePreparationDataset" in src
    # Must not use HMAC primitives or server secrets
    low = src.lower()
    assert "hash_hmac" not in low
    assert "hmac_sha" not in low
    assert "sign(" not in low or "context_token" in low  # allow local var name but not signing
    assert "server secret" not in low
    # Context token is checked for shape, not signature
    assert "context_token" in src
    assert "Noncanonical" in src or "canonical" in src.lower()
