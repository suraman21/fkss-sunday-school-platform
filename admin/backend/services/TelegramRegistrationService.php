<?php
/** Review-only Telegram member-registration conversation. */
namespace App\Services;

use InvalidArgumentException;

final class TelegramRegistrationService
{
    public function __construct(private \mysqli $conn) {}

    /**
     * Serialize one operator's conversation so concurrent Telegram deliveries
     * cannot advance the same draft twice. The short advisory lock is released
     * on every return/exception and never spans an outbound HTTP request.
     * @param array<string,mixed> $message @return array<string,mixed>
     */
    public function handleMessage(array $message): array
    {
        $from=is_array($message['from']??null)?$message['from']:[];
        $telegramId=self::positiveId($from['id']??null);
        if($telegramId===0)return $this->handleLocked($message);
        $lockName='tg-reg-'.hash('sha256',(string)$telegramId);
        $q=$this->conn->prepare('SELECT GET_LOCK(?,1) acquired');$q->bind_param('s',$lockName);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
        if((int)($row['acquired']??0)!==1){$chat=is_array($message['chat']??null)?(int)($message['chat']['id']??0):0;return self::message($chat,'Your previous message is still processing. Please retry in a moment.');}
        try{return $this->handleLocked($message);}finally{$q=$this->conn->prepare('SELECT RELEASE_LOCK(?)');$q->bind_param('s',$lockName);$q->execute();$q->close();}
    }

    /** @param array<string,mixed> $message @return array<string,mixed> */
    private function handleLocked(array $message): array
    {
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $telegramId = self::positiveId($from['id'] ?? null);
        $chatId = self::positiveId($chat['id'] ?? null);
        $text = is_scalar($message['text'] ?? null) ? trim((string)$message['text']) : '';
        if ($telegramId === 0 || $chatId === 0 || ($chat['type'] ?? '') !== 'private') {
            return self::message($chatId, 'This bot works only in an authorized private chat.');
        }
        $actor = $this->actor($telegramId, $from);
        if (($actor['status'] ?? '') !== 'active' || empty($actor['linked_user_id']) || empty($actor['system_user_active'])) {
            $this->audit('telegram', $telegramId, 'access_denied', 'bot_user', (int)($actor['id'] ?? 0), 'denied');
            $pairing = (int)($actor['id'] ?? 0);
            return self::message($chatId,
                "ይህ መለያ ገና አልተፈቀደም። / This account is not authorized yet.\n\nTelegram ID: {$telegramId}\nPairing request: {$pairing}\nAsk an administrator to link it to your active system account."
            );
        }
        $this->expireStale((int)$actor['id']);

        if ($text === '/start' || $text === '/help') return $this->home($chatId, $actor);
        if ($text === '/cancel') { $this->cancelActive((int)$actor['id']); return self::message($chatId, 'Cancelled. / ተሰርዟል።', self::homeKeyboard()); }
        if ($text === '/new' || $text === '➕ አዲስ ምዝገባ' || $text === '➕ New registration') return $this->begin($chatId, $actor);

        $session = $this->activeSession((int)$actor['id'], $chatId);
        if (!$session) return $this->home($chatId, $actor);
        if ($text === '/back') return $this->back($session);
        if ($text === '/review') return $this->review($session);

        if ($session['status'] === 'choosing') return $this->chooseProfile($session, $actor, $text);
        if ($session['status'] === 'review') {
            if (in_array($text, ['✅ ላክ','✅ Submit for HR review'], true)) return $this->submitReview($session);
            if (in_array($text, ['✏️ አርም','✏️ Edit'], true)) return $this->restartFields($session);
            return $this->review($session);
        }
        if ($session['status'] === 'collecting') return $this->acceptAnswer($session, $text);
        return $this->home($chatId, $actor);
    }

    /** @param array<string,mixed> $from */
    private function actor(int $telegramId, array $from): array
    {
        $name = trim((string)($from['first_name'] ?? '') . ' ' . (string)($from['last_name'] ?? ''));
        $username = is_scalar($from['username'] ?? null) ? (string)$from['username'] : null;
        $statement = $this->conn->prepare('SELECT tb.*,u.role AS system_role,u.is_active AS system_user_active FROM telegram_bot_users tb LEFT JOIN users u ON u.id=tb.linked_user_id WHERE tb.telegram_user_id=? LIMIT 1');
        $statement->bind_param('i', $telegramId); $statement->execute(); $row=$statement->get_result()->fetch_assoc(); $statement->close();
        if (!$row) {
            $statement=$this->conn->prepare("INSERT INTO telegram_bot_users (telegram_user_id,display_name,username_snapshot,status) VALUES (?,?,?,'pending')");
            $statement->bind_param('iss',$telegramId,$name,$username); $statement->execute(); $id=(int)$this->conn->insert_id; $statement->close();
            return ['id'=>$id,'telegram_user_id'=>$telegramId,'status'=>'pending'];
        }
        $id=(int)$row['id'];
        $statement=$this->conn->prepare('UPDATE telegram_bot_users SET display_name=?,username_snapshot=?,last_seen_at=CURRENT_TIMESTAMP WHERE id=?');
        $statement->bind_param('ssi',$name,$username,$id); $statement->execute(); $statement->close();
        if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) <= time()) $row['status']='suspended';
        return $row;
    }

    private function home(int $chatId, array $actor): array
    {
        return self::message($chatId,
            "የአባላት ምዝገባ ረዳት\nMember registration assistant\n\nThe bot collects a draft. HR must review it before any member is created.\n\n/new — start\n/cancel — cancel current draft\n/back — previous question\n/review — review answers",
            self::homeKeyboard()
        );
    }

    private function begin(int $chatId, array $actor): array
    {
        $actorId=(int)$actor['id'];$dailyLimit=max(1,min(200,(int)($actor['daily_registration_limit']??50)));
        $statement=$this->conn->prepare('SELECT COUNT(*) n FROM telegram_registration_sessions WHERE bot_user_id=? AND created_at>=CURRENT_DATE');$statement->bind_param('i',$actorId);$statement->execute();$used=(int)($statement->get_result()->fetch_assoc()['n']??0);$statement->close();
        if($used>=$dailyLimit){$this->audit('telegram',(int)$actor['telegram_user_id'],'daily_limit_reached','bot_user',$actorId,'denied');return self::message($chatId,'Daily registration draft limit reached. Contact an administrator if more capacity is required.');}
        $this->cancelActive($actorId);
        $publicId=self::publicId(); $expires=date('Y-m-d H:i:s',time()+86400);
        $statement=$this->conn->prepare("INSERT INTO telegram_registration_sessions (public_id,bot_user_id,telegram_chat_id,status,expires_at) VALUES (?,?,?,'choosing',?)");
        $statement->bind_param('siis',$publicId,$actorId,$chatId,$expires); $statement->execute(); $id=(int)$this->conn->insert_id; $statement->close();
        $this->audit('telegram',(int)$actor['telegram_user_id'],'draft_started','session',$id);
        $buttons=[];
        if ((int)$actor['can_waiting']===1) $buttons[]=[['text'=>'⏳ ጊዜያዊ / Temporary']];
        if ((int)$actor['can_direct']===1) $buttons[]=[['text'=>'⚡ ቀጥታ / Direct']];
        $buttons[]=[['text'=>'❌ Cancel']];
        return self::message($chatId,'የምዝገባ ዓይነት ይምረጡ። / Choose registration type.', ['keyboard'=>$buttons,'resize_keyboard'=>true,'one_time_keyboard'=>true]);
    }

    private function chooseProfile(array $session,array $actor,string $text): array
    {
        $profile = str_contains(strtolower($text),'direct') || str_contains($text,'ቀጥታ') ? 'direct' : (str_contains(strtolower($text),'temporary') || str_contains($text,'ጊዜያዊ') ? 'waiting' : '');
        if ($profile==='' || ($profile==='direct' && (int)$actor['can_direct']!==1) || ($profile==='waiting' && (int)$actor['can_waiting']!==1)) {
            return self::message((int)$session['telegram_chat_id'],'Choose one of the allowed registration types.');
        }
        $statement=$this->conn->prepare("SELECT id,version FROM telegram_form_profiles WHERE profile_key=? AND status='published' ORDER BY version DESC LIMIT 1");
        $statement->bind_param('s',$profile); $statement->execute(); $form=$statement->get_result()->fetch_assoc(); $statement->close();
        if (!$form) return self::message((int)$session['telegram_chat_id'],'The form is not published. Contact an administrator.');
        $first=$this->firstField((int)$form['id']);
        if (!$first) return self::message((int)$session['telegram_chat_id'],'The form has no enabled questions.');
        $id=(int)$session['id']; $formId=(int)$form['id']; $version=(int)$form['version']; $key=(string)$first['field_key'];
        $statement=$this->conn->prepare("UPDATE telegram_registration_sessions SET profile_key=?,form_profile_id=?,form_version=?,status='collecting',current_field_key=?,lock_version=lock_version+1 WHERE id=? AND status='choosing'");
        $statement->bind_param('siisi',$profile,$formId,$version,$key,$id); $statement->execute(); $statement->close();
        $session['form_profile_id']=$formId; $session['current_field_key']=$key; $session['status']='collecting';
        return $this->prompt($session,$first);
    }

    private function acceptAnswer(array $session,string $text): array
    {
        $field=$this->field((int)$session['form_profile_id'],(string)$session['current_field_key']);
        if (!$field) return self::message((int)$session['telegram_chat_id'],'Form configuration changed. Use /cancel and start again.');
        try { $value=TelegramFormRegistry::validate((string)$field['field_key'],$text,(int)$field['is_required']===1); }
        catch (InvalidArgumentException $e) { return self::message((int)$session['telegram_chat_id'],"⚠️ {$e->getMessage()}\n\n".$this->promptText($field)); }
        $sessionId=(int)$session['id']; $key=(string)$field['field_key']; $display=$value['display']; $normal=$value['normalized'];
        $statement=$this->conn->prepare('INSERT INTO telegram_registration_answers (session_id,field_key,value_text,value_normalized) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),value_normalized=VALUES(value_normalized),updated_at=CURRENT_TIMESTAMP');
        $statement->bind_param('isss',$sessionId,$key,$display,$normal); $statement->execute(); $statement->close();
        if (in_array($key,['full_name_am','dob_ec','phone_number','baptismal_name','gender'],true)) $this->refreshDuplicates($session);
        $next=$this->nextField((int)$session['form_profile_id'],(int)$field['sort_order'],(int)$field['id']);
        if (!$next) { $statement=$this->conn->prepare("UPDATE telegram_registration_sessions SET status='review',current_field_key=NULL,lock_version=lock_version+1 WHERE id=?"); $statement->bind_param('i',$sessionId); $statement->execute(); $statement->close(); $session['status']='review'; return $this->review($session); }
        $nextKey=(string)$next['field_key'];
        $statement=$this->conn->prepare('UPDATE telegram_registration_sessions SET current_field_key=?,lock_version=lock_version+1 WHERE id=?');
        $statement->bind_param('si',$nextKey,$sessionId); $statement->execute(); $statement->close(); $session['current_field_key']=$nextKey;
        return $this->prompt($session,$next);
    }

    private function refreshDuplicates(array $session): void
    {
        $answers=$this->answers((int)$session['id']);
        try { $meta=TelegramDuplicateCandidateService::findWithMeta($this->conn,$answers); $matches=$meta['candidates']; $state=$matches===[]?'none':(((int)$matches[0]['match_score']>=85)?'likely':'possible'); $summary=json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); }
        catch (\Throwable $e) { reportInternalError('Telegram duplicate lookup failed',$e); $state='error'; $summary=null; }
        $id=(int)$session['id']; $statement=$this->conn->prepare('UPDATE telegram_registration_sessions SET duplicate_state=?,duplicate_summary=? WHERE id=?'); $statement->bind_param('ssi',$state,$summary,$id); $statement->execute(); $statement->close();
    }

    private function review(array $session): array
    {
        $answers=$this->answers((int)$session['id']);
        $lines=['📋 የምዝገባ ማጠቃለያ / Registration draft'];
        foreach($answers as $key=>$value) if($value!=='') $lines[]=$key.': '.$value;
        $fresh=$this->session((int)$session['id']) ?: $session;
        $state=(string)($fresh['duplicate_state']??'not_checked');
        $lines[]='';
        $lines[]=$state==='none'?'✅ No indexed duplicate candidate found.':($state==='likely'?'🚨 Likely duplicate candidate found — HR must review.':($state==='possible'?'⚠️ Possible duplicate candidate found — HR must review.':'ℹ️ Duplicate check: '.$state));
        $duplicateMeta=json_decode((string)($fresh['duplicate_summary']??''),true);if(is_array($duplicateMeta)&&!empty($duplicateMeta['capped']))$lines[]='More plausible candidates exist than the safe display limit. Add or verify date of birth and phone so HR can refine the result.';
        $lines[]='No member will be created now. Submit this draft to HR for review.';
        return self::message((int)$session['telegram_chat_id'],implode("\n",$lines),['keyboard'=>[[['text'=>'✅ ላክ'],['text'=>'✏️ አርም']],[['text'=>'❌ Cancel']]],'resize_keyboard'=>true]);
    }

    private function submitReview(array $session): array
    {
        $id=(int)$session['id'];
        $statement=$this->conn->prepare("UPDATE telegram_registration_sessions SET status='submitted',submitted_at=CURRENT_TIMESTAMP,current_field_key=NULL,lock_version=lock_version+1 WHERE id=? AND status='review'");
        $statement->bind_param('i',$id); $statement->execute(); $changed=$statement->affected_rows; $statement->close();
        if ($changed!==1) return self::message((int)$session['telegram_chat_id'],'This draft was already submitted or changed.');
        $this->audit('telegram',(int)$session['bot_user_id'],'draft_submitted','session',$id);
        return self::message((int)$session['telegram_chat_id'],"✅ ለHR ግምገማ ተልኳል።\nSubmitted for HR review. No member has been created.\nDraft: ".(string)$session['public_id'],self::homeKeyboard());
    }

    private function back(array $session): array
    {
        $fields=$this->fields((int)($session['form_profile_id']??0)); $answers=$this->answers((int)$session['id']);
        $keys=array_column($fields,'field_key'); $current=array_search($session['current_field_key'],$keys,true);
        $index=$current===false?count($keys)-1:max(0,$current-1); if(!$keys)return $this->review($session);
        $key=(string)$keys[$index]; $id=(int)$session['id'];
        $statement=$this->conn->prepare("UPDATE telegram_registration_sessions SET status='collecting',current_field_key=?,lock_version=lock_version+1 WHERE id=?"); $statement->bind_param('si',$key,$id);$statement->execute();$statement->close();
        $session['status']='collecting';$session['current_field_key']=$key;
        return $this->prompt($session,$fields[$index],isset($answers[$key])?'Current: '.$answers[$key]:null);
    }

    private function restartFields(array $session): array
    {
        $first=$this->firstField((int)$session['form_profile_id']); if(!$first)return $this->review($session);
        $key=(string)$first['field_key'];$id=(int)$session['id'];$statement=$this->conn->prepare("UPDATE telegram_registration_sessions SET status='collecting',current_field_key=? WHERE id=?");$statement->bind_param('si',$key,$id);$statement->execute();$statement->close();$session['status']='collecting';$session['current_field_key']=$key;return $this->prompt($session,$first);
    }

    private function prompt(array $session,array $field,?string $prefix=null): array
    {
        $keyboard=['remove_keyboard'=>true];
        if ($field['field_key']==='gender') $keyboard=['keyboard'=>[[['text'=>'ወንድ'],['text'=>'ሴት']]],'resize_keyboard'=>true,'one_time_keyboard'=>true];
        $text=$this->promptText($field); if($prefix)$text=$prefix."\n\n".$text;
        return self::message((int)$session['telegram_chat_id'],$text,$keyboard);
    }
    private function promptText(array $field): string { $text=(string)$field['prompt_am']."\n".(string)$field['prompt_en'].((string)($field['help_am']??'')!==''?"\n\nℹ️ ".$field['help_am']." / ".$field['help_en']:'');if((int)($field['is_required']??0)!==1)$text.="\n\n/skip — ዝለል / skip this optional question";return $text; }

    private function activeSession(int $actorId,int $chatId): ?array { $s=$this->conn->prepare("SELECT * FROM telegram_registration_sessions WHERE bot_user_id=? AND telegram_chat_id=? AND status IN ('choosing','collecting','review') AND expires_at>CURRENT_TIMESTAMP ORDER BY id DESC LIMIT 1");$s->bind_param('ii',$actorId,$chatId);$s->execute();$r=$s->get_result()->fetch_assoc()?:null;$s->close();return $r; }
    private function session(int $id): ?array { $s=$this->conn->prepare('SELECT * FROM telegram_registration_sessions WHERE id=?');$s->bind_param('i',$id);$s->execute();$r=$s->get_result()->fetch_assoc()?:null;$s->close();return $r; }
    private function cancelActive(int $actorId): void { $s=$this->conn->prepare("UPDATE telegram_registration_sessions SET status='cancelled',lock_version=lock_version+1 WHERE bot_user_id=? AND status IN ('choosing','collecting','review')");$s->bind_param('i',$actorId);$s->execute();$s->close(); }
    private function expireStale(int $actorId): void { $s=$this->conn->prepare("UPDATE telegram_registration_sessions SET status='expired',lock_version=lock_version+1 WHERE bot_user_id=? AND status IN ('choosing','collecting','review') AND expires_at<=CURRENT_TIMESTAMP");$s->bind_param('i',$actorId);$s->execute();$s->close(); }
    private function fields(int $profileId): array { $s=$this->conn->prepare('SELECT * FROM telegram_form_fields WHERE profile_id=? AND is_enabled=1 ORDER BY sort_order,id');$s->bind_param('i',$profileId);$s->execute();$r=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $r; }
    private function firstField(int $profileId): ?array { $a=$this->fields($profileId);return $a[0]??null; }
    private function field(int $profileId,string $key): ?array { $s=$this->conn->prepare('SELECT * FROM telegram_form_fields WHERE profile_id=? AND field_key=? AND is_enabled=1');$s->bind_param('is',$profileId,$key);$s->execute();$r=$s->get_result()->fetch_assoc()?:null;$s->close();return $r; }
    private function nextField(int $profileId,int $sort,int $id): ?array { $s=$this->conn->prepare('SELECT * FROM telegram_form_fields WHERE profile_id=? AND is_enabled=1 AND (sort_order>? OR (sort_order=? AND id>?)) ORDER BY sort_order,id LIMIT 1');$s->bind_param('iiii',$profileId,$sort,$sort,$id);$s->execute();$r=$s->get_result()->fetch_assoc()?:null;$s->close();return $r; }
    /** @return array<string,string> */ private function answers(int $sessionId): array { $s=$this->conn->prepare('SELECT field_key,value_text,value_normalized FROM telegram_registration_answers WHERE session_id=? ORDER BY id');$s->bind_param('i',$sessionId);$s->execute();$r=$s->get_result();$a=[];while($row=$r->fetch_assoc())$a[(string)$row['field_key']]=(string)($row['value_normalized']!==''&&in_array($row['field_key'],['gender'],true)?$row['value_normalized']:$row['value_text']);$s->close();return $a; }
    private function audit(string $type,int $actor,string $event,string $entity,int $entityId,string $result='success'): void { try{$s=$this->conn->prepare('INSERT INTO telegram_audit_log (actor_type,actor_id,event,entity_type,entity_id,result) VALUES (?,?,?,?,?,?)');$s->bind_param('sissis',$type,$actor,$event,$entity,$entityId,$result);$s->execute();$s->close();}catch(\Throwable $e){error_log('Telegram audit failed: '.$e->getMessage());} }

    private static function message(int $chatId,string $text,?array $markup=null): array { $p=['method'=>'sendMessage','chat_id'=>$chatId,'text'=>$text];if($markup!==null)$p['reply_markup']=$markup;return $p; }
    private static function homeKeyboard(): array { return ['keyboard'=>[[['text'=>'➕ አዲስ ምዝገባ']]],'resize_keyboard'=>true]; }
    private static function positiveId($value): int { return is_int($value)||is_string($value)?max(0,(int)$value):0; }
    private static function publicId(): string { return substr(strtoupper(bin2hex(random_bytes(13))),0,26); }
}
