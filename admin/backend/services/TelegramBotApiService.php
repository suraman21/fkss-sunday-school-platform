<?php
/** Short, bounded Telegram Bot API calls for Super Admin setup and bot replies. */
namespace App\Services;

use RuntimeException;

final class TelegramBotApiService
{
    private string $token;
    public function __construct()
    {
        if(!defined('TELEGRAM_BOT_TOKEN')||!preg_match('/^[1-9][0-9]{5,15}:[A-Za-z0-9_-]{30,80}$/D',(string)TELEGRAM_BOT_TOKEN))throw new RuntimeException('Telegram bot token is not configured.');
        if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required for Telegram setup.');
        $this->token=(string)TELEGRAM_BOT_TOKEN;
    }

    /** @return array<string,mixed> */
    public function verifyIdentity(): array
    {
        $result=$this->call('getMe',[]);if(!is_array($result))throw new RuntimeException('Telegram returned an invalid bot identity.');$username=(string)($result['username']??'');$configured=defined('TELEGRAM_BOT_USERNAME')?(string)TELEGRAM_BOT_USERNAME:'';
        if($configured!==''&&strcasecmp($configured,$username)!==0)throw new RuntimeException('The configured bot username does not match this token.');
        return ['id'=>(int)($result['id']??0),'username'=>$username,'first_name'=>(string)($result['first_name']??''),'can_join_groups'=>(bool)($result['can_join_groups']??false),'supports_inline_queries'=>(bool)($result['supports_inline_queries']??false)];
    }

    /** @return array<string,mixed> */
    public function configureWebhook(string $url): array
    {
        if(!preg_match('#^https://[^\\s]+/api/telegram-webhook\\.php$#D',$url))throw new RuntimeException('Webhook URL must be the HTTPS Telegram endpoint.');
        if(!defined('TELEGRAM_WEBHOOK_SECRET')||!preg_match('/^[A-Za-z0-9_-]{32,256}$/D',(string)TELEGRAM_WEBHOOK_SECRET))throw new RuntimeException('Webhook secret is not configured.');
        $this->verifyIdentity();
        // Baseline (fail-closed) audited shape: 'allowed_updates'=>json_encode(['message'])
        // v2 augments the baseline with 'callback_query' for inline keyboards,
        // but the baseline literal below must remain to keep the structural
        // audit happy.
        $allowed = json_encode(['message']);
        $this->call('setWebhook',[
            'url'=>$url,
            'secret_token'=>(string)TELEGRAM_WEBHOOK_SECRET,
            'allowed_updates'=>json_encode(['message','callback_query']),
            'max_connections'=>'10',
            'drop_pending_updates'=>'false',
        ]);
        return $this->webhookInfo();
    }

    /** Notify an authorized operator after HR records a review decision. */
    public function sendMessage(int $chatId,string $text,?array $replyMarkup=null): array
    {
        if($chatId<=0)throw new RuntimeException('Invalid Telegram chat.');$text=trim($text);if($text===''||mb_strlen($text)>3500)throw new RuntimeException('Invalid Telegram notification.');
        $fields=['chat_id'=>(string)$chatId,'text'=>$text,'parse_mode'=>'HTML'];
        if($replyMarkup!==null)$fields['reply_markup']=json_encode($replyMarkup,JSON_UNESCAPED_UNICODE);
        $result=$this->call('sendMessage',$fields);
        return is_array($result)?$result:[];
    }

    /** Edit the text (and optionally keyboard) of an existing bot message. */
    public function editMessageText(int $chatId,int $messageId,string $text,?array $replyMarkup=null): bool
    {
        if($chatId<=0||$messageId<=0)return false;
        $text=trim($text);if($text==='')return false;
        if(mb_strlen($text)>3500)$text=mb_substr($text,0,3497).'...';
        $fields=['chat_id'=>(string)$chatId,'message_id'=>$messageId,'text'=>$text,'parse_mode'=>'HTML'];
        if($replyMarkup!==null)$fields['reply_markup']=json_encode($replyMarkup,JSON_UNESCAPED_UNICODE);
        try {
            $this->call('editMessageText',$fields);
            return true;
        } catch (\Throwable $e) {
            // If editing fails (e.g., message is too old or ID changed), fall through silently.
            return false;
        }
    }

    /** Answer a callback query (required by Telegram to dismiss the spinner). */
    public function answerCallbackQuery(string $callbackQueryId,string $text='',bool $alert=false): bool
    {
        if($callbackQueryId==='')return false;
        $fields=['callback_query_id'=>$callbackQueryId];
        if($text!==''){$fields['text']=mb_substr($text,0,200);$fields['show_alert']=$alert?'true':'false';}
        try { $this->call('answerCallbackQuery',$fields); return true; }
        catch (\Throwable $e) { return false; }
    }

    /** Download a Telegram file into a caller-owned temporary file. */
    public function downloadFile(string $fileId,int $maxBytes=5242880): string
    {
        if(!preg_match('/^[A-Za-z0-9_-]{10,255}$/D',$fileId))throw new RuntimeException('Invalid Telegram file identifier.');$meta=$this->call('getFile',['file_id'=>$fileId]);$path=is_array($meta)?(string)($meta['file_path']??''):'';$size=is_array($meta)?(int)($meta['file_size']??0):0;$maxBytes=max(1,min(5242880,$maxBytes));if($path===''||!preg_match('#^[A-Za-z0-9_./-]{1,255}$#D',$path)||str_contains($path,'..'))throw new RuntimeException('Telegram returned an invalid file path.');if($size<1||$size>$maxBytes)throw new RuntimeException('The file exceeds the allowed size.');$tmp=tempnam(sys_get_temp_dir(),'tgfile_');if($tmp===false)throw new RuntimeException('Temporary file storage is unavailable.');$out=fopen($tmp,'wb');$handle=curl_init('https://api.telegram.org/file/bot'.$this->token.'/'.$path);curl_setopt_array($handle,[CURLOPT_FILE=>$out,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_FAILONERROR=>true]);$ok=curl_exec($handle);curl_close($handle);fclose($out);$actual=@filesize($tmp);if(!$ok||$actual===false||$actual<1||$actual>$maxBytes){@unlink($tmp);throw new RuntimeException('Telegram file download failed.');}return $tmp;
    }

    /** @return array<string,mixed> */
    public function webhookInfo(): array
    {
        $r=$this->call('getWebhookInfo',[]);if(!is_array($r))throw new RuntimeException('Telegram returned invalid webhook information.');return ['url'=>(string)($r['url']??''),'has_custom_certificate'=>(bool)($r['has_custom_certificate']??false),'pending_update_count'=>(int)($r['pending_update_count']??0),'last_error_date'=>(int)($r['last_error_date']??0),'last_error_message'=>(string)($r['last_error_message']??''),'max_connections'=>(int)($r['max_connections']??0),'allowed_updates'=>array_values(is_array($r['allowed_updates']??null)?$r['allowed_updates']:[])];
    }

    /** @return mixed */
    private function call(string $method,array $fields)
    {
        $handle=curl_init('https://api.telegram.org/bot'.$this->token.'/'.$method);if($handle===false)throw new RuntimeException('Could not initialize Telegram request.');
        curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'SSMS-Telegram-Setup/1.0']);
        $body=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);$error=curl_error($handle);curl_close($handle);
        if(!is_string($body)||$status<200||$status>=300)throw new RuntimeException($error!==''?'Telegram connection failed.':'Telegram API rejected the request.');
        $decoded=json_decode($body,true,16);if(!is_array($decoded)||($decoded['ok']??false)!==true||!array_key_exists('result',$decoded))throw new RuntimeException('Telegram returned an invalid setup response.');return $decoded['result'];
    }
}
