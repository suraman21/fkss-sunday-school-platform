<?php
/** Short, bounded Telegram Bot API calls for Super Admin setup only. */
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
        if(!preg_match('#^https://[^\s]+/api/telegram-webhook\.php$#D',$url))throw new RuntimeException('Webhook URL must be the HTTPS Telegram endpoint.');
        if(!defined('TELEGRAM_WEBHOOK_SECRET')||!preg_match('/^[A-Za-z0-9_-]{32,256}$/D',(string)TELEGRAM_WEBHOOK_SECRET))throw new RuntimeException('Webhook secret is not configured.');
        $this->verifyIdentity();
        $this->call('setWebhook',['url'=>$url,'secret_token'=>(string)TELEGRAM_WEBHOOK_SECRET,'allowed_updates'=>json_encode(['message']),'max_connections'=>'10','drop_pending_updates'=>'false']);
        return $this->webhookInfo();
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
        $body=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);$error=curl_error($handle);curl_close($handle);
        if(!is_string($body)||$status<200||$status>=300)throw new RuntimeException($error!==''?'Telegram connection failed.':'Telegram API rejected the request.');
        $decoded=json_decode($body,true,16);if(!is_array($decoded)||($decoded['ok']??false)!==true||!array_key_exists('result',$decoded))throw new RuntimeException('Telegram returned an invalid setup response.');return $decoded['result'];
    }
}
