<?php
/** Thin, fail-closed Telegram webhook. Phase 1 creates review drafts only. */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

function telegram_reply(int $status, array $body = ['ok' => true]): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); telegram_reply(405, ['ok'=>false]);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../admin/backend/services/AmharicIdentityNormalizer.php';
require_once __DIR__ . '/../admin/backend/services/TelegramFormRegistry.php';
require_once __DIR__ . '/../admin/backend/services/TelegramDuplicateCandidateService.php';
require_once __DIR__ . '/../admin/backend/services/TelegramRegistrationService.php';

if (!feature_enabled('telegram_registration') || !defined('TELEGRAM_WEBHOOK_SECRET') || !defined('TELEGRAM_BOT_TOKEN')) {
    telegram_reply(404, ['ok'=>false]);
}
$provided = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!is_string($provided) || !hash_equals((string)TELEGRAM_WEBHOOK_SECRET, $provided)) {
    telegram_reply(403, ['ok'=>false]);
}
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') telegram_reply(415, ['ok'=>false]);
$limit = defined('TELEGRAM_WEBHOOK_MAX_BYTES') ? max(1024, min(1048576, (int)TELEGRAM_WEBHOOK_MAX_BYTES)) : 262144;
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) telegram_reply(413, ['ok'=>false]);
$raw = file_get_contents('php://input', false, null, 0, $limit + 1);
if (!is_string($raw) || strlen($raw) > $limit) telegram_reply(413, ['ok'=>false]);
$update = json_decode($raw, true, 32, JSON_BIGINT_AS_STRING);
if (!is_array($update) || !isset($update['update_id']) || !preg_match('/^\d{1,19}$/', (string)$update['update_id'])) telegram_reply(400, ['ok'=>false]);
$updateId=(int)$update['update_id'];
$message=is_array($update['message'] ?? null)?$update['message']:null;
$userId=is_array($message['from'] ?? null)?(int)($message['from']['id']??0):0;

$insert=$conn->prepare("INSERT IGNORE INTO telegram_processed_updates (update_id,telegram_user_id,update_type,status) VALUES (?,?,'message','processing')");
$insert->bind_param('ii',$updateId,$userId);$insert->execute();$owned=$insert->affected_rows===1;$insert->close();
if(!$owned) telegram_reply(200);
$started=microtime(true);
try {
    if ($message===null || ($message['chat']['type']??'')!=='private' || !isset($message['text'])) {
        $status='ignored';
    } else {
        $service=new \App\Services\TelegramRegistrationService($conn);
        $payload=$service->handleMessage($message);
        // Telegram accepts a Bot API method object as the webhook response,
        // avoiding a second outbound HTTPS call on constrained shared hosting.
        $status='processed';
    }
    $ms=(int)round((microtime(true)-$started)*1000);
    $done=$conn->prepare('UPDATE telegram_processed_updates SET status=?,processed_at=CURRENT_TIMESTAMP,processing_ms=? WHERE update_id=?');
    $done->bind_param('sii',$status,$ms,$updateId);$done->execute();$done->close();
    if(isset($payload)) telegram_reply(200,$payload);
    telegram_reply(200);
} catch (Throwable $e) {
    reportInternalError('Telegram webhook failed',$e);
    $ms=(int)round((microtime(true)-$started)*1000);
    $failed=$conn->prepare("UPDATE telegram_processed_updates SET status='failed',processed_at=CURRENT_TIMESTAMP,processing_ms=? WHERE update_id=?");
    $failed->bind_param('ii',$ms,$updateId);$failed->execute();$failed->close();
    // A non-2xx response lets Telegram retry. Idempotency prevents replay work;
    // failed rows require admin inspection/requeue rather than duplicate drafts.
    telegram_reply(500,['ok'=>false]);
}
