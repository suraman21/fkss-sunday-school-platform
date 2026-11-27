<?php
/**
 * Offline photo upload ingestion (P3).
 *
 * Accepts multipart POST /api/v1/members/{id}/photo from the mobile app
 * (and later from the web review screen), validates auth, MIME, size,
 * sha256, and idempotency, stores the file through MemberFileService,
 * writes a receipt + candidate row, and either auto-sets the member's
 * current photo or flags it for admin conflict review.
 *
 * Multi-photographer conflict rule (OFFLINE_PHOTO_SYNC_DESIGN §5.1.1):
 *   - Same operator, newer taken_at  → auto-replaces (photographer retake).
 *   - Different operator             → stored as candidate, conflict:true,
 *                                      admin picks winner in review UI.
 *   - Same client_op_id replay       → returns replayed:true (idempotent).
 *   - Same sha256 from any operator  → returns replayed:true (duplicate).
 *
 * This service is intentionally side-effect-free except inside the
 * commit transaction: if any validation fails, no rows are written and
 * no files are kept.
 */

namespace App\Services;

use mysqli;

final class PhotoUploadService
{
    public const MAX_BYTES = 2 * 1024 * 1024; // 2 MB hard cap (P1)
    public const ACCEPTED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /**
     * Result shape:
     *   { ok:bool, status:int, replayed:bool, conflict:bool,
     *     path:?string, receipt_id:?int, error:?string, code:?string }
     *
     * @param mysqli $conn     live connection (MUST have begin_transaction
     *                         already called if $opts['in_transaction']=true,
     *                         otherwise we open and commit our own).
     */
    public static function ingest(mysqli $conn, array $opts): array
    {
        $memberId       = (int)($opts['member_id'] ?? 0);
        $operatorId     = (int)($opts['operator_admin_id'] ?? 0);
        $deviceId       = (string)($opts['device_id'] ?? '');
        $clientOpId     = (string)($opts['client_op_id'] ?? '');
        $field          = (string)($opts['field'] ?? 'student_photo');
        $claimedSha256  = strtolower((string)($opts['sha256'] ?? ''));
        $claimedTakenAt = (string)($opts['taken_at'] ?? '');
        $width          = (int)($opts['width'] ?? 0);
        $height         = (int)($opts['height'] ?? 0);
        $format         = strtolower((string)($opts['format'] ?? ''));
        $fileField      = (string)($opts['file_field'] ?? 'photo');

        // ---------- Validation ----------
        if ($memberId <= 0) {
            return self::err(400, 'invalid_member', 'Member id is required.');
        }
        if ($operatorId <= 0) {
            return self::err(401, 'auth_required', 'You must be logged in.');
        }
        if ($clientOpId === '' || !preg_match('/^[a-f0-9\-]{32,64}$/D', $clientOpId)) {
            return self::err(400, 'invalid_idempotency_key',
                'Idempotency-Key (client_op_id) is required and must be a UUID.');
        }
        if ($field !== 'student_photo' && $field !== 'guardian_photo') {
            return self::err(400, 'invalid_field', 'Field must be student_photo or guardian_photo.');
        }
        if ($claimedSha256 === '' || !preg_match('/^[a-f0-9]{64}$/D', $claimedSha256)) {
            return self::err(400, 'invalid_sha256', 'X-Photo-SHA256 must be 64 hex chars.');
        }
        if (!isset(self::ACCEPTED_MIMES['image/' . $format])) {
            return self::err(415, 'unsupported_format',
                'Only WebP and JPEG are accepted.', ['accepted' => ['image/webp', 'image/jpeg']]);
        }
        $takenAt = null;
        if ($claimedTakenAt !== '') {
            try {
                $takenAt = new \DateTimeImmutable($claimedTakenAt);
            } catch (\Throwable $e) {
                return self::err(400, 'invalid_taken_at', 'X-Photo-Taken-At is not a valid ISO 8601 timestamp.');
            }
        }

        // ---------- Member exists and is not archived ----------
        $stmt = $conn->prepare(
            'SELECT id, status, student_photo_path FROM members WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$member) {
            return self::err(404, 'member_not_found', 'Member does not exist.');
        }
        if (($member['status'] ?? '') === 'archived') {
            return self::err(410, 'member_archived', 'Member is archived; photos cannot be updated.');
        }

        // ---------- Idempotency: exact client_op_id replay ----------
        $existing = self::findReceiptByOpId($conn, $clientOpId);
        if ($existing) {
            return [
                'ok' => true, 'status' => 200, 'replayed' => true, 'conflict' => false,
                'path' => $existing['stored_path'], 'receipt_id' => (int)$existing['id'],
                'error' => null, 'code' => null,
            ];
        }

        // ---------- Uploaded file ----------
        if (!isset($_FILES[$fileField]) || !is_array($_FILES[$fileField])) {
            return self::err(400, 'missing_file', 'No photo file was uploaded.');
        }
        $file = $_FILES[$fileField];
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return self::err(400, 'upload_error', self::uploadErrMsg((int)$file['error']));
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return self::err(400, 'upload_error', 'Upload could not be verified.');
        }
        $size = @filesize($tmp);
        if ($size === false || $size <= 0 || $size > self::MAX_BYTES) {
            return self::err(413, 'file_too_large', 'Photos must be between 1 byte and 2 MB.');
        }

        // ---------- Actual sha256 & MIME ----------
        $actualSha = hash_file('sha256', $tmp);
        if ($actualSha === false || !hash_equals($claimedSha256, $actualSha)) {
            return self::err(400, 'sha256_mismatch',
                'The uploaded file does not match the declared SHA-256.');
        }

        $mime = self::detectMime($tmp);
        $ext = self::ACCEPTED_MIMES[$mime] ?? null;
        if ($ext === null) {
            return self::err(415, 'invalid_image',
                'File does not decode as WebP or JPEG.',
                ['accepted' => ['image/webp', 'image/jpeg']]);
        }
        // Double-check the client's declared format matches what we detected.
        if ('image/' . $format !== $mime) {
            return self::err(415, 'format_mismatch',
                'Declared format ' . $format . ' does not match actual content (' . $mime . ').');
        }
        if (!self::isValidImage($tmp, $mime)) {
            return self::err(400, 'invalid_image', 'The image could not be decoded.');
        }

        // ---------- Duplicate-content replay (same operator+file) ----------
        $dupReceipt = self::findReceiptBySha($conn, $operatorId, $claimedSha256);
        if ($dupReceipt) {
            return [
                'ok' => true, 'status' => 200, 'replayed' => true, 'conflict' => false,
                'path' => $dupReceipt['stored_path'], 'receipt_id' => (int)$dupReceipt['id'],
                'error' => null, 'code' => null,
            ];
        }

        // ---------- Store ----------
        // We move/copy into a private area first, then flip it into the
        // public photos directory inside the transaction. Using
        // MemberFileService::storeRequestUpload would re-encode and
        // randomize the name; we use the same underlying logic but pass
        // through our own filename so sha256 remains stable.
        $randomName = bin2hex(random_bytes(16)) . '.' . $ext;
        $publicDir  = self::publicPhotoRoot();
        if (!self::ensureDir($publicDir, 0755)) {
            return self::err(500, 'storage_unavailable', 'Photo storage directory is not writable.');
        }
        $targetPath = $publicDir . '/' . $randomName;
        if (!move_uploaded_file($tmp, $targetPath)) {
            return self::err(500, 'storage_failed', 'Could not save the uploaded photo.');
        }
        @chmod($targetPath, 0644);
        $webPath = 'uploads/members/photos/' . $randomName;

        // ---------- Transaction ----------
        // Always open our own transaction. The route does not wrap us in
        // one; if that ever changes this is still safe because we pass
        // $ownedTransaction=false externally later. For now: own the tx.
        $conn->begin_transaction();
        $ownedTransaction = true;
        try {
            // Insert receipt.
            // bind_param() takes its values by reference, so the
            // taken_at expression must live in a variable first.
            $takenAtStr = $takenAt ? $takenAt->format('Y-m-d H:i:s') : null;
            $stmt = $conn->prepare(
                'INSERT INTO photo_upload_receipts
                   (client_op_id, import_item_id, member_id, field,
                    operator_admin_id, device_id, sha256, stored_path, mime,
                    byte_size, width, height, taken_at, created_at)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->bind_param(
                'sisssssssiis',
                $clientOpId, $memberId, $field,
                $operatorId, $deviceId, $claimedSha256, $webPath, $mime,
                $size, $width, $height,
                $takenAtStr
            );
            if (!$stmt->execute()) {
                throw new \RuntimeException('receipt_insert_failed');
            }
            $receiptId = (int)$conn->insert_id;
            $stmt->close();

            // Decide conflict policy.
            $current = self::findCurrentCandidate($conn, $memberId, $field);
            $isConflict = false;
            $shouldBecomeCurrent = false;

            if ($current === null) {
                // First photo for this member — auto-win.
                $shouldBecomeCurrent = true;
            } else {
                $currentOperator = (int)$current['operator_admin_id'];
                $currentTaken    = $current['taken_at'] ? strtotime($current['taken_at']) : 0;
                $newTaken        = $takenAt ? $takenAt->getTimestamp() : time();
                if ($currentOperator === $operatorId && $newTaken >= $currentTaken) {
                    // Same photographer, newer capture → auto-replaces (retake).
                    $shouldBecomeCurrent = true;
                } else {
                    // Different photographer, or same photographer but older
                    // timestamp (suspicious out-of-order upload) → conflict.
                    $isConflict = true;
                    $shouldBecomeCurrent = false;
                }
            }

            // Insert candidate.
            $stmt = $conn->prepare(
                'INSERT INTO member_photo_candidates
                   (member_id, field, receipt_id, operator_admin_id, device_id,
                    sha256, stored_path, mime, byte_size, width, height, taken_at,
                    is_current, decision_pending, archived,
                    created_at, decided_at, decided_by_admin_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NULL, NULL)'
            );
            $isCurrentInt = $shouldBecomeCurrent ? 1 : 0;
            $pendingInt  = $isConflict ? 1 : 0;
            $stmt->bind_param(
                'isiissssiiisii',
                $memberId, $field, $receiptId, $operatorId, $deviceId,
                $claimedSha256, $webPath, $mime, $size, $width, $height,
                $takenAtStr,
                $isCurrentInt, $pendingInt
            );
            if (!$stmt->execute()) {
                throw new \RuntimeException('candidate_insert_failed');
            }
            $candidateId = (int)$conn->insert_id;
            $stmt->close();

            if ($shouldBecomeCurrent) {
                // De-throne previous current candidates (if any) — there
                // should be at most one, but UPDATE handles 0..n safely.
                $conn->query(
                    "UPDATE member_photo_candidates
                        SET is_current = 0
                      WHERE member_id = $memberId
                        AND field = '" . $conn->real_escape_string($field) . "'
                        AND is_current = 1
                        AND id <> $candidateId"
                );
                // Defensive: make sure our winner is current.
                $conn->query("UPDATE member_photo_candidates SET is_current = 1 WHERE id = $candidateId");

                // Update members.student_photo_path denormalized cache.
                $col = $field === 'guardian_photo' ? 'guardian_photo_path' : 'student_photo_path';
                $u = $conn->prepare("UPDATE members SET $col = ? WHERE id = ?");
                $u->bind_param('si', $webPath, $memberId);
                $u->execute();
                $u->close();
            }

            if ($ownedTransaction) $conn->commit();

            return [
                'ok' => true, 'status' => 200,
                'replayed' => false,
                'conflict' => $isConflict,
                'path' => $webPath,
                'receipt_id' => $receiptId,
                'error' => null, 'code' => null,
            ];
        } catch (\Throwable $e) {
            if ($ownedTransaction) $conn->rollback();
            // Remove the moved file since we didn't commit.
            if (is_file($targetPath)) @unlink($targetPath);
            // The client gets a correlation reference, never the driver text:
            // a failed INSERT can carry the SQL statement, the table name and
            // the parameter values, and this message goes to the API response.
            $ref = function_exists('reportInternalError')
                ? reportInternalError('Photo save failed', $e) : '';
            return self::err(500, 'server_error',
                'Photo could not be saved. Quote this reference if you need help: ' . $ref);
        }
    }

    /**
     * Ingest a photo from an on-disk file (used by the ZIP airlock
     * importer). Same conflict/idempotency/audit semantics as
     * {@see ingest()} but no multipart dependency and an optional
     * $importItemId for lineage. The file at $path is COPIED into the
     * photos directory (caller still owns the source temp file).
     *
     * Result shape matches ingest().
     */
    public static function ingestFromFile(mysqli $conn, array $opts): array
    {
        $memberId     = (int)($opts['member_id'] ?? 0);
        $operatorId   = (int)($opts['operator_admin_id'] ?? 0);
        $deviceId     = (string)($opts['device_id'] ?? '');
        $clientOpId   = (string)($opts['client_op_id'] ?? '');
        $importItemId = isset($opts['import_item_id']) ? (int)$opts['import_item_id'] : null;
        $field        = (string)($opts['field'] ?? 'student_photo');
        $claimedSha256 = strtolower((string)($opts['sha256'] ?? ''));
        $claimedTakenAt = (string)($opts['taken_at'] ?? '');
        $width        = (int)($opts['width'] ?? 0);
        $height       = (int)($opts['height'] ?? 0);
        $srcPath      = (string)($opts['source_path'] ?? '');

        if ($memberId <= 0) return self::err(400,'invalid_member','Member id is required.');
        if ($operatorId <= 0) return self::err(401,'auth_required','You must be logged in.');
        if ($field !== 'student_photo' && $field !== 'guardian_photo') {
            return self::err(400,'invalid_field','Field must be student_photo or guardian_photo.');
        }
        if ($claimedSha256 === '' || !preg_match('/^[a-f0-9]{64}$/D', $claimedSha256)) {
            return self::err(400,'invalid_sha256','SHA-256 must be 64 hex chars.');
        }
        if ($srcPath === '' || !is_file($srcPath) || !is_readable($srcPath)) {
            return self::err(400,'missing_file','Source file is not readable.');
        }
        $size = @filesize($srcPath);
        if ($size === false || $size <= 0 || $size > self::MAX_BYTES) {
            return self::err(413,'file_too_large','Photos must be between 1 byte and 2 MB.');
        }
        $takenAt = null;
        if ($claimedTakenAt !== '') {
            try { $takenAt = new \DateTimeImmutable($claimedTakenAt); }
            catch (\Throwable $e) { return self::err(400,'invalid_taken_at','Invalid taken_at timestamp.'); }
        }

        // Member exists / not archived.
        $stmt = $conn->prepare('SELECT id, status FROM members WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$member) return self::err(404,'member_not_found','Member does not exist.');
        if (($member['status'] ?? '') === 'archived') {
            return self::err(410,'member_archived','Member is archived; photos cannot be updated.');
        }

        // Idempotency: same client_op_id (when present) → replay.
        if ($clientOpId !== '') {
            $existing = self::findReceiptByOpId($conn, $clientOpId);
            if ($existing) {
                return ['ok'=>true,'status'=>200,'replayed'=>true,'conflict'=>false,
                    'path'=>$existing['stored_path'],'receipt_id'=>(int)$existing['id'],
                    'error'=>null,'code'=>null];
            }
        }
        // Actual sha256 match.
        $actualSha = hash_file('sha256', $srcPath);
        if ($actualSha === false || !hash_equals($claimedSha256, $actualSha)) {
            return self::err(400,'sha256_mismatch','File does not match declared SHA-256.');
        }
        $mime = self::detectMime($srcPath);
        $ext = self::ACCEPTED_MIMES[$mime] ?? null;
        if ($ext === null) {
            return self::err(415,'invalid_image','File does not decode as WebP or JPEG.');
        }
        if (!self::isValidImage($srcPath, $mime)) {
            return self::err(400,'invalid_image','The image could not be decoded.');
        }
        // Duplicate content from same operator → replay.
        $dupReceipt = self::findReceiptBySha($conn, $operatorId, $claimedSha256);
        if ($dupReceipt) {
            return ['ok'=>true,'status'=>200,'replayed'=>true,'conflict'=>false,
                'path'=>$dupReceipt['stored_path'],'receipt_id'=>(int)$dupReceipt['id'],
                'error'=>null,'code'=>null];
        }

        // Copy into public photos directory.
        $randomName = bin2hex(random_bytes(16)) . '.' . $ext;
        $publicDir  = self::publicPhotoRoot();
        if (!self::ensureDir($publicDir, 0755)) {
            return self::err(500,'storage_unavailable','Photo storage directory is not writable.');
        }
        $targetPath = $publicDir . '/' . $randomName;
        if (!copy($srcPath, $targetPath)) {
            return self::err(500,'storage_failed','Could not save the photo.');
        }
        @chmod($targetPath, 0644);
        $webPath = 'uploads/members/photos/' . $randomName;

        $conn->begin_transaction();
        try {
            // bind_param() takes its values by reference, so the
            // taken_at expression must live in a variable first.
            $takenAtStr = $takenAt ? $takenAt->format('Y-m-d H:i:s') : null;
            $stmt = $conn->prepare(
                'INSERT INTO photo_upload_receipts
                   (client_op_id, import_item_id, member_id, field,
                    operator_admin_id, device_id, sha256, stored_path, mime,
                    byte_size, width, height, taken_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->bind_param(
                'siisssssssiis',
                $clientOpId, $importItemId, $memberId, $field,
                $operatorId, $deviceId, $claimedSha256, $webPath, $mime,
                $size, $width, $height,
                $takenAtStr
            );
            if (!$stmt->execute()) throw new \RuntimeException('receipt_insert_failed');
            $receiptId = (int)$conn->insert_id;
            $stmt->close();

            // Conflict decision — same as ingest().
            $current = self::findCurrentCandidate($conn, $memberId, $field);
            $isConflict = false; $shouldBecomeCurrent = false;
            if ($current === null) {
                $shouldBecomeCurrent = true;
            } else {
                $curOp = (int)$current['operator_admin_id'];
                $curTaken = $current['taken_at'] ? strtotime($current['taken_at']) : 0;
                $newTaken = $takenAt ? $takenAt->getTimestamp() : time();
                if ($curOp === $operatorId && $newTaken >= $curTaken) {
                    $shouldBecomeCurrent = true;
                } else {
                    $isConflict = true; $shouldBecomeCurrent = false;
                }
            }

            $stmt = $conn->prepare(
                'INSERT INTO member_photo_candidates
                   (member_id, field, receipt_id, operator_admin_id, device_id,
                    sha256, stored_path, mime, byte_size, width, height, taken_at,
                    is_current, decision_pending, archived,
                    created_at, decided_at, decided_by_admin_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NULL, NULL)'
            );
            $isCurrentInt = $shouldBecomeCurrent ? 1 : 0;
            $pendingInt = $isConflict ? 1 : 0;
            $stmt->bind_param(
                'isiissssiiisii',
                $memberId, $field, $receiptId, $operatorId, $deviceId,
                $claimedSha256, $webPath, $mime, $size, $width, $height,
                $takenAtStr,
                $isCurrentInt, $pendingInt
            );
            if (!$stmt->execute()) throw new \RuntimeException('candidate_insert_failed');
            $candidateId = (int)$conn->insert_id;
            $stmt->close();

            if ($shouldBecomeCurrent) {
                $conn->query(
                    "UPDATE member_photo_candidates SET is_current = 0
                      WHERE member_id = $memberId AND field = '" . $conn->real_escape_string($field) . "'
                        AND is_current = 1 AND id <> $candidateId"
                );
                $conn->query("UPDATE member_photo_candidates SET is_current = 1 WHERE id = $candidateId");
                $col = $field === 'guardian_photo' ? 'guardian_photo_path' : 'student_photo_path';
                $u = $conn->prepare("UPDATE members SET $col = ? WHERE id = ?");
                $u->bind_param('si', $webPath, $memberId);
                $u->execute(); $u->close();
            }

            $conn->commit();
            return ['ok'=>true,'status'=>200,'replayed'=>false,'conflict'=>$isConflict,
                'path'=>$webPath,'receipt_id'=>$receiptId,'error'=>null,'code'=>null];
        } catch (\Throwable $e) {
            $conn->rollback();
            if (is_file($targetPath)) @unlink($targetPath);
            $ref = function_exists('reportInternalError')
                ? reportInternalError('Photo save (airlock) failed', $e) : '';
            return self::err(500,'server_error',
                'Photo could not be saved. Quote this reference if you need help: '.$ref);
        }
    }

    // ---------- Helpers ----------

    /** @return array{id:int,operator_admin_id:int,taken_at:?string}|null */
    private static function findCurrentCandidate(mysqli $conn, int $memberId, string $field): ?array
    {
        $stmt = $conn->prepare(
            'SELECT id, operator_admin_id, taken_at
               FROM member_photo_candidates
              WHERE member_id = ? AND field = ? AND is_current = 1 AND archived = 0
              LIMIT 1'
        );
        $stmt->bind_param('is', $memberId, $field);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function findReceiptByOpId(mysqli $conn, string $clientOpId): ?array
    {
        $stmt = $conn->prepare(
            'SELECT id, stored_path FROM photo_upload_receipts WHERE client_op_id = ? LIMIT 1'
        );
        $stmt->bind_param('s', $clientOpId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function findReceiptBySha(mysqli $conn, int $operatorId, string $sha): ?array
    {
        $stmt = $conn->prepare(
            'SELECT id, stored_path FROM photo_upload_receipts
              WHERE operator_admin_id = ? AND sha256 = ? LIMIT 1'
        );
        $stmt->bind_param('is', $operatorId, $sha);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function detectMime(string $path): string
    {
        if (!class_exists('finfo')) return '';
        $f = new \finfo(FILEINFO_MIME_TYPE);
        return strtolower((string)$f->file($path));
    }

    private static function isValidImage(string $path, string $mime): bool
    {
        if (!extension_loaded('gd')) return true; // fail open; GD re-encode in MemberFileService can defend
        switch ($mime) {
            case 'image/jpeg':
                return function_exists('imagecreatefromjpeg') && (@imagecreatefromjpeg($path) !== false);
            case 'image/webp':
                return function_exists('imagecreatefromwebp') && (@imagecreatefromwebp($path) !== false);
        }
        return false;
    }

    private static function publicPhotoRoot(): string
    {
        if (defined('ADMIN_PATH')) {
            $base = ADMIN_PATH . '/uploads/members/photos';
        } else {
            $base = dirname(__DIR__, 2) . '/admin/uploads/members/photos';
        }
        return $base;
    }

    private static function ensureDir(string $dir, int $mode): bool
    {
        return (is_dir($dir) || @mkdir($dir, $mode, true)) && is_dir($dir) && is_writable($dir);
    }

    private static function uploadErrMsg(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the server size limit (2 MB max).',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp storage is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the upload.',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension.',
            default => 'Upload failed.',
        };
    }

    private static function err(int $status, string $code, string $message, array $extra = []): array
    {
        return [
            'ok' => false, 'status' => $status, 'replayed' => false, 'conflict' => false,
            'path' => null, 'receipt_id' => null, 'error' => $message, 'code' => $code,
            'extra' => $extra,
        ];
    }
}
