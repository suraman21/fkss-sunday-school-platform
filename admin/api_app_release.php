<?php
/**
 * ============================================================
 * Super Admin — Mobile App Release Management API
 * ============================================================
 * Production-grade release management and chunked resumable APK
 * upload engine designed specifically for cPanel shared hosting.
 *
 * Security: Super Admin session required, CSRF validated,
 * strict APK MIME/extension checks, rate limiting on uploads.
 * ============================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/AppReleaseManager.php';
require_once __DIR__ . '/backend/danger_confirm.php';

use App\Services\AppReleaseManager;
use App\Services\AppReleaseUploadException;

/** Audit failure is visible but cannot unpublish an already committed artifact. */
function appReleaseAudit(mixed $connection, string $action, string $details): bool
{
    try {
        if (!$connection instanceof mysqli) return false;
        $uid = (int)($_SESSION['admin_id'] ?? 0);
        $user = (string)($_SESSION['admin_username'] ?? 'admin');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $stmt = $connection->prepare('INSERT INTO activity_logs (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        if (!$stmt) throw new RuntimeException('Audit statement could not be prepared.');
        try {
            $stmt->bind_param('issss', $uid, $user, $action, $details, $ip);
            if (!$stmt->execute()) throw new RuntimeException('Audit insert was not acknowledged.');
        } finally { $stmt->close(); }
        return true;
    } catch (Throwable $error) {
        error_log('AppRelease audit write failed after commit: ' . $error->getMessage());
        return false;
    }
}

// 1. Strict Authentication & Authorization
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied. Super Admin privileges required.']);
    exit;
}

// Telegram-style parallel part uploads: release the session lock immediately
// after authentication so concurrent chunk POSTs are not serialized behind a
// single stalled request (PHP's default session handler holds an exclusive
// lock per session for the whole request otherwise). $_SESSION stays readable
// in memory for the audit entries below; only the lock is dropped.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$action = is_scalar($_REQUEST['action'] ?? '') ? (string)($_REQUEST['action'] ?? '') : 'get_release';

// 2. Validate CSRF for all POST / write requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh the page and try again.']);
        exit;
    }
}

try {
    switch ($action) {
        case 'get_release': {
            $info = AppReleaseManager::getReleaseInfo(ROOT_PATH);
            echo json_encode([
                'status' => 'success',
                'data' => $info,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'save_config': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                exit;
            }

            $input = [
                'latest_version' => $_POST['latest_version'] ?? '',
                'latest_build' => $_POST['latest_build'] ?? '',
                'min_version' => $_POST['min_version'] ?? '',
                'min_build' => $_POST['min_build'] ?? '',
                'force_update' => !empty($_POST['force_update']),
                'release_notes' => $_POST['release_notes'] ?? '',
                'banner_text' => $_POST['banner_text'] ?? '',
                'banner_kind' => $_POST['banner_kind'] ?? 'info',
            ];
            // The dashboard always sends "1" or "0"; when the field is absent
            // (an older cached page) the current value is kept.
            if (isset($_POST['background_drains_enabled'])) {
                $input['background_drains_enabled'] = (string)$_POST['background_drains_enabled'] === '1';
            }

            // Danger confirmation (HIGH, conditional): turning Force update ON
            // or changing the minimum version/build decides which installed
            // apps are blocked until they update, so it needs the typed phrase.
            // Other edits (notes, banner, latest version) do not.
            $__cur = AppReleaseManager::getReleaseInfo(ROOT_PATH);
            $__newMinV = preg_replace('/[^0-9.]/', '', (string)$input['min_version']);
            if ($__newMinV === '') { $__newMinV = '1.0.0'; }
            $__newMinB = max(1, (int)($input['min_build'] !== '' ? $input['min_build'] : ($__cur['min_build'] ?? 1)));
            $__risky = ($input['force_update'] && empty($__cur['force_update']))
                || ($__newMinV !== (string)($__cur['min_version'] ?? ''))
                || ($__newMinB !== (int)($__cur['min_build'] ?? 0));
            if ($__risky) {
                dc_require_phrase('app_release.force_update');
            }

            $updated = AppReleaseManager::saveConfig(ROOT_PATH, $input);

            $auditLogged = appReleaseAudit($conn ?? null, 'App Release Config Updated',
                "Updated app release policy: v{$updated['latest_version']} (Build {$updated['latest_build']})");

            echo json_encode([
                'status' => 'success',
                'audit_logged' => $auditLogged,
                'message' => $auditLogged ? 'App release configuration saved successfully.'
                    : 'Settings saved. Audit logging failed; ask the administrator to check the server log.',
                'data' => $updated,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'upload_chunk': {
            // resumable.js speaks GET as a "does this chunk already exist?" test.
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $gid = trim((string)($_GET['resumableIdentifier'] ?? $_GET['upload_id'] ?? ''));
                $gidx = (int)($_GET['resumableChunkNumber'] ?? ($_GET['chunk_index'] ?? 0)) - (isset($_GET['resumableChunkNumber']) ? 1 : 0);
                if (AppReleaseManager::chunkExists(ROOT_PATH, $gid, $gidx)) {
                    http_response_code(200);
                    echo json_encode(['status' => 'success', 'message' => 'Chunk already received.']);
                } else {
                    http_response_code(404);
                    echo json_encode(['status' => 'error', 'message' => 'Chunk not found.']);
                }
                exit;
            }

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                exit;
            }

            // Accept both the legacy/custom parameter names and the
            // resumable.js protocol names (resumable* namespace).
            $uploadId = trim((string)($_POST['upload_id'] ?? $_POST['resumableIdentifier'] ?? ''));
            $chunkIndex = isset($_POST['resumableChunkNumber'])
                ? (int)$_POST['resumableChunkNumber'] - 1
                : (int)($_POST['chunk_index'] ?? 0);
            $totalChunks = (int)($_POST['total_chunks'] ?? $_POST['resumableTotalChunks'] ?? 1);
            $totalSize = (int)($_POST['total_size'] ?? $_POST['resumableTotalSize'] ?? 0);
            $base64Payload = isset($_POST['chunk_b64']) && is_string($_POST['chunk_b64']) ? $_POST['chunk_b64'] : null;

            $file = $_FILES['chunk_file'] ?? null;
            $chunkSize = $file !== null && isset($file['size'])
                ? (int)$file['size']
                : (int)($_POST['chunk_size'] ?? 0);

            if ($file === null && $base64Payload === null) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'No chunk payload received. Server limits: upload_max_filesize='
                        . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size') . '.',
                ]);
                exit;
            }

            try {
                $result = AppReleaseManager::handleChunkUpload(
                    ROOT_PATH,
                    $file,
                    $uploadId,
                    $chunkIndex,
                    $totalChunks,
                    $chunkSize,
                    $totalSize,
                    $base64Payload
                );
            } catch (Throwable $chunkErr) {
                error_log(sprintf(
                    'AppRelease chunk failure: upload=%s chunk=%d/%d status=%s err=%s',
                    $uploadId,
                    $chunkIndex,
                    $totalChunks,
                    http_response_code() ?: 'n/a',
                    $chunkErr->getMessage()
                ));
                throw $chunkErr;
            }

            echo json_encode([
                'status' => 'success',
                'message' => "Chunk {$chunkIndex} received successfully.",
                'data' => $result,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'upload_status': {
            $uploadId = trim((string)($_REQUEST['upload_id'] ?? ''));
            $totalChunks = (int)($_REQUEST['total_chunks'] ?? 0);
            $descriptor = [];
            if (isset($_POST['fingerprint'])) {
                $hashes = json_decode((string)($_POST['part_hashes'] ?? ''), true);
                if (!is_array($hashes)) throw new InvalidArgumentException('Invalid upload checksum descriptor.');
                $descriptor = ['fingerprint' => (string)$_POST['fingerprint'],
                    'part_size' => (int)($_POST['part_size'] ?? 0), 'part_hashes' => $hashes,
                    'abi' => (string)($_POST['abi'] ?? '')];
            }
            echo json_encode([
                'status' => 'success',
                'data' => AppReleaseManager::uploadStatus(ROOT_PATH, $uploadId, $totalChunks,
                    (int)($_REQUEST['total_size'] ?? 0), $descriptor),
            ]);
            exit;
        }

        case 'upload_preflight': {
            echo json_encode([
                'status' => 'success',
                'data' => AppReleaseManager::getPreflightInfo(ROOT_PATH),
            ], JSON_PRETTY_PRINT);
            exit;
        }

        case 'assemble_chunks': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                exit;
            }

            $uploadId = trim((string)($_POST['upload_id'] ?? ''));
            $totalChunks = (int)($_POST['total_chunks'] ?? 1);
            $totalSize = (int)($_POST['total_size'] ?? 0);
            $fileName = trim((string)($_POST['file_name'] ?? 'app-release.apk'));
            $abi = (string)($_POST['abi'] ?? 'universal');

            if (!in_array($abi, ['universal', 'arm64-v8a', 'armeabi-v7a'], true)) {
                $abi = 'universal';
            }

            $updated = AppReleaseManager::assembleChunks(
                ROOT_PATH,
                $uploadId,
                $totalChunks,
                $totalSize,
                $fileName,
                $abi
            );

            $auditLogged = appReleaseAudit($conn ?? null, 'APK Uploaded (Chunked)',
                "Published APK ($abi): {$fileName}; upload={$uploadId}; bytes={$totalSize}; chunks={$totalChunks}");

            echo json_encode([
                'status' => 'success',
                'audit_logged' => $auditLogged,
                'message' => $auditLogged ? 'APK validated and published successfully (SHA-256 recorded).'
                    : 'APK published. Audit logging failed; ask the administrator to check the server log.',
                'data' => $updated,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'cancel_upload': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                exit;
            }

            $uploadId = trim((string)($_POST['upload_id'] ?? ''));
            if ($uploadId !== '') {
                AppReleaseManager::cancelUpload(ROOT_PATH, $uploadId);
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Upload session canceled and temporary storage cleaned.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'upload_apk': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                exit;
            }

            if (empty($_FILES['apk_file'])) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'No APK file received.']);
                exit;
            }

            $abi = (string)($_POST['abi'] ?? 'universal');
            if (!in_array($abi, ['universal', 'arm64-v8a', 'armeabi-v7a'], true)) {
                $abi = 'universal';
            }

            $updated = AppReleaseManager::handleApkUpload(ROOT_PATH, $_FILES['apk_file'], $abi);

            $auditLogged = appReleaseAudit($conn ?? null, 'APK Uploaded',
                "Uploaded APK ($abi): " . ($file['name'] ?? 'unknown'));

            echo json_encode([
                'status' => 'success',
                'audit_logged' => $auditLogged,
                'message' => $auditLogged ? 'APK uploaded and published successfully!'
                    : 'APK published. Audit logging failed; ask the administrator to check the server log.',
                'data' => $updated,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'delete_apk': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                exit;
            }
            dc_require_phrase('app_release.delete_apk');

            $abi = (string)($_POST['abi'] ?? 'universal');
            if (!in_array($abi, ['universal', 'arm64-v8a', 'armeabi-v7a'], true)) {
                $abi = 'universal';
            }

            $updated = AppReleaseManager::deleteApk(ROOT_PATH, $abi);

            echo json_encode([
                'status' => 'success',
                'message' => 'APK artifact removed successfully.',
                'data' => $updated,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
            exit;
    }
} catch (AppReleaseUploadException $e) {
    http_response_code($e->httpStatus);
    echo json_encode(['status' => 'error', 'code' => $e->errorCode, 'message' => $e->clientMessage,
        'retryable' => $e->retryable, 'details' => $e->details]);
    exit;
} catch (InvalidArgumentException $e) {
    $err = $e->getMessage();
    http_response_code(422);
    echo json_encode(['status' => 'error', 'code' => 'validation_error', 'retryable' => false, 'message' => $err]);
    exit;
} catch (Throwable $e) {
    $err = $e->getMessage();
    error_log('AppRelease API error: ' . $err);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'code' => 'storage_or_server_error', 'retryable' => true,
        'message' => 'Unable to process the app release request. Saved parts are retained; check server logs for details.']);
    exit;
}
