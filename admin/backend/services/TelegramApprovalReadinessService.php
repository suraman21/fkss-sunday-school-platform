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
        if(!in_array((string)($session['profile_key']??''),['waiting','direct','transfer'],true))$blockers[]='The registration profile is invalid.';
        $byKey=[];foreach($answers as $answer)$byKey[(string)($answer['field_key']??'')]=$answer;
        $profileId=(int)($session['form_profile_id']??0);
        $q=$this->conn->prepare('SELECT field_key FROM telegram_form_fields WHERE profile_id=? AND is_enabled=1 AND is_required=1 ORDER BY sort_order,id');$q->bind_param('i',$profileId);$q->execute();$required=array_column($q->get_result()->fetch_all(MYSQLI_ASSOC),'field_key');$q->close();
        foreach($required as $key)if(trim((string)($byKey[$key]['value_text']??''))==='')$blockers[]=TelegramFormRegistry::label($key).' is required.';
        $name=trim((string)($byKey['full_name_am']['value_text']??''));if(count(preg_split('/\s+/u',$name,-1,PREG_SPLIT_NO_EMPTY)?:[])<2)$blockers[]='Full name must contain at least first and father name.';
        if(!in_array((string)($byKey['gender']['value_normalized']??''),['male','female'],true))$blockers[]='Gender must be a validated choice.';
        $section=trim((string)($byKey['current_section']['value_normalized']??''));if($section!==''){$q=$this->conn->prepare('SELECT id FROM classes WHERE class_name=? AND is_active=1 LIMIT 1');$q->bind_param('s',$section);$q->execute();$active=$q->get_result()->fetch_assoc();$q->close();if(!$active)$blockers[]='The selected school section is no longer active.';}
        // Legacy duplicate-state flag is still honoured: duplicate==='likely' blocks approval.
        // v2 also consults telegram_candidate_decisions below so that human identity
        // verdicts (same/different/uncertain) are respected.
        $duplicate=(string)($session['duplicate_state']??'not_checked');if($duplicate==='likely')$blockers[]='A likely duplicate must be resolved before approval.';elseif(in_array($duplicate,['possible','error','not_checked'],true)){$legacyDupWarn=true;}else{$legacyDupWarn=false;}
        // v2 duplicate policy (per REG spec):
        //   - If any high-confidence plausible candidate is still 'viewed' (no
        //     human decision), submission/approval is BLOCKED.
        //   - Any candidate marked 'same_person' (same) blocks — the user must
        //     use the existing record, not create a new one.
        //   - 'uncertain' / 'escalated' are NEVER treated as unique — they warn
        //     HR to review before approval.
        //   - 'different_person' on all plausible candidates is required for a
        //     clean approval.
        $sessionId=(int)($session['id']??0);
        $unresolvedHigh=0;$same=0;$uncertain=0;$escalated=0;$different=0;
        if($sessionId>0){
            $summary=json_decode((string)($session['duplicate_summary']??''),true);
            $highIds=[];
            if(is_array($summary)&&isset($summary['candidates'])&&is_array($summary['candidates'])){
                foreach($summary['candidates'] as $c){
                    $band=(string)($c['band']??($c['score_band']??''));
                    $cid=(int)($c['member_id']??($c['id']??0));
                    if($cid>0&&in_array($band,['high','very_high','certain'],true))$highIds[$cid]=true;
                }
            }
            if($highIds!==[]){
                $in=str_repeat('?,',count($highIds)-1).'?';
                $types=str_repeat('i',count($highIds));
                $sql="SELECT candidate_member_id,decision FROM telegram_candidate_decisions WHERE session_id=? AND candidate_member_id IN ($in) ORDER BY id DESC";
                $stmt=$this->conn->prepare($sql);
                if($stmt){
                    $params=[$sessionId];foreach(array_keys($highIds)as$hid)$params[]=(int)$hid;
                    $stmt->bind_param('i'.$types,...$params);$stmt->execute();$res=$stmt->get_result();
                    $latest=[];while($row=$res->fetch_assoc()){
                        $mid=(int)$row['candidate_member_id'];
                        if(!isset($latest[$mid]))$latest[$mid]=(string)$row['decision'];
                    }$stmt->close();
                    foreach(array_keys($highIds)as$hid){
                        $d=(string)($latest[$hid]??'viewed');
                        // telegram_candidate_decisions.decision enum: same|different|uncertain|viewed
                        if($d==='same')$same++;
                        elseif($d==='uncertain')$uncertain++;
                        elseif($d==='different')$different++;
                        else $unresolvedHigh++; // 'viewed' or missing → unresolved high-confidence candidate
                    }
                }
            }
            // Escalation marker from the session itself
            if((string)($session['identity_verification_decision']??'')==='escalated')$escalated=1;
        }
        if($same>0)$blockers[]='At least one high-confidence candidate has been marked as the same person — approve the existing member instead of creating a new one.';
        if($unresolvedHigh>0)$blockers[]='There are '.$unresolvedHigh.' unresolved high-confidence duplicate candidates. The operator must mark each as "same person" or "different person" before approval.';
        if($legacyDupWarn && $duplicate!=='likely')$warnings[]='Duplicate verification requires HR attention.';
        if($uncertain>0)$warnings[]=$uncertain.' candidate(s) were marked uncertain — HR should double-check before approval.';
        if($escalated>0)$warnings[]='This registration was escalated by the submitter — HR follow-up required.';

        $profile=(string)($session['profile_key']??'');
        if(in_array($profile,['direct','transfer'],true))$warnings[]='Approval will create a permanent member and requires final transactional duplicate enforcement.';
        else $warnings[]='Waiting approval will create only a temporary/waiting record.';
        return ['ready'=>$blockers===[],'blockers'=>$blockers,'warnings'=>$warnings];
    }
}
