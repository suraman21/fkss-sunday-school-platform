"""Synthetic ELF/ZIP and SDK-tool orchestration tests, NOT signed APK acceptance."""
import importlib.util
import io
from pathlib import Path
import struct
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock
import zipfile

SCRIPT = Path(__file__).resolve().parents[2] / 'Mobile/wbws_flutter_app/scripts/verify_android_apk.py'
spec = importlib.util.spec_from_file_location('android_apk_gate', SCRIPT)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


def elf(abi='arm64-v8a', align=16384, address=0):
    cls, machine = gate.ABIS[abi]
    ident = b'\x7fELF' + bytes((cls, 1, 1)) + bytes(9)
    if cls == 2:
        head = struct.pack('<16sHHIQQQIHHHHHH', ident, 3, machine, 1, 0, 64, 0, 0, 64, 56, 1, 0, 0, 0)
        return head + struct.pack('<IIQQQQQQ', 1, 5, 0, address, 0, 120, 120, align)
    head = struct.pack('<16sHHIIIIIHHHHHH', ident, 3, machine, 1, 0, 52, 0, 0, 52, 32, 1, 0, 0, 0)
    return head + struct.pack('<IIIIIIII', 1, 0, address, 0, 84, 84, 5, align)


class AndroidApkGateTests(unittest.TestCase):
    def test_elf_headers_and_loads_for_all_targets(self):
        for abi in gate.ABIS:
            with self.subTest(abi=abi):
                data = elf(abi)
                self.assertEqual(gate.elf_info(io.BytesIO(data), len(data), abi)['abi'], abi)

    def test_arm64_rejects_4kb_alignment_and_noncongruent_load(self):
        for data in (elf(align=4096), elf(address=1), elf(align=24576)):
            with self.assertRaises(ValueError):
                gate.elf_info(io.BytesIO(data), len(data), 'arm64-v8a')

    def test_arm32_4kb_remains_supported(self):
        data = elf('armeabi-v7a', align=4096)
        self.assertEqual(gate.elf_info(io.BytesIO(data), len(data), 'armeabi-v7a')['load_alignments'], [4096])

    def test_truncated_wrong_machine_and_invalid_program_table_rejected(self):
        data = elf()
        for invalid in (b'not ELF', data[:60], data[:18] + b'\x28\x00' + data[20:], data[:54] + bytes(4) + data[58:]):
            with self.assertRaises(ValueError):
                gate.elf_info(io.BytesIO(invalid), len(invalid), 'arm64-v8a')

    def test_relro_page_protection_permits_isolation_not_writable_overlap(self):
        def image(load_start, load_end, relro_start, relro_end, other=None):
            entries = [struct.pack('<IIQQQQQQ', 1, 6, load_start % 16384, load_start, 0, 0,
                                   load_end - load_start, 16384),
                       struct.pack('<IIQQQQQQ', 0x6474e552, 4, 0, relro_start, 0, 0,
                                   relro_end - relro_start, 1)]
            if other:
                start, end = other
                entries.append(struct.pack('<IIQQQQQQ', 1, 6, start % 16384, start, 0, 0, end - start, 16384))
            head = bytearray(elf()[:64])
            struct.pack_into('<H', head, 56, len(entries))
            return (head + b''.join(entries)).ljust(16384, b'\x00')
        for data in (image(0x4000, 0xc000, 0x4000, 0x8000),
                     image(0x4680, 0x5000, 0x4680, 0x5000),
                     image(0x4680, 0x5000, 0x4680, 0x5000, (0x8000, 0x9000))):
            self.assertEqual(gate.elf_info(io.BytesIO(data), len(data), 'arm64-v8a')['relro_segments'], 1)
        for data in (image(0x4000, 0x8000, 0x4000, 0x5000),
                     image(0x4000, 0x8000, 0x5000, 0x8000),
                     image(0x4680, 0x5000, 0x4680, 0x5000, (0x6000, 0x6800))):
            with self.assertRaisesRegex(ValueError, 'RELRO protection overlaps'):
                gate.elf_info(io.BytesIO(data), len(data), 'arm64-v8a')

    def test_load_file_and_memory_bounds_checked(self):
        data = bytearray(elf())
        struct.pack_into('<Q', data, 96, 121)
        for memory_size in (120, 512):
            struct.pack_into('<Q', data, 104, memory_size)
            with self.assertRaisesRegex(ValueError, 'file/memory bounds'):
                gate.elf_info(io.BytesIO(data), len(data), 'arm64-v8a')

    def test_windows_signer_avoids_shell_parsing_of_apk_names(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / 'bin').mkdir()
            (root / 'bin/java.exe').touch()
            (root / 'lib').mkdir()
            (root / 'lib/apksigner.jar').touch()
            result = SimpleNamespace(returncode=0, stdout='verified', stderr='')
            with mock.patch.dict(gate.os.environ, {'JAVA_HOME': str(root)}), mock.patch.object(gate.subprocess, 'run', return_value=result) as run:
                gate.run_tool(root / 'apksigner.bat', 'verify', 'a & untrusted.apk')
                self.assertEqual(run.call_args.args[0], [str(root / 'bin/java.exe'), '-jar', str(root / 'lib/apksigner.jar'), 'verify', 'a & untrusted.apk'])
                self.assertNotIn('shell', run.call_args.kwargs)

    def make_apk(self, path, abis=tuple(gate.ABIS), plugin=None):
        with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as archive:
            for abi in abis:
                for lib in ('libflutter.so', 'libapp.so'):
                    archive.writestr(f'lib/{abi}/{lib}', elf(abi))
            if plugin:
                archive.writestr('lib/arm64-v8a/libplugin.so', plugin)
        return path

    def test_all_bundled_plugins_checked_not_only_flutter(self):
        with tempfile.TemporaryDirectory() as tmp:
            apk = self.make_apk(Path(tmp) / 'test.apk', plugin=elf(align=4096))
            with self.assertRaises(ValueError):
                gate.inspect_native(apk, tuple(gate.ABIS))

    def test_plugin_only_extra_abi_cannot_claim_flutter_compatibility(self):
        with tempfile.TemporaryDirectory() as tmp:
            apk = self.make_apk(Path(tmp) / 'test.apk', abis=('arm64-v8a',))
            with zipfile.ZipFile(apk, 'a') as archive:
                archive.writestr('lib/armeabi-v7a/libplugin.so', elf('armeabi-v7a'))
            with self.assertRaisesRegex(ValueError, 'Missing armeabi-v7a/lib'):
                gate.inspect_native(apk, ('arm64-v8a',))

    def test_wrong_slot_missing_library_and_duplicate_entries(self):
        with tempfile.TemporaryDirectory() as tmp:
            apk = self.make_apk(Path(tmp) / 'test.apk', abis=('arm64-v8a',))
            with self.assertRaises(ValueError):
                gate.inspect_native(apk, tuple(gate.ABIS))
            self.assertEqual(len(gate.inspect_native(apk, ('arm64-v8a',))), 2)
            with zipfile.ZipFile(apk, 'a') as archive, self.assertWarns(UserWarning):
                archive.writestr('lib/arm64-v8a/libapp.so', elf())
            with self.assertRaises(ValueError):
                gate.inspect_native(apk, ('arm64-v8a',))

    def test_mock_sdk_orchestration_identity_sdk_and_signature_fail_closed(self):
        # Tool output is mocked. A successful test is not evidence of signing.
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            tools = root / 'build-tools/36.0.0'
            tools.mkdir(parents=True)
            for name in ('aapt', 'zipalign', 'apksigner'):
                suffix = ('.bat' if name == 'apksigner' else '.exe') if gate.os.name == 'nt' else ''
                (tools / (name + suffix)).touch()
            args = SimpleNamespace(apk=self.make_apk(root / 'test.apk'), sdk_root=str(root),
                build_tools='36.0.0', package='com.arkeonethiopia.fkss', version='1.7.4', version_code=41,
                max_min_sdk=24, min_target_sdk=36, abis=','.join(gate.ABIS))
            good = "package: name='com.arkeonethiopia.fkss' versionCode='41' versionName='1.7.4'\nsdkVersion:'24'\ntargetSdkVersion:'36'\n"
            with mock.patch.object(gate, 'run_tool', side_effect=[good, 'aligned', 'MOCK signed']) as run:
                report = gate.verify(args)
                self.assertEqual((report['min_sdk'], report['target_sdk']), (24, 36))
                self.assertEqual(len(report['native_libraries']), 6)
                self.assertEqual(run.call_args_list[-1].args[1:], ('verify', '--verbose', '--print-certs', args.apk))
            for bad in (good.replace("sdkVersion:'24'", "sdkVersion:'29'"),
                        good.replace("targetSdkVersion:'36'", "targetSdkVersion:'35'"),
                        good.replace("versionCode='41'", "versionCode='42'"),
                        good.replace("name='com.arkeonethiopia.fkss'", "name='example.other'"), 'unknown'):
                with mock.patch.object(gate, 'run_tool', return_value=bad), self.assertRaises(ValueError):
                    gate.verify(args)
            with mock.patch.object(gate, 'run_tool', side_effect=[good, 'aligned', ValueError('invalid signature')]), self.assertRaises(ValueError):
                gate.verify(args)
            (tools / ('apksigner.bat' if gate.os.name == 'nt' else 'apksigner')).unlink()
            with mock.patch.object(gate, 'run_tool', side_effect=[good, 'aligned']), self.assertRaises(ValueError):
                gate.verify(args)


if __name__ == '__main__':
    unittest.main()
