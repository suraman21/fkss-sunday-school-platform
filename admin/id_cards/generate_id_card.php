<?php
/** Generate or renew one ID card through an authorized, CSRF-protected POST. */

// TEMPORARY DEBUG: add &debug=1 to the referring page URL
if (!empty($_POST['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/services/SecurityAuditService.php';

use App\Services\SecurityAuditService;

// SECURITY: ID-card generation mutates member rows and writes assets —
// an authenticated admin session is mandatory (CSRF alone is not enough,
// anonymous sessions also receive CSRF tokens).
if (!isLoggedIn()) {
    http_response_code(401);
    exit('Authentication required.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}
if (!validateCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token. Refresh the page and try again.');
}

$memberId = filter_var($_POST['member_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$action = (string)($_POST['action'] ?? 'generate');
if ($memberId === false || !in_array($action, ['generate', 'renew'], true)) {
    http_response_code(422);
    exit('Invalid ID-card request.');
}

require_once __DIR__ . '/libs/qr_loader.php';
if (!class_exists('QRcode')) {
    error_log('ID-card QR library is unavailable.');
    http_response_code(503);
    exit('ID-card generation is temporarily unavailable.');
}

$qrDirectory = __DIR__ . '/assets/qr';
if (!is_dir($qrDirectory) || !is_writable($qrDirectory)) {
    error_log('The deployment-managed ID-card QR directory is unavailable.');
    http_response_code(503);
    exit('ID-card generation is temporarily unavailable.');
}

$tempPath = null;
$finalPath = null;
$backupPath = null;
$installedNewFile = false;

try {
    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        'SELECT id, member_code, registration_type, member_type, membership_tier, status, id_card_status
         FROM members WHERE id = ? FOR UPDATE'
    );
    $statement->execute([(int)$memberId]);
    $member = $statement->fetch(PDO::FETCH_ASSOC);
    // H4 (updated 2026-11-18): promotions now flip waiting→direct, but
    // legacy permanent+waiting rows were never backfilled by decision —
    // the tier=permanent clause covers them. Keep in sync with
    // MemberDirectoryService eligible_for_id_card.
    $eligible = $member
        && $member['status'] === 'active'
        && (
            in_array($member['registration_type'], ['direct', 'transfer'], true)
            || $member['member_type'] === 'honorary'
            || ($member['membership_tier'] ?? '') === 'permanent'
        );
    if (!$eligible) {
        throw new DomainException('The member is not eligible for an ID card.');
    }

    $memberCode = trim((string)($member['member_code'] ?? ''));
    if ($memberCode === '') {
        $memberCode = MEMBER_CODE_FORMAT . str_pad((string)$memberId, 4, '0', STR_PAD_LEFT);
        $statement = $pdo->prepare('UPDATE members SET member_code = ? WHERE id = ?');
        $statement->execute([$memberCode, (int)$memberId]);
    }

    $baseUrl = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
    $qrContent = $baseUrl . '/member.php?code=' . rawurlencode($memberCode);
    $filename = 'qr_member_' . (int)$memberId . '.png';
    $finalPath = $qrDirectory . '/' . $filename;
    $webPath = '/admin/id_cards/assets/qr/' . $filename;
    $tempPath = tempnam($qrDirectory, '.qr-');
    if ($tempPath === false) {
        throw new RuntimeException('Could not allocate a QR output file.');
    }

    QRcode::png($qrContent, $tempPath, QR_ECLEVEL_L, 4, 2);
    if (!is_file($tempPath) || filesize($tempPath) < 32) {
        throw new RuntimeException('QR generation did not produce a valid image.');
    }
    @chmod($tempPath, 0644);

    if (!SecurityAuditService::record(
        $pdo,
        'Member ID Card ' . ($action === 'renew' ? 'Renewed' : 'Generated'),
        ['action' => $action],
        'member',
        (int)$memberId
    )) {
        throw new RuntimeException('ID-card audit recording failed.');
    }

    $refreshIssueDate = $action === 'renew' || ($member['id_card_status'] ?? 'none') !== 'generated';
    $statement = $pdo->prepare(
        $refreshIssueDate
            ? "UPDATE members
               SET qr_code_path = ?, id_card_status = 'generated', id_card_generated_at = CURRENT_TIMESTAMP
               WHERE id = ?"
            : 'UPDATE members SET qr_code_path = ? WHERE id = ?'
    );
    $statement->execute([$webPath, (int)$memberId]);

    // Replace the generated asset atomically and keep the old image available
    // for restoration if the database commit fails.
    if (is_file($finalPath)) {
        $backupPath = $finalPath . '.bak-' . bin2hex(random_bytes(6));
        if (!rename($finalPath, $backupPath)) {
            throw new RuntimeException('Could not preserve the prior QR image.');
        }
    }
    if (!rename($tempPath, $finalPath)) {
        throw new RuntimeException('Could not install the generated QR image.');
    }
    $tempPath = null;
    $installedNewFile = true;

    $pdo->commit();
    if ($backupPath !== null && is_file($backupPath)) {
        @unlink($backupPath);
    }

    header('Location: view_id_card.php?member_id=' . (int)$memberId, true, 303);
    exit;
} catch (DomainException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($tempPath !== null && is_file($tempPath)) {
        @unlink($tempPath);
    }
    http_response_code(422);
    exit($error->getMessage());
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($tempPath !== null && is_file($tempPath)) {
        @unlink($tempPath);
    }
    if ($installedNewFile && $finalPath !== null && is_file($finalPath)) {
        @unlink($finalPath);
    }
    if ($backupPath !== null && is_file($backupPath) && $finalPath !== null) {
        @rename($backupPath, $finalPath);
    }
    error_log('ID-card generation failed.');
    http_response_code(500);
    exit('ID-card generation failed. Please try again.');
}
