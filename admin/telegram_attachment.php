<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/backend/services/TelegramAttachmentService.php';
use App\Services\TelegramAttachmentService;
if(!function_exists('isLoggedIn')||!isLoggedIn()||($_SESSION['admin_role']??'')!=='hr_dept'){http_response_code(403);exit;}
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id){http_response_code(400);exit;}
$q=$conn->prepare("SELECT stored_path,mime_type,byte_size FROM telegram_registration_attachments WHERE id=? AND status IN ('stored','attached') LIMIT 1");$q->bind_param('i',$id);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();if(!$row){http_response_code(404);exit;}$path=TelegramAttachmentService::resolve((string)$row['stored_path']);if($path===null){http_response_code(404);exit;}$mime=(string)$row['mime_type'];if(!in_array($mime,['image/jpeg','image/png','image/webp','application/pdf'],true)){http_response_code(415);exit;}header('Content-Type: '.$mime);header('Content-Length: '.(string)filesize($path));header('Content-Disposition: inline; filename="registration-attachment.'.($mime==='application/pdf'?'pdf':($mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg'))).'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile($path);
