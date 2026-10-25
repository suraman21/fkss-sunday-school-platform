<?php
/**
 * Read the package name, versionName and versionCode from an APK.
 *
 * An APK is a ZIP archive; AndroidManifest.xml inside it is Android binary
 * XML (AXML). Both are parsed here in plain PHP (no ZipArchive, no
 * extensions beyond zlib for deflated entries) so the release dashboard
 * can take the version from the uploaded file itself on shared hosting.
 *
 * Every function returns null on anything unexpected; callers decide what
 * a missing result means. Nothing here throws for malformed input.
 */

const FKSS_APK_MAX_MANIFEST_BYTES = 4194304;      // 4 MB, real manifests are a few KB
const FKSS_APK_MAX_CENTRAL_DIR_BYTES = 16777216;  // 16 MB
const FKSS_ATTR_VERSION_CODE = 0x0101021b;        // android:versionCode
const FKSS_ATTR_VERSION_NAME = 0x0101021c;        // android:versionName

/**
 * @return array{package:string,version_name:string,version_code:int}|null
 */
function fkssApkManifest(string $file): ?array
{
    try {
        $xml = fkssZipReadEntry($file, 'AndroidManifest.xml', FKSS_APK_MAX_MANIFEST_BYTES);
        if ($xml === null) {
            return null;
        }
        $info = fkssAxmlManifestInfo($xml);
        if ($info === null) {
            return null;
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/', $info['package'])
            || !preg_match('/^[0-9][0-9A-Za-z.+_-]{0,39}$/', $info['version_name'])
            || $info['version_code'] < 1 || $info['version_code'] > 2100000000) {
            return null;
        }
        return $info;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Cached form of fkssApkManifest(): "<file>.manifest.json" holds the result
 * together with the file's SHA-256 and size, and is reused only while both
 * still match. Best effort: when the sidecar cannot be written the file is
 * parsed on every call (a few milliseconds).
 *
 * @param array{size:int,sha256:string} $meta from fkssApkMeta()
 * @return array{package:string,version_name:string,version_code:int}|null
 */
function fkssApkManifestCached(string $file, array $meta): ?array
{
    $sidecar = $file . '.manifest.json';
    if (is_file($sidecar)) {
        $cached = json_decode((string)@file_get_contents($sidecar), true);
        if (is_array($cached)
            && ($cached['sha256'] ?? '') === ($meta['sha256'] ?? null)
            && (int)($cached['size'] ?? -1) === (int)($meta['size'] ?? -2)
            && is_string($cached['package'] ?? null)
            && is_string($cached['version_name'] ?? null)
            && is_int($cached['version_code'] ?? null)) {
            return ['package' => $cached['package'], 'version_name' => $cached['version_name'],
                'version_code' => $cached['version_code']];
        }
    }
    $info = fkssApkManifest($file);
    if ($info !== null) {
        @file_put_contents($sidecar, json_encode($info + [
            'sha256' => (string)($meta['sha256'] ?? ''), 'size' => (int)($meta['size'] ?? 0),
        ]), LOCK_EX);
    }
    return $info;
}

/** Bytes of one ZIP entry (stored or deflated), CRC-checked; null if absent or invalid. */
function fkssZipReadEntry(string $file, string $entryName, int $maxBytes): ?string
{
    $size = @filesize($file);
    if (!is_int($size) || $size < 22) {
        return null;
    }
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return null;
    }
    try {
        // End of central directory: last 22 bytes plus an optional comment (≤ 65535).
        $tailLen = min($size, 22 + 65535);
        fseek($fh, $size - $tailLen);
        $tail = (string)fread($fh, $tailLen);
        $pos = strrpos($tail, "PK\x05\x06");
        if ($pos === false || strlen($tail) - $pos < 22) {
            return null;
        }
        $eocd = unpack('Vsig/vdisk/vcdDisk/vdiskEntries/ventries/VcdSize/VcdOffset', substr($tail, $pos, 20));
        $cdSize = (int)$eocd['cdSize'];
        $cdOffset = (int)$eocd['cdOffset'];
        if ($cdOffset === 0xFFFFFFFF || $cdSize > FKSS_APK_MAX_CENTRAL_DIR_BYTES || $cdOffset + $cdSize > $size) {
            return null; // ZIP64 or corrupt
        }
        fseek($fh, $cdOffset);
        $cd = (string)fread($fh, $cdSize);
        $p = 0;
        $found = null;
        $cdLen = strlen($cd);
        while ($p + 46 <= $cdLen && substr($cd, $p, 4) === "PK\x01\x02") {
            $h = unpack('vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen/vcommentLen/vdiskStart/vintAttr/VextAttr/Vlocal',
                substr($cd, $p + 8, 38));
            $name = substr($cd, $p + 46, $h['nameLen']);
            if ($name === $entryName) {
                $found = $h;
                break;
            }
            $p += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
        }
        if ($found === null || ($found['flags'] & 1) || $found['usize'] > $maxBytes
            || $found['csize'] > $maxBytes + 1048576 || $found['local'] + 30 > $size) {
            return null;
        }
        fseek($fh, (int)$found['local']);
        $lh = (string)fread($fh, 30);
        if (strlen($lh) !== 30 || substr($lh, 0, 4) !== "PK\x03\x04") {
            return null;
        }
        $l = unpack('vnameLen/vextraLen', substr($lh, 26, 4));
        fseek($fh, (int)$found['local'] + 30 + $l['nameLen'] + $l['extraLen']);
        $raw = $found['csize'] > 0 ? (string)fread($fh, (int)$found['csize']) : '';
        if (strlen($raw) !== (int)$found['csize']) {
            return null;
        }
        if ($found['method'] === 0) {
            $data = $raw;
        } elseif ($found['method'] === 8 && function_exists('gzinflate')) {
            $data = @gzinflate($raw, max(1, (int)$found['usize']));
            if (!is_string($data)) {
                return null;
            }
        } else {
            return null;
        }
        if (strlen($data) !== (int)$found['usize']
            || (int)hexdec(hash('crc32b', $data)) !== (int)$found['crc']) {
            return null;
        }
        return $data;
    } finally {
        fclose($fh);
    }
}

/**
 * package / versionName / versionCode of the <manifest> element of an
 * Android binary XML document.
 *
 * @return array{package:string,version_name:string,version_code:int}|null
 */
function fkssAxmlManifestInfo(string $xml): ?array
{
    $len = strlen($xml);
    if ($len < 8) {
        return null;
    }
    $head = unpack('vtype/vheaderSize/Vsize', substr($xml, 0, 8));
    if ($head['type'] !== 0x0003 || $head['headerSize'] < 8) {
        return null;
    }
    $strings = null;
    $resIds = [];
    $p = $head['headerSize'];
    while ($p + 8 <= $len) {
        $ch = unpack('vtype/vheaderSize/Vsize', substr($xml, $p, 8));
        $chSize = (int)$ch['size'];
        if ($chSize < 8 || $p + $chSize > $len || $ch['headerSize'] < 8 || $ch['headerSize'] > $chSize) {
            return null;
        }
        if ($ch['type'] === 0x0001) {
            $strings = fkssAxmlStringPool(substr($xml, $p, $chSize), (int)$ch['headerSize']);
            if ($strings === null) {
                return null;
            }
        } elseif ($ch['type'] === 0x0180) {
            $body = substr($xml, $p + $ch['headerSize'], $chSize - $ch['headerSize']);
            $resIds = $body === '' ? [] : array_values(unpack('V*', substr($body, 0, intdiv(strlen($body), 4) * 4)));
        } elseif ($ch['type'] === 0x0102 && $strings !== null && $chSize >= 36) {
            $el = unpack('Vns/Vname/vattrStart/vattrSize/vattrCount', substr($xml, $p + 16, 14));
            if (($strings[$el['name']] ?? '') !== 'manifest') {
                return null; // the first element of a manifest is <manifest>
            }
            $out = ['package' => null, 'version_name' => null, 'version_code' => null];
            $base = $p + 16 + $el['attrStart'];
            for ($i = 0; $i < $el['attrCount']; $i++) {
                $a = $base + $i * $el['attrSize'];
                if ($el['attrSize'] < 20 || $a + 20 > $p + $chSize) {
                    return null;
                }
                $at = unpack('Vns/Vname/Vraw/vtsize/Cres0/Ctype/Vdata', substr($xml, $a, 20));
                $name = $strings[$at['name']] ?? '';
                $resId = $resIds[$at['name']] ?? 0;
                if ($at['type'] === 0x03) {
                    $value = $strings[$at['data']] ?? null;
                } elseif ($at['type'] === 0x10 || $at['type'] === 0x11) {
                    $value = (int)$at['data'];
                } elseif ($at['raw'] !== 0xFFFFFFFF) {
                    $value = $strings[$at['raw']] ?? null;
                } else {
                    $value = null; // references (e.g. @string/…) are not resolved
                }
                if ($name === 'package' && is_string($value)) {
                    $out['package'] = $value;
                } elseif (($name === 'versionCode' || $resId === FKSS_ATTR_VERSION_CODE) && is_int($value)) {
                    $out['version_code'] = $value;
                } elseif (($name === 'versionName' || $resId === FKSS_ATTR_VERSION_NAME) && is_string($value)) {
                    $out['version_name'] = $value;
                }
            }
            if ($out['package'] === null || $out['version_name'] === null || $out['version_code'] === null) {
                return null;
            }
            return $out;
        }
        $p += $chSize;
    }
    return null;
}

/** @return array<int,string>|null strings of a ResStringPool chunk */
function fkssAxmlStringPool(string $chunk, int $headerSize): ?array
{
    if (strlen($chunk) < 28) {
        return null;
    }
    $h = unpack('VstringCount/VstyleCount/Vflags/VstringsStart/VstylesStart', substr($chunk, 8, 20));
    $count = (int)$h['stringCount'];
    $utf8 = ($h['flags'] & 0x100) !== 0;
    $chunkLen = strlen($chunk);
    if ($count > 200000 || $headerSize + $count * 4 > $chunkLen || $h['stringsStart'] > $chunkLen) {
        return null;
    }
    $offsets = $count > 0 ? array_values(unpack('V' . $count, substr($chunk, $headerSize, $count * 4))) : [];
    $out = [];
    foreach ($offsets as $i => $off) {
        $q = (int)$h['stringsStart'] + (int)$off;
        if ($q + 2 > $chunkLen) {
            return null;
        }
        if ($utf8) {
            // UTF-16 length (1–2 bytes), then UTF-8 byte length (1–2 bytes), then bytes
            $q += (ord($chunk[$q]) & 0x80) ? 2 : 1;
            if ($q + 1 > $chunkLen) {
                return null;
            }
            $n = ord($chunk[$q]);
            if ($n & 0x80) {
                if ($q + 2 > $chunkLen) {
                    return null;
                }
                $n = (($n & 0x7F) << 8) | ord($chunk[$q + 1]);
                $q += 2;
            } else {
                $q += 1;
            }
            if ($q + $n > $chunkLen) {
                return null;
            }
            $out[$i] = substr($chunk, $q, $n);
        } else {
            $n = unpack('v', substr($chunk, $q, 2))[1];
            $q += 2;
            if ($n & 0x8000) {
                if ($q + 2 > $chunkLen) {
                    return null;
                }
                $n = (($n & 0x7FFF) << 16) | unpack('v', substr($chunk, $q, 2))[1];
                $q += 2;
            }
            if ($q + $n * 2 > $chunkLen) {
                return null;
            }
            $out[$i] = fkssUtf16leToUtf8(substr($chunk, $q, $n * 2));
        }
    }
    return $out;
}

/** UTF-16LE → UTF-8 without mbstring/iconv (both are optional on shared hosting). */
function fkssUtf16leToUtf8(string $s): string
{
    $out = '';
    $n = intdiv(strlen($s), 2);
    for ($i = 0; $i < $n; $i++) {
        $u = ord($s[2 * $i]) | (ord($s[2 * $i + 1]) << 8);
        if ($u >= 0xD800 && $u <= 0xDBFF && $i + 1 < $n) {
            $lo = ord($s[2 * $i + 2]) | (ord($s[2 * $i + 3]) << 8);
            if ($lo >= 0xDC00 && $lo <= 0xDFFF) {
                $u = 0x10000 + (($u - 0xD800) << 10) + ($lo - 0xDC00);
                $i++;
            }
        }
        if ($u < 0x80) {
            $out .= chr($u);
        } elseif ($u < 0x800) {
            $out .= chr(0xC0 | ($u >> 6)) . chr(0x80 | ($u & 0x3F));
        } elseif ($u < 0x10000) {
            $out .= chr(0xE0 | ($u >> 12)) . chr(0x80 | (($u >> 6) & 0x3F)) . chr(0x80 | ($u & 0x3F));
        } else {
            $out .= chr(0xF0 | ($u >> 18)) . chr(0x80 | (($u >> 12) & 0x3F))
                . chr(0x80 | (($u >> 6) & 0x3F)) . chr(0x80 | ($u & 0x3F));
        }
    }
    return $out;
}
