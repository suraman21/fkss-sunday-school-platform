"""Activated dataset validation boundaries; client-side parsing is not HMAC verification."""
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


def test_dataset_callers_and_atomic_publication_boundary():
    lib = pathlib.Path("Mobile/wbws_flutter_app/lib")
    callers = set()
    for path in lib.rglob("*.dart"):
        if path.name == "attendance_preparation_dataset.dart":
            continue
        text = path.read_text()
        if "attendance_preparation_dataset" in text or "AttendancePreparationDataset" in text:
            callers.add(path.relative_to(lib).as_posix())
    assert callers == {
        "services/attendance_preparation_fetcher.dart",
        "services/attendance_preparation_adapter.dart",
        "services/attendance_preparation_store.dart",
        "services/grades_preparation_dataset.dart",
    }
    fetcher = (lib / "services/attendance_preparation_fetcher.dart").read_text()
    assert fetcher.index("AttendancePreparationEnvelope.decode(") < fetcher.index("AttendancePreparationDataset.decode(") < fetcher.index("_store.publishPreparation(")
    store = (lib / "services/attendance_preparation_store.dart").read_text()
    publish = store.split("Future<void> publishPreparation(", 1)[1].split("Future<AttendancePreparationSnapshot?> getSnapshot(", 1)[0]
    assert "dataset.ownerUserId != expectedSession.ownerUserId" in publish
    assert "dataset.authorizationVersion != expectedSession.authorizationVersion" in publish
    assert "await db.transaction((txn) async" in publish
    assert "_activeSessionMatchesTxn(txn, expectedSession: expectedSession)" in publish
    assert publish.index("_activeSessionMatchesTxn") < publish.index("await txn.delete(") < publish.index("await txn.insert(")


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
