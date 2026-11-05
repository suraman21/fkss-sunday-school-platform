#!/usr/bin/env python3
"""Read-only release gate; requires Python 3.11+ and SDK build-tools 36.0.0.

Checks actual APK metadata, CRC, complete native ABI core sets, ELF identity,
64-bit 16 KiB LOAD alignment,
ZIP alignment and SDK signature verification. Not installation, runtime,
owner-certificate continuity or physical-device/performance acceptance.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import struct
import subprocess
import sys
import zipfile

ABIS = {"armeabi-v7a": (1, 40), "arm64-v8a": (2, 183), "x86_64": (2, 62)}


def elf_info(stream, size, abi):
    head = stream.read(64)
    elf_class, machine = ABIS[abi]
    minimum = 52 if elf_class == 1 else 64
    if (len(head) < minimum or head[:4] != b"\x7fELF" or head[4:7] != bytes((elf_class, 1, 1))
            or struct.unpack_from("<HHI", head, 16) != (3, machine, 1)):
        raise ValueError(f"Invalid {abi} ELF header")
    if elf_class == 1:
        phoff = struct.unpack_from("<I", head, 28)[0]
        phsize, count = struct.unpack_from("<HH", head, 42)
        expected = 32
    else:
        phoff = struct.unpack_from("<Q", head, 32)[0]
        phsize, count = struct.unpack_from("<HH", head, 54)
        expected = 56
    if not (expected <= phsize <= 256 and 1 <= count <= 1024 and phoff >= minimum
            and phoff + phsize * count <= size):
        raise ValueError(f"Invalid {abi} ELF program-header table")
    stream.seek(phoff)
    table = stream.read(phsize * count)
    if len(table) != phsize * count:
        raise ValueError("Truncated ELF program-header table")
    loads, writable, relro = [], [], []
    for pos in range(0, len(table), phsize):
        kind = struct.unpack_from('<I', table, pos)[0]
        if kind not in (1, 0x6474e552):  # PT_LOAD, PT_GNU_RELRO
            continue
        if elf_class == 1:
            _, offset, address, _, filesz, memsz, flags, align = struct.unpack_from('<IIIIIIII', table, pos)
        else:
            _, flags, offset, address, _, filesz, memsz, align = struct.unpack_from('<IIQQQQQQ', table, pos)
        if kind == 0x6474e552:
            if elf_class == 2 and memsz:
                relro.append((address, address + memsz))
            continue
        if filesz > memsz or offset + filesz > size:
            raise ValueError('PT_LOAD exceeds ELF file/memory bounds')
        if elf_class == 2 and (align < 16384 or align & (align - 1) or (address - offset) % 16384):
            raise ValueError(f'{abi} PT_LOAD is not 16KiB aligned; investigate the native dependency')
        loads.append(align)
        if flags & 2:  # PF_W
            writable.append((address, address + memsz))
    if not loads:
        raise ValueError('ELF has no PT_LOAD segments')
    for start, end in relro:
        protected = (start // 16384 * 16384, (end + 16383) // 16384 * 16384)
        for lo, hi in writable:
            # Page-rounded RELRO must not make data outside RELRO read-only.
            # An isolated RELRO LOAD is valid even if its end is not aligned.
            for a, b in ((lo, min(hi, start)), (max(lo, end), hi)):
                if a < b and max(a, protected[0]) < min(b, protected[1]):
                    raise ValueError(f'{abi} 16KiB RELRO protection overlaps writable data')
    return {'abi': abi, 'load_alignments': loads, 'relro_segments': len(relro)}


def inspect_native(apk, required):
    with zipfile.ZipFile(apk) as archive:
        names = archive.namelist()
        if len(set(names)) != len(names):
            raise ValueError("Ambiguous duplicate ZIP entries")
        bad = archive.testzip()  # streaming CRC check, no extraction
        if bad:
            raise ValueError(f"ZIP CRC failed: {bad}")
        present = {name.split('/')[1] for name in names
                   if name.startswith('lib/') and name.endswith('.so') and name.count('/') == 2}
        # A plugin-only ABI can make Android select a native architecture for
        # which Flutter has no engine/app. Check closure for every bundled ABI,
        # not just the CLI's minimum required set.
        for abi in sorted(set(required) | present):
            if abi not in ABIS:
                raise ValueError(f"Unsupported ABI: {abi}")
            for library in ("libflutter.so", "libapp.so"):
                if f"lib/{abi}/{library}" not in names:
                    raise ValueError(f"Missing {abi}/{library}")
        libraries = {}
        for item in archive.infolist():
            if not item.filename.startswith("lib/") or not item.filename.endswith(".so"):
                continue
            parts = item.filename.split("/")
            if len(parts) != 3 or parts[1] not in ABIS:
                raise ValueError(f"Unsupported native library path: {item.filename}")
            with archive.open(item) as stream:
                try:
                    libraries[item.filename] = elf_info(stream, item.file_size, parts[1])
                except ValueError as error:
                    raise ValueError(f'{item.filename}: {error}') from error
        return libraries


def run_tool(tool, *args):
    command = [str(tool), *map(str, args)]
    if tool.suffix.lower() == ".bat":
        # Invoke the SDK signer's jar directly, not cmd.exe: downloaded APK
        # filenames containing shell metacharacters must remain literal args.
        home = os.environ.get("JAVA_HOME")
        java = str(Path(home) / "bin/java.exe") if home else shutil.which("java.exe")
        jar = tool.parent / "lib/apksigner.jar"
        if not java or not Path(java).is_file() or not jar.is_file():
            raise ValueError("Windows SDK signature verification requires JDK java.exe and lib/apksigner.jar")
        command = [java, "-jar", str(jar), *map(str, args)]
    result = subprocess.run(command, capture_output=True, text=True, timeout=180)
    if result.returncode:
        raise ValueError(f"{tool.name} failed ({result.returncode}): {result.stderr or result.stdout}")
    return result.stdout


def verify(args):
    sdk = args.sdk_root or os.environ.get("ANDROID_SDK_ROOT") or os.environ.get("ANDROID_HOME")
    if not sdk:
        raise ValueError("Set ANDROID_SDK_ROOT/ANDROID_HOME or --sdk-root; no checks are skipped")
    tools = Path(sdk) / "build-tools" / args.build_tools
    def tool(name):
        suffix = (".bat" if name == "apksigner" else ".exe") if os.name == "nt" else ""
        path = tools / (name + suffix)
        if not path.is_file():
            raise ValueError(f"Required SDK tool missing: {path}")
        return path
    badging = run_tool(tool("aapt"), "dump", "badging", args.apk)
    identity = re.search(r"^package: name='([^']+)' versionCode='(\d+)' versionName='([^']+)'", badging, re.M)
    minimum = re.search(r"^sdkVersion:'(\d+)'", badging, re.M)
    target = re.search(r"^targetSdkVersion:'(\d+)'", badging, re.M)
    if not identity or not minimum or not target:
        raise ValueError("SDK tools did not return package/version/SDK metadata")
    package, code, version = identity.groups()
    if package != args.package or (args.version and version != args.version) or (args.version_code and int(code) != args.version_code):
        raise ValueError("APK identity differs from the requested build")
    if int(minimum[1]) > args.max_min_sdk or int(target[1]) < args.min_target_sdk:
        raise ValueError(f"SDK support regression: min={minimum[1]}, target={target[1]}")
    libraries = inspect_native(args.apk, args.abis.split(","))
    alignment = run_tool(tool("zipalign"), "-c", "-P", "16", "-v", "4", args.apk)
    signature = run_tool(tool("apksigner"), "verify", "--verbose", "--print-certs", args.apk)
    # Default apksigner verification uses the APK's actual minimum SDK, not just
    # the host's newest API. Owner must separately compare trusted fingerprints.
    with args.apk.open("rb") as source:
        digest = hashlib.file_digest(source, "sha256").hexdigest()
    return {"apk": args.apk.name, "sha256": digest, "package": package, "version": version,
            "version_code": int(code), "min_sdk": int(minimum[1]), "target_sdk": int(target[1]),
            "build_tools": args.build_tools, "native_libraries": libraries,
            "zip_alignment_output": alignment, "signature_output": signature,
            "scope": "Artifact checks only; owner certificate continuity and device acceptance remain required."}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("apk", type=Path)
    parser.add_argument("--sdk-root")
    parser.add_argument("--build-tools", default="36.0.0")
    parser.add_argument("--abis", default=",".join(ABIS))
    parser.add_argument("--package", default="com.arkeonethiopia.fkss")
    parser.add_argument("--version")
    parser.add_argument("--version-code", type=int)
    parser.add_argument("--max-min-sdk", type=int, default=24)
    parser.add_argument("--min-target-sdk", type=int, default=36)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    try:
        report = json.dumps(verify(args), indent=2) + "\n"
        if args.report:
            args.report.write_text(report, encoding="utf-8")
        print(report, end="")
    except (ValueError, OSError, subprocess.SubprocessError, zipfile.BadZipFile) as error:
        print(f"APK COMPATIBILITY GATE FAILED: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
