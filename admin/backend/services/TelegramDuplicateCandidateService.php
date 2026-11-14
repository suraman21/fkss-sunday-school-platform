<?php
/** Bounded, indexed duplicate candidates for Telegram review drafts (v2). */
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
        $bapNorm = AmharicIdentityNormalizer::normalize($answers['baptismal_name'] ?? '');
        $gender = (string)($answers['gender'] ?? '');
        $motherNorm = AmharicIdentityNormalizer::normalize($answers['mother_name'] ?? '');

        $sql = "SELECT DISTINCT m.id, m.member_code, m.student_name, m.father_name,
                       m.mother_name, m.grandfather_name, m.baptismal_name, m.gender,
                       m.dob_ec_day, m.dob_ec_month, m.dob_ec_year, m.current_section,
                       m.status, m.membership_tier, m.phone_suffix9, m.phone_number,
                       m.age, m.education_level, m.member_type, m.registration_type,
                       m.registered_at
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
                  UNION
                  SELECT id FROM members
                   WHERE student_name_norm=? AND ?>0
                  LIMIT {$candidateLimit}
                ) c ON c.id=m.id
                LIMIT {$candidateLimit}";
        $statement = $conn->prepare($sql);
        if (!$statement) throw new \RuntimeException('Could not prepare duplicate lookup.');
        $hash = $names['pair_hash'];
        $types = 'sssssiiisiss';
        $statement->bind_param($types,
            $hash,
            $names['student'], $names['father'],
            $phone, $phone,
            $year, $month, $day, $names['student'], $year,
            $names['student'], $names['student']
        );
        $statement->execute();
        $result = $statement->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $candidateFull = trim(implode(' ', array_filter([
                $row['student_name'] ?? '', $row['father_name'] ?? '', $row['grandfather_name'] ?? ''
            ], static fn($v): bool => (string)$v !== '')));
            $candidate = AmharicIdentityNormalizer::splitFullName($candidateFull);
            $candMotherNorm = AmharicIdentityNormalizer::normalize($row['mother_name'] ?? '');
            $candBapNorm = AmharicIdentityNormalizer::normalize($row['baptismal_name'] ?? '');
            $score = 0; $reasons = [];
            if ($candidate['full'] === $names['full']) { $score += 55; $reasons[] = 'ሙሉ ስም ተመሳሳይ'; }
            if ($candidate['student'] === $names['student'] && $candidate['father'] === $names['father']) {
                $score += 40; $reasons[] = 'የራስ እና የአባት ስም ተመሳሳይ';
            }
            if ($candidate['student'] === $names['student']) { $score += 10; $reasons[] = 'የመጀመሪያ ስም ተመሳሳይ'; }
            if ($names['grandfather'] !== '' && $candidate['grandfather'] === $names['grandfather']) {
                $score += 15; $reasons[] = 'የአያት ስም ተመሳሳይ';
            }
            if ($year > 0 && (int)$row['dob_ec_year'] === $year && (int)$row['dob_ec_month'] === $month && (int)$row['dob_ec_day'] === $day) {
                $score += 30; $reasons[] = 'የትውልድ ቀን ተመሳሳይ';
            }
            if ($phone !== '' && hash_equals($phone, (string)($row['phone_suffix9'] ?? ''))) {
                $score += 35; $reasons[] = 'ስልክ ቁጥር ተመሳሳይ';
            }
            if ($bapNorm !== '' && $bapNorm === $candBapNorm) {
                $score += 10; $reasons[] = 'የክርስትና ስም ተመሳሳይ';
            }
            if ($motherNorm !== '' && $candMotherNorm !== '' && $motherNorm === $candMotherNorm) {
                $score += 20; $reasons[] = 'የእናት ስም ተመሳሳይ';
            }
            if ($gender !== '' && $gender === ($row['gender'] ?? '')) $score += 3;
            if ($score < 25) continue;
            // department info
            $eduStatus = (string)($row['education_level'] ?? '');
            $secStatus = (string)($row['current_section'] ?? '');
            unset($row['phone_suffix9']);
            $dob = '';
            if (!empty($row['dob_ec_year'])) $dob = sprintf('%02d/%02d/%04d',(int)$row['dob_ec_day'],(int)$row['dob_ec_month'],(int)$row['dob_ec_year']);
            $age = (int)($row['age'] ?? 0);
            $phoneDisplay = '';
            if (!empty($row['phone_number'])) {
                $pn = (string)$row['phone_number'];
                $phoneDisplay = mb_strlen($pn) >= 7 ? mb_substr($pn,0,4).'***'.mb_substr($pn,-3) : $pn;
            }
            $memberCode = (string)($row['member_code'] ?? ('M'.str_pad((string)$row['id'],5,'0',STR_PAD_LEFT)));
            $statusLabel = match((string)$row['status']) {
                'active' => 'ንቁ', 'warning' => 'ማስጠንቀቂያ', 'inactive' => 'እንቅስቃሴ-አልባ',
                'archived' => 'የተዘጋ', 'closed' => 'የተዘጋ', 'upcoming' => 'መጪ', default=>(string)$row['status']
            };
            $matchBand = $score >= 70 ? 'high' : ($score >= 45 ? 'medium' : 'low');
            $rows[] = [
                'id' => (int)$row['id'],
                'member_code' => $memberCode,
                'full_name' => $candidateFull,
                'baptismal_name' => (string)($row['baptismal_name'] ?? ''),
                'mother_name' => (string)($row['mother_name'] ?? ''),
                'father_name' => (string)($row['father_name'] ?? ''),
                'grandfather_name' => (string)($row['grandfather_name'] ?? ''),
                'gender' => (string)($row['gender'] ?? ''),
                'dob_ec' => $dob,
                'age' => $age,
                'phone_masked' => $phoneDisplay,
                'education_level' => $eduStatus,
                'mezmur_status' => '',
                'current_section' => $secStatus,
                'status' => (string)$row['status'],
                'status_label' => $statusLabel,
                'match_score' => min(100, $score),
                'match_band' => $matchBand,
                'match_reasons' => $reasons,
            ];
        }
        $statement->close();
        usort($rows, static fn(array $a,array $b): int => ((int)$b['match_score'] <=> (int)$a['match_score']) ?: ((int)$b['id'] <=> (int)$a['id']));
        $plausibleCount=count($rows);
        return ['candidates'=>array_slice($rows,0,$displayLimit),'plausible_count'=>$plausibleCount,'capped'=>$plausibleCount>$displayLimit,'candidate_limit'=>$displayLimit];
    }

    /** @return array{0:int,1:int,2:int} */
    private static function dateParts(string $date): array
    {
        if (preg_match('/^(\\d{2})\\/(\\d{2})\\/(\\d{4})$/', $date, $m)) return [(int)$m[1],(int)$m[2],(int)$m[3]];
        return [0,0,0];
    }
}
