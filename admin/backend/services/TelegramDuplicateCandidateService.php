<?php
/** Bounded, indexed duplicate candidates for Telegram review drafts. */
namespace App\Services;

final class TelegramDuplicateCandidateService
{
    /** @param array<string,string> $answers @return list<array<string,mixed>> */
    public static function find(\mysqli $conn, array $answers, int $displayLimit = 10): array
    {
        return self::findWithMeta($conn,$answers,$displayLimit)['candidates'];
    }

    /** @param array<string,string> $answers @return array{candidates:list<array<string,mixed>>,plausible_count:int,capped:bool,candidate_limit:int} */
    public static function findWithMeta(\mysqli $conn, array $answers, int $displayLimit = 10): array
    {
        $names = AmharicIdentityNormalizer::splitFullName($answers['full_name_am'] ?? '');
        if ($names['student'] === '' || $names['father'] === '') return ['candidates'=>[],'plausible_count'=>0,'capped'=>false,'candidate_limit'=>10];
        $displayLimit = max(1, min(10, $displayLimit));
        $candidateLimit = 50;
        $phone = AmharicIdentityNormalizer::phoneSuffix($answers['phone_number'] ?? '');
        [$day,$month,$year] = self::dateParts($answers['dob_ec'] ?? '');

        // Every UNION arm starts from a dedicated index. There is no leading
        // wildcard and no full-member fuzzy scan.
        $sql = "SELECT DISTINCT m.id, m.member_code, m.student_name, m.father_name,
                       m.grandfather_name, m.baptismal_name, m.gender, m.dob_ec_day,
                       m.dob_ec_month, m.dob_ec_year, m.current_section, m.status,
                       m.membership_tier, m.phone_suffix9
                FROM members m JOIN (
                  SELECT id FROM members
                   WHERE name_pair_hash=? AND name_pair_hash IS NOT NULL
                  UNION
                  SELECT id FROM members
                   WHERE student_name_norm=? AND father_name_norm=?
                  UNION
                  SELECT id FROM members
                   WHERE phone_suffix9=? AND ?<>''
                  UNION
                  SELECT id FROM members
                   WHERE dob_ec_year=? AND dob_ec_month=? AND dob_ec_day=?
                     AND student_name_norm=? AND ?>0
                  LIMIT {$candidateLimit}
                ) c ON c.id=m.id
                LIMIT {$candidateLimit}";
        $statement = $conn->prepare($sql);
        if (!$statement) throw new \RuntimeException('Could not prepare duplicate lookup.');
        $hash = $names['pair_hash'];
        $types = 'sssssiiisi';
        $statement->bind_param($types, $hash, $names['student'], $names['father'], $phone, $phone, $year, $month, $day, $names['student'], $year);
        $statement->execute();
        $result = $statement->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $candidateFull = trim(implode(' ', array_filter([
                $row['student_name'] ?? '', $row['father_name'] ?? '', $row['grandfather_name'] ?? ''
            ], static fn($v): bool => (string)$v !== '')));
            $candidate = AmharicIdentityNormalizer::splitFullName($candidateFull);
            $score = 0; $reasons = [];
            if ($candidate['full'] === $names['full']) { $score += 55; $reasons[] = 'Canonical full name matches'; }
            if ($candidate['student'] === $names['student'] && $candidate['father'] === $names['father']) {
                $score += 40; $reasons[] = 'First and father names match';
            }
            if ($names['grandfather'] !== '' && $candidate['grandfather'] === $names['grandfather']) {
                $score += 15; $reasons[] = 'Grandfather name matches';
            }
            if ($year > 0 && (int)$row['dob_ec_year'] === $year && (int)$row['dob_ec_month'] === $month && (int)$row['dob_ec_day'] === $day) {
                $score += 30; $reasons[] = 'Ethiopian date of birth matches';
            }
            if ($phone !== '' && hash_equals($phone, (string)($row['phone_suffix9'] ?? ''))) {
                $score += 35; $reasons[] = 'Phone number matches';
            }
            $baptismal = AmharicIdentityNormalizer::normalize($answers['baptismal_name'] ?? '');
            if ($baptismal !== '' && $baptismal === AmharicIdentityNormalizer::normalize($row['baptismal_name'] ?? '')) {
                $score += 10; $reasons[] = 'Christian name matches';
            }
            if (($answers['gender'] ?? '') !== '' && ($answers['gender'] ?? '') === ($row['gender'] ?? '')) $score += 3;
            if ($score < 40) continue;
            unset($row['phone_suffix9']);
            $row['full_name'] = $candidateFull;
            $row['match_score'] = min(100, $score);
            $row['match_band'] = $score >= 85 ? 'likely' : ($score >= 65 ? 'possible' : 'weak');
            $row['match_reasons'] = $reasons;
            $rows[] = $row;
        }
        $statement->close();
        usort($rows, static fn(array $a,array $b): int => ((int)$b['match_score'] <=> (int)$a['match_score']) ?: ((int)$b['id'] <=> (int)$a['id']));
        $plausibleCount=count($rows);
        return ['candidates'=>array_slice($rows,0,$displayLimit),'plausible_count'=>$plausibleCount,'capped'=>$plausibleCount>$displayLimit,'candidate_limit'=>$displayLimit];
    }

    /** @return array{0:int,1:int,2:int} */
    private static function dateParts(string $date): array
    {
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $m)) return [(int)$m[1],(int)$m[2],(int)$m[3]];
        return [0,0,0];
    }
}
