<?php
/**
 * Authenticated photo stream for member records and candidate review.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/backend/services/MemberFileService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = (string)($_SESSION['admin_role'] ?? '');
$photoOnly = in_array($role, ['photo_dept', 'photo_importer'], true);
$candidateId = filter_input(INPUT_GET, 'candidate_id', FILTER_VALIDATE_INT) ?: 0;
$memberId = filter_input(INPUT_GET, 'member_id', FILTER_VALIDATE_INT) ?: 0;
if ($candidateId > 0 && !in_array(
    $role,
    ['super_admin', 'school_admin', 'info_dept', 'hr_dept', 'photo_dept', 'photo_importer'],
    true
)) {
    http_response_code(404);
    exit('Photo not found.');
}
if (($candidateId > 0) === ($memberId > 0)) {
    http_response_code(404);
    exit('Photo not found.');
}

if ($candidateId > 0) {
    $stmt = $conn->prepare(
        'SELECT field, stored_path FROM member_photo_candidates
         WHERE id = ? AND archived = 0 LIMIT 1'
    );
    if (!$stmt) {
        reportInternalError('Photo candidate lookup prepare failed', $conn->error);
        http_response_code(503);
        exit('Photo is temporarily unavailable.');
    }
    $stmt->bind_param('i', $candidateId);
} else {
    $stmt = $conn->prepare(
        "SELECT 'student_photo' AS field, student_photo_path AS stored_path
         FROM members WHERE id = ? LIMIT 1"
    );
    if (!$stmt) {
        reportInternalError('Member photo lookup prepare failed', $conn->error);
        http_response_code(503);
        exit('Photo is temporarily unavailable.');
    }
    $stmt->bind_param('i', $memberId);
}
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || empty($row['stored_path'])
    || ($photoOnly && $row['field'] !== 'student_photo')) {
    http_response_code(404);
    exit('Photo not found.');
}

$path = $row['field'] === 'student_photo'
    ? \App\Services\MemberFileService::resolvePhotoPath((string)$row['stored_path'])
    : \App\Services\MemberFileService::resolvePrivatePath((string)$row['stored_path']);
$mime = $path ? \App\Services\MemberFileService::mimeForPath($path) : null;
if ($path === null || $mime === null || strpos($mime, 'image/') !== 0) {
    http_response_code(404);
    exit('Photo not found.');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($path);
exit;
