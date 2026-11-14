"""Pending-link activation fix pins: the flip site, resolver widening, leniency.

Static pins (no PHP runtime in CI sandbox): the activation branch
of completeFull flips the provision-time pending link to active in
the same transaction (lenient: error_log only, no rowCount gate so
bookkeeping can never brick a ceremony); userIdFor resolves
pending+active (the ceremony runs on pending links); no other
pending->active flip site exists; device_add/staff_enroll/session
branches still touch no links.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DEV = ROOT / "acm" / "src" / "Device" / "DeviceService.php"
PROV = ROOT / "acm" / "src" / "Accounts" / "AccountProvisioner.php"


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


def test_link_flip_lives_in_activation_branch_only():
    s = read(DEV)
    assert "UPDATE acm_person_accounts SET status = 'active'" in s
    branch = s.split("$operation === 'activation' && $ticketId !== null")[1].split(
        "$operation === 'device_add'"
    )[0]
    assert "UPDATE acm_person_accounts SET status = 'active'" in branch
    assert "AND status = 'pending'" in branch
    rest = s.split("$operation === 'device_add'")[1]
    assert "acm_person_accounts" not in rest


def test_link_flip_is_lenient_never_bricks_ceremony():
    s = read(DEV)
    flip = s.split("$linkFlip = ")[1].split("markActivated($ticketId, $meta)")[0]
    assert "rowCount() !== 1" in flip
    assert "error_log(" in flip
    assert "throw" not in flip


def test_user_id_for_resolves_pending_and_active():
    s = read(PROV)
    body = s.split("function userIdFor(")[1].split("function usernameForUser(")[0]
    assert "status IN ('pending','active')" in body
    assert "status = 'active'" not in body.replace("status IN ('pending','active')", "")


def test_no_other_pending_to_active_flip_site():
    s = read(PROV)
    assert s.count("SET status = 'active'") == 1  # reactivate() only
