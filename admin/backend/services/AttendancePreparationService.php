<?php
/** Complete server-scoped facts; verified adapter supplies signed auth/policy. */
namespace App\Services;

require_once __DIR__ . '/AttendancePreparationReadBoundary.php';
require_once __DIR__ . '/AttendancePreparationWorkflow.php';
require_once __DIR__ . '/EnrollmentService.php';
require_once __DIR__ . '/SchoolCalendarService.php';
require_once __DIR__ . '/RefreshTokenService.php';

final class AttendancePreparationDenied extends \DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Attendance preparation authority is not current.');
    }
}

final class AttendancePreparationService
{
    /** No request-supplied dimensions. Policy comes from existing API ACL. */
    public static function read(\mysqli $conn, array $auth, array $attendanceRoles, bool $classRestricted): array
    {
        $uid = self::id($auth['uid'] ?? null); $av = self::id($auth['av'] ?? null);
        $role = $auth['rol'] ?? null; $fid = $auth['fid'] ?? null; $rsid = $auth['rsid'] ?? null;
        if (!is_string($role) || !in_array($role, $attendanceRoles, true)
            || !is_string($fid) || !preg_match('/^[a-f0-9]{64}$/D', $fid)
            || !is_string($rsid) || !preg_match('/^[a-f0-9]{64}$/D', $rsid)) {
            throw new AttendancePreparationDenied('PREPARATION_BOUND_AUTH_REQUIRED');
        }
        $tables = ['users','academic_years','api_refresh_sessions','classes','teacher_assignments',
            'class_enrollments','members','timetable_class_days','timetable_makeup_classes',
            'school_day_offs','school_holidays'];
        $tables = array_merge($tables, AttendancePreparationWorkflow::tables());
        if (FeatureGate::isEnabled('attendance_v2')) $tables[] = 'member_department_periods';
        $issuedAt = time();
        $today = SchoolCalendarService::today();
        $knownDenial = null;
        $deny = static function (string $reason) use (&$knownDenial): never {
            $knownDenial = new AttendancePreparationDenied($reason);
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
                // Never request-static ay_active_year or closed is_current fallback.
                $years = self::rows($db, "SELECT id,start_date,end_date FROM academic_years WHERE status='active'", '', []);
                if (count($years) !== 1) throw new \RuntimeException('Preparation write year is unavailable or ambiguous.');
                $yearId = self::id($years[0]['id']);
                $yearFrom = self::date($years[0]['start_date']); $yearTo = self::date($years[0]['end_date']);
                if ($yearFrom > $today || $yearTo < $today || $yearFrom > $yearTo) {
                    $deny('PREPARATION_WRITE_YEAR_NOT_CURRENT');
                }
                $day = new \DateTimeImmutable($today, new \DateTimeZone('Africa/Addis_Ababa'));
                $from = max($yearFrom,$day->modify('-'.SchoolCalendarService::BACKDATE_DAYS.' days')->format('Y-m-d'));
                $to = min($yearTo,$day->modify('+'.SchoolCalendarService::MOBILE_FUTURE_DAYS.' days')->format('Y-m-d'));
                $dates = [];
                for ($d = new \DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
                    $dates[] = $d->format('Y-m-d');
                }
                // Exact existing restricted assignment predicate, including its
                // class-active behavior; privileged scope is active classes only.
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
                $calendar = SchoolCalendarService::preparationCalendar($db,$classIds,$yearId,$from,$to);
                $members = []; $preparedClasses = [];
                foreach ($classes as $i => $class) {
                    $classId = $classIds[$i];
                    $rosterYear = EnrollmentService::resolvePreparationRosterYear($db,$classId,$yearId);
                    $roster = EnrollmentService::fetchPreparationRosterDates($db,$classId,$rosterYear['year_id'],$dates);
                    foreach ($roster['members'] as $member) {
                        $id = $member['member_id'];
                        if (isset($members[$id]) && $members[$id] !== $member) throw new \RuntimeException('Preparation member identity is inconsistent.');
                        $members[$id] = $member;
                    }
                    $workflow = AttendancePreparationWorkflow::read($db,$classId,$yearId,$dates);
                    $days = [];
                    foreach ($dates as $j => $date) {
                        $facts = $workflow['days'][$j]; $membership = $roster['dates'][$j];
                        if ($facts['date'] !== $date || $membership['date'] !== $date) throw new \RuntimeException('Preparation date coverage is incomplete.');
                        $memberIds = $membership['member_ids']; $rosterIds = array_fill_keys($memberIds,true); $baseFitsRoster = true;
                        foreach ($facts['records'] as $record) {
                            if (!isset($rosterIds[$record['member_id']])) $baseFitsRoster = false;
                        }
                        $code = $calendar['classes'][$i]['days'][$j];
                        $days[] = ['date'=>$date,'member_ids'=>$memberIds,'calendar_code'=>$code,
                            'base_roster_compatible'=>$baseFitsRoster] + $facts;
                    }
                    $preparedClasses[] = ['class_id'=>$classId,'class_name'=>(string)$class['class_name'],
                        'class_name_en'=>(string)$class['class_name_en'],'section'=>(string)$class['section'],
                        'write_year_id'=>$yearId,'roster_year_id'=>$rosterYear['year_id'],
                        'roster_fallback'=>$rosterYear['fallback'],'days'=>$days];
                }
                // Midnight cannot mix two calendar clock windows in one response.
                if (SchoolCalendarService::today() !== $today) throw new \RuntimeException('Preparation calendar clock changed; retry.');
                ksort($members,SORT_NUMERIC);
                $yearEnd = (new \DateTimeImmutable($yearTo.' 23:59:59',new \DateTimeZone('Africa/Addis_Ababa')))->getTimestamp();
                return ['preparation_version'=>1,'complete'=>true,'owner_user_id'=>$uid,'authorization_version'=>$av,
                    'role'=>$role,'family_id'=>$fid,'issued_at'=>$issuedAt,'deadline'=>min($issuedAt+30*86400,$yearEnd),
                    'server_today'=>$today,'from'=>$from,'to'=>$to,'backdate_days'=>SchoolCalendarService::BACKDATE_DAYS,
                    'write_year'=>['id'=>$yearId,'from'=>$yearFrom,'to'=>$yearTo,'status'=>'active'],
                    'lessons_enabled'=>FeatureGate::isEnabled('attendance_v2'),
                    'members'=>array_values($members),'classes'=>$preparedClasses,'school_calendar'=>$calendar['school']];
            });
        } catch (\Throwable $error) {
            // A cleanup failure must not turn a known authority denial into a
            // transient response that could keep an older publication usable.
            // The boundary still discards an uncertain owned connection.
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
