<?php
/**
 * App release metadata — how the phone knows a new APK exists.
 *
 * Edit /home/USER/.fkss_app_release.php on the server (see
 * api/v1/app_release.example.php). Never put the APK in git.
 *
 * P65: optional per-ABI artifacts. The universal APK ('apk_path') is
 * ALWAYS the baseline and must always be published; 'apk_arm64_path' /
 * 'apk_arm32_path' let the phone download a ~2x smaller per-device build
 * (the app sends ?abi=arm64-v8a|armeabi-v7a). Old app versions ignore
 * the extra fields and keep receiving the universal file.
 *
 * The published version is read from the universal APK itself
 * (apk_manifest.php); the typed latest_version / latest_build are only a
 * fallback for a file whose manifest cannot be read. Phones are never told
 * to update, or blocked, unless the file they need can be downloaded:
 * see fkssPhoneUpdatePolicy().
 */

require_once __DIR__ . '/apk_manifest.php';

/**
 * Size + SHA-256 of an APK, with a sidecar cache so an ~80MB file is not
 * re-hashed on every /app/config poll (the config endpoint is hit by
 * every app at every launch — at fleet scale that is real CPU).
 * Sidecar: "<file>.meta" holding "<sha256> <size>"; re-computed when the
 * APK's size changes (i.e. after the admin uploads a new build). Best
 * effort: if the sidecar cannot be written we simply hash every time.
 */
function fkssApkMeta(string $file): array
{
    clearstatcache(true, $file);
    $size = (int)filesize($file);
    $mtime = (int)filemtime($file);
    $sidecar = $file . '.meta';
    // "<sha256> <size> <mtime>": a replaced file of the same size is re-hashed.
    if (is_file($sidecar)) {
        $parts = preg_split('/\s+/', trim((string)@file_get_contents($sidecar)));
        if (
            is_array($parts) && count($parts) === 3
            && strlen((string)$parts[0]) === 64 && ctype_xdigit((string)$parts[0])
            && (int)$parts[1] === $size && (int)$parts[2] === $mtime
        ) {
            return ['size' => $size, 'sha256' => strtolower((string)$parts[0])];
        }
    }
    $sha = hash_file('sha256', $file);
    @file_put_contents($sidecar, $sha . ' ' . $size . ' ' . $mtime, LOCK_EX);
    return ['size' => $size, 'sha256' => $sha];
}

function fkssLoadAppRelease(): array
{
    $defaults = [
        // Keep these synchronized with Mobile/wbws_flutter_app/pubspec.yaml
        // and AppConfig. Deployment-owned metadata may move ahead only after
        // the matching APK and checksums have been published.
        'latest_version' => '1.7.3',
        'latest_build'   => 40,
        'min_version'    => '1.0.0',
        'min_build'      => 1,
        'force_update'   => false,
        // Emergency containment only: false pauses outbound mobile outbox
        // drains. It never disables durable local saves or deletes rows.
        'background_drains_enabled' => true,
        'release_notes'  => '',
        'banner_text'    => '',
        'banner_kind'    => 'info',
        'apk_path'       => '',
        'apk_arm64_path' => '',
        'apk_arm32_path' => '',
        'tiles'          => [
            'education' => ['classes', 'teachers', 'subjects', 'enrollment', 'grades', 'attendance', 'warnings'],
        ],
    ];

    $candidates = [];
    if (defined('ROOT_PATH')) {
        $candidates[] = dirname(ROOT_PATH) . '/.fkss_app_release.php';
        $candidates[] = ROOT_PATH . '/api/v1/app_release.local.php';
    }
    $candidates[] = dirname(__DIR__, 2) . '/../.fkss_app_release.php';
    $candidates[] = dirname(__DIR__) . '/app_release.local.php';

    $file = null;
    foreach ($candidates as $path) {
        if (is_string($path) && is_file($path)) {
            $file = $path;
            break;
        }
    }

    $loaded = [];
    $configFile = null;
    if ($file) {
        $configFile = $file;
        $raw = include $file;
        if (is_array($raw)) {
            $loaded = $raw;
        }
    }

    $out = array_merge($defaults, $loaded);
    $out['latest_version'] = preg_replace('/[^0-9.]/', '', (string)$out['latest_version']) ?: '1.5.0';
    $out['min_version'] = preg_replace('/[^0-9.]/', '', (string)$out['min_version']) ?: '1.0.0';
    $out['latest_build'] = max(1, (int)$out['latest_build']);
    $out['min_build'] = max(1, (int)$out['min_build']);
    $out['force_update'] = !empty($out['force_update']);
    // Deliberately strict: a typo such as the string "false" must not turn
    // an emergency pause back on. Omission inherits the safe normal default.
    $out['background_drains_enabled'] = $out['background_drains_enabled'] === true;
    $out['release_notes'] = trim((string)$out['release_notes']);
    $out['banner_text'] = trim((string)$out['banner_text']);
    $out['banner_kind'] = in_array($out['banner_kind'] ?? '', ['info', 'warn'], true) ? $out['banner_kind'] : 'info';
    $out['apk_path'] = trim((string)$out['apk_path']);
    $out['apk_arm64_path'] = trim((string)($out['apk_arm64_path'] ?? ''));
    $out['apk_arm32_path'] = trim((string)($out['apk_arm32_path'] ?? ''));
    if (!is_array($out['tiles'])) {
        $out['tiles'] = $defaults['tiles'];
    }

    $out['_config_file'] = $configFile;

    // Universal artifact — the baseline every client can fall back to.
    $resolved = fkssResolveApkPath($out['apk_path']);
    $out['_apk_file'] = $resolved;
    $out['download_available'] = $resolved !== null;
    $out['version_source'] = 'config';
    $out['apk_package'] = '';
    $universalApp = null;

    $artifacts = [];
    $files = [];
    if ($resolved !== null) {
        $meta = fkssApkMeta($resolved);
        $out['apk_size_bytes'] = $meta['size'];
        $out['apk_sha256'] = $meta['sha256'];
        $artifacts['universal'] = ['size_bytes' => $meta['size'], 'sha256' => $meta['sha256']];
        // The file is the truth: advertise exactly the version it contains.
        $universalApp = fkssApkManifestCached($resolved, $meta);
        if ($universalApp !== null) {
            $out['latest_version'] = preg_replace('/[^0-9.]/', '', $universalApp['version_name']) ?: $out['latest_version'];
            $out['latest_build'] = $universalApp['version_code'];
            $out['version_source'] = 'apk';
            $out['apk_package'] = $universalApp['package'];
        }
    } else {
        $out['apk_size_bytes'] = 0;
        $out['apk_sha256'] = '';
    }

    // Optional per-ABI artifacts (P65). Both resolve through the SAME
    // allowlisted roots; a missing or invalid file is simply not offered.
    $abiKeys = ['arm64-v8a' => 'apk_arm64_path', 'armeabi-v7a' => 'apk_arm32_path'];
    foreach ($abiKeys as $abi => $key) {
        if ($out[$key] === '') {
            continue;
        }
        $abiFile = fkssResolveApkPath($out[$key]);
        if ($abiFile === null) {
            continue;
        }
        $meta = fkssApkMeta($abiFile);
        // A per-ABI file is offered only when it is the same app and build as
        // the universal file; a stale split from an older release is skipped.
        if ($universalApp !== null) {
            $abiApp = fkssApkManifestCached($abiFile, $meta);
            if ($abiApp === null || $abiApp['package'] !== $universalApp['package']
                || $abiApp['version_code'] !== $universalApp['version_code']) {
                continue;
            }
        }
        $files[$abi] = $abiFile;
        $artifacts[$abi] = ['size_bytes' => $meta['size'], 'sha256' => $meta['sha256']];
    }

    $out['_apk_files'] = $files;
    $out['apk_artifacts'] = $artifacts;

    return $out;
}

/**
 * Compare two app versions the way the phone does (AppVersion in
 * Mobile/wbws_flutter_app/lib/utils/version.dart): major.minor.patch, then
 * build. Returns -1, 0 or 1.
 */
function fkssVersionCompare(string $versionA, int $buildA, string $versionB, int $buildB): int
{
    $parts = static function (string $v): array {
        $p = explode('.', preg_replace('/[^0-9.]/', '', $v));
        return [(int)($p[0] ?? 0), (int)($p[1] ?? 0), (int)($p[2] ?? 0)];
    };
    $a = $parts($versionA);
    $a[] = $buildA;
    $b = $parts($versionB);
    $b[] = $buildB;
    return $a <=> $b;
}

/**
 * What phones are told by GET /app/config.
 *  - No downloadable APK: no update prompt and no block (a phone cannot
 *    install a file that does not exist).
 *  - A minimum above the published file is lowered to the published file,
 *    so installing the published APK always clears the block.
 *
 * @return array{latest_version:string,latest_build:int,min_version:string,min_build:int,force_update:bool}
 */
function fkssPhoneUpdatePolicy(array $rel): array
{
    if (empty($rel['download_available'])) {
        return ['latest_version' => '1.0.0', 'latest_build' => 1,
            'min_version' => '1.0.0', 'min_build' => 1, 'force_update' => false];
    }
    $latestV = (string)$rel['latest_version'];
    $latestB = (int)$rel['latest_build'];
    $minV = (string)$rel['min_version'];
    $minB = (int)$rel['min_build'];
    if (fkssVersionCompare($minV, $minB, $latestV, $latestB) > 0) {
        $minV = $latestV;
        $minB = $latestB;
    }
    return ['latest_version' => $latestV, 'latest_build' => $latestB,
        'min_version' => $minV, 'min_build' => $minB, 'force_update' => !empty($rel['force_update'])];
}

/** Only files under an allowed folder, ending in .apk, may be served. */
function fkssResolveApkPath(string $configured): ?string
{
    $allowedRoots = [];
    if (defined('ROOT_PATH')) {
        $allowedRoots[] = dirname(ROOT_PATH) . '/fkss_releases';
        $allowedRoots[] = ROOT_PATH . '/releases';
    }
    $allowedRoots[] = dirname(__DIR__, 2) . '/../fkss_releases';
    $allowedRoots[] = dirname(__DIR__, 2) . '/releases';

    $try = [];
    if ($configured !== '') {
        $try[] = $configured;
    }
    foreach ($allowedRoots as $root) {
        $try[] = rtrim($root, '/') . '/fkss.apk';
    }

    foreach ($try as $path) {
        $real = realpath($path);
        if (!$real || !is_file($real)) {
            continue;
        }
        if (strtolower(substr($real, -4)) !== '.apk') {
            continue;
        }
        foreach ($allowedRoots as $root) {
            $rootReal = realpath($root);
            if ($rootReal && strncmp($real, $rootReal, strlen($rootReal)) === 0) {
                return $real;
            }
            // folder may not exist yet — allow exact configured file only if parent is allowed name
            if (!$rootReal && $configured !== '' && basename(dirname($real)) === 'fkss_releases') {
                return $real;
            }
        }
    }
    return null;
}
