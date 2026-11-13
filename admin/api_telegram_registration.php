<?php
/** Protected administration API for review-only Telegram registration. */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/config.php';
require_once __DIR__.'/backend/services/AmharicIdentityNormalizer.php';
require_once __DIR__.'/backend/services/MemberCanonicalIdentityService.php';
require_once __DIR__.'/backend/services/TelegramFormRegistry.php';
require_once __DIR__.'/backend/services/TelegramFormAdminService.php';
require_once __DIR__.'/backend/services/TelegramBotConfigService.php';
require_once __DIR__.'/backend/services/TelegramBotApiService.php';

use App\Services\MemberCanonicalIdentityService;
use App\Services\TelegramFormRegistry;
use App\Services\TelegramFormAdminService;
use App\Services\TelegramBotConfigService;
use App\Services\TelegramBotApiService;

function tg_admin_error(string $message,int $code=400): never { http_response_code($code);echo json_encode(['status'=>'error','message'=>$message],JSON_UNESCAPED_UNICODE);exit; }
function tg_admin_ok(array $data=[]): never { echo json_encode(['status'=>'success']+$data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit; }
function tg_admin_tables(\mysqli $conn): bool { $r=$conn->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('telegram_bot_users','telegram_form_profiles','telegram_form_fields','telegram_registration_sessions','telegram_registration_answers','telegram_processed_updates','telegram_registration_reviews','telegram_audit_log')");$row=$r?$r->fetch_assoc():null;return (int)($row['n']??0)===8; }
function tg_admin_limit($value,int $default=50): int { $n=(int)$value;return $n>0?min(200,$n):$default; }
function tg_admin_audit(\mysqli $conn,int $actor,string $event,string $entity,int $entityId,array $meta=[]): void { $json=json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);$s=$conn->prepare("INSERT INTO telegram_audit_log(actor_type,actor_id,event,entity_type,entity_id,result,meta) VALUES('admin',?,?,?,?, 'success',?)");$s->bind_param('issis',$actor,$event,$entity,$entityId,$json);$s->execute();$s->close(); }

if (!function_exists('isLoggedIn') || !isLoggedIn()) tg_admin_error('Not authenticated.',401);
$actor=(int)($_SESSION['admin_id']??0);$role=(string)($_SESSION['admin_role']??'');
if($actor<=0||!in_array($role,['super_admin','hr_dept'],true))tg_admin_error('Access denied.',403);
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$input=$method==='POST'?$_POST:$_GET;
$action=trim((string)($input['action']??'overview'));
$superActions=['overview','bot_config','bot_config_save','bot_verify','webhook_status','webhook_set','canonical_backfill'];
$hrActions=['overview','operators','eligible_users','forms','form_registry','form_detail','form_create','form_save','form_publish','sessions','session_detail','review_decide','audit','operator_save'];
if(($role==='super_admin'&&!in_array($action,$superActions,true))||($role==='hr_dept'&&!in_array($action,$hrActions,true)))tg_admin_error('This function belongs to the other administration area.',403);
if($method==='POST'&&!validateCsrf($input['csrf_token']??($_SERVER['HTTP_X_CSRF_TOKEN']??null)))tg_admin_error('Invalid session token.',403);
$schema=tg_admin_tables($conn);
if(!$schema&&$action!=='overview')tg_admin_error('Telegram migration 080 is not installed.',503);

try{
switch($action){
case 'overview':
    $stats=['operators'=>0,'active_operators'=>0,'submitted'=>0,'open_sessions'=>0,'failed_updates_24h'=>0];
    if($schema){
        $r=$conn->query("SELECT COUNT(*) operators,COALESCE(SUM(status='active'),0) active_operators FROM telegram_bot_users");$stats=array_merge($stats,$r->fetch_assoc());
        $r=$conn->query("SELECT COALESCE(SUM(status='submitted'),0) submitted,COALESCE(SUM(status IN ('choosing','collecting','review')),0) open_sessions FROM telegram_registration_sessions");$stats=array_merge($stats,$r->fetch_assoc());
        $r=$conn->query("SELECT COUNT(*) failed_updates_24h FROM telegram_processed_updates WHERE status='failed' AND received_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)");$stats=array_merge($stats,$r->fetch_assoc());
    }
    tg_admin_ok(['available'=>$schema,'feature_enabled'=>feature_enabled('telegram_registration'),'secrets'=>['bot_token'=>defined('TELEGRAM_BOT_TOKEN'),'webhook_secret'=>defined('TELEGRAM_WEBHOOK_SECRET')],'canonical_columns'=>MemberCanonicalIdentityService::available($conn),'stats'=>$stats]);
case 'bot_config':
    if($role!=='super_admin')tg_admin_error('Super Admin access required.',403);$botConfig=new TelegramBotConfigService($conn,dirname(__DIR__));tg_admin_ok(['bot'=>$botConfig->status()]);
case 'bot_config_save':
    if($method!=='POST'||$role!=='super_admin')tg_admin_error('Super Admin access required.',403);$botConfig=new TelegramBotConfigService($conn,dirname(__DIR__));$bot=$botConfig->save($actor,(string)($input['current_password']??''),(string)($input['bot_token']??''),(string)($input['bot_username']??''));tg_admin_audit($conn,$actor,'bot_configuration_saved','deployment',0,['username'=>$bot['username'],'token_changed'=>true]);tg_admin_ok(['bot'=>$bot,'reload_required'=>true]);
case 'bot_verify':
    if($method!=='POST'||$role!=='super_admin')tg_admin_error('Super Admin access required.',403);$botApi=new TelegramBotApiService();$identity=$botApi->verifyIdentity();tg_admin_audit($conn,$actor,'bot_identity_verified','deployment',0,['username'=>$identity['username'],'bot_id'=>$identity['id']]);tg_admin_ok(['identity'=>$identity]);
case 'webhook_status':
    if($role!=='super_admin')tg_admin_error('Super Admin access required.',403);$botApi=new TelegramBotApiService();tg_admin_ok(['webhook'=>$botApi->webhookInfo(),'expected_url'=>rtrim((string)SITE_URL,'/').'/api/telegram-webhook.php']);
case 'webhook_set':
    if($method!=='POST'||$role!=='super_admin')tg_admin_error('Super Admin access required.',403);$url=rtrim((string)SITE_URL,'/').'/api/telegram-webhook.php';$botApi=new TelegramBotApiService();$webhook=$botApi->configureWebhook($url);tg_admin_audit($conn,$actor,'webhook_configured','deployment',0,['url'=>$url,'allowed_updates'=>['message'],'max_connections'=>10]);tg_admin_ok(['webhook'=>$webhook,'expected_url'=>$url]);
case 'operators':
    $limit=tg_admin_limit($input['limit']??50);$offset=max(0,(int)($input['offset']??0));
    $sql="SELECT tb.id,tb.telegram_user_id,tb.display_name,tb.username_snapshot,tb.status,tb.can_waiting,tb.can_direct,tb.daily_registration_limit,tb.expires_at,tb.last_seen_at,tb.created_at,tb.linked_user_id,u.full_name linked_user_name,u.username linked_username,u.role linked_role,u.is_active linked_user_active FROM telegram_bot_users tb LEFT JOIN users u ON u.id=tb.linked_user_id ORDER BY tb.id DESC LIMIT {$limit} OFFSET {$offset}";
    $rows=[];$r=$conn->query($sql);while($row=$r->fetch_assoc())$rows[]=$row;tg_admin_ok(['operators'=>$rows]);
case 'eligible_users':
    $q=trim((string)($input['q']??''));if(function_exists('mb_substr'))$q=mb_substr($q,0,80,'UTF-8');else$q=substr($q,0,80);$rows=[];if($q===''){$r=$conn->query("SELECT id,full_name,username,role FROM users WHERE is_active=1 AND role IN ('super_admin','school_admin','hr_dept') ORDER BY full_name,id LIMIT 100");while($row=$r->fetch_assoc())$rows[]=$row;}else{$pattern='%'.$q.'%';$s=$conn->prepare("SELECT id,full_name,username,role FROM users WHERE is_active=1 AND role IN ('super_admin','school_admin','hr_dept') AND (full_name LIKE ? OR username LIKE ?) ORDER BY full_name,id LIMIT 50");$s->bind_param('ss',$pattern,$pattern);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();}tg_admin_ok(['users'=>$rows]);
case 'forms':
    $rows=[];$r=$conn->query("SELECT p.*,COUNT(f.id) field_count,COALESCE(SUM(f.is_enabled=1),0) enabled_count,COALESCE(SUM(f.is_enabled=1 AND f.is_required=1),0) required_count FROM telegram_form_profiles p LEFT JOIN telegram_form_fields f ON f.profile_id=p.id GROUP BY p.id ORDER BY p.profile_key,p.version DESC");while($row=$r->fetch_assoc())$rows[]=$row;tg_admin_ok(['forms'=>$rows]);
case 'form_registry':
    tg_admin_ok(['fields'=>TelegramFormRegistry::definitions()]);
case 'form_detail':
    $forms=new TelegramFormAdminService($conn);$form=$forms->detail((int)($input['id']??0));if(!$form)tg_admin_error('Form not found.',404);tg_admin_ok(['form'=>$form]);
case 'form_create':
    if($method!=='POST'||$role!=='hr_dept')tg_admin_error('Only HR may create form versions.',403);
    $forms=new TelegramFormAdminService($conn);$form=$forms->createDraft((string)($input['profile_key']??''),$actor);tg_admin_audit($conn,$actor,'form_draft_created','form_profile',(int)$form['id'],['profile_key'=>$form['profile_key'],'version'=>$form['version']]);tg_admin_ok(['form'=>$form]);
case 'form_save':
    if($method!=='POST'||$role!=='hr_dept')tg_admin_error('Only HR may edit forms.',403);
    $fields=json_decode((string)($input['fields_json']??''),true);if(!is_array($fields))tg_admin_error('Invalid form fields.');$forms=new TelegramFormAdminService($conn);$form=$forms->saveDraft((int)($input['id']??0),(string)($input['title_am']??''),(string)($input['title_en']??''),$fields);tg_admin_audit($conn,$actor,'form_draft_saved','form_profile',(int)$form['id'],['field_count'=>count($fields)]);tg_admin_ok(['form'=>$form]);
case 'form_publish':
    if($method!=='POST'||$role!=='hr_dept')tg_admin_error('Only HR may publish forms.',403);
    $forms=new TelegramFormAdminService($conn);$form=$forms->publish((int)($input['id']??0),$actor);tg_admin_audit($conn,$actor,'form_published','form_profile',(int)$form['id'],['profile_key'=>$form['profile_key'],'version'=>$form['version']]);tg_admin_ok(['form'=>$form]);
case 'sessions':
    $limit=tg_admin_limit($input['limit']??50);$status=trim((string)($input['status']??''));$allowed=['choosing','collecting','review','submitted','cancelled','expired','failed'];
    if($status!==''&&!in_array($status,$allowed,true))tg_admin_error('Invalid session status.');
    $where=$status!==''?'WHERE s.status=?':'';$sql="SELECT s.id,s.public_id,s.profile_key,s.form_version,s.language,s.status,s.current_field_key,s.duplicate_state,s.submitted_at,s.created_at,s.updated_at,tb.display_name operator_name,tb.telegram_user_id,r.decision review_decision,r.created_at reviewed_at FROM telegram_registration_sessions s JOIN telegram_bot_users tb ON tb.id=s.bot_user_id LEFT JOIN telegram_registration_reviews r ON r.session_id=s.id {$where} ORDER BY s.id DESC LIMIT {$limit}";$q=$conn->prepare($sql);if($status!=='')$q->bind_param('s',$status);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();tg_admin_ok(['sessions'=>$rows]);
case 'session_detail':
    $id=(int)($input['id']??0);$q=$conn->prepare('SELECT s.*,tb.telegram_user_id,tb.display_name operator_name,u.full_name linked_user_name,u.role linked_role,r.decision review_decision,r.note review_note,r.reviewer_user_id,r.created_at reviewed_at,ru.full_name reviewer_name FROM telegram_registration_sessions s JOIN telegram_bot_users tb ON tb.id=s.bot_user_id LEFT JOIN users u ON u.id=tb.linked_user_id LEFT JOIN telegram_registration_reviews r ON r.session_id=s.id LEFT JOIN users ru ON ru.id=r.reviewer_user_id WHERE s.id=? LIMIT 1');$q->bind_param('i',$id);$q->execute();$session=$q->get_result()->fetch_assoc();$q->close();if(!$session)tg_admin_error('Session not found.',404);$q=$conn->prepare('SELECT field_key,value_text,value_normalized,updated_at FROM telegram_registration_answers WHERE session_id=? ORDER BY id');$q->bind_param('i',$id);$q->execute();$answers=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();$summary=json_decode((string)($session['duplicate_summary']??''),true);$duplicates=[];$capped=false;$plausible=0;if(is_array($summary)&&isset($summary['candidates'])&&is_array($summary['candidates'])){$duplicates=$summary['candidates'];$capped=!empty($summary['capped']);$plausible=(int)($summary['plausible_count']??count($duplicates));}elseif(is_array($summary)){$duplicates=$summary;$plausible=count($duplicates);}unset($session['duplicate_summary']);tg_admin_ok(['session'=>$session,'answers'=>$answers,'duplicate_candidates'=>$duplicates,'candidate_cap'=>10,'candidate_cap_reached'=>$capped,'plausible_count'=>$plausible]);
case 'review_decide':
    if($method!=='POST')tg_admin_error('Use POST for review decisions.',405);$id=(int)($input['id']??0);$decision=(string)($input['decision']??'');if(!in_array($decision,['rejected','changes_requested','duplicate'],true))tg_admin_error('Approval is unavailable in review-only phase.');$note=trim((string)($input['note']??''));if($note===''||strlen($note)>1000)tg_admin_error('A review note of up to 1000 bytes is required.');$conn->begin_transaction();try{$q=$conn->prepare("SELECT id FROM telegram_registration_sessions WHERE id=? AND status='submitted' FOR UPDATE");$q->bind_param('i',$id);$q->execute();$found=$q->get_result()->fetch_assoc();$q->close();if(!$found)throw new \InvalidArgumentException('Only a submitted draft can be reviewed.');$q=$conn->prepare('INSERT INTO telegram_registration_reviews(session_id,decision,reviewer_user_id,note) VALUES(?,?,?,?)');$q->bind_param('isis',$id,$decision,$actor,$note);$q->execute();$reviewId=(int)$conn->insert_id;$q->close();tg_admin_audit($conn,$actor,'review_decided','session',$id,['decision'=>$decision,'review_id'=>$reviewId]);$conn->commit();tg_admin_ok(['reviewed'=>true,'decision'=>$decision]);}catch(\Throwable $e){$conn->rollback();throw $e;}
case 'audit':
    $limit=tg_admin_limit($input['limit']??100,100);$rows=[];$r=$conn->query("SELECT id,actor_type,actor_id,event,entity_type,entity_id,result,created_at FROM telegram_audit_log ORDER BY id DESC LIMIT {$limit}");while($row=$r->fetch_assoc())$rows[]=$row;tg_admin_ok(['audit'=>$rows]);
case 'operator_save':
    if($method!=='POST'||$role!=='hr_dept')tg_admin_error('Only HR may manage operators.',403);
    $id=max(0,(int)($input['id']??0));$telegramId=filter_var($input['telegram_user_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$userId=max(1,(int)($input['linked_user_id']??0));$status=(string)($input['operator_status']??'active');
    if(!$telegramId||!in_array($status,['active','suspended','revoked'],true))tg_admin_error('Valid numeric Telegram ID and status are required.');
    $u=$conn->prepare("SELECT id FROM users WHERE id=? AND is_active=1 AND role IN ('super_admin','school_admin','hr_dept')");$u->bind_param('i',$userId);$u->execute();$valid=$u->get_result()->fetch_assoc();$u->close();if(!$valid)tg_admin_error('Linked user must be an active HR/admin account.');
    $waiting=!empty($input['can_waiting'])?1:0;$direct=!empty($input['can_direct'])?1:0;$daily=max(1,min(200,(int)($input['daily_registration_limit']??50)));
    if($id>0){$q=$conn->prepare('UPDATE telegram_bot_users SET telegram_user_id=?,linked_user_id=?,status=?,can_waiting=?,can_direct=?,daily_registration_limit=?,authorization_version=authorization_version+1,revoked_at=IF(?=\'revoked\',NOW(),NULL) WHERE id=?');$q->bind_param('iisiiisi',$telegramId,$userId,$status,$waiting,$direct,$daily,$status,$id);$q->execute();if($q->affected_rows<0){$q->close();tg_admin_error('Operator update failed.',500);}$q->close();}
    else{$q=$conn->prepare('INSERT INTO telegram_bot_users(telegram_user_id,linked_user_id,status,can_waiting,can_direct,daily_registration_limit,created_by) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE linked_user_id=VALUES(linked_user_id),status=VALUES(status),can_waiting=VALUES(can_waiting),can_direct=VALUES(can_direct),daily_registration_limit=VALUES(daily_registration_limit),authorization_version=authorization_version+1');$q->bind_param('iisiiii',$telegramId,$userId,$status,$waiting,$direct,$daily,$actor);$q->execute();$id=(int)$conn->insert_id;$q->close();if($id===0){$q=$conn->prepare('SELECT id FROM telegram_bot_users WHERE telegram_user_id=? LIMIT 1');$q->bind_param('i',$telegramId);$q->execute();$id=(int)($q->get_result()->fetch_assoc()['id']??0);$q->close();}}
    if($id>0&&$status!=='active'){$q=$conn->prepare("UPDATE telegram_registration_sessions SET status='cancelled',lock_version=lock_version+1 WHERE bot_user_id=? AND status IN ('choosing','collecting','review')");$q->bind_param('i',$id);$q->execute();$q->close();}
    tg_admin_audit($conn,$actor,'operator_saved','bot_user',$id,['status'=>$status,'linked_user_id'=>$userId]);tg_admin_ok(['saved'=>true,'id'=>$id]);
case 'canonical_backfill':
    if($method!=='POST'||$role!=='super_admin')tg_admin_error('Only super administrators may run this operation.',403);
    $result=MemberCanonicalIdentityService::backfill($conn,(int)($input['cursor']??0),(int)($input['limit']??100));tg_admin_audit($conn,$actor,'canonical_backfill','members',0,$result);tg_admin_ok(['backfill'=>$result]);
default:tg_admin_error('Unknown action.');
}}
catch(\InvalidArgumentException $e){tg_admin_error($e->getMessage(),400);}
catch(\mysqli_sql_exception $e){reportInternalError('Telegram admin API database failure',$e);tg_admin_error('Database operation failed.',500);}
catch(\Throwable $e){reportInternalError('Telegram admin API failure',$e);tg_admin_error('Operation failed.',500);}
