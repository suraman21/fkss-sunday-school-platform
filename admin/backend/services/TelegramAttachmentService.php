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
        $definition=TelegramFormRegistry::definitions()[$fieldKey]??null;if(!$definition||($definition['input']??'')!=='file')throw new InvalidArgumentException('Unsupported attachment field.');$size=@filesize($tmp);if($size===false||$size<1||$size>5242880)throw new InvalidArgumentException('File must be between 1 byte and 5 MB.');$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);$ext=self::IMAGE[$mime]??null;if($ext!==null&&@getimagesize($tmp)===false)$ext=null;if($ext===null&&($definition['accept']??'')==='image_pdf'&&$mime==='application/pdf'&&file_get_contents($tmp,false,null,0,5)==='%PDF-')$ext='pdf';if($ext===null)throw new InvalidArgumentException('Only verified JPG, PNG, WebP, or PDF files are allowed.');$root=(defined('ROOT_PATH')?dirname(ROOT_PATH):dirname(__DIR__,4)).'/ssms_private/telegram_quarantine';if((!is_dir($root)&&!@mkdir($root,0700,true))||!is_writable($root))throw new RuntimeException('Private attachment storage is unavailable.');$name=bin2hex(random_bytes(16)).'.'.$ext;$target=$root.'/'.$name;if(!@rename($tmp,$target)&&!@copy($tmp,$target))throw new RuntimeException('Attachment could not be quarantined.');@chmod($target,0600);$stored='private://telegram_quarantine/'.$name;$q=$this->conn->prepare("INSERT INTO telegram_registration_attachments(session_id,field_key,telegram_file_id,telegram_unique_id,stored_path,mime_type,byte_size,status) VALUES(?,?,?,?,?,?,?,'stored') ON DUPLICATE KEY UPDATE telegram_file_id=VALUES(telegram_file_id),telegram_unique_id=VALUES(telegram_unique_id),stored_path=VALUES(stored_path),mime_type=VALUES(mime_type),byte_size=VALUES(byte_size),status='stored',updated_at=NOW()");$q->bind_param('isssssi',$sessionId,$fieldKey,$fileId,$uniqueId,$stored,$mime,$size);$q->execute();$id=(int)($this->conn->insert_id?:0);$q->close();return ['id'=>$id,'path'=>$stored,'mime'=>$mime,'size'=>$size];
    }
}
