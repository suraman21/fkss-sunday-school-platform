<?php
namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class TelegramAttachmentService
{
    private const IMAGE=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    public function __construct(private \mysqli $conn){}
    public function store(int $sessionId,string $fieldKey,string $fileId,string $uniqueId,string $tmp): array
    {
        $definition=TelegramFormRegistry::definitions()[$fieldKey]??null;if(!$definition||($definition['input']??'')!=='file')throw new InvalidArgumentException('Unsupported attachment field.');$size=@filesize($tmp);if($size===false||$size<1||$size>5242880)throw new InvalidArgumentException('File must be between 1 byte and 5 MB.');$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);$ext=self::IMAGE[$mime]??null;if($ext!==null&&@getimagesize($tmp)===false)$ext=null;if($ext===null&&($definition['accept']??'')==='image_pdf'&&$mime==='application/pdf'&&file_get_contents($tmp,false,null,0,5)==='%PDF-')$ext='pdf';if($ext===null)throw new InvalidArgumentException('Only verified JPG, PNG, WebP, or PDF files are allowed.');$root=(defined('ROOT_PATH')?dirname(ROOT_PATH):dirname(__DIR__,4)).'/ssms_private/telegram_quarantine';if((!is_dir($root)&&!@mkdir($root,0700,true))||!is_writable($root))throw new RuntimeException('Private attachment storage is unavailable.');$oldPath=null;$old=$this->conn->prepare("SELECT stored_path FROM telegram_registration_attachments WHERE session_id=? AND field_key=? AND status IN ('pending','stored') LIMIT 1");$old->bind_param('is',$sessionId,$fieldKey);$old->execute();$oldPath=$old->get_result()->fetch_assoc()['stored_path']??null;$old->close();$name=bin2hex(random_bytes(16)).'.'.$ext;$target=$root.'/'.$name;if(!@rename($tmp,$target)&&!@copy($tmp,$target))throw new RuntimeException('Attachment could not be quarantined.');@chmod($target,0600);$stored='private://telegram_quarantine/'.$name;$q=$this->conn->prepare("INSERT INTO telegram_registration_attachments(session_id,field_key,telegram_file_id,telegram_unique_id,stored_path,mime_type,byte_size,status) VALUES(?,?,?,?,?,?,?,'stored') ON DUPLICATE KEY UPDATE telegram_file_id=VALUES(telegram_file_id),telegram_unique_id=VALUES(telegram_unique_id),stored_path=VALUES(stored_path),mime_type=VALUES(mime_type),byte_size=VALUES(byte_size),status='stored',updated_at=NOW()");$q->bind_param('isssssi',$sessionId,$fieldKey,$fileId,$uniqueId,$stored,$mime,$size);$q->execute();$id=(int)($this->conn->insert_id?:0);$q->close();if(is_string($oldPath)&&$oldPath!==$stored)self::discard($oldPath);return ['id'=>$id,'path'=>$stored,'mime'=>$mime,'size'=>$size];
    }
    public function cleanupSession(int $sessionId): void
    {
        $q=$this->conn->prepare("SELECT stored_path FROM telegram_registration_attachments WHERE session_id=? AND status IN ('pending','stored','rejected')");$q->bind_param('i',$sessionId);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();foreach($rows as $row)self::discard((string)($row['stored_path']??''));$q=$this->conn->prepare("UPDATE telegram_registration_attachments SET status='deleted',stored_path=NULL,updated_at=NOW() WHERE session_id=? AND status IN ('pending','stored','rejected')");$q->bind_param('i',$sessionId);$q->execute();$q->close();
    }
    public function cleanupStale(int $limit=100): int
    {
        $limit=max(1,min(200,$limit));$q=$this->conn->query("SELECT DISTINCT a.session_id FROM telegram_registration_attachments a JOIN telegram_registration_sessions s ON s.id=a.session_id WHERE a.status IN ('pending','stored','rejected') AND s.status IN ('cancelled','expired','failed') ORDER BY a.session_id LIMIT {$limit}");$ids=$q?$q->fetch_all(MYSQLI_ASSOC):[];foreach($ids as $row)$this->cleanupSession((int)$row['session_id']);return count($ids);
    }
    private static function discard(string $stored): void
    {
        if(!preg_match('#^private://telegram_quarantine/([a-f0-9]{32}\.(?:jpg|png|webp|pdf))$#D',$stored,$m))return;$root=(defined('ROOT_PATH')?dirname(ROOT_PATH):dirname(__DIR__,4)).'/ssms_private/telegram_quarantine';$path=$root.'/'.$m[1];if(is_file($path))@unlink($path);
    }
}
