<?php
/** Preflight only: proves whether a submitted Telegram draft is safe to approve later. */
namespace App\Services;

final class TelegramApprovalReadinessService
{
    public function __construct(private \mysqli $conn) {}

    /** @return array{ready:bool,blockers:list<string>,warnings:list<string>} */
    public function inspect(array $session,array $answers): array
    {
        $blockers=[];$warnings=[];
        if(($session['status']??'')!=='submitted')$blockers[]='The draft must be submitted before approval.';
        if(!in_array((string)($session['profile_key']??''),['waiting','direct'],true))$blockers[]='The registration profile is invalid.';
        $byKey=[];foreach($answers as $answer)$byKey[(string)($answer['field_key']??'')]=$answer;
        $profileId=(int)($session['form_profile_id']??0);
        $q=$this->conn->prepare('SELECT field_key FROM telegram_form_fields WHERE profile_id=? AND is_enabled=1 AND is_required=1 ORDER BY sort_order,id');$q->bind_param('i',$profileId);$q->execute();$required=array_column($q->get_result()->fetch_all(MYSQLI_ASSOC),'field_key');$q->close();
        foreach($required as $key)if(trim((string)($byKey[$key]['value_text']??''))==='')$blockers[]=TelegramFormRegistry::label($key).' is required.';
        $name=trim((string)($byKey['full_name_am']['value_text']??''));if(count(preg_split('/\s+/u',$name,-1,PREG_SPLIT_NO_EMPTY)?:[])<2)$blockers[]='Full name must contain at least first and father name.';
        if(!in_array((string)($byKey['gender']['value_normalized']??''),['male','female'],true))$blockers[]='Gender must be a validated choice.';
        $section=trim((string)($byKey['current_section']['value_normalized']??''));if($section!==''){$q=$this->conn->prepare('SELECT id FROM classes WHERE class_name=? AND is_active=1 LIMIT 1');$q->bind_param('s',$section);$q->execute();$active=$q->get_result()->fetch_assoc();$q->close();if(!$active)$blockers[]='The selected school section is no longer active.';}
        $duplicate=(string)($session['duplicate_state']??'not_checked');if($duplicate==='likely')$blockers[]='A likely duplicate must be resolved before approval.';elseif(in_array($duplicate,['possible','error','not_checked'],true))$warnings[]='Duplicate verification requires HR attention.';
        if((string)($session['profile_key']??'')==='direct')$warnings[]='Direct approval will create a permanent member and requires final transactional duplicate enforcement.';
        else $warnings[]='Waiting approval will create only a temporary/waiting record.';
        return ['ready'=>$blockers===[],'blockers'=>$blockers,'warnings'=>$warnings];
    }
}
