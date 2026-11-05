"""Hosted-runner-only cleanup; sudo is mocked, no installed tooling is removed."""
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / '.github/workflows/backend-checks.yml'


def cleanup_script():
    android = WORKFLOW.read_text().split('  android-build:\n', 1)[1]
    step = android.split('      - name: Reclaim unrelated hosted-runner disk space\n', 1)[1]
    return textwrap.dedent(step.split('        run: |\n', 1)[1].split('\n      - ', 1)[0])


class AndroidRunnerResourcesTests(unittest.TestCase):
    def run_cleanup(self, **overrides):
        with tempfile.TemporaryDirectory() as directory:
            capture = Path(directory) / 'sudo-arguments'
            env = {**os.environ, 'GITHUB_ACTIONS': 'true',
                   'RUNNER_ENVIRONMENT': 'github-hosted', 'RUNNER_OS': 'Linux',
                   'GITHUB_WORKSPACE': directory, 'CAPTURE': str(capture), **overrides}
            # Exported shell function shadows sudo: even a failing test cannot
            # invoke real removal. Capture literal argv, not shell rendering.
            script = 'sudo() { printf "%s\\0" "$@" >> "$CAPTURE"; };\n' + cleanup_script()
            result = subprocess.run(['bash', '-c', script], env=env,
                                    capture_output=True, text=True, timeout=15)
            recorded = capture.read_bytes().split(b'\0')[:-1] if capture.exists() else []
            return result, [item.decode() for item in recorded]

    def test_only_explicit_unused_tool_roots_are_removed_on_hosted_linux(self):
        result, arguments = self.run_cleanup()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(arguments, ['rm', '-rf', '--', '/usr/share/dotnet', '/opt/ghc',
                                     '/usr/local/.ghcup', '/opt/hostedtoolcache/CodeQL'])

    def test_other_environments_fail_before_any_removal(self):
        for values in ({'GITHUB_ACTIONS': ''}, {'GITHUB_ACTIONS': 'false'},
                       {'RUNNER_ENVIRONMENT': ''}, {'RUNNER_ENVIRONMENT': 'self-hosted'},
                       {'RUNNER_OS': 'Windows'}, {'RUNNER_OS': 'macOS'}):
            with self.subTest(values=values):
                result, arguments = self.run_cleanup(**values)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(arguments, [])
                self.assertIn('restricted to Linux GitHub-hosted runners', result.stdout)

    def test_embedded_shell_syntax(self):
        subprocess.run(['bash', '-n'], input=cleanup_script(), text=True, check=True)

    def test_cleanup_precedes_setup_without_weakening_release_gates(self):
        android = WORKFLOW.read_text().split('  android-build:\n', 1)[1]
        self.assertLess(android.index('Reclaim unrelated hosted-runner disk space'),
                        android.index('Set up JDK 17'))
        self.assertIn('flutter pub get --enforce-lockfile', android)
        self.assertIn('./scripts/build-release.ps1 -SplitPerAbi', android)
        self.assertIn('tests/e2e/android_release_artifacts.py', android)
        self.assertIn('name: android-compatibility-evidence', android)
        self.assertIn('name: android-ci-test-signed-apks-not-for-publication', android)
        self.assertNotIn('continue-on-error', android)
        self.assertNotIn('-SkipTests', android)


if __name__ == '__main__':
    unittest.main()
