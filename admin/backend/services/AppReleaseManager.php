<?php

namespace App\Services;

use RuntimeException;
use InvalidArgumentException;
use Throwable;

/**
 * Service for managing mobile application releases, APK artifacts,
 * chunked resumable uploads, and version policies from the Super Admin dashboard.
 */
final class AppReleaseManager
{
    public const MAX_APK_BYTES = 262144000; // 250 MB

    /** Android packages this server may publish (production app and Beta). */
    public const KNOWN_PACKAGES = [
        'com.arkeonethiopia.fkss' => 'FKSS',
        'com.arkeonethiopia.fkss.beta' => 'FKSS Beta',
    ];

    /**
     * Get the target configuration file path.
     */
    public static function getConfigFile(string $projectRoot): string
    {
        // Check for server-level private file first
        $privateFile = dirname($projectRoot) . '/.fkss_app_release.php';
        if (is_file($privateFile) && is_writable($privateFile)) {
            return $privateFile;
        }

        // Local api directory release file
        return $projectRoot . '/api/v1/app_release.local.php';
    }

    /**
     * Get the releases storage directory.
     */
    public static function getReleasesDirectory(string $projectRoot): string
    {
        $privateDir = dirname($projectRoot) . '/fkss_releases';
        if (is_dir($privateDir) && is_writable($privateDir)) {
            return $privateDir;
        }

        $localDir = $projectRoot . '/releases';
        if (!is_dir($localDir)) {
            @mkdir($localDir, 0755, true);
        }

        $htaccess = $localDir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "# Protect release directory\nOptions -Indexes\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phps)\$\">\n    Order allow,deny\n    Deny from all\n</FilesMatch>\n");
        }

        return $localDir;
    }

    /**
     * Get temporary chunks storage directory for an upload session.
     */
    public static function getChunkDirectory(string $projectRoot, string $uploadId): string
    {
        $cleanId = preg_replace('/[^a-zA-Z0-9_-]/', '', $uploadId);
        if (strlen($cleanId) < 8 || strlen($cleanId) > 64) {
            throw new InvalidArgumentException('Invalid upload session identifier.');
        }

        $releasesDir = self::getReleasesDirectory($projectRoot);
        $baseTmp = $releasesDir . '/.tmp_chunks';
        if (!is_dir($baseTmp)) {
            @mkdir($baseTmp, 0755, true);
            $htaccess = $baseTmp . '/.htaccess';
            if (!is_file($htaccess)) {
                @file_put_contents($htaccess, "# Protect temp chunks\nOrder deny,allow\nDeny from all\n");
            }
        }

        $targetDir = $baseTmp . '/' . $cleanId;
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        return $targetDir;
    }

    /**
     * Garbage collect abandoned temporary chunk directories older than $maxAgeSeconds.
     */
    public static function cleanupOldChunks(string $projectRoot, int $maxAgeSeconds = 14400): void
    {
        $releasesDir = self::getReleasesDirectory($projectRoot);
        $baseTmp = $releasesDir . '/.tmp_chunks';
        if (!is_dir($baseTmp)) {
            return;
        }

        $now = time();
        $items = @scandir($baseTmp);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.htaccess') {
                continue;
            }
            $dirPath = $baseTmp . '/' . $item;
            if (is_dir($dirPath)) {
                $mtime = filemtime($dirPath);
                if ($mtime !== false && ($now - $mtime) > $maxAgeSeconds) {
                    self::deleteRecursive($dirPath);
                }
            }
        }
    }

    /**
     * Read the full current app release metadata.
     */
    public static function getReleaseInfo(string $projectRoot): array
    {
        require_once $projectRoot . '/api/v1/core/app_release.php';
        $rel = fkssLoadAppRelease();

        $configFile = self::configTarget($rel, $projectRoot);
        $rel['config_file'] = $configFile;
        $rel['config_file_exists'] = is_file($configFile);
        $rel['phone_policy'] = fkssPhoneUpdatePolicy($rel);

        $releasesDir = self::getReleasesDirectory($projectRoot);
        $rel['releases_dir'] = $releasesDir;

        // Details of active files
        $artifactsDetail = [];
        $apkFile = $rel['_apk_file'] ?? null;
        if ($apkFile && is_file($apkFile)) {
            $meta = fkssApkMeta($apkFile);
            $artifactsDetail['universal'] = [
                'path' => $apkFile,
                'filename' => basename($apkFile),
                'size_bytes' => $meta['size'],
                'size_formatted' => self::formatBytes($meta['size']),
                'sha256' => $meta['sha256'],
                'modified_at' => date('Y-m-d H:i:s', filemtime($apkFile)),
            ];
        }

        foreach (['arm64-v8a', 'armeabi-v7a'] as $abi) {
            $f = $rel['_apk_files'][$abi] ?? null;
            if ($f && is_file($f)) {
                $meta = fkssApkMeta($f);
                $artifactsDetail[$abi] = [
                    'path' => $f,
                    'filename' => basename($f),
                    'size_bytes' => $meta['size'],
                    'size_formatted' => self::formatBytes($meta['size']),
                    'sha256' => $meta['sha256'],
                    'modified_at' => date('Y-m-d H:i:s', filemtime($f)),
                ];
            }
        }

        $rel['artifacts_detail'] = $artifactsDetail;
        return $rel;
    }

    /**
     * The settings file to write: the file the release loader actually read
     * (so a save is never shadowed by another file), else the default
     * location. Read-only loaded file => error, nothing is written elsewhere.
     */
    private static function configTarget(array $rel, string $projectRoot): string
    {
        $loaded = $rel['_config_file'] ?? null;
        if (is_string($loaded) && $loaded !== '' && is_file($loaded)) {
            return $loaded;
        }
        return self::getConfigFile($projectRoot);
    }

    /**
     * Save updated release configuration. Keys missing from $input keep their
     * current value (an APK upload passes only the file path and must not
     * change Force update, the minimum version or background sync).
     */
    public static function saveConfig(string $projectRoot, array $input): array
    {
        $current = self::getReleaseInfo($projectRoot);

        // The published version comes from the APK when it can be read.
        $fromApk = ($current['version_source'] ?? '') === 'apk';
        $version = $fromApk
            ? (string)$current['latest_version']
            : preg_replace('/[^0-9.]/', '', (string)($input['latest_version'] ?? $current['latest_version']));
        if ($version === '') {
            throw new InvalidArgumentException('Latest version must be a valid version string (e.g. 1.5.0).');
        }

        $minVersion = preg_replace('/[^0-9.]/', '', (string)($input['min_version'] ?? $current['min_version']));
        if ($minVersion === '') {
            $minVersion = '1.0.0';
        }

        $build = $fromApk
            ? (int)$current['latest_build']
            : max(1, (int)($input['latest_build'] ?? $current['latest_build']));
        $minBuild = max(1, (int)($input['min_build'] ?? $current['min_build']));
        $forceUpdate = array_key_exists('force_update', $input)
            ? !empty($input['force_update'])
            : !empty($current['force_update']);
        $drainsEnabled = array_key_exists('background_drains_enabled', $input)
            ? (bool)$input['background_drains_enabled']
            : (($current['background_drains_enabled'] ?? true) === true);

        $notes = trim((string)($input['release_notes'] ?? $current['release_notes']));
        $bannerText = trim((string)($input['banner_text'] ?? $current['banner_text']));
        $bannerKindIn = $input['banner_kind'] ?? ($current['banner_kind'] ?? 'info');
        $bannerKind = in_array($bannerKindIn, ['info', 'warn'], true) ? $bannerKindIn : 'info';

        $apkPath = trim((string)($input['apk_path'] ?? ($current['apk_path'] ?? '')));
        $apkArm64Path = trim((string)($input['apk_arm64_path'] ?? ($current['apk_arm64_path'] ?? '')));
        $apkArm32Path = trim((string)($input['apk_arm32_path'] ?? ($current['apk_arm32_path'] ?? '')));

        $configData = [
            'latest_version' => $version,
            'latest_build'   => $build,
            'min_version'    => $minVersion,
            'min_build'      => $minBuild,
            'force_update'   => $forceUpdate,
            'background_drains_enabled' => $drainsEnabled,
            'release_notes'  => $notes,
            'banner_text'    => $bannerText,
            'banner_kind'    => $bannerKind,
            'apk_path'       => $apkPath,
            'apk_arm64_path' => $apkArm64Path,
            'apk_arm32_path' => $apkArm32Path,
            'tiles'          => $current['tiles'] ?? [
                'education' => ['classes', 'teachers', 'subjects', 'enrollment', 'grades', 'attendance'],
            ],
        ];

        $targetFile = self::configTarget($current, $projectRoot);
        if (is_file($targetFile) && !is_writable($targetFile)) {
            throw new RuntimeException('The release settings file ' . basename($targetFile)
                . ' is read-only on the server, so nothing was saved. Ask the hosting provider to make it writable.');
        }
        $content = "<?php\n/**\n * Auto-generated by Super Admin App Release Manager\n * Updated: " . date('Y-m-d H:i:s T') . "\n */\nreturn " . var_export($configData, true) . ";\n";

        $tempFile = $targetFile . '.tmp.' . bin2hex(random_bytes(8));
        if (@file_put_contents($tempFile, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write temporary release config file.');
        }

        if (!@rename($tempFile, $targetFile)) {
            @unlink($tempFile);
            throw new RuntimeException('Failed to save release config file.');
        }

        @chmod($targetFile, 0644);
        return self::getReleaseInfo($projectRoot);
    }

    /**
     * Handle an individual upload chunk.
     */
    public static function handleChunkUpload(
        string $projectRoot,
        ?array $fileInfo,
        string $uploadId,
        int $chunkIndex,
        int $totalChunks,
        int $chunkSize,
        int $totalSize,
        ?string $base64Payload = null
    ): array {
        self::cleanupOldChunks($projectRoot);

        if ($chunkIndex < 0 || $chunkIndex >= $totalChunks || $totalChunks <= 0) {
            throw new InvalidArgumentException('Invalid chunk index or total chunks.');
        }

        if ($totalSize <= 0 || $totalSize > self::MAX_APK_BYTES) {
            throw new InvalidArgumentException('APK file exceeds the maximum allowed size of 250MB.');
        }

        $chunkDir = self::getChunkDirectory($projectRoot, $uploadId);
        $chunkFilename = sprintf('chunk_%05d', $chunkIndex);
        $chunkPath = $chunkDir . '/' . $chunkFilename;

        if ($base64Payload !== null) {
            // Emergency text-mode transport (base64): bypasses WAF rules that
            // reject binary multipart bodies. Strict-decode and verify length.
            if ($chunkSize <= 0) {
                throw new InvalidArgumentException('Chunk size must be positive.');
            }
            $raw = base64_decode($base64Payload, true);
            if ($raw === false) {
                throw new InvalidArgumentException('Chunk payload is not valid base64.');
            }
            if ($chunkSize > 0 && strlen($raw) !== $chunkSize) {
                throw new InvalidArgumentException('Decoded chunk size mismatch: expected ' . $chunkSize . ' got ' . strlen($raw) . '.');
            }
            $tmp = $chunkPath . '.part';
            if (file_put_contents($tmp, $raw) === false || !rename($tmp, $chunkPath)) {
                throw new RuntimeException("Failed to save chunk {$chunkIndex}.");
            }
        } else {
            if (empty($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
                $limitHint = ' (server limits: upload_max_filesize=' . ini_get('upload_max_filesize')
                    . ', post_max_size=' . ini_get('post_max_size') . ')';
                throw new InvalidArgumentException('No valid chunk file uploaded.' . $limitHint);
            }

            if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Chunk upload error: ' . self::uploadErrorText((int)$fileInfo['error']));
            }

            if (!move_uploaded_file($fileInfo['tmp_name'], $chunkPath)) {
                throw new RuntimeException("Failed to save chunk {$chunkIndex}.");
            }
        }

        @chmod($chunkPath, 0644);

        return [
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
            'received' => true,
            'size' => filesize($chunkPath),
        ];
    }

    /**
     * Human-readable PHP upload error codes (UPLOAD_ERR_*).
     */
    public static function uploadErrorText(int $code): string
    {
        $map = [
            UPLOAD_ERR_INI_SIZE   => 'file exceeds upload_max_filesize (' . ini_get('upload_max_filesize') . ')',
            UPLOAD_ERR_FORM_SIZE  => 'file exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL    => 'file was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'no file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'failed to write file to disk (quota/permissions?)',
            UPLOAD_ERR_EXTENSION  => 'a PHP extension stopped the upload',
        ];
        return $map[$code] ?? ('unknown upload error code ' . $code);
    }

    /**
     * Whether a chunk was already received (resumable.js GET test + resume).
     */
    public static function chunkExists(string $projectRoot, string $uploadId, int $chunkIndex): bool
    {
        $cleanId = preg_replace('/[^a-zA-Z0-9_-]/', '', $uploadId);
        if (strlen($cleanId) < 8 || strlen($cleanId) > 64) {
            return false;
        }
        $path = self::getChunkDirectory($projectRoot, $cleanId) . '/' . sprintf('chunk_%05d', $chunkIndex);
        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Server-side truth about which chunks exist — lets a fallback sender
     * resume exactly where the primary sender stopped.
     */
    public static function uploadStatus(string $projectRoot, string $uploadId, int $totalChunks): array
    {
        $cleanId = preg_replace('/[^a-zA-Z0-9_-]/', '', $uploadId);
        $received = [];
        if (strlen($cleanId) >= 8 && strlen($cleanId) <= 64) {
            $dir = self::getChunkDirectory($projectRoot, $cleanId);
            for ($i = 0; $i < $totalChunks; $i++) {
                $p = $dir . '/' . sprintf('chunk_%05d', $i);
                if (is_file($p) && filesize($p) > 0) {
                    $received[] = $i;
                }
            }
        }
        $missing = [];
        for ($i = 0; $i < $totalChunks; $i++) {
            if (!in_array($i, $received, true)) {
                $missing[] = $i;
            }
        }
        return [
            'upload_id' => $cleanId,
            'total_chunks' => $totalChunks,
            'received' => $received,
            'missing' => $missing,
            'received_count' => count($received),
        ];
    }

    /**
     * Environment report for diagnosing upload failures on shared hosting.
     */
    public static function getPreflightInfo(string $projectRoot): array
    {
        $releasesDir = self::getReleasesDirectory($projectRoot);
        $chunkBase = $releasesDir . '/.tmp_chunks';
        if (!is_dir($chunkBase)) {
            @mkdir($chunkBase, 0755, true);
        }
        $bytes = [
            'upload_max_filesize' => self::iniBytes(ini_get('upload_max_filesize')),
            'post_max_size'       => self::iniBytes(ini_get('post_max_size')),
        ];
        return [
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'cli',
            'limits' => [
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'max_input_time' => ini_get('max_input_time'),
            ],
            'limits_bytes' => $bytes,
            'recommended_chunk_size' => max(262144, min(1048576, (int)floor(min($bytes['upload_max_filesize'], $bytes['post_max_size']) / 4))),
            'releases_dir' => $releasesDir,
            'releases_dir_writable' => is_writable($releasesDir),
            'chunk_dir_writable' => is_dir($chunkBase) && is_writable($chunkBase),
            'disk_free_space' => @disk_free_space($releasesDir) ?: null,
            'mod_security_env_hint' => isset($_SERVER['MOD_SECURITY']) || false !== getenv('MODSEC'),
        ];
    }

    private static function iniBytes(string $val): int
    {
        $val = trim($val);
        if ($val === '') {
            return 0;
        }
        $unit = strtolower($val[strlen($val) - 1]);
        $num = (int)$val;
        switch ($unit) {
            case 'g': $num *= 1024 ** 3; break;
            case 'm': $num *= 1024 ** 2; break;
            case 'k': $num *= 1024; break;
        }
        return $num;
    }

    /**
     * Assemble all received chunks into the final APK artifact.
     */
    public static function assembleChunks(
        string $projectRoot,
        string $uploadId,
        int $totalChunks,
        int $totalSize,
        string $originalFilename,
        string $targetAbi = 'universal'
    ): array {
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($ext !== 'apk') {
            self::cancelUpload($projectRoot, $uploadId);
            throw new InvalidArgumentException('The uploaded file must have a .apk extension.');
        }

        if ($totalSize <= 0 || $totalSize > self::MAX_APK_BYTES) {
            self::cancelUpload($projectRoot, $uploadId);
            throw new InvalidArgumentException('APK file exceeds the maximum allowed size of 250MB.');
        }

        $chunkDir = self::getChunkDirectory($projectRoot, $uploadId);
        $releasesDir = self::getReleasesDirectory($projectRoot);

        $targetFilename = match ($targetAbi) {
            'arm64-v8a' => 'fkss-arm64-v8a.apk',
            'armeabi-v7a' => 'fkss-armeabi-v7a.apk',
            default => 'fkss.apk',
        };

        $finalDestination = $releasesDir . '/' . $targetFilename;
        $tempAssembly = $finalDestination . '.assembling.' . bin2hex(random_bytes(6));

        $outHandle = @fopen($tempAssembly, 'wb');
        if (!$outHandle) {
            self::cancelUpload($projectRoot, $uploadId);
            throw new RuntimeException('Failed to create assembly destination file on server disk.');
        }

        $assembledBytes = 0;

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkFile = sprintf('%s/chunk_%05d', $chunkDir, $i);
                if (!is_file($chunkFile)) {
                    throw new RuntimeException("Missing chunk {$i} of {$totalChunks}. Please retry upload.");
                }

                $inHandle = @fopen($chunkFile, 'rb');
                if (!$inHandle) {
                    throw new RuntimeException("Failed to open chunk {$i} for reading.");
                }

                while (!feof($inHandle)) {
                    $buf = fread($inHandle, 65536);
                    if ($buf === false) {
                        fclose($inHandle);
                        throw new RuntimeException("Error reading chunk {$i}.");
                    }
                    if (fwrite($outHandle, $buf) === false) {
                        fclose($inHandle);
                        throw new RuntimeException("Disk write error assembling APK.");
                    }
                    $assembledBytes += strlen($buf);
                }
                fclose($inHandle);
            }
            fclose($outHandle);
            $outHandle = null;

            if ($assembledBytes !== $totalSize) {
                throw new RuntimeException("Assembled size mismatch. Expected {$totalSize} bytes, got {$assembledBytes} bytes.");
            }

            // Version, build and app come from the file itself.
            self::inspectApk($projectRoot, $tempAssembly, $targetAbi);
            self::clearApkSidecars($finalDestination);

            // Move assembled file to final destination atomically
            if (!@rename($tempAssembly, $finalDestination)) {
                throw new RuntimeException('Failed to finalize APK destination file.');
            }
            @chmod($finalDestination, 0644);

            // Clean up chunk files immediately
            self::cancelUpload($projectRoot, $uploadId);

            // Recompute metadata and write sidecar
            require_once $projectRoot . '/api/v1/core/app_release.php';
            $meta = fkssApkMeta($finalDestination);

            // Update release config with new path
            $configKey = match ($targetAbi) {
                'arm64-v8a' => 'apk_arm64_path',
                'armeabi-v7a' => 'apk_arm32_path',
                default => 'apk_path',
            };

            return self::saveConfig($projectRoot, [$configKey => $finalDestination]);
        } catch (Throwable $e) {
            if ($outHandle) {
                @fclose($outHandle);
            }
            if (is_file($tempAssembly)) {
                @unlink($tempAssembly);
            }
            self::cancelUpload($projectRoot, $uploadId);
            throw $e;
        }
    }

    /** Drop cached hash/manifest of a file that is about to be replaced. */
    private static function clearApkSidecars(string $apkFile): void
    {
        @unlink($apkFile . '.meta');
        @unlink($apkFile . '.manifest.json');
    }

    /**
     * Check an uploaded APK before it is published and return what it is.
     * The universal file must be a known FKSS app, the same app as the file
     * already published, and not an older build. A per-device file must be
     * the same app and build as the published universal file.
     *
     * @return array{package:string,version_name:string,version_code:int}
     */
    public static function inspectApk(string $projectRoot, string $file, string $targetAbi): array
    {
        require_once $projectRoot . '/api/v1/core/app_release.php';
        $app = fkssApkManifest($file);
        if ($app === null) {
            throw new InvalidArgumentException('This file is not a readable Android app: its version could not be read. Upload the APK from the release build.');
        }
        if (!isset(self::KNOWN_PACKAGES[$app['package']])) {
            throw new InvalidArgumentException('This APK is "' . $app['package'] . '", not an FKSS app. Nothing was published.');
        }
        $info = self::getReleaseInfo($projectRoot);
        $curFile = $info['_apk_file'] ?? null;
        $cur = ($curFile && is_file($curFile)) ? fkssApkManifest($curFile) : null;
        $label = static fn (array $a): string => (self::KNOWN_PACKAGES[$a['package']] ?? $a['package'])
            . ' ' . $a['version_name'] . ' (build ' . $a['version_code'] . ')';

        if ($targetAbi === 'arm64-v8a' || $targetAbi === 'armeabi-v7a') {
            if ($cur === null) {
                throw new InvalidArgumentException('Publish the universal APK of this version first; per-device files must match it.');
            }
            if ($cur['package'] !== $app['package'] || $cur['version_code'] !== $app['version_code']) {
                throw new InvalidArgumentException('This per-device file is ' . $label($app) . ', but the published universal APK is '
                    . $label($cur) . '. Upload the matching file.');
            }
            return $app;
        }
        if ($cur !== null) {
            if ($cur['package'] !== $app['package']) {
                throw new InvalidArgumentException('This server publishes ' . $label($cur) . ', and the uploaded file is ' . $label($app)
                    . '. To switch apps, delete the current APK first.');
            }
            if ($app['version_code'] < $cur['version_code']) {
                throw new InvalidArgumentException('The uploaded file is ' . $label($app) . ', older than the published ' . $label($cur)
                    . '. Upload the newer APK.');
            }
        }
        return $app;
    }

    /**
     * Cancel an upload session and remove all temporary chunks.
     */
    public static function cancelUpload(string $projectRoot, string $uploadId): void
    {
        try {
            $chunkDir = self::getChunkDirectory($projectRoot, $uploadId);
            if (is_dir($chunkDir)) {
                self::deleteRecursive($chunkDir);
            }
        } catch (Throwable) {}
    }

    /**
     * Upload an APK file directly (single-shot legacy fallback).
     */
    public static function handleApkUpload(string $projectRoot, array $fileInfo, string $targetAbi = 'universal'): array
    {
        if (empty($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
            throw new InvalidArgumentException('No valid APK file was uploaded.');
        }

        if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed with error code: ' . $fileInfo['error']);
        }

        $size = (int)$fileInfo['size'];
        if ($size <= 0 || $size > self::MAX_APK_BYTES) {
            throw new InvalidArgumentException('APK file exceeds the maximum allowed size of 250MB.');
        }

        $originalName = (string)($fileInfo['name'] ?? '');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== 'apk') {
            throw new InvalidArgumentException('The uploaded file must have a .apk extension.');
        }

        $releasesDir = self::getReleasesDirectory($projectRoot);
        
        $targetFilename = match ($targetAbi) {
            'arm64-v8a' => 'fkss-arm64-v8a.apk',
            'armeabi-v7a' => 'fkss-armeabi-v7a.apk',
            default => 'fkss.apk',
        };

        $destination = $releasesDir . '/' . $targetFilename;
        $staging = $destination . '.uploading.' . bin2hex(random_bytes(6));

        if (!move_uploaded_file($fileInfo['tmp_name'], $staging)) {
            throw new RuntimeException('Failed to save the APK to releases storage.');
        }
        try {
            self::inspectApk($projectRoot, $staging, $targetAbi);
            self::clearApkSidecars($destination);
            if (!@rename($staging, $destination)) {
                throw new RuntimeException('Failed to finalize APK destination file.');
            }
        } catch (Throwable $e) {
            @unlink($staging);
            throw $e;
        }

        @chmod($destination, 0644);

        // Recompute metadata and write sidecar
        require_once $projectRoot . '/api/v1/core/app_release.php';
        $meta = fkssApkMeta($destination);

        // Update release config with new path
        $configKey = match ($targetAbi) {
            'arm64-v8a' => 'apk_arm64_path',
            'armeabi-v7a' => 'apk_arm32_path',
            default => 'apk_path',
        };

        return self::saveConfig($projectRoot, [$configKey => $destination]);
    }

    /**
     * Remove an APK artifact and update release configuration.
     */
    public static function deleteApk(string $projectRoot, string $targetAbi = 'universal'): array
    {
        $info = self::getReleaseInfo($projectRoot);
        $targetFile = match ($targetAbi) {
            'arm64-v8a' => $info['_apk_files']['arm64-v8a'] ?? null,
            'armeabi-v7a' => $info['_apk_files']['armeabi-v7a'] ?? null,
            default => $info['_apk_file'] ?? null,
        };

        if ($targetFile && is_file($targetFile)) {
            @unlink($targetFile);
            @unlink($targetFile . '.meta');
            @unlink($targetFile . '.manifest.json');
        }

        $configKey = match ($targetAbi) {
            'arm64-v8a' => 'apk_arm64_path',
            'armeabi-v7a' => 'apk_arm32_path',
            default => 'apk_path',
        };

        return self::saveConfig($projectRoot, [$configKey => '']);
    }

    private static function deleteRecursive(string $dir): void
    {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::deleteRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
}
