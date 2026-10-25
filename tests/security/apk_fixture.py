"""
Minimal APK fixtures for tests: a ZIP archive whose AndroidManifest.xml is
Android binary XML (AXML) with a <manifest> element carrying package,
versionCode and versionName. Supports UTF-16 and UTF-8 string pools, stored
or deflated entries, and attribute names given only through the resource map
(how aapt2 output often looks).
"""
from __future__ import annotations

import struct
import zipfile
from pathlib import Path

ANDROID_NS = "http://schemas.android.com/apk/res/android"
RES_VERSION_CODE = 0x0101021B
RES_VERSION_NAME = 0x0101021C
NO_INDEX = 0xFFFFFFFF


def _string_pool(strings: list[str], utf8: bool) -> bytes:
    data = b""
    offsets = []
    for s in strings:
        offsets.append(len(data))
        if utf8:
            raw = s.encode("utf-8")
            assert len(s) < 0x80 and len(raw) < 0x80
            data += bytes([len(s), len(raw)]) + raw + b"\x00"
        else:
            raw = s.encode("utf-16-le")
            data += struct.pack("<H", len(s)) + raw + b"\x00\x00"
    while len(data) % 4:
        data += b"\x00"
    header_size = 28
    strings_start = header_size + 4 * len(strings)
    size = strings_start + len(data)
    flags = 0x100 if utf8 else 0
    return (struct.pack("<HHI", 0x0001, header_size, size)
            + struct.pack("<IIIII", len(strings), 0, flags, strings_start, 0)
            + b"".join(struct.pack("<I", o) for o in offsets) + data)


def axml_manifest(package: str, version_name: str, version_code: int, *,
                  utf8: bool = False, names_by_resource_id_only: bool = False,
                  root_element: str = "manifest") -> bytes:
    # String order: attribute names first (they line up with the resource map).
    vc_name = "" if names_by_resource_id_only else "versionCode"
    vn_name = "" if names_by_resource_id_only else "versionName"
    strings = [vc_name, vn_name, "package", ANDROID_NS, "android", root_element, package, version_name]
    if names_by_resource_id_only:
        strings[1] = " "  # keep indexes distinct; resolved by resource id
    pool = _string_pool(strings, utf8)
    res_ids = [RES_VERSION_CODE, RES_VERSION_NAME]
    resmap = struct.pack("<HHI", 0x0180, 8, 8 + 4 * len(res_ids)) + b"".join(struct.pack("<I", r) for r in res_ids)

    def node(chunk_type: int, body: bytes) -> bytes:
        size = 16 + len(body)
        return struct.pack("<HHI", chunk_type, 16, size) + struct.pack("<II", 1, NO_INDEX) + body

    start_ns = node(0x0100, struct.pack("<II", 4, 3))

    def attr(ns: int, name: int, raw: int, dtype: int, data: int) -> bytes:
        return struct.pack("<IIIHBBI", ns, name, raw, 8, 0, dtype, data)

    attrs = (attr(3, 0, NO_INDEX, 0x10, version_code)
             + attr(3, 1, 7, 0x03, 7)
             + attr(NO_INDEX, 2, 6, 0x03, 6))
    start_el = node(0x0102, struct.pack("<IIHHHHHH", NO_INDEX, 5, 20, 20, 3, 0, 0, 0) + attrs)
    end_el = node(0x0103, struct.pack("<II", NO_INDEX, 5))
    end_ns = node(0x0101, struct.pack("<II", 4, 3))
    body = pool + resmap + start_ns + start_el + end_el + end_ns
    return struct.pack("<HHI", 0x0003, 8, 8 + len(body)) + body


def write_apk(path: Path, package: str = "com.arkeonethiopia.fkss", version_name: str = "1.7.4",
              version_code: int = 41, *, deflate: bool = True, utf8: bool = False,
              names_by_resource_id_only: bool = False, manifest: bytes | None = None,
              padding: int = 2048) -> Path:
    path.parent.mkdir(parents=True, exist_ok=True)
    xml = manifest if manifest is not None else axml_manifest(
        package, version_name, version_code, utf8=utf8, names_by_resource_id_only=names_by_resource_id_only)
    method = zipfile.ZIP_DEFLATED if deflate else zipfile.ZIP_STORED
    with zipfile.ZipFile(path, "w", method) as z:
        z.writestr("META-INF/MANIFEST.MF", "Manifest-Version: 1.0\n")
        if manifest is not False:
            z.writestr("AndroidManifest.xml", xml)
        z.writestr("classes.dex", b"dex\n035\x00" + b"\x00" * padding)
    return path
