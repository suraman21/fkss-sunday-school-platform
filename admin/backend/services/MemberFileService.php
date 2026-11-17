<?php
/**
 * Validates and stores member photos/documents.
 *
 * Member photos and supporting documents are stored outside the web root and
 * resolved only by authenticated controllers. Legacy public photo paths remain
 * readable during the migration period.
 */

namespace App\Services;

final class MemberFileService
{
    public const MAX_BYTES = 5242880;
    public const PRIVATE_PREFIX = 'private://members/';

    private const IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
    ];

    private const FIELDS = [
        'student_photo' => ['category' => 'photos', 'private' => true, 'pdf' => false],
        'guardian_photo' => ['category' => 'guardian_photos', 'private' => true, 'pdf' => false],
        'doc_school_records' => ['category' => 'docs', 'private' => true, 'pdf' => true],
        'doc_spiritual' => ['category' => 'docs', 'private' => true, 'pdf' => true],
        'doc_signed_form' => ['category' => 'docs', 'private' => true, 'pdf' => true],
    ];

    /**
     * @return array{path:?string,error:?string}
     */
    public static function storeRequestUpload(string $field): array
    {
        if (!isset(self::FIELDS[$field])) {
            return ['path' => null, 'error' => 'Unsupported upload field.'];
        }
        if (!isset($_FILES[$field]) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['path' => null, 'error' => null];
        }

        $file = $_FILES[$field];
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['path' => null, 'error' => self::uploadError($error)];
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['path' => null, 'error' => 'The uploaded file could not be verified.'];
        }
        $size = @filesize($tmp);
        if ($size === false || $size <= 0 || $size > self::MAX_BYTES) {
            return ['path' => null, 'error' => 'File must be between 1 byte and 5 MB.'];
        }

        $spec = self::FIELDS[$field];
        $mime = self::detectMime($tmp);
        $extension = self::IMAGE_MIMES[$mime] ?? null;
        $reencodedTmp = null;
        if ($extension !== null) {
            if (@getimagesize($tmp) === false) {
                return ['path' => null, 'error' => 'The file is not a valid image.'];
            }
            // Re-encode the image to strip EXIF metadata and any appended
            // payload (polyglot protection). Falls back to the original upload
            // when GD is unavailable or re-encoding fails.
            $reencodedTmp = self::reencodeImage($tmp, $mime, $extension);
            if ($reencodedTmp !== null) {
                $tmp = $reencodedTmp;
            }
        } elseif (!empty($spec['pdf']) && $mime === 'application/pdf' && self::hasPdfHeader($tmp)) {
            $extension = 'pdf';
        } else {
            return ['path' => null, 'error' => 'Only verified JPG, PNG, GIF, WebP, BMP, or PDF files are allowed.'];
        }

        try {
            $random = bin2hex(random_bytes(16));
        } catch (\Throwable $error) {
            if ($reencodedTmp !== null) @unlink($reencodedTmp);
            return ['path' => null, 'error' => 'Secure upload naming is unavailable.'];
        }
        $name = $random . '.' . $extension;

        $saveFile = static function (string $source, string $target): bool {
            // Re-encoded files are our own temp files (not uploads), so use
            // rename/copy instead of move_uploaded_file.
            if (@rename($source, $target)) {
                return true;
            }
            if (@copy($source, $target)) {
                @unlink($source);
                return true;
            }
            return false;
        };

        if (!empty($spec['private'])) {
            $base = self::privateRoot();
            $dir = $base . '/' . $spec['category'];
            if (!self::ensureDirectory($dir, 0700)) {
                if ($reencodedTmp !== null) @unlink($reencodedTmp);
                return ['path' => null, 'error' => 'Private document storage is unavailable.'];
            }
            $target = $dir . '/' . $name;
            $ok = ($reencodedTmp !== null)
                ? $saveFile($tmp, $target)
                : move_uploaded_file($tmp, $target);
            if (!$ok) {
                if ($reencodedTmp !== null) @unlink($reencodedTmp);
                return ['path' => null, 'error' => 'The file could not be saved.'];
            }
            @chmod($target, 0600);
            if ($reencodedTmp !== null) @unlink($reencodedTmp);
            return ['path' => self::PRIVATE_PREFIX . $spec['category'] . '/' . $name, 'error' => null];
        }

        $dir = self::publicRoot() . '/' . $spec['category'];
        if (!self::ensureDirectory($dir, 0755)) {
            if ($reencodedTmp !== null) @unlink($reencodedTmp);
            return ['path' => null, 'error' => 'Photo storage is unavailable.'];
        }
        $target = $dir . '/' . $name;
        $ok = ($reencodedTmp !== null)
            ? $saveFile($tmp, $target)
            : move_uploaded_file($tmp, $target);
        if (!$ok) {
            if ($reencodedTmp !== null) @unlink($reencodedTmp);
            return ['path' => null, 'error' => 'The photo could not be saved.'];
        }
        @chmod($target, 0644);
        if ($reencodedTmp !== null) @unlink($reencodedTmp);
        return ['path' => 'uploads/members/' . $spec['category'] . '/' . $name, 'error' => null];
    }

    /** Resolve only private URI paths or protected legacy file directories. */
    public static function resolvePrivatePath(string $storedPath): ?string
    {
        if (preg_match('#^private://members/(photos|docs|guardian_photos)/([a-f0-9]{32}\.(?:jpg|png|gif|webp|bmp|pdf))$#D', $storedPath, $match)) {
            $candidate = self::privateRoot() . '/' . $match[1] . '/' . $match[2];
            return is_file($candidate) ? $candidate : null;
        }

        $legacy = str_replace('\\', '/', trim($storedPath));
        $legacy = ltrim($legacy, '/');
        if (strpos($legacy, 'admin/') === 0) {
            $legacy = substr($legacy, 6);
        }
        if (!preg_match('#^uploads/members/(docs|guardian_photos)/([^/]+)$#D', $legacy, $match)) {
            return null;
        }
        if ($match[2] !== basename($match[2])) {
            return null;
        }
        $candidate = self::publicRoot() . '/' . $match[1] . '/' . $match[2];
        $root = realpath(self::publicRoot() . '/' . $match[1]);
        $real = realpath($candidate);
        if ($root === false || $real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
            return null;
        }
        return $real;
    }

    /** Resolve a current private photo or a legacy public photo path. */
    public static function resolvePhotoPath(string $storedPath): ?string
    {
        $private = self::resolvePrivatePath($storedPath);
        if ($private !== null) {
            return $private;
        }

        $legacy = str_replace('\\', '/', trim($storedPath));
        $legacy = ltrim($legacy, '/');
        if (strpos($legacy, 'admin/') === 0) {
            $legacy = substr($legacy, 6);
        }
        if (!preg_match('#^uploads/members/photos/([a-f0-9]{32}\.(?:jpg|png|gif|webp|bmp))$#D', $legacy, $match)) {
            return null;
        }
        $root = realpath(self::publicRoot() . '/photos');
        $real = realpath(self::publicRoot() . '/photos/' . $match[1]);
        if ($root === false || $real === false
            || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0
            || !is_file($real)) {
            return null;
        }
        return $real;
    }

    public static function photoStorageDirectory(): ?string
    {
        $dir = self::privateRoot() . '/photos';
        return self::ensureDirectory($dir, 0700) ? $dir : null;
    }

    public static function photoImportDirectory(): ?string
    {
        $dir = self::privateRoot() . '/photo_imports';
        return self::ensureDirectory($dir, 0700) ? $dir : null;
    }

    public static function mimeForPath(string $path): ?string
    {
        $mime = self::detectMime($path);
        if (isset(self::IMAGE_MIMES[$mime])) {
            return @getimagesize($path) !== false ? $mime : null;
        }
        if ($mime === 'application/pdf' && self::hasPdfHeader($path)) {
            return $mime;
        }
        return null;
    }

    public static function discard(?string $storedPath): void
    {
        if (!$storedPath) {
            return;
        }
        $path = self::resolvePrivatePath($storedPath);
        if ($path === null && preg_match('#^uploads/members/photos/([a-f0-9]{32}\.(?:jpg|png|gif|webp|bmp))$#D', $storedPath, $match)) {
            $path = self::publicRoot() . '/photos/' . $match[1];
        }
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    private static function publicRoot(): string
    {
        return defined('ADMIN_PATH') ? ADMIN_PATH . '/uploads/members' : dirname(__DIR__, 2) . '/uploads/members';
    }

    private static function privateRoot(): string
    {
        if (defined('MEMBER_PRIVATE_STORAGE_PATH') && MEMBER_PRIVATE_STORAGE_PATH !== '') {
            return rtrim(MEMBER_PRIVATE_STORAGE_PATH, '/\\') . '/members';
        }
        $projectRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3);
        return dirname($projectRoot) . '/ssms_private/members';
    }

    private static function ensureDirectory(string $dir, int $mode): bool
    {
        return (is_dir($dir) || @mkdir($dir, $mode, true)) && is_dir($dir) && is_writable($dir);
    }

    private static function reencodeImage(string $source, string $mime, string $extension): ?string
    {
        if (!extension_loaded('gd')) {
            error_log('MemberFileService: GD not available; skipping image re-encode.');
            return null;
        }
        $img = null;
        switch ($mime) {
            case 'image/jpeg': $img = @imagecreatefromjpeg($source); break;
            case 'image/png':  $img = @imagecreatefrompng($source); break;
            case 'image/gif':  $img = @imagecreatefromgif($source); break;
            case 'image/webp': $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : null; break;
            case 'image/bmp':
            case 'image/x-ms-bmp': $img = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($source) : null; break;
        }
        if (!($img instanceof \GdImage)) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'reenc_');
        if ($tmp === false) {
            imagedestroy($img);
            return null;
        }
        $ok = false;
        switch ($extension) {
            case 'jpg':  $ok = @imagejpeg($img, $tmp, 90); break;
            case 'png':  $ok = @imagepng($img, $tmp, 6); break;
            case 'gif':  $ok = @imagegif($img, $tmp); break;
            case 'webp': $ok = function_exists('imagewebp') ? @imagewebp($img, $tmp, 90) : false; break;
            case 'bmp':  $ok = function_exists('imagebmp') ? @imagebmp($img, $tmp) : false; break;
        }
        imagedestroy($img);
        if (!$ok || !is_file($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return null;
        }
        return $tmp;
    }

    private static function detectMime(string $path): string
    {
        if (!class_exists('finfo')) {
            return '';
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return strtolower((string)$finfo->file($path));
    }

    private static function hasPdfHeader(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $header = fread($handle, 5);
        fclose($handle);
        return $header === '%PDF-';
    }

    private static function uploadError(int $error): string
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds the form upload limit.',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporary upload storage is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the upload.',
            UPLOAD_ERR_EXTENSION => 'The upload was blocked by the server.',
        ];
        return $messages[$error] ?? 'The upload could not be completed.';
    }
}
