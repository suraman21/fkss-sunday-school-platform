<?php
/** Transactional, idempotent creation of one member from one HR-approved Telegram draft. */
namespace App\Services;

use DomainException;
use RuntimeException;

final class TelegramMemberApprovalService
{
    public function __construct(private \mysqli $conn) {}

    /** @return array{member_id:int,already_approved:bool,profile:string} */
    public function approve(int $sessionId,int $reviewerId,string $note): array
    {
        if($sessionId<1||$reviewerId<1||trim($note)===''||strlen($note)>1000)throw new \InvalidArgumentException('A valid draft and approval note are required.');
        $lock=null;$this->conn->begin_transaction();
        try{
            $q=$this->conn->prepare('SELECT * FROM telegram_registration_sessions WHERE id=? FOR UPDATE');$q->bind_param('i',$sessionId);$q->execute();$session=$q->get_result()->fetch_assoc();$q->close();
            if(!$session)throw new \InvalidArgumentException('Draft not found.');
            if((int)($session['created_member_id']??0)>0){$this->conn->commit();return ['member_id'=>(int)$session['created_member_id'],'already_approved'=>true,'profile'=>(string)$session['profile_key']];}
            $q=$this->conn->prepare('SELECT field_key,value_text,value_normalized FROM telegram_registration_answers WHERE session_id=? ORDER BY id');$q->bind_param('i',$sessionId);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            $ready=(new TelegramApprovalReadinessService($this->conn))->inspect($session,$rows);if(!$ready['ready'])throw new DomainException('Approval preflight is blocked: '.implode(' ',$ready['blockers']));
            $a=[];foreach($rows as $row)$a[(string)$row['field_key']]=$row;$q=$this->conn->prepare("SELECT field_key,stored_path FROM telegram_registration_attachments WHERE session_id=? AND status='stored' FOR UPDATE");$q->bind_param('i',$sessionId);$q->execute();$fileRows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();$files=[];foreach($fileRows as $file)$files[(string)$file['field_key']]=(string)$file['stored_path'];
            $parts=preg_split('/\s+/u',trim((string)$a['full_name_am']['value_text']),3,PREG_SPLIT_NO_EMPTY)?:[];
            $dob=[null,null,null];if(!empty($a['dob_ec']['value_normalized'])&&preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/',(string)$a['dob_ec']['value_normalized'],$m))$dob=[(int)$m[1],(int)$m[2],(int)$m[3]];
            // Prefer the separately-collected father_name if provided; fall back to full-name parsing.
            $fatherName = trim((string)($a['father_name']['value_text'] ?? ''));
            if ($fatherName === '') $fatherName = (string)($parts[1] ?? '');
            $identity=['student_name'=>$parts[0]??'','father_name'=>$fatherName,'grandfather_name'=>$parts[2]??'','dob_ec_day'=>$dob[0],'dob_ec_month'=>$dob[1],'dob_ec_year'=>$dob[2],'phone_number'=>(string)($a['phone_number']['value_text']??'')];
            $lock=MemberDuplicateService::acquireStrongIdentityLock($this->conn,$identity,5);$duplicate=MemberDuplicateService::findStrongMatch($this->conn,$identity,0,true);if($duplicate)throw new DomainException('A strongly matching member already exists. Mark the draft as duplicate or investigate the existing member.');
            $profile=(string)$session['profile_key'];$registration=$profile==='waiting'?'waiting':($profile==='transfer'?'transfer':'direct');$tier=$profile==='waiting'?'temporary':'permanent';$waiting=$profile==='waiting'?date('Y-m-d'):null;$today=date('Y-m-d');
            $data=['member_code'=>null,'registration_type'=>$registration,'member_type'=>'regular','status'=>'active','membership_tier'=>$tier,'full_name_am'=>trim((string)$a['full_name_am']['value_text']),'student_name'=>$parts[0],'father_name'=>$parts[1],'mother_name'=>$a['mother_name']['value_text']??null,'grandfather_name'=>$parts[2]??null,'baptismal_name'=>$a['baptismal_name']['value_text']??null,'gender'=>$a['gender']['value_normalized'],'dob_ec_day'=>$dob[0],'dob_ec_month'=>$dob[1],'dob_ec_year'=>$dob[2],'current_section'=>$a['current_section']['value_normalized']??null,'education_level'=>$a['education_level']['value_normalized']??null,'spiritual_education'=>$a['spiritual_education']['value_normalized']??null,'city'=>$a['city']['value_text']??null,'sub_city'=>$a['sub_city']['value_text']??null,'woreda'=>$a['woreda']['value_text']??null,'mender'=>$a['mender']['value_text']??null,'block_number'=>$a['block_number']['value_text']??null,'house_number'=>$a['house_number']['value_text']??null,'work_profession'=>$a['work_profession']['value_text']??null,'phone_number'=>$a['phone_number']['value_text']??null,'alt_phone_number'=>$a['alt_phone_number']['value_text']??null,'guardian_name'=>$a['guardian_name']['value_text']??null,'guardian_phone1'=>$a['guardian_phone1']['value_text']??null,'guardian_phone2'=>$a['guardian_phone2']['value_text']??null,'guardian_city'=>$a['guardian_city']['value_text']??null,'guardian_sub_city'=>$a['guardian_sub_city']['value_text']??null,'guardian_woreda'=>$a['guardian_woreda']['value_text']??null,'guardian_mender'=>$a['guardian_mender']['value_text']??null,'guardian_block_number'=>$a['guardian_block_number']['value_text']??null,'guardian_house'=>$a['guardian_house']['value_text']??null,'student_photo_path'=>$files['student_photo']??null,'guardian_photo_path'=>$files['guardian_photo']??null,'doc_school_records_path'=>$files['doc_school_records']??null,'doc_spiritual_path'=>$files['doc_spiritual']??null,'doc_signed_form_path'=>$files['doc_signed_form']??null,'waiting_since'=>$waiting,'registered_at'=>$today];
            $columns=array_keys($data);$sql='INSERT INTO members (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';$q=$this->conn->prepare($sql);$types='';$values=[];foreach($data as $value){if(is_int($value)){$types.='i';$values[]=$value;}else{$types.='s';$values[]=$value;}}$q->bind_param($types,...$values);$q->execute();$memberId=(int)$this->conn->insert_id;$q->close();if($memberId<1)throw new RuntimeException('Member creation failed.');
            if(!MemberCanonicalIdentityService::refresh($this->conn,$memberId)&&MemberCanonicalIdentityService::available($this->conn))throw new RuntimeException('Canonical identity refresh failed.');
            $revision=(int)$session['revision_number'];$q=$this->conn->prepare("INSERT INTO telegram_registration_reviews(session_id,session_revision,decision,reviewer_user_id,member_id,note) VALUES(?,?,'approved',?,?,?)");$q->bind_param('iiiis',$sessionId,$revision,$reviewerId,$memberId,$note);$q->execute();$q->close();
            $q=$this->conn->prepare("UPDATE telegram_registration_sessions SET status='approved',created_member_id=?,current_field_key=NULL,lock_version=lock_version+1 WHERE id=? AND created_member_id IS NULL");$q->bind_param('ii',$memberId,$sessionId);$q->execute();if($q->affected_rows!==1)throw new RuntimeException('Approval state conflict.');$q->close();$q=$this->conn->prepare("UPDATE telegram_registration_attachments SET status='attached',updated_at=NOW() WHERE session_id=? AND status='stored'");$q->bind_param('i',$sessionId);$q->execute();$q->close();
            $this->conn->commit();MemberDuplicateService::releaseIdentityLock($this->conn,$lock);return ['member_id'=>$memberId,'already_approved'=>false,'profile'=>$profile];
        }catch(\Throwable $e){$this->conn->rollback();MemberDuplicateService::releaseIdentityLock($this->conn,$lock);throw $e;}
    }
}
