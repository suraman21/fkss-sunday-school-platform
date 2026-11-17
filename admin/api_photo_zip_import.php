<?php
/**
 * Z2-Z5 — ZIP airlock import.
 *
 * Accepts a ZIP bundle exported from the mobile app, verifies the
 * manifest structure, validates the HMAC signature (warn, don't block,
 * if mismatched — same as the spec says the admin is shown a warning),
 * streams bounded entries into a private temp dir, and feeds every photo through
 * PhotoUploadService::ingestFromFile() so they land through the same
 * receipt+candidate pipeline as online syncs.
 *
 * The ZIP archive is retained outside the web root for audit; staged files
 * are always cleaned up.
 *
 * Access: super_admin, school_admin, info_dept, hr_dept, photo_importer.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/backend/services/PhotoUploadService.php';
require_once __DIR__ . '/backend/services/PhotoDevicePairing.php';
require_once __DIR__ . '/backend/services/MemberFileService.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
// access_control.php auto-runs auth + role gating on include.

use App\Services\PhotoUploadService;

header('Content-Type: application/json; charset=utf-8');

$role = (string)($_SESSION['admin_role'] ?? '');
$allowed = ['super_admin','school_admin','info_dept','hr_dept','photo_dept','photo_importer'];
if (!in_array($role, $allowed, true)) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Access denied.']);
    exit;
}
$operatorId = (int)($_SESSION['admin_id'] ?? 0);
if ($operatorId <= 0) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'You must be logged in.']);
    exit;
}

$action = $_GET['action'] ?? 'upload';

if ($action === 'preview' || $action === 'commit') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['status'=>'error','message'=>'Method not allowed.']);
        exit;
    }
    requireCsrfForPost();
    handleUpload($operatorId, $action === 'commit');
} elseif ($action === 'recent') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['status'=>'error','message'=>'Method not allowed.']);
        exit;
    }
    handleRecent($operatorId);
} else {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Unknown action.']);
}

function handleUpload(int $operatorId, bool $commit): void {
    global $conn;
    if (!isset($_FILES['zip']) || !is_array($_FILES['zip'])) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'No ZIP file uploaded.']); return;
    }
    $f = $_FILES['zip'];
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Upload error: '.($f['error']??'unknown')]); return;
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    $size = @filesize($tmp);
    if ($tmp === '' || !is_uploaded_file($tmp) || $size === false || $size <= 0) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Upload could not be verified.']); return;
    }
    if ($size > 200 * 1024 * 1024) { // 200 MB cap (≈100 photos × 2 MB)
        http_response_code(413);
        echo json_encode(['status'=>'error','message'=>'ZIP exceeds 200 MB cap.']); return;
    }
    $bundleSha = hash_file('sha256', $tmp);
    if (!is_string($bundleSha)) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'ZIP could not be verified.']);
        return;
    }

    // ZipArchive availability check.
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'Server is missing the ZipArchive extension (ext-zip). Enable it to import.']); return;
    }

    $tmpDir = null;
    $zip = null;
    $archivePath = null;
    $batchId = 0;
    try {
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('Could not open ZIP (corrupt?).');
        }
        if ($zip->numFiles > 102) {
            throw new RuntimeException('ZIP contains too many entries.');
        }
        $manifestStat = $zip->statName('manifest.json');
        if (!is_array($manifestStat) || (int)($manifestStat['size'] ?? 0) <= 0
            || (int)$manifestStat['size'] > 2 * 1024 * 1024) {
            throw new RuntimeException('manifest.json missing — this is not a valid FKSS photo bundle.');
        }
        $sigStat = $zip->statName('manifest.sig');
        if (!is_array($sigStat) || (int)($sigStat['size'] ?? 0) > 128) {
            throw new RuntimeException('manifest.sig is missing or invalid.');
        }
        $manifestRaw = $zip->getFromName('manifest.json');
        if (!is_string($manifestRaw)) {
            throw new RuntimeException('Could not read manifest.json.');
        }
        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest) || ($manifest['manifest_version'] ?? '') !== 'fkss-photo-airlock-v1') {
            throw new RuntimeException('Unsupported manifest version.');
        }
        if (!is_string($manifest['bundle_id'] ?? null)
            || !preg_match('/^[a-f0-9\-]{32,64}$/D', $manifest['bundle_id'])) {
            throw new RuntimeException('Manifest bundle id is invalid.');
        }
        if (!is_string($manifest['device_id'] ?? null)
            || !preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $manifest['device_id'])) {
            throw new RuntimeException('Manifest device id is invalid.');
        }
        $photos = $manifest['photos'] ?? [];
        if (!is_array($photos) || !$photos || count($photos) > 100
            || (int)($manifest['photo_count'] ?? count($photos)) !== count($photos)) {
            throw new RuntimeException('Manifest photo count is invalid.');
        }
        if (array_keys($photos) !== range(0, count($photos) - 1)) {
            throw new RuntimeException('Manifest photo entries must be a sequential list.');
        }
        if ($zip->numFiles !== count($photos) + 2
            || $zip->locateName('manifest.sig') === false) {
            throw new RuntimeException('ZIP entries do not match the manifest.');
        }

        $tmpDir = sys_get_temp_dir() . '/fkss_zip_' . bin2hex(random_bytes(8));
        if (!@mkdir($tmpDir, 0700, true)) {
            throw new RuntimeException('Could not create private staging directory.');
        }
        $stagedPaths = [];
        $seenPaths = [];
        $totalBytes = 0;
        $expectedEntries = ['manifest.json' => true, 'manifest.sig' => true];
        foreach ($photos as $index => $photo) {
            if (!is_array($photo)) {
                throw new RuntimeException('Manifest photo entry is invalid.');
            }
            $arcPath = (string)($photo['arc_path'] ?? '');
            if (!preg_match('#^photos/[A-Za-z0-9_-]{1,64}__[1-9][0-9]{0,9}__[a-f0-9-]{32,64}\.(?:jpg|webp)$#D', $arcPath)
                || isset($seenPaths[$arcPath])) {
                throw new RuntimeException('Manifest contains an unsafe or duplicate photo path.');
            }
            $seenPaths[$arcPath] = true;
            $expectedEntries[$arcPath] = true;
            $stat = $zip->statName($arcPath);
            if (!is_array($stat) || (int)($stat['size'] ?? 0) <= 0
                || (int)$stat['size'] > PhotoUploadService::MAX_BYTES
                || (int)($photo['byte_size'] ?? 0) !== (int)$stat['size']) {
                throw new RuntimeException('Photo entry size does not match the manifest.');
            }
            $totalBytes += (int)$stat['size'];
            if ($totalBytes > 200 * 1024 * 1024) {
                throw new RuntimeException('ZIP exceeds the uncompressed size limit.');
            }

            $entry = $zip->getStream($arcPath);
            if (!is_resource($entry)) {
                throw new RuntimeException('Photo entry could not be opened.');
            }
            $stagedPath = $tmpDir . '/photo_' . ((int)$index + 1);
            $out = @fopen($stagedPath, 'xb');
            if ($out === false) {
                fclose($entry);
                throw new RuntimeException('Private staging file could not be created.');
            }
            $written = 0;
            while (!feof($entry)) {
                $chunk = fread($entry, 65536);
                if ($chunk === false) {
                    fclose($entry);
                    fclose($out);
                    throw new RuntimeException('Photo entry could not be read.');
                }
                $written += strlen($chunk);
                if ($written > PhotoUploadService::MAX_BYTES
                    || fwrite($out, $chunk) !== strlen($chunk)) {
                    fclose($entry);
                    fclose($out);
                    throw new RuntimeException('Photo entry exceeded its size limit.');
                }
            }
            fclose($entry);
            fclose($out);
            if ($written !== (int)$stat['size']) {
                throw new RuntimeException('Photo entry was truncated.');
            }
            $stagedPaths[(int)$index + 1] = $stagedPath;
        }
        for ($entryIndex = 0; $entryIndex < $zip->numFiles; $entryIndex++) {
            $entryStat = $zip->statIndex($entryIndex);
            $entryName = is_array($entryStat) ? (string)($entryStat['name'] ?? '') : '';
            if (!isset($expectedEntries[$entryName])) {
                throw new RuntimeException('ZIP contains an unlisted entry.');
            }
            unset($expectedEntries[$entryName]);
        }
        if ($expectedEntries !== []) {
            throw new RuntimeException('ZIP is missing a manifest entry.');
        }
        if (isset($manifest['total_bytes']) && (int)$manifest['total_bytes'] !== $totalBytes) {
            throw new RuntimeException('Manifest total byte count does not match the ZIP.');
        }

        // HMAC verification: if the bundle was signed with a server-paired
        // key (hmac_key_type = server_paired), look up the stored key for
        // (operator, device_id) and verify. A mismatch is a hard warning.
        // per_install bundles cannot be verified server-side (the key
        // never left the device) — we surface that honestly to the admin.
        $sigValid = false; $sigWarning = null;
        $hmacKeyType = (string)($manifest['hmac_key_type'] ?? 'per_install');
        $sigRaw = $zip->getFromName('manifest.sig');
        $sigHex = is_string($sigRaw) && strlen($sigRaw) <= 128 ? trim($sigRaw) : '';
        $deviceId = (string)($manifest['device_id'] ?? '');
        if ($sigHex === '') {
            $sigWarning = 'Bundle has no manifest.sig (unsigned export).';
        } elseif ($hmacKeyType === 'server_paired') {
            $keyHex = \App\Services\PhotoDevicePairing::lookupKey($conn, $operatorId, $deviceId);
            if ($keyHex === null) {
                $sigWarning = 'Signed with a server-paired key but this device is not paired for your account. Re-sync the device once over Wi-Fi to establish trust.';
            } else {
                $expected = hash_hmac('sha256', $manifestRaw, hex2bin($keyHex));
                if (hash_equals($expected, $sigHex)) {
                    $sigValid = true;
                } else {
                    $sigWarning = 'HMAC signature does not match the paired key for this device. The bundle may have been tampered with or signed by a different app install.';
                }
            }
        } else {
            // per_install (pre-pairing) — we cannot verify. Accept but
            // tell the admin so they know to verify the chain of custody
            // (e.g., confirm the USB stick came directly from the field).
            $sigWarning = 'Bundle signed with a per-install key (device has not yet synced to pair with server). Signature cannot be server-verified — confirm the ZIP came directly from a field device.';
        }

        $batchUuid = $manifest['bundle_id'];
        $photoOnly = in_array(
            (string)($_SESSION['admin_role'] ?? ''),
            ['photo_dept', 'photo_importer'],
            true
        );
        $duplicate = $conn->prepare(
            'SELECT id FROM photo_import_batches WHERE batch_uuid = ? LIMIT 1'
        );
        $duplicate->bind_param('s', $batchUuid);
        $duplicate->execute();
        $alreadyImported = $duplicate->get_result()->fetch_assoc();
        $duplicate->close();
        if ($alreadyImported) {
            http_response_code(409);
            echo json_encode(['status'=>'error','message'=>'This photo bundle was already imported.']);
            return;
        }

        $preview = buildPhotoZipPreview($conn, $photos, $stagedPaths, $photoOnly);
        if (!$preview['can_commit']) {
            unset($_SESSION['photo_zip_preview']);
        }
        if (!$commit) {
            if ($preview['can_commit']) {
                $_SESSION['photo_zip_preview'] = [
                    'sha256' => $bundleSha,
                    'operator_id' => $operatorId,
                    'bundle_uuid' => $batchUuid,
                    'expires_at' => time() + 600,
                ];
            }
            echo json_encode([
                'status' => 'preview',
                'can_commit' => $preview['can_commit'],
                'total' => count($photos),
                'ready' => $preview['ready'],
                'items' => $preview['items'],
                'sig_valid' => $sigValid,
                'sig_warning' => $sigWarning,
            ]);
            return;
        }

        $pendingPreview = $_SESSION['photo_zip_preview'] ?? null;
        unset($_SESSION['photo_zip_preview']);
        if (!is_array($pendingPreview)
            || (int)($pendingPreview['operator_id'] ?? 0) !== $operatorId
            || (int)($pendingPreview['expires_at'] ?? 0) < time()
            || !hash_equals((string)($pendingPreview['sha256'] ?? ''), $bundleSha)
            || !hash_equals((string)($pendingPreview['bundle_uuid'] ?? ''), $batchUuid)
            || !$preview['can_commit']) {
            http_response_code(409);
            echo json_encode([
                'status'=>'error',
                'message'=>'The ZIP no longer matches its valid preview. Preview it again before importing.',
            ]);
            return;
        }

        $archiveDir = \App\Services\MemberFileService::photoImportDirectory();
        if ($archiveDir === null) {
            throw new RuntimeException('Private ZIP archive storage is unavailable.');
        }
        $archivePath = $archiveDir . '/' . $batchUuid . '.zip';
        if (!move_uploaded_file($tmp, $archivePath)) {
            throw new RuntimeException('ZIP archive could not be saved to private storage.');
        }
        @chmod($archivePath, 0600);
        $storedZipPath = \App\Services\MemberFileService::PRIVATE_PREFIX
            . 'photo_imports/' . $batchUuid . '.zip';

        // Begin batch transaction (master row + per-photo ingest inside its own tx).
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                'INSERT INTO photo_import_batches
                   (batch_uuid, operator_admin_id, device_id, source,
                    manifest_sig_valid, total_photos, status,
                    manifest_json, zip_path, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $totalCount = count($photos);
            $source = 'zip';
            $sigInt = $sigValid ? 1 : 0;
            $status = 'importing';
            $manifestJson = json_encode($manifest);
            // Column types: uuid(s), operator(i), device(s), source(s),
            // sig(i), total(i), status(s), manifest(s) → s i s s i i s s.
            $stmt->bind_param('sissiisss',
                $batchUuid, $operatorId, $deviceId, $source,
                $sigInt, $totalCount, $status, $manifestJson, $storedZipPath);
            if (!$stmt->execute()) throw new RuntimeException('batch_insert_failed');
            $batchId = (int)$conn->insert_id;
            $stmt->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        // Per-photo ingestion (each in its own transaction inside ingestFromFile).
        $results = [];
        $committed = 0;
        $conflicts = 0;
        $replays = 0;
        $errors = [];
        $seq = 0;
        $itemStmt = $conn->prepare(
            "INSERT INTO photo_import_items
                (batch_id, seq, member_id, field, sha256, decision, created_at)
             VALUES (?, ?, NULLIF(?, 0), ?, ?, 'pending', NOW())"
        );
        $itemUpdate = $conn->prepare(
            'UPDATE photo_import_items
                SET decision = ?, resolved_member_id = NULLIF(?, 0), receipt_id = NULLIF(?, 0)
              WHERE id = ?'
        );
        foreach ($photos as $p) {
            $seq++;
            $memberCode = (string)($p['member_code'] ?? '');
            $memberId = (int)($p['member_id'] ?? 0);
            $field = (string)($p['field'] ?? 'student_photo');
            $fieldAllowed = in_array($field, ['student_photo','guardian_photo'], true)
                && (!$photoOnly || $field === 'student_photo');
            $sha = strtolower((string)($p['sha256'] ?? ''));
            $clientOpId = (string)($p['client_op_id'] ?? '');
            $takenAt = (string)($p['taken_at'] ?? '');

            if ($memberCode !== '') {
                $resolved = resolveMemberIdByCode($conn, $memberCode);
                $memberId = $resolved ?? 0;
            }

            $srcPath = $stagedPaths[$seq] ?? '';
            $itemStmt->bind_param('iiiss',
                $batchId, $seq, $memberId, $field, $sha);
            if (!$itemStmt->execute()) {
                throw new RuntimeException('import_item_insert_failed');
            }
            $itemId = (int)$conn->insert_id;

            if (!$fieldAllowed || $memberId <= 0 || !is_file($srcPath)) {
                $reason = !$fieldAllowed ? 'field_not_allowed'
                    : ($memberId <= 0 ? 'member_not_found' : 'file_missing');
                $itemDecision = 'failed';
                $zero = 0;
                $itemUpdate->bind_param('siii', $itemDecision, $zero, $zero, $itemId);
                $itemUpdate->execute();
                $results[] = ['seq'=>$seq,'code'=>$memberCode,'status'=>'skipped',
                    'reason'=>$reason];
                $errors[] = "Seq $seq ($memberCode): " . str_replace('_', ' ', $reason);
                continue;
            }

            try {
                $res = PhotoUploadService::ingestFromFile($conn, [
                    'member_id' => $memberId,
                    'operator_admin_id' => $operatorId,
                    'device_id' => $deviceId,
                    'client_op_id' => $clientOpId,
                    'import_item_id' => $itemId,
                    'field' => $field,
                    'sha256' => $sha,
                    'taken_at' => $takenAt,
                    'width' => (int)($p['width'] ?? 0),
                    'height' => (int)($p['height'] ?? 0),
                    'source_path' => $srcPath,
                ]);
            } catch (Throwable $e) {
                error_log('Photo ZIP item ingest failed: ' . $e->getMessage());
                $res = ['ok' => false, 'error' => 'Photo could not be saved.'];
            }
            if (!empty($res['ok'])) {
                if (!empty($res['replayed'])) $replays++; else $committed++;
                if (!empty($res['conflict'])) $conflicts++;
                $decision = !empty($res['conflict']) ? 'conflict' : 'accepted';
                $receiptId = (int)($res['receipt_id'] ?? 0);
                $itemUpdate->bind_param('siii',
                    $decision, $memberId, $receiptId, $itemId);
                $itemUpdate->execute();
                $results[] = ['seq'=>$seq,'code'=>$memberCode,'status'=>$decision,
                    'receipt_id'=>$receiptId, 'replayed'=>!empty($res['replayed'])];
            } else {
                $errors[] = "Seq $seq ($memberCode): ".($res['error']??'unknown');
                $itemDecision = 'failed';
                $zero = 0;
                $itemUpdate->bind_param('siii', $itemDecision, $zero, $zero, $itemId);
                $itemUpdate->execute();
                $results[] = ['seq'=>$seq,'code'=>$memberCode,'status'=>'failed',
                    'reason'=>$res['error']??'unknown'];
            }
        }
        $itemStmt->close();
        $itemUpdate->close();

        // Finalize batch.
        $batchStatus = count($errors) === 0 ? 'done' : 'partial';
        $finish = $conn->prepare(
            'UPDATE photo_import_batches
                SET status = ?, committed_photos = ?, committed_at = NOW()
              WHERE id = ?'
        );
        $finish->bind_param('sii', $batchStatus, $committed, $batchId);
        $finish->execute();
        $finish->close();

        echo json_encode([
            'status' => 'success',
            'batch_id' => $batchId,
            'batch_uuid' => $batchUuid,
            'total' => $totalCount,
            'committed' => $committed,
            'replays' => $replays,
            'conflicts' => $conflicts,
            'failures' => count($errors),
            'sig_valid' => $sigValid,
            'sig_warning' => $sigWarning,
            'errors' => array_slice($errors, 0, 20),
        ]);
    } catch (Throwable $e) {
        error_log('Photo ZIP import failed: ' . $e->getMessage());
        if ($batchId > 0) {
            $failedBatch = $conn->prepare(
                "UPDATE photo_import_batches SET status='failed', committed_at=NOW() WHERE id=?"
            );
            if ($failedBatch) {
                $failedBatch->bind_param('i', $batchId);
                $failedBatch->execute();
                $failedBatch->close();
            }
        } elseif (is_string($archivePath) && is_file($archivePath)) {
            @unlink($archivePath);
        }
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'Photo ZIP import could not be completed.']);
    } finally {
        if ($zip instanceof ZipArchive) {
            $zip->close();
        }
        if (is_string($tmpDir) && is_dir($tmpDir)) {
            rmdir_recursive($tmpDir);
        }
    }
}

function handleRecent(int $operatorId): void {
    global $conn;
    $limit = 20;
    $photoOnly = in_array(
        (string)($_SESSION['admin_role'] ?? ''),
        ['photo_dept', 'photo_importer'],
        true
    );
    if ($photoOnly) {
        $stmt = $conn->prepare(
            'SELECT id, batch_uuid, device_id, total_photos, committed_photos,
                    status, manifest_sig_valid, created_at, committed_at
               FROM photo_import_batches
              WHERE operator_admin_id = ?
              ORDER BY id DESC LIMIT ?'
        );
        if (!$stmt) {
            reportInternalError('Photo batch history query prepare failed', $conn->error);
            http_response_code(503);
            echo json_encode(['status' => 'error', 'message' => 'Batch history is temporarily unavailable.']);
            return;
        }
        $stmt->bind_param('ii', $operatorId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query("SELECT id, batch_uuid, device_id, total_photos, committed_photos,
            status, manifest_sig_valid, created_at, committed_at
            FROM photo_import_batches
            ORDER BY id DESC LIMIT $limit");
        $stmt = null;
    }
    if (!$res) {
        reportInternalError('Photo batch history query failed', $conn->error);
        if ($stmt) {
            $stmt->close();
        }
        http_response_code(503);
        echo json_encode(['status' => 'error', 'message' => 'Batch history is temporarily unavailable.']);
        return;
    }
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    if ($stmt) {
        $stmt->close();
    }
    echo json_encode(['status'=>'success','batches'=>$rows]);
}

function resolveMemberIdByCode(mysqli $conn, string $code): ?int {
    $stmt = $conn->prepare('SELECT id FROM members WHERE member_code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function buildPhotoZipPreview(
    mysqli $conn,
    array $photos,
    array $stagedPaths,
    bool $photoOnly
): array {
    $items = [];
    $ready = 0;
    foreach ($photos as $index => $photo) {
        $seq = (int)$index + 1;
        $code = is_string($photo['member_code'] ?? null)
            ? trim($photo['member_code'])
            : '';
        $field = (string)($photo['field'] ?? 'student_photo');
        $path = $stagedPaths[$seq] ?? '';
        $reason = null;
        $memberId = (int)($photo['member_id'] ?? 0);

        if (!in_array($field, ['student_photo', 'guardian_photo'], true)
            || ($photoOnly && $field !== 'student_photo')) {
            $reason = 'Photo field is not allowed for this account.';
        }
        if ($reason === null && $code !== '') {
            $memberId = resolveMemberIdByCode($conn, $code) ?? 0;
        }
        if ($reason === null && $memberId <= 0) {
            $reason = 'Member could not be matched.';
        }
        if ($reason === null) {
            $memberStmt = $conn->prepare('SELECT status FROM members WHERE id = ? LIMIT 1');
            $memberStmt->bind_param('i', $memberId);
            $memberStmt->execute();
            $member = $memberStmt->get_result()->fetch_assoc();
            $memberStmt->close();
            if (!$member) {
                $reason = 'Member could not be matched.';
            } elseif (($member['status'] ?? '') === 'archived') {
                $reason = 'Member is archived.';
            }
        }
        if ($reason === null && (!is_file($path) || !is_readable($path))) {
            $reason = 'Photo entry is missing.';
        }

        $sha = strtolower((string)($photo['sha256'] ?? ''));
        if ($reason === null
            && (!preg_match('/^[a-f0-9]{64}$/D', $sha)
                || !hash_equals($sha, (string)hash_file('sha256', $path)))) {
            $reason = 'Photo checksum does not match the manifest.';
        }
        if ($reason === null) {
            $mime = class_exists('finfo')
                ? (new finfo(FILEINFO_MIME_TYPE))->file($path)
                : false;
            $expectedMime = strtolower((string)($photo['mime_type'] ?? ''));
            if (!is_string($mime)
                || !in_array($mime, ['image/jpeg', 'image/webp'], true)
                || $mime !== $expectedMime
                || @getimagesize($path) === false) {
                $reason = 'Photo content is not a valid JPEG or WebP image.';
            }
        }

        if ($reason === null) {
            $ready++;
        }
        $items[] = [
            'seq' => $seq,
            'code' => $code,
            'status' => $reason === null ? 'ready' : 'blocked',
            'reason' => $reason,
        ];
    }

    return [
        'ready' => $ready,
        'can_commit' => $ready === count($photos),
        'items' => $items,
    ];
}

function rmdir_recursive(string $dir): void {
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $it) {
        if ($it->isDir()) @rmdir($it->getPathname()); else @unlink($it->getPathname());
    }
    @rmdir($dir);
}
