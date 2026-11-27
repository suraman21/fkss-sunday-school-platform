<?php
/**
 * S12 — Automatic identity detection for activation claims.
 * ------------------------------------------------------------
 * WHAT THIS REPLACES
 *   Before this class, `submit()` inserted a row for ANY well-formed input
 *   and left the office to work out whether the person existed. A claim with
 *   an ID that belongs to nobody, or a name that matches nobody, became a
 *   pending review item. That is how the console filled up with "0 tickets —
 *   no tickets match these filters": the queue held claims that were never
 *   matchable.
 *
 * THE RULE
 *   Activation is only for members who are already registered. A claim is
 *   either RESOLVED (exactly one member account, and the name agrees) or it
 *   is NOT. A claim that does not resolve creates NO work: `submit()` refuses
 *   it, and the member app shows one neutral message with the office contact.
 *
 * WHY "EXACTLY ONE" AND NOT "BEST SCORE"
 *   Two members can share a first name inside one section. Picking the
 *   higher-scoring one would give an account to the wrong child, which is the
 *   worst outcome this system can produce. Ambiguity is therefore not a
 *   match: the member is told to contact the office, and the office resolves
 *   it by hand. This is deliberately more conservative than it needs to be
 *   for the common case, because the failure is silent and severe.
 *
 * PRIVACY
 *   The caller must never surface WHY a claim failed. Returning "the ID
 *   exists but the name is wrong" turns this endpoint into a member-directory
 *   oracle: try IDs until one gets a different reply. Every failure mode is
 *   collapsed into one result, and the route has one message.
 */

declare(strict_types=1);

namespace SSMS\Acm\Requests;

use PDO;
use SSMS\Acm\Identity\AmharicNameMatcher;

final class AcmActivationDetector
{
    /**
     * Values of `kind` this class can return.
     *
     *   resolved      one account, name agrees  -> may proceed
     *   no_member     the ID belongs to no member
     *   name_mismatch the ID is real, the name is not
     *   ambiguous     more than one account could be meant
     *   not_active    the member record is not active (archived/inactive)
     *   no_account    the member exists but has no login account to activate
     *
     * The member-facing layer MUST treat every value except `resolved`
     * identically. They differ only for the audit trail and the office.
     */
    public const RESOLVED = 'resolved';
    public const NO_MEMBER = 'no_member';
    public const NAME_MISMATCH = 'name_mismatch';
    public const AMBIGUOUS = 'ambiguous';
    public const NOT_ACTIVE = 'not_active';
    public const NO_ACCOUNT = 'no_account';

    /** Member statuses that may activate an account. */
    private const ACTIVATABLE_STATUSES = ['active', 'warning'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Resolve a claim to a single member.
     *
     * @param string $memberCode as typed; normalised here
     * @param string $firstName  the FIRST name only, as the app now asks
     * @param string $accountType 'member' or 'parent'
     * @return array{
     *   kind:string,
     *   person_id:?int, member_id:?int, guardian_id:?int,
     *   match:string, score:float, detail:string,
     *   candidates:list<array>
     * }
     */
    public function detect(string $memberCode, string $firstName, string $accountType = 'member'): array
    {
        $code = AcmActivationRequestService::normalizeMemberCode($memberCode);
        $typed = AmharicNameMatcher::trimAndCollapse($firstName);
        $empty = [
            'kind' => self::NO_MEMBER, 'person_id' => null, 'member_id' => null,
            'guardian_id' => null, 'match' => 'none', 'score' => 0.0,
            'detail' => '', 'candidates' => [],
        ];
        if ($code === '' || $typed === '') {
            return $empty;
        }

        // The register of record is `members`: it holds the member code, the
        // names in both scripts, the section, class, birth date and the
        // Christian name the verification card shows.
        $rows = $this->memberRows($code);
        if ($rows === []) {
            return $empty;
        }

        // Compare the typed first name against every name the register holds
        // for this member. The best agreement wins.
        $scored = [];
        foreach ($rows as $row) {
            $best = $this->bestNameMatch($typed, $row);
            $scored[] = ['row' => $row, 'match' => $best];
        }
        usort($scored, static fn($a, $b) => $b['match']['score'] <=> $a['match']['score']);
        $top = $scored[0];

        $agrees = array_values(array_filter(
            $scored,
            static fn($s) => in_array($s['match']['kind'], ['exact', 'homophone'], true)
        ));

        // More than one member agrees on this first name: do not guess.
        if (count($agrees) > 1) {
            return [
                'kind' => self::AMBIGUOUS,
                'person_id' => null,
                'member_id' => null,
                'guardian_id' => null,
                'match' => 'ambiguous',
                'score' => $top['match']['score'],
                'detail' => count($agrees) . ' members share this ID and name',
                'candidates' => array_map([$this, 'summarise'], array_column($agrees, 'row')),
            ];
        }

        if ($agrees === []) {
            return [
                'kind' => self::NAME_MISMATCH,
                'person_id' => null,
                'member_id' => (int)$top['row']['id'],
                'guardian_id' => null,
                'match' => $top['match']['kind'],
                'score' => $top['match']['score'],
                'detail' => $top['match']['detail'],
                'candidates' => [$this->summarise($top['row'])],
            ];
        }

        $member = $agrees[0]['row'];
        $status = strtolower((string)($member['status'] ?? ''));
        if (!in_array($status, self::ACTIVATABLE_STATUSES, true)) {
            return [
                'kind' => self::NOT_ACTIVE,
                'person_id' => $this->personIdFor($member),
                'member_id' => (int)$member['id'],
                'guardian_id' => $this->guardianIdFor($member, $accountType),
                'match' => $agrees[0]['match']['kind'],
                'score' => $agrees[0]['match']['score'],
                'detail' => 'member status is ' . ($status === '' ? 'unknown' : $status),
                'candidates' => [$this->summarise($member)],
            ];
        }

        $personId = $this->personIdFor($member);
        if ($personId === null) {
            return [
                'kind' => self::NO_ACCOUNT,
                'person_id' => null,
                'member_id' => (int)$member['id'],
                'guardian_id' => null,
                'match' => $agrees[0]['match']['kind'],
                'score' => $agrees[0]['match']['score'],
                'detail' => 'member has no ACM person record yet',
                'candidates' => [$this->summarise($member)],
            ];
        }

        return [
            'kind' => self::RESOLVED,
            'person_id' => $personId,
            'member_id' => (int)$member['id'],
            'guardian_id' => $this->guardianIdFor($member, $accountType),
            'match' => $agrees[0]['match']['kind'],
            'score' => $agrees[0]['match']['score'],
            'detail' => $agrees[0]['match']['detail'],
            'candidates' => [$this->summarise($member)],
        ];
    }

    /**
     * Every member row for this code.
     *
     * member_code is not unique in this schema, and a family can share one,
     * so this returns a list and the caller decides. Ordering is stable so
     * the ambiguity check is deterministic.
     *
     * @return list<array<string,mixed>>
     */
    private function memberRows(string $code): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.member_code, m.full_name_am, m.full_name_en, m.student_name,'
            . ' m.christian_name, m.baptismal_name, m.father_name, m.grandfather_name,'
            . ' m.gender, m.date_of_birth, m.age, m.current_section, m.education_level,'
            . ' m.status, m.phone_primary, m.phone_number, m.photo_path'
            . ' FROM members m WHERE m.member_code = :code'
            . ' ORDER BY m.id ASC LIMIT 25'
        );
        $stmt->execute([':code' => $code]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Best name agreement across the spellings the register holds.
     *
     * The member types the name in ONE script, but the register may hold the
     * Amharic form, the English form, a baptismal name and a "student name"
     * that is the given name. All of them are legitimate targets, so the
     * typed name is compared against each and the best result is kept.
     */
    private function bestNameMatch(string $typed, array $row): array
    {
        $candidates = array_filter([
            (string)($row['student_name'] ?? ''),
            (string)($row['full_name_am'] ?? ''),
            (string)($row['full_name_en'] ?? ''),
            (string)($row['christian_name'] ?? ''),
            (string)($row['baptismal_name'] ?? ''),
            (string)($row['father_name'] ?? ''),
        ], static fn($v) => trim($v) !== '');

        $best = ['score' => 0.0, 'kind' => 'different', 'detail' => 'No name on record.'];
        foreach ($candidates as $name) {
            $cmp = AmharicNameMatcher::compare($typed, $name);
            if ($cmp['score'] > $best['score']) {
                $best = $cmp;
            }
        }
        return $best;
    }

    /** The ACM person for this member row, if one has been created. */
    private function personIdFor(array $member): ?int
    {
        // acm_persons.member_id is the link the ACM package uses.
        $stmt = $this->pdo->prepare(
            'SELECT id FROM acm_persons WHERE member_id = :mid ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([':mid' => (int)$member['id']]);
        $id = $stmt->fetchColumn();
        return $id === false || $id === null ? null : (int)$id;
    }

    /** The guardian row for a parent account, when the register holds one. */
    private function guardianIdFor(array $member, string $accountType): ?int
    {
        if ($accountType !== 'parent') {
            return null;
        }
        $guardianId = (int)($member['guardian_id'] ?? 0);
        return $guardianId > 0 ? $guardianId : null;
    }

    /**
     * The subset the console may show. Deliberately excludes anything the
     * member did not already provide, apart from what the officer needs to
     * recognise the person on the verification card.
     */
    private function summarise(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'member_code' => (string)($row['member_code'] ?? ''),
            'full_name_am' => (string)($row['full_name_am'] ?? ''),
            'full_name_en' => (string)($row['full_name_en'] ?? ''),
            'student_name' => (string)($row['student_name'] ?? ''),
            'christian_name' => (string)($row['christian_name'] ?? ''),
            'father_name' => (string)($row['father_name'] ?? ''),
            'gender' => (string)($row['gender'] ?? ''),
            'date_of_birth' => (string)($row['date_of_birth'] ?? ''),
            'age' => isset($row['age']) && $row['age'] !== null ? (int)$row['age'] : null,
            'current_section' => (string)($row['current_section'] ?? ''),
            'education_level' => (string)($row['education_level'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'photo_path' => (string)($row['photo_path'] ?? ''),
        ];
    }
}
