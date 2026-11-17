<?php
/**
 * Z2-Z5 — ZIP airlock import.
 *
 * Accepts a ZIP bundle exported from the mobile app, verifies the
 * manifest structure, validates the HMAC signature (warn, don't block,
 * if mismatched — same as the spec says the admin is shown a warning),
 * extracts to a temp dir, and feeds every photo through
 * PhotoUploadService::ingestFromFile() so they land through the same
 * receipt+candidate pipeline as online syncs.
 *
 * After commit, the ZIP is moved into admin/uploads/members/photo_imports/
 * for audit; on failure the temp dir is cleaned up.
 *
 * Access: super_admin, school_admin, info_dept, hr_dept, photo_importer.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/backend/services/PhotoUploadService.php';
require_once __DIR__ . '/backend/services/PhotoDevicePairing.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
// access_control.php auto-runs auth + role gating on include.

use App\Services\PhotoUploadService;

header('Content-Type: application/json; charset=utf-8');

$role = (string)($_SESSION['admin_role'] ?? '');
$allowed = ['super_admin','school_admin','info_dept','hr_dept','photo_importer'];
if (!in_array($role, $allowed, true)) {
    jsonOut(['status'=>'error','message'=>'Access denied.'], 403);
    exit;
}
$operatorId = (int)($_SESSION['admin_id'] ?? 0);
if ($operatorId <= 0) {
    jsonOut(['status'=>'error','message'=>'You must be logged in.'], 401);
    exit;
}

$action = $_GET['action'] ?? 'upload';

if ($action === 'upload') {
    handleUpload($operatorId);
} elseif ($action === 'recent') {
    handleRecent($operatorId);
} else {
    jsonOut(['status'=>'error','message'=>'Unknown action.'], 400);
}

/**
 * Emit a JSON response. Guaranteed to never emit an empty body: some
 * hosts replace an empty 5xx body with a generic 404 page, which hides
 * the real error from the dashboard ("Unexpected response: <html>...").
 */
function jsonOut(array $payload, int $status = 200): void {
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{"status":"error","message":"Response encoding failed."}';
    }
    echo $json;
}

function handleUpload(int $operatorId): void {
    global $conn;
    // Bulk imports (hundreds of photos) legitimately run for minutes:
    // never let the script die mid-batch (a bare 5xx with no body shows
    // up in the dashboard as a confusing "Unexpected response" page).
    @set_time_limit(0);
    ignore_user_abort(true);
    if (!isset($_FILES['zip']) || !is_array($_FILES['zip'])) {
        jsonOut(['status'=>'error','message'=>'No ZIP file uploaded.'], 400); return;
    }
    $f = $_FILES['zip'];
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonOut(['status'=>'error','message'=>'Upload error: '.($f['error']??'unknown')], 400); return;
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    $size = @filesize($tmp);
    if ($tmp === '' || !is_uploaded_file($tmp) || $size === false || $size <= 0) {
        jsonOut(['status'=>'error','message'=>'Upload could not be verified.'], 400); return;
    }
    if ($size > 200 * 1024 * 1024) { // 200 MB cap (≈100 photos × 2 MB)
        jsonOut(['status'=>'error','message'=>'ZIP exceeds 200 MB cap.'], 413); return;
    }

    // ZipArchive availability check.
    if (!class_exists('ZipArchive')) {
        jsonOut(['status'=>'error','message'=>'Server is missing the ZipArchive extension (ext-zip). Enable it to import.'], 500); return;
    }

    // Extract to temp dir.
    $tmpDir = sys_get_temp_dir() . '/fkss_zip_' . bin2hex(random_bytes(8));
    if (!@mkdir($tmpDir, 0700, true)) {
        jsonOut(['status'=>'error','message'=>'Could not create temp extract directory.'], 500); return;
    }
    try {
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('Could not open ZIP (corrupt?).');
        }
        // Zip-slip defense: a hostile bundle must not write outside $tmpDir.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false || $entryName === ''
                || str_contains($entryName, '..')
                || $entryName[0] === '/' || str_contains($entryName, '\\')) {
                $zip->close();
                throw new RuntimeException('ZIP contains an unsafe entry name.');
            }
        }
        if (!$zip->extractTo($tmpDir)) {
            $zip->close();
            throw new RuntimeException('Could not extract ZIP.');
        }
        $zip->close();

        $manifestPath = $tmpDir . '/manifest.json';
        $sigPath = $tmpDir . '/manifest.sig';
        if (!is_file($manifestPath)) {
            throw new RuntimeException('manifest.json missing — this is not a valid FKSS photo bundle.');
        }
        $manifestRaw = file_get_contents($manifestPath);
        if ($manifestRaw === false) throw new RuntimeException('Could not read manifest.json.');
        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest) || ($manifest['manifest_version'] ?? '') !== 'fkss-photo-airlock-v1') {
            throw new RuntimeException('Unsupported manifest version.');
        }
        $photos = $manifest['photos'] ?? [];
        if (!is_array($photos) || !$photos) {
            throw new RuntimeException('Manifest has no photos listed.');
        }

        // HMAC verification: if the bundle was signed with a server-paired
        // key (hmac_key_type = server_paired), look up the stored key for
        // (operator, device_id) and verify. A mismatch is a hard warning.
        // per_install bundles cannot be verified server-side (the key
        // never left the device) — we surface that honestly to the admin.
        $sigValid = false; $sigWarning = null;
        $hmacKeyType = (string)($manifest['hmac_key_type'] ?? 'per_install');
        $sigHex = is_file($sigPath) ? trim((string)file_get_contents($sigPath)) : '';
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

        // Begin batch transaction (master row + per-photo ingest inside its own tx).
        $conn->begin_transaction();
        try {
            $batchUuid = (string)($manifest['bundle_id'] ?? '');
            if (!preg_match('/^[a-f0-9\-]{32,64}$/D', $batchUuid)) $batchUuid = bin2hex(random_bytes(16));
            $deviceId = (string)($manifest['device_id'] ?? '');
            $stmt = $conn->prepare(
                'INSERT INTO photo_import_batches
                   (batch_uuid, operator_admin_id, device_id, source,
                    manifest_sig_valid, total_photos, status,
                    manifest_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $totalCount = count($photos);
            $source = 'zip';
            $sigInt = $sigValid ? 1 : 0;
            $status = 'importing';
            $manifestJson = json_encode($manifest);
            if ($manifestJson === false) $manifestJson = '{}';
            // Column types: uuid(s), operator(i), device(s), source(s),
            // sig(i), total(i), status(s), manifest(s) → s i s s i i s s.
            $stmt->bind_param('sissiiss',
                $batchUuid, $operatorId, $deviceId, $source,
                $sigInt, $totalCount, $status, $manifestJson);
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
        foreach ($photos as $p) {
            $seq++;
            $arcPath = (string)($p['arc_path'] ?? '');
            $memberCode = (string)($p['member_code'] ?? '');
            $memberId = (int)($p['member_id'] ?? 0);
            $field = (string)($p['field'] ?? 'student_photo');
            if (!in_array($field, ['student_photo','guardian_photo'], true)) $field = 'student_photo';
            $sha = strtolower((string)($p['sha256'] ?? ''));
            $clientOpId = (string)($p['client_op_id'] ?? '');
            $takenAt = (string)($p['taken_at'] ?? '');
            $byteSize = (int)($p['byte_size'] ?? 0);

            // Create the import item row first so ingestFromFile can back-link.
            $conn->query('INSERT INTO photo_import_items
                (batch_id, seq, member_id, field, sha256, decision, created_at)
                VALUES ('.$batchId.', '.$seq.', '.($memberId>0?$memberId:'NULL').',
                "'.$conn->real_escape_string($field).'", "'.$conn->real_escape_string($sha).'",
                "pending", NOW())');
            $itemId = (int)$conn->insert_id;

            $srcPath = $tmpDir . '/' . $arcPath;
            // Belt-and-braces: refuse manifest paths escaping the extract dir.
            if ($arcPath === '' || str_contains($arcPath, '..')
                || ($arcPath[0] ?? '') === '/' || str_contains($arcPath, '\\')) {
                $results[] = ['seq'=>$seq,'code'=>$memberCode,'status'=>'skipped',
                    'reason'=>'bad_path'];
                $errors[] = "Seq $seq ($memberCode): unsafe path in manifest";
                $conn->query("UPDATE photo_import_items SET decision='failed' WHERE id=$itemId");
                continue;
            }
            // Older bundles omit width/height — measure from the file.
            $width = (int)($p['width'] ?? 0);
            $height = (int)($p['height'] ?? 0);
            if (($width <= 0 || $height <= 0) && is_file($srcPath)) {
                $dims = @getimagesize($srcPath);
                if (is_array($dims)) {
                    if ($width <= 0) $width = (int)$dims[0];
                    if ($height <= 0) $height = (int)$dims[1];
                }
            }
            // Resolve member_id by code if not trusted (defense: trust client member_id
            // but cross-check; resolve via member_code when possible).
            if ($memberCode !== '') {
                $resolved = resolveMemberIdByCode($conn, $memberCode);
                if ($resolved !== null) $memberId = $resolved;
            }

            if ($memberId <= 0 || !is_file($srcPath)) {
                $results[] = ['seq'=>$seq,'code'=>$memberCode,'status'=>'skipped',
                    'reason'=>$memberId<=0?'member_not_found':'file_missing'];
                $errors[] = "Seq $seq ($memberCode): ".($memberId<=0?'member not found':'file missing in ZIP');
                $conn->query("UPDATE photo_import_items SET decision='failed' WHERE id=$itemId");
                continue;
            }

            $res = PhotoUploadService::ingestFromFile($conn, [
                'member_id' => $memberId,
                'operator_admin_id' => $operatorId,
                'device_id' => $deviceId,
                'client_op_id' => $clientOpId,
                'import_item_id' => $itemId,
                'field' => $field,
                'sha256' => $sha,
                'taken_at' => $takenAt,
                'width' => $width,
                'height' => $height,
                'source_path' => $srcPath,
            ]);
            if (!empty($res['ok'])) {
                if (!empty($res['replayed'])) $replays++; else $committed++;
                if (!empty($res['conflict'])) $conflicts++;
                $decision = !empty($res['conflict']) ? 'conflict' : 'accepted';
                $receiptId = (int)($res['receipt_id'] ?? 0);
                $conn->query("UPDATE photo_import_items SET decision='$decision',
                    resolved_member_id=$memberId, receipt_id=$receiptId WHERE id=$itemId");
                $results[] = ['seq'=>$seq,'code'=>$memberCode,'status'=>$decision,
                    'receipt_id'=>$receiptId, 'replayed'=>!empty($res['replayed'])];
            } else {
                $errors[] = "Seq $seq ($memberCode): ".($res['error']??'unknown');
                $conn->query("UPDATE photo_import_items SET decision='failed' WHERE id=$itemId");
                $results[] = ['seq'=>$seq,'code'=>$memberCode,'status'=>'failed',
                    'reason'=>$res['error']??'unknown'];
            }
        }

        // Finalize batch.
        $conn->query("UPDATE photo_import_batches SET
            status='done', committed_photos=$committed, committed_at=NOW()
            WHERE id=$batchId");

        // Archive the ZIP.
        $archiveDir = __DIR__ . '/uploads/members/photo_imports';
        if (!is_dir($archiveDir)) @mkdir($archiveDir, 0755, true);
        $archivePath = $archiveDir . '/' . $batchUuid . '.zip';
        @move_uploaded_file($tmp, $archivePath); // source may already be gone; fall back to copy
        if (!is_file($archivePath)) @copy($tmp, $archivePath);

        jsonOut([
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
        jsonOut(['status'=>'error','message'=>$e->getMessage()], 500);
    } finally {
        // Clean up temp extraction.
        if (is_dir($tmpDir)) @rmdir_recursive($tmpDir);
    }
}

function handleRecent(int $operatorId): void {
    global $conn;
    try {
        $limit = 20;
        $res = $conn->query("SELECT id, batch_uuid, operator_admin_id, device_id, total_photos, committed_photos,
            status, manifest_sig_valid, manifest_json, created_at, committed_at
            FROM photo_import_batches
            ORDER BY id DESC LIMIT $limit");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) {
            $r['sig_status'] = \App\Services\PhotoDevicePairing::sigStatusForBatch($conn, $r);
            unset($r['manifest_json']); // working input only — keeps payload small
            $rows[] = $r;
        }
        jsonOut(['status'=>'success','batches'=>$rows]);
    } catch (Throwable $e) {
        jsonOut(['status'=>'error','message'=>$e->getMessage()], 500);
    }
}

function resolveMemberIdByCode(mysqli $conn, string $code): ?int {
    $stmt = $conn->prepare('SELECT id FROM members WHERE member_code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
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
