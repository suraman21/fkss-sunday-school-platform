"""Synthetic record consistency; not SDK signing, installability or device tests."""
import contextlib
import html
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest import mock

from apk_fixture import write_apk

SCRIPT = Path(__file__).resolve().parents[2] / 'tests/e2e/android_release_artifacts.py'
spec = importlib.util.spec_from_file_location('android_release_set', SCRIPT)
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class AndroidReleaseSetTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.directory = Path(self.temp.name)
        items = []
        for kind, abis in release.KINDS.items():
            apk = write_apk(self.directory / (kind + '.apk'), abis=tuple(sorted(abis)))
            sha = release.digest(apk)
            # Deliberately fabricated tool records. Actual SDK/ELF/signature
            # checks are exercised separately, with real APKs, by Android CI.
            report = {'package': 'com.arkeonethiopia.fkss', 'version': '1.7.4', 'version_code': 41,
                      'min_sdk': 24, 'target_sdk': 36, 'sha256': sha,
                      'native_libraries': {f'lib/{abi}/libapp.so': {'abi': abi} for abi in abis}}
            report_name = kind + '.json'
            (self.directory / report_name).write_text(json.dumps(report))
            items.append({'kind': kind, 'name': apk.name, 'sha256': sha, 'compatibility_report': report_name})
        self.manifest = {'app_version': '1.7.4', 'app_build': '41', 'artifacts': items}
        self.save_manifest()

    def tearDown(self):
        self.temp.cleanup()

    def save_manifest(self):
        # Also exercise Windows PowerShell 5 UTF8 BOM handling.
        (self.directory / 'release-manifest.json').write_text(json.dumps(self.manifest), encoding='utf-8-sig')

    def change_report(self, **values):
        path = self.directory / 'arm64-v8a.json'
        data = json.loads(path.read_text())
        data.update(values)
        path.write_text(json.dumps(data))

    def test_matching_base_build_and_expected_abi_sets(self):
        result = release.check_set(self.directory)
        self.assertEqual(set(result), set(release.KINDS))
        self.assertEqual({row['version_code'] for row in result.values()}, {41})

    def test_offset_split_build_is_refused_not_normalized(self):
        self.change_report(version_code=2041)
        with self.assertRaisesRegex(ValueError, 'version mismatch'):
            release.check_set(self.directory)

    def test_report_hash_and_actual_bytes_must_agree(self):
        apk = self.directory / 'arm64-v8a.apk'
        apk.write_bytes(apk.read_bytes() + b'changed')
        with self.assertRaisesRegex(ValueError, 'hash mismatch'):
            release.check_set(self.directory)

    def test_duplicate_missing_or_extra_artifact_kinds_refused(self):
        self.manifest['artifacts'][1]['kind'] = 'universal'
        self.save_manifest()
        with self.assertRaises(ValueError):
            release.check_set(self.directory)

    def test_plugin_only_or_extra_abi_cannot_masquerade_as_split(self):
        self.change_report(native_libraries={'lib/arm64-v8a/libapp.so': {'abi': 'arm64-v8a'},
                                            'lib/armeabi-v7a/libplugin.so': {'abi': 'armeabi-v7a'}})
        with self.assertRaisesRegex(ValueError, 'complete ABI set'):
            release.check_set(self.directory)

    def test_package_or_sdk_regressions_refused(self):
        for values in ({'min_sdk': 29}, {'min_sdk': 24, 'target_sdk': 35},
                       {'target_sdk': 36, 'package': 'example.other'}):
            with self.subTest(values=values):
                self.change_report(**values)
                with self.assertRaises(ValueError):
                    release.check_set(self.directory)

    def test_evidence_paths_cannot_escape_directory(self):
        for name in ('../outside.json', '/outside.json', 'nested/report.json', '..\\outside.json'):
            with self.subTest(name=name), self.assertRaises(ValueError):
                release.local_file(self.directory, name)

    def test_real_php_inspector_with_explicitly_noninstallable_fixtures(self):
        # This exercises fixture plumbing and PHP version/ELF identity checks,
        # NOT real SDK verification. Real artifacts run through this same path
        # in Android CI after the owner script's mandatory SDK gates.
        result = release.inspect_publication(release.check_set(self.directory))
        self.assertEqual(set(result), set(release.KINDS))
        self.assertEqual({row['version_code'] for row in result.values()}, {41})

    def summary_inputs(self):
        build = self.directory / 'build'
        build.mkdir(exist_ok=True)
        for name in ('barcode-native-dependency.txt', 'camera-native-dependencies.txt'):
            (build / name).write_text('SYNTHETIC dependency record; not SDK evidence')
        return build / 'release-set.json', self.directory / 'step-summary'

    def test_summary_and_structured_log_preserve_all_synthetic_records(self):
        report, summary = self.summary_inputs()
        result = {'scope': 'SYNTHETIC test record, not real APK acceptance', 'read_only': True}
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            release.write_ci_summary(self.directory, result, report, summary)
        text = summary.read_text()
        payload = json.loads(html.unescape(text.split('<pre>', 1)[1].split('</pre>', 1)[0]))
        logged = json.loads(output.getvalue().split('ANDROID_CI_EVIDENCE_BEGIN\n', 1)[1]
                            .split('\nANDROID_CI_EVIDENCE_END', 1)[0])
        self.assertEqual(payload, logged)
        self.assertEqual(payload['release_set'], result)
        self.assertEqual(payload['release_manifest'], self.manifest)  # UTF8 BOM accepted
        self.assertEqual(set(payload['compatibility_reports']), set(release.KINDS))
        for item in self.manifest['artifacts']:
            self.assertEqual(payload['compatibility_reports'][item['kind']],
                             json.loads((self.directory / item['compatibility_report']).read_text()))
        self.assertEqual(len(payload['native_dependencies']), 2)
        self.assertIn('No private APK bytes are retained', text)

    def test_summary_appends_and_escapes_untrusted_report_text(self):
        report, summary = self.summary_inputs()
        summary.write_text('Existing summary\n')
        unsafe = '</pre><script>alert(1)</script>\n```'
        (report.parent / 'barcode-native-dependency.txt').write_text(unsafe)
        with contextlib.redirect_stdout(io.StringIO()):
            release.write_ci_summary(self.directory, {}, report, summary)
        text = summary.read_text()
        self.assertTrue(text.startswith('Existing summary\n'))
        self.assertNotIn('<script>', text)
        self.assertIn('&lt;script&gt;', text)
        payload = json.loads(html.unescape(text.split('<pre>', 1)[1].split('</pre>', 1)[0]))
        self.assertEqual(payload['native_dependencies']['barcode-native-dependency.txt'], unsafe)

    def test_summary_requires_each_native_dependency_record(self):
        report, summary = self.summary_inputs()
        for name in ('barcode-native-dependency.txt', 'camera-native-dependencies.txt'):
            with self.subTest(name=name):
                path = report.parent / name
                original = path.read_text()
                path.unlink()
                with self.assertRaises(ValueError):
                    release.write_ci_summary(self.directory, {}, report, summary)
                self.assertFalse(summary.exists())
                path.write_text(original)

    def test_summary_refuses_missing_malformed_or_nonlocal_compatibility_records(self):
        report, summary = self.summary_inputs()
        path = self.directory / 'arm64-v8a.json'
        original = path.read_text()
        path.unlink()
        with self.assertRaises(ValueError):
            release.write_ci_summary(self.directory, {}, report, summary)
        path.write_text('not JSON')
        with self.assertRaises(json.JSONDecodeError):
            release.write_ci_summary(self.directory, {}, report, summary)
        path.write_text(original)
        self.manifest['artifacts'][0]['compatibility_report'] = '../outside.json'
        self.save_manifest()
        with self.assertRaises(ValueError):
            release.write_ci_summary(self.directory, {}, report, summary)
        self.assertFalse(summary.exists())

    def test_summary_never_silently_truncates_oversize_records(self):
        report, summary = self.summary_inputs()
        (report.parent / 'barcode-native-dependency.txt').write_text('x' * 900_001)
        with self.assertRaisesRegex(ValueError, '900KB'):
            release.write_ci_summary(self.directory, {}, report, summary)
        self.assertFalse(summary.exists())

    def test_summary_write_failure_is_not_swallowed(self):
        report, _ = self.summary_inputs()
        with self.assertRaises(IsADirectoryError):
            release.write_ci_summary(self.directory, {}, report, self.directory)

    def test_ci_cli_writes_full_summary_after_actual_inspector_call(self):
        # Deliberately fake SDK records and mocked PHP result: plumbing only.
        report, summary = self.summary_inputs()
        argv = ['android_release_artifacts.py', str(self.directory), '--report', str(report),
                '--summary', str(summary)]
        with mock.patch.object(release.sys, 'argv', argv), \
                mock.patch.object(release, 'inspect_publication', return_value={'synthetic': True}) as inspect, \
                contextlib.redirect_stdout(io.StringIO()):
            release.main()
        inspect.assert_called_once()
        self.assertEqual(json.loads(report.read_text())['publication_inspection'], {'synthetic': True})
        self.assertIn('Full generated verification records', summary.read_text())

    def test_no_summary_cli_retains_existing_report_and_stdout_contract(self):
        report, summary = self.summary_inputs()
        argv = ['android_release_artifacts.py', str(self.directory), '--report', str(report)]
        output = io.StringIO()
        with mock.patch.object(release.sys, 'argv', argv), \
                mock.patch.object(release, 'inspect_publication', return_value={'synthetic': True}), \
                mock.patch.object(release, 'write_ci_summary') as writer, contextlib.redirect_stdout(output):
            release.main()
        writer.assert_not_called()
        self.assertFalse(summary.exists())
        self.assertEqual(json.loads(output.getvalue()), json.loads(report.read_text()))

    def test_inspection_failure_cannot_be_converted_into_summary_success(self):
        report, summary = self.summary_inputs()
        argv = ['android_release_artifacts.py', str(self.directory), '--report', str(report),
                '--summary', str(summary)]
        with mock.patch.object(release.sys, 'argv', argv), \
                mock.patch.object(release, 'inspect_publication', side_effect=ValueError('inspection failed')), \
                mock.patch.object(release, 'write_ci_summary') as writer, self.assertRaises(ValueError):
            release.main()
        writer.assert_not_called()
        self.assertFalse(summary.exists())
        self.assertFalse(report.exists())

    def test_workflow_archives_only_for_exact_public_visibility_without_skipping_checks(self):
        workflow = (SCRIPT.parents[2] / '.github/workflows/backend-checks.yml').read_text()
        android = workflow.split('  android-build:\n', 1)[1]
        for name, status in (
            ('Preserve release artifact compatibility evidence (not a publishable release)', 'always()'),
            ('Preserve CI-test-signed release APKs for native inspection, never publication', 'always()'),
            ('Upload the debug APK', 'success()'),
        ):
            with self.subTest(step=name):
                step = android.split('      - name: ' + name + '\n', 1)[1].split('\n      - ', 1)[0]
                self.assertIn("if: " + status + " && github.event.repository.visibility == 'public'", step)
                self.assertIn('uses: actions/upload-artifact@v4', step)
                self.assertIn('retention-days: 7', step)
        self.assertIn('--report build/android-release-set.json --summary "$GITHUB_STEP_SUMMARY"', android)
        for command in ('flutter build apk --debug', './scripts/build-release.ps1 -SplitPerAbi',
                        'tests/e2e/android_release_artifacts.py', 'git diff --exit-code'):
            self.assertIn(command, android)
        self.assertNotIn('continue-on-error', android)
        self.assertNotIn('-SkipTests', android)


if __name__ == '__main__':
    unittest.main()
