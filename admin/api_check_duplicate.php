<?php
/**
 * Advisory duplicate-member lookup.
 *
 * Registration enforcement lives in MemberDuplicateService and is invoked by
 * hr_register_member.php inside its transaction. This route is only a bounded
 * UI adapter over the same domain service.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/MemberDuplicateService.php';

function duplicateJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (empty($_SESSION['admin_id'])) {
    duplicateJson(['status' => 'error', 'message' => 'Unauthorized'], 401);
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    duplicateJson(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf((string)$csrfToken)) {
        duplicateJson(['status' => 'error', 'message' => 'Security token expired. Please refresh.'], 403);
    }
}

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$identity = [];
foreach (['student_name', 'father_name', 'grandfather_name'] as $field) {
    $raw = $input[$field] ?? '';
    if (!is_scalar($raw)) {
        duplicateJson(['status' => 'error', 'message' => 'Invalid name value.'], 422);
    }
    $value = trim((string)$raw);
    if (preg_match('//u', $value) !== 1) {
        duplicateJson(['status' => 'error', 'message' => 'Names must be valid UTF-8.'], 422);
    }
    if ((function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value)) > 150) {
        duplicateJson(['status' => 'error', 'message' => 'A name is too long.'], 422);
    }
    $identity[$field] = $value;
}
$phoneRaw = $input['phone'] ?? $input['phone_number'] ?? '';
if (!is_scalar($phoneRaw)) {
    duplicateJson(['status' => 'error', 'message' => 'Invalid phone value.'], 422);
}
$identity['phone_number'] = trim((string)$phoneRaw);
if (strlen($identity['phone_number']) > 30) {
    duplicateJson(['status' => 'error', 'message' => 'Phone number is too long.'], 422);
}

if ($identity['student_name'] === '' || $identity['father_name'] === '') {
    duplicateJson([
        'status' => 'success',
        'found' => false,
        'count' => 0,
        'matches' => [],
        'message' => 'Insufficient data to check duplicates',
    ]);
}

try {
    $matches = \App\Services\MemberDuplicateService::findAdvisoryMatches($conn, $identity, 5);
} catch (Throwable $error) {
    error_log('Duplicate advisory lookup failed: ' . $error->getMessage());
    duplicateJson(['status' => 'error', 'message' => 'Duplicate check is temporarily unavailable.'], 503);
}

foreach ($matches as &$match) {
    if (!empty($match['student_photo_path'])) {
        $match['student_photo_path'] = '/admin/photo_file.php?member_id=' . (int)$match['id'];
    }
}
unset($match);

duplicateJson([
    'status' => 'success',
    'found' => $matches !== [],
    'count' => count($matches),
    'matches' => $matches,
    'message' => $matches === [] ? 'No duplicates found' : 'Potential duplicate member(s) found!',
]);
