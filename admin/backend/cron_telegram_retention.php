<?php
/** Shared-host cron entry point. Run with PHP CLI; never expose over HTTP. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/config.php';
require_once __DIR__.'/services/TelegramRetentionService.php';
if(!feature_enabled('telegram_registration')){fwrite(STDOUT,"Telegram registration disabled; nothing to do.\n");exit(0);}
try{$result=\App\Services\TelegramRetentionService::run($conn,500);fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_UNICODE)."\n");}
catch(Throwable $e){reportInternalError('Telegram retention cron failed',$e);fwrite(STDERR,"Telegram retention failed.\n");exit(1);}
