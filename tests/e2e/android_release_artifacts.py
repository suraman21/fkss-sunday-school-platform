#!/usr/bin/env python3
"""CI-only real-artifact acceptance. No HTTP/DB/production; no APK execution.

Requires the owner script's dist/<version>+<build> output and PHP CLI. Checks
all three actual artifacts/reports, then the real shared-host inspector in an
isolated configuration. SDK gates must already have run before reports exist.
"""
import argparse
import hashlib
import html
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
KINDS = {'universal': {'armeabi-v7a', 'arm64-v8a', 'x86_64'},
         'armeabi-v7a': {'armeabi-v7a'}, 'arm64-v8a': {'arm64-v8a'}}


def digest(path):
    with path.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest()


def local_file(directory, name):
    if not isinstance(name, str) or Path(name).name != name or '/' in name or '\\' in name:
        raise ValueError('Artifact/report filename must be a local basename')
    path = directory / name
    if not path.is_file() or path.resolve().parent != directory.resolve():
        raise ValueError(f'Missing or nonlocal evidence: {name}')
    return path


def check_set(directory):
    manifest = json.loads((directory / 'release-manifest.json').read_text(encoding='utf-8-sig'))
    items = manifest['artifacts']
    if len(items) != len(KINDS) or {item['kind'] for item in items} != set(KINDS):
        raise ValueError('Require exactly universal, ARM32 and ARM64 artifacts')
    result = {}
    for item in items:
        kind = item['kind']
        apk = local_file(directory, item['name'])
        report_file = local_file(directory, item['compatibility_report'])
        report = json.loads(report_file.read_text(encoding='utf-8'))
        sha = digest(apk)
        if sha != item['sha256'] or sha != report['sha256']:
            raise ValueError(f'{kind}: APK/report/manifest hash mismatch')
        if report['version'] != manifest['app_version'] or report['version_code'] != int(manifest['app_build']):
            raise ValueError(f'{kind}: universal/per-device version mismatch')
        if report['package'] != 'com.arkeonethiopia.fkss' or report['min_sdk'] > 24 or report['target_sdk'] < 36:
            raise ValueError(f'{kind}: package or SDK support mismatch')
        abis = {value['abi'] for value in report['native_libraries'].values()}
        if abis != KINDS[kind]:
            raise ValueError(f'{kind}: not the expected complete ABI set: {sorted(abis)}')
        result[kind] = {'path': apk, 'sha256': sha, 'version_code': report['version_code'],
                        'version': report['version'], 'min_sdk': report['min_sdk'],
                        'target_sdk': report['target_sdk'], 'abis': sorted(abis)}
    return result


def inspect_publication(artifacts):
    # Reuse the already tested CLI fixture, not a second implementation of
    # publication validation. Only inspect operations are used; no uploads.
    sys.path.insert(0, str(ROOT / 'tests/security'))
    from test_app_release_from_apk import HARNESS, php_config
    with tempfile.TemporaryDirectory(prefix='android-artifact-inspect-') as temp:
        home = Path(temp)
        web = home / 'public_html'
        (web / 'admin/backend').mkdir(parents=True)
        (web / 'api/v1').mkdir(parents=True)
        (web / 'admin/backend/services').symlink_to(ROOT / 'admin/backend/services', target_is_directory=True)
        (web / 'api/v1/core').symlink_to(ROOT / 'api/v1/core', target_is_directory=True)
        # Respect the real loader's allowed release-root boundary. A symlink
        # to the source APK outside that root would (correctly) be refused.
        (web / 'releases').mkdir()
        published = web / 'releases/fkss.apk'
        shutil.copyfile(artifacts['universal']['path'], published)
        cfg = web / 'api/v1/app_release.local.php'
        before = php_config({'apk_path': str(published),
                             'force_update': False, 'min_build': 1})
        cfg.write_text(before)
        harness = home / 'inspect.php'
        harness.write_text(HARNESS)
        def inspect(kind, path):
            operation = {'op': 'inspect', 'file': str(path.resolve()), 'abi': kind}
            response = subprocess.run(['php', str(harness), str(web), json.dumps(operation)],
                                      capture_output=True, text=True, timeout=90, check=True)
            return json.loads(response.stdout)
        outputs = {}
        for kind, artifact in artifacts.items():
            response = inspect(kind, artifact['path'])
            if not response['ok'] or response['out']['version_code'] != artifact['version_code']:
                raise ValueError(f'{kind}: actual PHP inspector refused artifact: {response}')
            outputs[kind] = response['out']
        wrong = artifacts['arm64-v8a']['path']
        if inspect('armeabi-v7a', wrong)['ok'] or inspect('universal', wrong)['ok']:
            raise ValueError('Actual ARM64-only artifact accepted into an incompatible slot')
        if cfg.read_text() != before or any(digest(item['path']) != item['sha256'] for item in artifacts.values()):
            raise ValueError('Read-only inspection modified configuration or an artifact')
        return outputs


def write_ci_summary(directory, result, report_path, summary):
    """Preserve full generated records, not private APK bytes or invented passes."""
    manifest = json.loads(local_file(directory, 'release-manifest.json').read_text(encoding='utf-8-sig'))
    records = {
        'release_set': result,
        'release_manifest': manifest,
        'compatibility_reports': {
            item['kind']: json.loads(local_file(directory, item['compatibility_report']).read_text(encoding='utf-8'))
            for item in manifest['artifacts']
        },
        'native_dependencies': {
            name: local_file(report_path.parent, name).read_text(encoding='utf-8')
            for name in ('barcode-native-dependency.txt', 'camera-native-dependencies.txt')
        },
    }
    serialized = json.dumps(records, indent=2)
    content = ('## Android CI verification records\n\n'
               'Actual build/SDK/PHP checks; not device execution or owner signing acceptance. '
               'Public repositories archive CI APKs; private repositories retain reports in logs/summary only. '
               'No private APK bytes are retained or copied elsewhere.\n\n'
               '<details><summary>Full generated verification records</summary>\n<pre>'
               + html.escape(serialized) + '</pre>\n</details>\n')
    # GitHub permits 1MiB per step summary. Never silently truncate evidence.
    if len(content.encode('utf-8')) > 900_000:
        raise ValueError('CI verification summary exceeds the 900KB evidence bound')
    with summary.open('a', encoding='utf-8') as destination:
        destination.write(content)
    print('ANDROID_CI_EVIDENCE_BEGIN')
    print(serialized)
    print('ANDROID_CI_EVIDENCE_END')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    parser.add_argument('--report', type=Path, required=True)
    parser.add_argument('--summary', type=Path, help='Append complete CI verification records to the step summary')
    args = parser.parse_args()
    artifacts = check_set(args.directory)
    publication = inspect_publication(artifacts)
    result = {'scope': 'Real built artifact/SDK and PHP inspection; NOT device execution or owner signing acceptance.',
              'artifacts': {kind: {k: str(v) if isinstance(v, Path) else v for k, v in item.items()}
                            for kind, item in artifacts.items()}, 'publication_inspection': publication,
              'actual_arm64_in_wrong_slots': 'rejected', 'read_only': True}
    args.report.write_text(json.dumps(result, indent=2) + '\n')
    if args.summary is not None:
        write_ci_summary(args.directory, result, args.report, args.summary)
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    main()
