<?php
namespace App\Services;

use RuntimeException;
use InvalidArgumentException;

/** Structured upload errors, separate from application/manifest validation. */
final class AppReleaseUploadException extends RuntimeException
{
    // Only explicit, user-safe messages belong in this domain exception.
    public function __construct(public readonly string $clientMessage, public readonly string $errorCode,
        public readonly int $httpStatus = 409, public readonly bool $retryable = false,
        public readonly array $details = [])
    {
        parent::__construct($clientMessage);
    }
}

/** Bounded, filesystem-backed upload sessions. No database or external service. */
final class AppReleaseUploadStore
{
    public const MAX_PARTS = 8192;
    public const MAX_PART_BYTES = 1048576;

    public static function locked(string $root, string $id, callable $operation): mixed
    {
        $dir = AppReleaseManager::getChunkDirectory($root, $id);
        $lock = @fopen($dir . '/.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            throw new AppReleaseUploadException('Upload session is busy; retry shortly.', 'upload_busy', 423, true);
        }
        try {
            @touch($dir);
            return $operation($dir);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function read(string $dir): ?array
    {
        $file = $dir . '/session.json';
        if (!is_file($file)) return null;
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $valid = is_array($data) && in_array($data['state'] ?? '', ['receiving', 'published', 'cancelled'], true);
        if ($valid && $data['state'] !== 'cancelled') {
            $valid = is_int($data['count'] ?? null) && $data['count'] >= 1 && $data['count'] <= self::MAX_PARTS
                && is_int($data['size'] ?? null) && $data['size'] >= 1 && $data['size'] <= AppReleaseManager::MAX_APK_BYTES;
            if ($valid && isset($data['fingerprint'])) {
                $valid = is_int($data['part_size'] ?? null) && $data['part_size'] >= 65536 && $data['part_size'] <= self::MAX_PART_BYTES
                    && (int)ceil($data['size'] / $data['part_size']) === $data['count']
                    && is_array($data['part_hashes'] ?? null) && array_is_list($data['part_hashes'])
                    && count($data['part_hashes']) === $data['count']
                    && in_array($data['abi'] ?? '', ['universal', 'arm64-v8a', 'armeabi-v7a'], true);
                if ($valid) foreach ($data['part_hashes'] as $hash) {
                    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) { $valid = false; break; }
                }
                if ($valid) $valid = is_string($data['fingerprint']) && hash_equals(hash('sha256',
                    $data['size'] . ':' . $data['part_size'] . ':' . $data['abi'] . ':' . implode('', $data['part_hashes'])), $data['fingerprint']);
            }
            if ($valid && $data['state'] === 'published') $valid = in_array($data['abi'] ?? '', ['universal', 'arm64-v8a', 'armeabi-v7a'], true)
                && is_string($data['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $data['sha256']);
        }
        if (!$valid) {
            throw new AppReleaseUploadException('Upload metadata is unreadable. Select the APK again to start a new session.', 'session_corrupt');
        }
        return $data;
    }

    public static function write(string $dir, array $data): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $tmp = $dir . '/session.' . bin2hex(random_bytes(6)) . '.part';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !@rename($tmp, $dir . '/session.json')) {
            @unlink($tmp);
            throw new AppReleaseUploadException('Could not save upload progress. Check hosting quota and storage permissions.', 'storage_error', 503, true);
        }
    }

    public static function shape(int $count, int $size): void
    {
        if ($count < 1 || $count > self::MAX_PARTS || $size < 1 || $size > AppReleaseManager::MAX_APK_BYTES) {
            throw new InvalidArgumentException('Invalid upload size or number of chunks.');
        }
    }

    public static function bind(string $dir, int $count, int $size, array $descriptor = []): array
    {
        self::shape($count, $size);
        $old = self::read($dir);
        if (($old['state'] ?? '') === 'cancelled') {
            throw new AppReleaseUploadException('This upload was cancelled. Select the APK again.', 'upload_cancelled');
        }
        if ($old && ($old['count'] !== $count || $old['size'] !== $size)) {
            throw new AppReleaseUploadException('The selected file does not match this upload session.', 'upload_mismatch');
        }
        if ($descriptor) {
            $partSize = (int)($descriptor['part_size'] ?? 0);
            $hashes = $descriptor['part_hashes'] ?? [];
            $fingerprint = $descriptor['fingerprint'] ?? '';
            $abi = $descriptor['abi'] ?? '';
            if ($partSize < 65536 || $partSize > self::MAX_PART_BYTES || (int)ceil($size / $partSize) !== $count
                || !is_array($hashes) || !array_is_list($hashes) || count($hashes) !== $count
                || !in_array($abi, ['universal', 'arm64-v8a', 'armeabi-v7a'], true)) {
                throw new InvalidArgumentException('Invalid upload descriptor.');
            }
            foreach ($hashes as $hash) {
                if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                    throw new InvalidArgumentException('Invalid chunk checksum.');
                }
            }
            // Same canonical encoding in the client; binds every chunk, size and ABI.
            $expected = hash('sha256', $size . ':' . $partSize . ':' . $abi . ':' . implode('', $hashes));
            if (!is_string($fingerprint) || !hash_equals($expected, $fingerprint)) {
                throw new InvalidArgumentException('Upload fingerprint does not match its chunk checksums.');
            }
            if ($old && isset($old['fingerprint']) && !hash_equals($old['fingerprint'], $fingerprint)) {
                throw new AppReleaseUploadException('This upload belongs to a different file or architecture.', 'upload_mismatch');
            }
            if ($old && !isset($old['fingerprint'])) {
                throw new AppReleaseUploadException('Legacy session cannot be resumed as a verified upload. Select the APK again.', 'upload_mismatch');
            }
        }
        if (!$old) {
            $old = ['count' => $count, 'size' => $size, 'state' => 'receiving'] + $descriptor;
            self::write($dir, $old);
        }
        return $old;
    }

    public static function validPart(string $dir, int $index, array $session): bool
    {
        $path = $dir . '/' . sprintf('chunk_%05d', $index);
        if (!is_file($path) || filesize($path) <= 0) return false;
        if (isset($session['part_size'])) {
            $expected = min($session['part_size'], $session['size'] - $index * $session['part_size']);
            if (filesize($path) !== $expected) return false;
            $hash = hash_file('sha256', $path);
            return is_string($hash) && hash_equals($session['part_hashes'][$index], $hash);
        }
        return true; // Legacy protocol remains readable, but is not labeled checksum-verified.
    }

    public static function removeParts(string $dir): void
    {
        foreach (glob($dir . '/chunk_*') ?: [] as $file) {
            if (is_file($file)) @unlink($file);
        }
    }
}
