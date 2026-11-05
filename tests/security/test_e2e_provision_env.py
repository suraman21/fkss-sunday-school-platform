"""Exercise only the disposable provisioner's secret-line parser; no DB/apt calls."""
import os
import subprocess
from pathlib import Path


def test_provisioner_reads_database_settings_linewise_without_splitting_password():
    script = Path(__file__).resolve().parents[1] / "e2e/provision_env.sh"
    read_line = next(line for line in script.read_text().splitlines() if line.startswith("IFS="))
    values = ["ssms_test", "ssms_audit", "Line n with spaces \"quotes\" and 'apostrophe'"]
    result = subprocess.run(
        ["bash", "-c", 'set -u\n' + read_line + '\nprintf "%s\\0" "$DB_NAME" "$DB_USER" "$DB_PASS"'],
        env={**os.environ, "_env_values": "\n".join(values)},
        capture_output=True,
        check=True,
    )
    assert result.stdout.decode().split("\0")[:-1] == values
