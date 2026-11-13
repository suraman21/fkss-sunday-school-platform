<?php
/** Secure deployment-local Telegram bot configuration outside public_html. */
namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class TelegramBotConfigService
{
    public function __construct(private string $publicRoot) {}

    /** @return array{configured:bool,username:string,webhook_secret_configured:bool,managed_file_exists:bool,writable:bool} */
    public function status(): array
    {
        $path=$this->path();$username=defined('TELEGRAM_BOT_USERNAME')?(string)TELEGRAM_BOT_USERNAME:'';
        return ['configured'=>defined('TELEGRAM_BOT_TOKEN')&&(string)TELEGRAM_BOT_TOKEN!=='','username'=>$username,'webhook_secret_configured'=>defined('TELEGRAM_WEBHOOK_SECRET')&&(string)TELEGRAM_WEBHOOK_SECRET!=='','managed_file_exists'=>is_file($path),'writable'=>is_file($path)?is_writable($path):is_writable(dirname($path))];
    }

    /** Never returns or logs the token. */
    public function save(string $token,string $username): array
    {
        $token=trim($token);$username=ltrim(trim($username),'@');
        if(!preg_match('/^[1-9][0-9]{5,15}:[A-Za-z0-9_-]{30,80}$/D',$token))throw new InvalidArgumentException('Enter a valid Telegram Bot API token.');
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,28}bot$/iD',$username))throw new InvalidArgumentException('Enter the bot username without @; it must end in bot.');
        $path=$this->path();$dir=dirname($path);if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('The private configuration directory is not writable.');
        $existingSecret=defined('TELEGRAM_WEBHOOK_SECRET')?(string)TELEGRAM_WEBHOOK_SECRET:'';$secret=preg_match('/^[A-Za-z0-9_-]{32,256}$/D',$existingSecret)?$existingSecret:self::secret();
        $content="<?php\n// Managed by the Super Admin Telegram setup page. Keep outside public_html.\nif (!defined('TELEGRAM_BOT_TOKEN')) define('TELEGRAM_BOT_TOKEN', ".var_export($token,true).");\nif (!defined('TELEGRAM_BOT_USERNAME')) define('TELEGRAM_BOT_USERNAME', ".var_export($username,true).");\nif (!defined('TELEGRAM_WEBHOOK_SECRET')) define('TELEGRAM_WEBHOOK_SECRET', ".var_export($secret,true).");\n";
        $temp=$path.'.tmp.'.bin2hex(random_bytes(6));if(file_put_contents($temp,$content,LOCK_EX)!==strlen($content)){@unlink($temp);throw new RuntimeException('Could not write private bot configuration.');}@chmod($temp,0600);if(!rename($temp,$path)){@unlink($temp);throw new RuntimeException('Could not activate private bot configuration.');}@chmod($path,0600);
        return ['configured'=>true,'username'=>$username,'webhook_secret_configured'=>true,'managed_file_exists'=>true,'writable'=>is_writable($path)];
    }

    private function path(): string { return dirname($this->publicRoot).'/.telegram_env.php'; }
    private static function secret(): string { return rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'='); }
}
