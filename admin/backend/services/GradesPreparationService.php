<?php
/** Complete server-scoped grades facts; verified adapter supplies signed auth/policy. */
namespace App\Services;

require_once __DIR__ . '/AttendancePreparationReadBoundary.php';
require_once __DIR__ . '/EnrollmentService.php';
require_once __DIR__ . '/SchoolCalendarService.php';
require_once __DIR__ . '/RefreshTokenService.php';

final class GradesPreparationDenied extends \DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Grades preparation authority is not current.');
    }
}

final class GradesPreparationService
{
    /** No request-supplied dimensions. Policy comes from existing API ACL. */
    public static function read(\mysqli $conn, array $auth, array $gradesRoles, bool $classRestricted): array
    {
        $uid = self::id($auth['uid'] ?? null); $av = self::id($auth['av'] ?? null);
        $role = $auth['rol'] ?? null; $fid = $auth['fid'] ?? null; $rsid = $auth['rsid'] ?? null;
        if (!is_string($role) || !in_array($role, $gradesRoles, true)
            || !is_string($fid) || !preg_match('/^[a-f0-9]{64}$/D', $fid)
            || !is_string($rsid) || !preg_match('/^[a-f0-9]{64}$/D', $rsid)) {
            throw new GradesPreparationDenied('PREPARATION_BOUND_AUTH_REQUIRED');
        }
        $tables = ['users','academic_years','api_refresh_sessions','classes','teacher_assignments',
            'class_enrollments','members','subjects','assessments','academic_terms'];
        $issuedAt = time();
        $today = SchoolCalendarService::today();
        $knownDenial = null;
        $deny = static function (string $reason) use (&$knownDenial): never {
            $knownDenial = new GradesPreparationDenied($reason);
            throw $knownDenial;
        };
        try {
        return AttendancePreparationReadBoundary::read($conn, $tables,
            static function (\mysqli $db) use ($uid,$av,$role,$fid,$rsid,$classRestricted,$issuedAt,$today,$deny): array {
                $users = self::rows($db, 'SELECT id,role,is_active,authorization_version FROM users WHERE id=?', 'i', [$uid]);
                if (count($users) !== 1 || (int)$users[0]['is_active'] !== 1) {
                    $deny('PREPARATION_ACCOUNT_INACTIVE');
                }
                if ($users[0]['role'] !== $role || self::id($users[0]['authorization_version']) !== $av) {
                    $deny('PREPARATION_SCOPE_CHANGED');
                }
                if (!RefreshTokenService::accessSessionIsActive($db,$uid,$fid,$rsid)) {
                    $deny('SESSION_REVOKED');
                }
                $years = self::rows($db, "SELECT id,start_date,end_date FROM academic_years WHERE status='active'", '', []);
                if (count($years) !== 1) throw new \RuntimeException('Preparation write year is unavailable or ambiguous.');
                $yearId = self::id($years[0]['id']);
                $yearFrom = self::date($years[0]['start_date']); $yearTo = self::date($years[0]['end_date']);
                if ($yearFrom > $today || $yearTo < $today || $yearFrom > $yearTo) {
                    $deny('PREPARATION_WRITE_YEAR_NOT_CURRENT');
                }
                $classSql = 'SELECT DISTINCT c.id,c.class_name,c.class_name_en,c.section FROM classes c';
                if ($classRestricted) {
                    $classes = self::rows($db,$classSql.' JOIN teacher_assignments ta ON ta.class_id=c.id
                        WHERE ta.teacher_id=? AND ta.is_active=1
                          AND (ta.academic_year_id IS NULL OR ta.academic_year_id=?) ORDER BY c.id','ii',[$uid,$yearId]);
                } else {
                    $classes = self::rows($db,$classSql.' WHERE c.is_active=1 ORDER BY c.id','',[]);
                }
                $classIds = []; $seen = [];
                foreach ($classes as $class) {
                    $id = self::id($class['id']);
                    if (isset($seen[$id])) throw new \RuntimeException('Preparation class identities are ambiguous.');
                    $seen[$id] = true; $classIds[] = $id;
                }
                $terms = [];
                try {
                    $terms = self::rows($db, 'SELECT id,term_name,term_number,is_current,is_reopened FROM academic_terms WHERE academic_year_id=? ORDER BY term_number', 'i', [$yearId]);
                    foreach ($terms as &$t) { $t['id']=(int)$t['id']; $t['term_number']=(int)$t['term_number']; $t['is_current']=(bool)$t['is_current']; $t['is_reopened']=(bool)$t['is_reopened']; }
                    unset($t);
                } catch (\Throwable $_) { $terms = []; }
                $members = []; $preparedClasses = [];
                foreach ($classes as $i => $class) {
                    $classId = $classIds[$i];
                    $rosterYear = EnrollmentService::resolvePreparationRosterYear($db,$classId,$yearId);
                    $from = max($yearFrom, (new \DateTimeImmutable($today))->modify('-'.SchoolCalendarService::BACKDATE_DAYS.' days')->format('Y-m-d'));
                    $to = min($yearTo, (new \DateTimeImmutable($today))->modify('+'.SchoolCalendarService::MOBILE_FUTURE_DAYS.' days')->format('Y-m-d'));
                    $dates = [];
                    for ($d = new \DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) { $dates[] = $d->format('Y-m-d'); }
                    $roster = EnrollmentService::fetchPreparationRosterDates($db,$classId,$rosterYear['year_id'],$dates);
                    $uniqueMemberIds = [];
                    foreach ($roster['dates'] as $day) { foreach ($day['member_ids'] as $mid) $uniqueMemberIds[$mid]=true; }
                    foreach ($roster['members'] as $member) { $members[$member['member_id']] = $member; }
                    $subjects = [];
                    if ($classRestricted) {
                        $subjects = self::rows($db,
                            'SELECT DISTINCT s.id,s.subject_name,s.subject_name_en,s.subject_code FROM teacher_assignments ta JOIN subjects s ON ta.subject_id=s.id WHERE ta.teacher_id=? AND ta.class_id=? AND (ta.academic_year_id IS NULL OR ta.academic_year_id=?) AND ta.is_active=1 AND s.is_active=1 ORDER BY s.subject_name',
                            'iii', [$uid,$classId,$yearId]);
                    } else {
                        try {
                            if (!class_exists('\\App\\Services\\AssignmentService')) require_once __DIR__ . '/AssignmentService.php';
                            $catalog = \App\Services\AssignmentService::subjectsForClass($db,$classId);
                            foreach ($catalog['subjects'] as $s) $subjects[] = ['id'=>$s['id'],'subject_name'=>$s['subject_name'],'subject_name_en'=>$s['subject_name_en']??null,'subject_code'=>$s['subject_code']??null];
                        } catch (\Throwable $_) {
                            $subjects = self::rows($db, 'SELECT id,subject_name,subject_name_en,subject_code FROM subjects WHERE is_active=1 ORDER BY subject_name','',[]);
                        }
                    }
                    foreach ($subjects as &$s) { $s['id']=(int)$s['id']; } unset($s);
                    $subjectIds = array_map(fn($s)=>(int)$s['id'], $subjects);
                    $assessments = [];
                    if ($subjectIds) {
                        $in = implode(',', $subjectIds);
                        $assessments = self::rows($db,
                            "SELECT a.id,a.subject_id,a.assessment_name,a.assessment_type,a.weight_percentage,a.max_score,a.assessment_order,a.is_published FROM assessments a WHERE a.class_id=? AND a.academic_year_id=? AND a.subject_id IN ($in) ORDER BY a.subject_id,a.assessment_order,a.created_at",
                            'ii', [$classId,$yearId]);
                        foreach ($assessments as &$a) {
                            $a['id']=(int)$a['id']; $a['subject_id']=(int)$a['subject_id']; $a['max_score']=(float)$a['max_score']; $a['weight_percentage']=(float)$a['weight_percentage']; $a['assessment_order']=(int)$a['assessment_order']; $a['is_published']=(bool)$a['is_published'];
                        } unset($a);
                    }
                    $preparedClasses[] = ['class_id'=>$classId,'class_name'=>(string)$class['class_name'],
                        'class_name_en'=>(string)$class['class_name_en'],'section'=>(string)$class['section'],
                        'write_year_id'=>$yearId,'roster_year_id'=>$rosterYear['year_id'],
                        'roster_fallback'=>$rosterYear['fallback'],'member_ids'=>array_keys($uniqueMemberIds),
                        'subjects'=>$subjects,'assessments'=>$assessments];
                }
                if (SchoolCalendarService::today() !== $today) throw new \RuntimeException('Preparation calendar clock changed; retry.');
                ksort($members,SORT_NUMERIC);
                $yearEnd = (new \DateTimeImmutable($yearTo.' 23:59:59',new \DateTimeZone('Africa/Addis_Ababa')))->getTimestamp();
                return ['preparation_version'=>1,'complete'=>true,'owner_user_id'=>$uid,'authorization_version'=>$av,
                    'role'=>$role,'family_id'=>$fid,'issued_at'=>$issuedAt,'deadline'=>min($issuedAt+30*86400,$yearEnd),
                    'server_today'=>$today,'write_year'=>['id'=>$yearId,'from'=>$yearFrom,'to'=>$yearTo,'status'=>'active'],
                    'terms'=>$terms,'members'=>array_values($members),'classes'=>$preparedClasses];
            });
        } catch (\Throwable $error) {
            if ($knownDenial !== null) throw $knownDenial;
            throw $error;
        }
    }

    private static function id(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]*$/D',(string)$value)
            || (float)$value > 9007199254740991) throw new \RuntimeException('Preparation identity unavailable.');
        return (int)$value;
    }

    private static function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)
            || !checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4))) {
            throw new \RuntimeException('Preparation year boundaries are unavailable.');
        }
        return $value;
    }

    private static function rows(\mysqli $db,string $sql,string $types,array $params): array
    {
        $stmt = null;
        try {
            $stmt = $db->prepare($sql);
            if (!$stmt || ($types !== '' && !$stmt->bind_param($types,...$params)) || !$stmt->execute()) throw new \RuntimeException('Preparation query failed.');
            $result = $stmt->get_result();
            if (!$result) throw new \RuntimeException('Preparation result unavailable.');
            try { return $result->fetch_all(MYSQLI_ASSOC); } finally { $result->free(); }
        } finally { if ($stmt) $stmt->close(); }
    }
}
