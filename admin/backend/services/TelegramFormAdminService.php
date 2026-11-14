<?php
/** Versioned administration for closed-registry Telegram forms. */
namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class TelegramFormAdminService
{
    public function __construct(private \mysqli $conn) {}

    /** @return array<string,mixed>|null */
    public function detail(int $id): ?array
    {
        $q=$this->conn->prepare('SELECT * FROM telegram_form_profiles WHERE id=?');$q->bind_param('i',$id);$q->execute();$profile=$q->get_result()->fetch_assoc();$q->close();
        if(!$profile)return null;
        $q=$this->conn->prepare('SELECT id,field_key,is_enabled,is_required,sort_order,prompt_am,prompt_en,help_am,help_en FROM telegram_form_fields WHERE profile_id=? ORDER BY sort_order,id');$q->bind_param('i',$id);$q->execute();$profile['fields']=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();return $profile;
    }

    /** Clone latest version into a new draft; published snapshots never mutate. */
    public function createDraft(string $profileKey,int $actor): array
    {
        if(!in_array($profileKey,['waiting','direct','transfer'],true))throw new InvalidArgumentException('Invalid form profile.');
        $this->conn->begin_transaction();
        try{
            $q=$this->conn->prepare('SELECT id,version,title_am,title_en FROM telegram_form_profiles WHERE profile_key=? ORDER BY version DESC LIMIT 1 FOR UPDATE');$q->bind_param('s',$profileKey);$q->execute();$source=$q->get_result()->fetch_assoc();$q->close();
            $version=(int)($source['version']??0)+1;$titleAm=(string)($source['title_am']??($profileKey==='waiting'?'ጊዜያዊ ምዝገባ':'ቀጥታ ምዝገባ'));$titleEn=(string)($source['title_en']??($profileKey==='waiting'?'Temporary Registration':'Direct Registration'));
            $q=$this->conn->prepare("INSERT INTO telegram_form_profiles(profile_key,version,status,title_am,title_en) VALUES(?,?,'draft',?,?)");$q->bind_param('siss',$profileKey,$version,$titleAm,$titleEn);$q->execute();$id=(int)$this->conn->insert_id;$q->close();
            if($source){$sourceId=(int)$source['id'];$q=$this->conn->prepare('INSERT INTO telegram_form_fields(profile_id,field_key,is_enabled,is_required,sort_order,prompt_am,prompt_en,help_am,help_en) SELECT ?,field_key,is_enabled,is_required,sort_order,prompt_am,prompt_en,help_am,help_en FROM telegram_form_fields WHERE profile_id=? ORDER BY sort_order,id');$q->bind_param('ii',$id,$sourceId);$q->execute();$q->close();}
            $this->conn->commit();return $this->detail($id)??throw new RuntimeException('Draft was not created.');
        }catch(\Throwable $e){$this->conn->rollback();throw $e;}
    }

    /** @param list<array<string,mixed>> $fields */
    public function saveDraft(int $id,string $titleAm,string $titleEn,array $fields): array
    {
        $titleAm=self::text($titleAm,150,'Amharic title');$titleEn=self::text($titleEn,150,'English title');
        if($titleAm===''||$titleEn==='')throw new InvalidArgumentException('Both titles are required.');
        if(count($fields)>count(TelegramFormRegistry::keys()))throw new InvalidArgumentException('Too many fields.');
        $seen=[];$clean=[];
        foreach($fields as $index=>$field){if(!is_array($field))throw new InvalidArgumentException('Invalid field payload.');$key=(string)($field['field_key']??'');if(!TelegramFormRegistry::exists($key)||isset($seen[$key]))throw new InvalidArgumentException('Unsupported or duplicate field.');$seen[$key]=true;$enabled=!empty($field['is_enabled'])?1:0;$required=!empty($field['is_required'])?1:0;if($required&&!$enabled)throw new InvalidArgumentException('A required field must be enabled.');$clean[]=['key'=>$key,'enabled'=>$enabled,'required'=>$required,'sort'=>($index+1)*10,'prompt_am'=>self::text($field['prompt_am']??'',500,'Amharic prompt'),'prompt_en'=>self::text($field['prompt_en']??'',500,'English prompt'),'help_am'=>self::nullableText($field['help_am']??'',500),'help_en'=>self::nullableText($field['help_en']??'',500)];if($enabled&&($clean[array_key_last($clean)]['prompt_am']===''||$clean[array_key_last($clean)]['prompt_en']===''))throw new InvalidArgumentException('Enabled fields require both prompts.');}
        $this->conn->begin_transaction();
        try{$q=$this->conn->prepare("SELECT status FROM telegram_form_profiles WHERE id=? FOR UPDATE");$q->bind_param('i',$id);$q->execute();$profile=$q->get_result()->fetch_assoc();$q->close();if(!$profile)throw new InvalidArgumentException('Form not found.');if($profile['status']!=='draft')throw new InvalidArgumentException('Published or retired versions are immutable.');$q=$this->conn->prepare('UPDATE telegram_form_profiles SET title_am=?,title_en=? WHERE id=?');$q->bind_param('ssi',$titleAm,$titleEn,$id);$q->execute();$q->close();$q=$this->conn->prepare('DELETE FROM telegram_form_fields WHERE profile_id=?');$q->bind_param('i',$id);$q->execute();$q->close();$insert=$this->conn->prepare('INSERT INTO telegram_form_fields(profile_id,field_key,is_enabled,is_required,sort_order,prompt_am,prompt_en,help_am,help_en) VALUES(?,?,?,?,?,?,?,?,?)');foreach($clean as $f){$insert->bind_param('isiiissss',$id,$f['key'],$f['enabled'],$f['required'],$f['sort'],$f['prompt_am'],$f['prompt_en'],$f['help_am'],$f['help_en']);$insert->execute();}$insert->close();$this->conn->commit();return $this->detail($id)??throw new RuntimeException('Draft disappeared.');}catch(\Throwable $e){$this->conn->rollback();throw $e;}
    }

    public function publish(int $id,int $actor): array
    {
        $this->conn->begin_transaction();
        try{$q=$this->conn->prepare("SELECT profile_key,status FROM telegram_form_profiles WHERE id=? FOR UPDATE");$q->bind_param('i',$id);$q->execute();$profile=$q->get_result()->fetch_assoc();$q->close();if(!$profile||$profile['status']!=='draft')throw new InvalidArgumentException('Only a draft can be published.');$q=$this->conn->prepare("SELECT field_key,is_required,prompt_am,prompt_en FROM telegram_form_fields WHERE profile_id=? AND is_enabled=1 ORDER BY sort_order,id");$q->bind_param('i',$id);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();$enabled=array_column($rows,'field_key');foreach(['full_name_am','gender'] as $required){$index=array_search($required,$enabled,true);if($index===false||(int)$rows[$index]['is_required']!==1)throw new InvalidArgumentException('Full name and gender must be enabled and required.');}foreach($rows as $row)if(trim((string)$row['prompt_am'])===''||trim((string)$row['prompt_en'])==='')throw new InvalidArgumentException('Every enabled field needs bilingual prompts.');$key=(string)$profile['profile_key'];$q=$this->conn->prepare("UPDATE telegram_form_profiles SET status='retired' WHERE profile_key=? AND status='published'");$q->bind_param('s',$key);$q->execute();$q->close();$q=$this->conn->prepare("UPDATE telegram_form_profiles SET status='published',published_by=?,published_at=NOW() WHERE id=? AND status='draft'");$q->bind_param('ii',$actor,$id);$q->execute();if($q->affected_rows!==1)throw new RuntimeException('Publish conflict.');$q->close();$this->conn->commit();return $this->detail($id)??throw new RuntimeException('Published form disappeared.');}catch(\Throwable $e){$this->conn->rollback();throw $e;}
    }

    private static function text($value,int $max,string $label): string {if(!is_scalar($value))throw new InvalidArgumentException($label.' is invalid.');$value=trim((string)preg_replace('/\s+/u',' ',(string)$value));$length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);if(preg_match('//u',$value)!==1||preg_match('/[\x00-\x1F\x7F]/',$value)||$length>$max)throw new InvalidArgumentException($label.' is invalid or too long.');return $value;}
    private static function nullableText($value,int $max): ?string {$text=self::text($value,$max,'Help text');return $text===''?null:$text;}
}
