<?php
/** Maintains versioned, lossy search keys without changing original member data. */
namespace App\Services;

final class MemberCanonicalIdentityService
{
    public const VERSION = AmharicIdentityNormalizer::VERSION;

    public static function available(\mysqli $conn): bool
    {
        $result = $conn->query("SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='members' AND column_name IN ('name_norm_version','student_name_norm','father_name_norm','grandfather_name_norm','name_pair_hash','phone_suffix9')");
        $row = $result ? $result->fetch_assoc() : null;
        return (int)($row['n'] ?? 0) === 6;
    }

    /** Refresh one member after any registration/import/name/phone update. */
    public static function refresh(\mysqli $conn, int $memberId): bool
    {
        if ($memberId <= 0 || !self::available($conn)) return false;
        $select=$conn->prepare('SELECT student_name,father_name,grandfather_name,phone_number FROM members WHERE id=? LIMIT 1');
        if (!$select) return false;
        $select->bind_param('i',$memberId);$select->execute();$member=$select->get_result()->fetch_assoc();$select->close();
        if (!$member) return false;
        $student=AmharicIdentityNormalizer::normalize((string)($member['student_name']??''));
        $father=AmharicIdentityNormalizer::normalize((string)($member['father_name']??''));
        $grandfather=AmharicIdentityNormalizer::normalize((string)($member['grandfather_name']??''));
        $hash=($student!==''&&$father!=='')?AmharicIdentityNormalizer::pairHash($student,$father):null;
        $phone=AmharicIdentityNormalizer::phoneSuffix((string)($member['phone_number']??''));
        $version=self::VERSION;
        $update=$conn->prepare('UPDATE members SET name_norm_version=?,student_name_norm=NULLIF(?,\'\'),father_name_norm=NULLIF(?,\'\'),grandfather_name_norm=NULLIF(?,\'\'),name_pair_hash=?,phone_suffix9=NULLIF(?,\'\') WHERE id=?');
        if (!$update) throw new \RuntimeException('Could not prepare canonical identity update.');
        $update->bind_param('isssssi',$version,$student,$father,$grandfather,$hash,$phone,$memberId);
        $update->execute();$changed=$update->affected_rows>=0;$update->close();return $changed;
    }

    /**
     * Bounded resumable backfill. Cursor is the last examined member id.
     * @return array{processed:int,updated:int,next_cursor:int,done:bool}
     */
    public static function backfill(\mysqli $conn, int $afterId=0, int $limit=100): array
    {
        if (!self::available($conn)) return ['processed'=>0,'updated'=>0,'next_cursor'=>max(0,$afterId),'done'=>true];
        $afterId=max(0,$afterId);$limit=max(1,min(500,$limit));$version=self::VERSION;
        $statement=$conn->prepare("SELECT id FROM members WHERE id>? AND (name_norm_version IS NULL OR name_norm_version<>?) ORDER BY id LIMIT {$limit}");
        $statement->bind_param('ii',$afterId,$version);$statement->execute();$result=$statement->get_result();
        $ids=[];while($row=$result->fetch_assoc())$ids[]=(int)$row['id'];$statement->close();
        $updated=0;$cursor=$afterId;
        foreach($ids as $id){$cursor=$id;if(self::refresh($conn,$id))$updated++;}
        return ['processed'=>count($ids),'updated'=>$updated,'next_cursor'=>$cursor,'done'=>count($ids)<$limit];
    }
}
