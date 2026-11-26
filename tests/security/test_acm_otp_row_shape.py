"""The OTP row shape: schema, writer and reader must agree.

WHY THIS FILE EXISTS
    Three places describe one row shape and they drifted apart:

      082_acm_activation_ceremony.sql  selector VARCHAR(16) NOT NULL UNIQUE
      ActivationService::issueCode()   wrote '' for an OTP row
      ActivationService::verifyOtp()   expected a row it could find

    Result: the empty string satisfied UNIQUE exactly once, so the SECOND
    activation code in the lifetime of the installation was rejected with
    SQLSTATE 23000, and every later issuance with it ("Code issuance
    failed"). Not just a bug in a corner -- activation could not work twice.

    Fixing it needs the column to be nullable (so an OTP row can say "no
    selector" instead of pretending), the writer to write NULL, and the
    unique key to survive so the selector+secret pair form stays unique.

    A silent schema/writer mismatch is exactly what shipped here, so it is
    worth pinning from both ends.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SQL82 = ROOT / "sql" / "082_acm_activation_ceremony.sql"
SQL103 = ROOT / "sql" / "103_acm_otp_selector_fix.sql"
SERVICE = ROOT / "acm" / "src" / "Activation" / "ActivationService.php"


def text(rel):
    return (ROOT / rel).read_text(encoding="utf-8")


def strip_sql_comments(src: str) -> str:
    src = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
    return re.sub(r"(?m)^\s*--.*$", "", src)


def strip_php_comments(src: str) -> str:
    out, i, n = [], 0, len(src)
    while i < n:
        c = src[i]
        if c in ("'", '"'):
            q, out = c, out + [c]
            i += 1
            while i < n and src[i] != q:
                out.append(src[i])
                i += 1
            if i < n:
                out.append(src[i])
                i += 1
        elif src.startswith("//", i):
            while i < n and src[i] != "\n":
                i += 1
        else:
            out.append(c)
            i += 1
    return "".join(out)


def test_migration_103_exists_and_relaxes_the_column():
    src = strip_sql_comments(SQL103.read_text(encoding="utf-8"))
    assert re.search(
        r"ALTER\s+TABLE\s+`?acm_activation_codes`?\s+MODIFY\s+(?:COLUMN\s+)?`?selector`?",
        src, re.I,
    ), "103 must MODIFY acm_activation_codes.selector to be nullable"
    # The new definition must NOT carry NOT NULL.
    modify = re.search(r"MODIFY\s+(?:COLUMN\s+)?`?selector`?[^;]*;", src, re.I).group(0)
    assert "NOT NULL" not in modify.upper().replace("NOT NULLS", ""), modify


def test_migration_103_keeps_the_unique_key():
    """Nullable must not be achieved by dropping uniqueness.

    The selector+secret pair form still needs a globally unique selector,
    so the fix is "many NULLs, one ''" -- not "no constraint".
    """
    src = strip_sql_comments(SQL103.read_text(encoding="utf-8"))
    assert "uniq_acm_code_selector" in src
    assert re.search(
        r"(?:ADD|CREATE)\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\s+`?uniq_acm_code_selector`?",
        src, re.I,
    ), "103 re-adds the unique key"
    assert "UNIQUE" in src.upper()


def test_migration_103_does_not_assume_it_is_the_first_run():
    """A migration that throws when re-run cannot be applied to a
    half-migrated database, which is when you need it most."""
    src = SQL103.read_text(encoding="utf-8")
    upper = src.upper()
    assert "INFORMATION_SCHEMA" in upper, "guard the DDL on the live catalog"
    # Dropping the index conditionally, adding it conditionally.
    assert re.search(r"DROP\s+INDEX", src, re.I)
    assert "IF EXISTS" in upper or "INFORMATION_SCHEMA.STATISTICS" in upper


def test_migration_103_converts_existing_empty_selectors():
    """Any database that already holds a '' row must keep it, as NULL."""
    src = strip_sql_comments(SQL103.read_text(encoding="utf-8"))
    assert re.search(r"UPDATE\s+`?acm_activation_codes`?", src, re.I)
    assert re.search(r"selector`?\s*=\s*''", src), "converts '' -> NULL"
    assert re.search(r"SELECTOR`?\s*=\s*NULL", src.upper()), "sets it to NULL"


def test_writer_uses_null_for_an_otp_row():
    """String '' satisfies UNIQUE exactly once. NULL satisfies it forever."""
    src = strip_php_comments(SERVICE.read_text(encoding="utf-8"))
    issue = src.split("public function issueCode(")[1]
    issue = issue.split("\n    private function ", 1)[0]
    # Everything up to the first INSERT is the branch that picks the value.
    head = issue.split("INSERT INTO acm_activation_codes", 1)[0]
    assert "$selector = null;" in head, (
        "an OTP row must carry a NULL selector; writing '' means only the "
        "first activation code can ever be inserted"
    )
    assert "$selector = '';" not in head, (
        "the empty-string selector is the defect 103 exists to undo"
    )


def test_reader_tolerates_a_null_selector():
    """The console reads the column too; it must not print an empty slot."""
    page = text("admin/acm_ticket.php")
    assert "selector" in page


def test_verify_reads_the_ticket_it_was_given():
    """A regression guard for the reference/ticket mix-up.

    verifyOtp() resolves a reference to a ticket id and must read THAT
    ticket's code. Reusing a caller-supplied ticket id directly would let
    one member's code be checked against another member's ticket.
    """
    src = strip_php_comments(SERVICE.read_text(encoding="utf-8"))
    body = src.split("public function verifyOtp(")[1].split("\n    private function ", 1)[0]
    assert "ticketIdByReference($reference)" in body
    code_read = body.split("lockedOtpCode(", 1)[1].split(")", 1)[0]
    assert "$ticketId" in code_read, "the code is read for the resolved ticket"


def test_issued_code_selector_may_be_null():
    """A string-typed selector forces the layer above to invent a value.

    082's NOT NULL came from exactly that: nothing was willing to say "an
    activation code has no selector", so '' was written and UNIQUE turned
    it into "one activation code per database, ever". The contract has to
    admit null, and display() must refuse an OTP rather than print a
    dangling separator.
    """
    src = text("acm/src/Contracts/IssuedCode.php")
    assert "?string $selector" in src, (
        "IssuedCode::selector must be nullable; issueCode() now passes NULL "
        "and a non-nullable string throws a TypeError on every activation"
    )
    body = src.split("public function display(")[1]
    assert "LogicException" in body, "display() must refuse an OTP, not print '-'"


def test_reader_of_a_null_selector_is_guarded():
    """The console must not render a separator before a missing selector."""
    page = text("admin/acm_ticket.php")
    assert "6-digit OTP" in page, (
        "an activation row has no selector; the console says so instead of "
        "leaving a blank in the 'last code' line"
    )
