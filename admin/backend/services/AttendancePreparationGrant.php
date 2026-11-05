<?php
/** Server-only provenance; opaque HMAC is NOT a client verification key. */
namespace App\Services;
require_once __DIR__ . '/SubmissionService.php';
require_once __DIR__ . '/AttendanceRecordService.php';

final class AttendancePreparationGrant
{
    public static function serverIdentity(string $configuredSiteUrl): string
    {
        $parts = parse_url($configuredSiteUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\\\\]/', $configuredSiteUrl)
            || in_array(strtolower($parts['host']), ['localhost','127.0.0.1','[::1]'], true)) {
            throw new \RuntimeException('Configured preparation server identity unavailable.');
        }
        $path = $parts['path'] ?? '';
        if (str_contains($path,'..') || str_contains($path,'%')) throw new \RuntimeException('Preparation server path unsupported.');
        $port = isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '';
        return 'https://'.strtolower($parts['host']).$port.rtrim($path,'/').'/api/v1';
    }

    /** All facts must come from a completed coherent authorized preparation. */
    public static function issue(array $facts, string $serverIdentity, string $secret): array
    {
        self::requireKey($secret);
        // Identity is configured by the adapter, never request Host/query/body.
        if (!str_ends_with($serverIdentity,'/api/v1')
            || self::serverIdentity(substr($serverIdentity,0,-7)) !== $serverIdentity) {
            throw new \RuntimeException('Preparation server identity is not canonical.');
        }
        if (($facts['complete'] ?? false) !== true || ($facts['preparation_version'] ?? null) !== 1
            || !is_int($facts['issued_at'] ?? null) || !is_int($facts['deadline'] ?? null)
            || $facts['issued_at'] <= 0 || $facts['deadline'] <= $facts['issued_at']
            || $facts['deadline'] > $facts['issued_at']+30*86400) {
            throw new \RuntimeException('Complete current preparation facts are required.');
        }
        $preparationId = bin2hex(random_bytes(32));
        $facts['preparation_id'] = $preparationId; $facts['server_identity'] = $serverIdentity;
        $common = ['preparation_id'=>$preparationId,'server_identity'=>$serverIdentity,
            'owner_user_id'=>$facts['owner_user_id'],'authorization_version'=>$facts['authorization_version'],
            'role'=>$facts['role'],'family_id'=>$facts['family_id'],'issued_at'=>$facts['issued_at'],'deadline'=>$facts['deadline']];
        $staff = SubmissionService::staffCanOverride(['rol'=>$facts['role']]);
        foreach ($facts['classes'] as &$class) {
            foreach ($class['days'] as &$day) {
                $start = new \DateTimeImmutable($day['date'].' 00:00:00',new \DateTimeZone('Africa/Addis_Ababa'));
                $notAfter = min($facts['deadline'],$start->modify('+'.$facts['backdate_days'].' days')->setTime(23,59,59)->getTimestamp());
                $eligible = in_array($day['calendar_code'],['o','m'],true)
                    && $day['origin_matches_write_year'] && $day['base_roster_compatible']
                    && !$day['lesson_derived'] && $day['workflow_supported']
                    && ($day['teacher_workflow_open'] || $staff)
                    && count($day['member_ids']) > 0 && count($day['member_ids']) <= AttendanceRecordService::MAX_RECORDS
                    && $start->getTimestamp() <= $notAfter;
                $workflow = ['packet'=>$day['packet'],'resolved_status'=>$day['resolved_status'],
                    'workflow_supported'=>$day['workflow_supported'],'lesson_derived'=>$day['lesson_derived'],
                    'origin_year_ids'=>$day['origin_year_ids'],'origin_unknown'=>$day['origin_unknown'],
                    'origin_matches_write_year'=>$day['origin_matches_write_year']];
                $context = $common + ['kind'=>'day','class_id'=>$class['class_id'],
                    'write_year_id'=>$class['write_year_id'],'roster_year_id'=>$class['roster_year_id'],
                    'date'=>$day['date'],'roster_digest'=>self::digest($day['member_ids']),
                    'base_digest'=>self::digest($day['records']),'workflow_digest'=>self::digest($workflow),
                    'calendar_code'=>$day['calendar_code'],'capture_eligible'=>$eligible,
                    'not_before'=>$start->getTimestamp(),'not_after'=>$notAfter];
                $day['context_token'] = self::sign($context,'day',$secret);
                $day['capture_eligible'] = $eligible;
                $day['not_before'] = $context['not_before']; $day['not_after'] = $notAfter;
            }
            unset($day);
        }
        unset($class);
        $payload = self::json($facts);
        $grant = $common + ['kind'=>'root','payload_sha256'=>hash('sha256',$payload)];
        // Preserve exact UTF8 bytes for digest + protected local integrity.
        // Clients never re-encode JSON to validate this transport digest.
        return ['preparation_version'=>1,'payload'=>self::encode($payload),
            'grant'=>self::sign($grant,'root',$secret),
            'authorizes_settlement'=>false];
    }

    /** Cryptographic provenance ONLY, not current scope, expiry or permission. */
    public static function inspect(string $token, string $kind, string $secret): array
    {
        self::requireKey($secret);
        if (!in_array($kind,['root','day'],true) || strlen($token)>16384
            || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D',$token,$parts)
            || !hash_equals(self::mac($parts[1],$kind,$secret),$parts[2])) {
            throw new \RuntimeException('Preparation provenance is invalid.');
        }
        $raw = base64_decode(strtr($parts[1],'-_','+/'),true);
        if ($raw === false || self::encode($raw) !== $parts[1]) throw new \RuntimeException('Preparation provenance encoding invalid.');
        $claims = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($claims) || ($claims['kind'] ?? null) !== $kind) throw new \RuntimeException('Preparation provenance kind invalid.');
        return $claims;
    }

    private static function sign(array $claims,string $kind,string $secret): string
    {
        $body = self::encode(self::json($claims));
        return $body.'.'.self::mac($body,$kind,$secret);
    }
    private static function mac(string $body,string $kind,string $secret): string
    {
        return hash_hmac('sha256',"ssms-attendance-preparation:".$kind.":v1\0".$body,$secret);
    }
    private static function requireKey(string $secret): void
    {
        if (strlen($secret)<32) throw new \RuntimeException('Preparation signing unavailable.');
    }
    private static function encode(string $bytes): string { return rtrim(strtr(base64_encode($bytes),'+/','-_'),'='); }
    private static function json(array $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    private static function digest(array $value): string { return hash('sha256',self::json($value)); }
}
