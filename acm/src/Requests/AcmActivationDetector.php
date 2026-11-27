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

    /**
     * Probe results for optional `members` columns, keyed by column name.
     * One lookup per column per instance, not per request row.
     *
     * @var array<string,bool>
     */
    private array $memberColumnCache = [];

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
        if ($rows === [] && preg_match('/^[0-9]{5}$/', $code) === 1) {
            // THE MEMBER TYPED ONLY THE DIGITS.
            //
            // Codes are allocated as letter + '-' + five digits ("B-24659",
            // "C-27456"), and a bare tail is what many people read off a
            // card or write on a form: they drop the category letter and the
            // dash. Measured against a register holding "B-24659", typing
            // "24659" answered `no_member` -- a real member, refused for
            // shortening their own ID.
            //
            // The tail is only a PROBE, never a decision. If it matches more
            // than one member (B-24659 and C-24659 can both exist), the
            // typed name has to separate them below; if the name agrees with
            // several, the ambiguity guard refuses instead of guessing. So
            // this widens the search, never the acceptance.
            //
            // COST, stated honestly: RIGHT() cannot use an index on
            // member_code, so this reads the register. It is gated three ways
            // -- the exact lookup must already have missed, the entry must be
            // exactly five digits, and the read is capped at 25 rows -- so it
            // is paid by a mistyped or shortened code, not by every claim.
            // The alternative (three exact lookups for A-/B-/C-) would use
            // the index but would silently miss legacy rows stored with no
            // letter at all, which this register is documented to contain.
            // For a parish-sized table the scan is the cheaper mistake.
            $rows = $this->memberRowsByTail($code);
        }
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

        // A missing person row is NOT a reason to refuse.
        //
        // This used to return NO_ACCOUNT, so only a member somebody had
        // registered by hand in the persons console could ever activate --
        // every other registered member was refused whatever they typed.
        // The register is the directory of people: when the ID and the name
        // both agree with exactly one active member, that member exists, and
        // the caller creates the person row from the register before the
        // ticket (AcmPersonService::ensureForMember, idempotent).
        //
        // `person_id` stays null here on purpose: detection answers "who is
        // this", and must not write. The route performs the write, because
        // the refusal path is what keeps an unmatched claim from becoming
        // work, and it must stay the only thing that decides.
        $personId = $this->personIdFor($member);

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
        return $this->memberQuery('m.member_code = :code', [':code' => $code]);
    }

    /**
     * Rows whose stored code ENDS with a bare five-digit tail.
     *
     * Only used when the exact lookup missed, and only for a digits-only
     * entry, so the scan it costs is paid by the rare shortened-code claim
     * rather than by every activation.
     *
     * @return list<array<string,mixed>>
     */
    private function memberRowsByTail(string $tail): array
    {
        return $this->memberQuery('RIGHT(m.member_code, 5) = :tail', [':tail' => $tail]);
    }

    /**
     * The register read both lookups share, so the column list (and the
     * photo probe) can never drift between them.
     *
     * $where is a SQL fragment supplied by the two callers above and is
     * always a string literal from this file -- never request data. Values
     * travel in $params, bound.
     *
     * @param array<string,string> $params
     * @return list<array<string,mixed>>
     */
    private function memberQuery(string $where, array $params): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.member_code, m.full_name_am, m.full_name_en, m.student_name,'
            . ' m.christian_name, m.baptismal_name, m.father_name, m.grandfather_name,'
            . ' m.gender, m.date_of_birth, m.age, m.current_section, m.education_level,'
            . ' m.status, m.phone_primary, m.phone_number,'
            // The member photo is `student_photo_path` in this schema (see
            // sql/legacy/database_schema.sql and sql/096). This SELECT used to
            // name `m.photo_path`, which no migration has ever added to
            // `members`: with ATTR_EMULATE_PREPARES false the server rejected
            // the whole statement, so the console 500'd on every request and
            // submit() threw before it could refuse anything.
            //
            // `members` is a legacy table whose shape varies between
            // deployments, so the photo is probed rather than assumed: a
            // missing photo column degrades the verification card to "no
            // photo" instead of taking the page down. Everything else in this
            // list is part of the register contract and stays hard.
            . ' ' . ($this->membersColumnExists('student_photo_path')
                ? 'm.student_photo_path AS photo_path'
                : 'NULL AS photo_path')
            . ' FROM members m WHERE ' . $where
            . ' ORDER BY m.id ASC LIMIT 25'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Is this column present on `members`? Probed once per instance; an
     * unreadable schema is treated as "absent" because the caller only uses
     * this to avoid naming a column that might not exist.
     */
    private function membersColumnExists(string $column): bool
    {
        if (array_key_exists($column, $this->memberColumnCache)) {
            return $this->memberColumnCache[$column];
        }
        $exists = false;
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE()'
                . ' AND TABLE_NAME = :tbl AND COLUMN_NAME = :col LIMIT 1'
            );
            $stmt->execute([':tbl' => 'members', ':col' => $column]);
            $exists = $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            $exists = false;
        }
        $this->memberColumnCache[$column] = $exists;
        return $exists;
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

    /**
     * The guardian row for a parent account, when the register holds one.
     *
     * `members` has no guardian_id column -- the link lives in
     * `member_guardians` (sql/069: member_id, guardian_id, is_primary). This
     * used to read it off the member row, so it was always absent and a
     * parent request never arrived with a guardian prefilled. Falls back to
     * whatever the row carries, so a caller that already resolved the link
     * keeps working.
     */
    private function guardianIdFor(array $member, string $accountType): ?int
    {
        if ($accountType !== 'parent') {
            return null;
        }
        $guardianId = (int)($member['guardian_id'] ?? 0);
        if ($guardianId > 0) {
            return $guardianId;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT guardian_id FROM member_guardians'
                . ' WHERE member_id = :mid ORDER BY is_primary DESC, contact_priority ASC, guardian_id ASC'
                . ' LIMIT 1'
            );
            $stmt->execute([':mid' => (int)($member['id'] ?? 0)]);
            $id = $stmt->fetchColumn();
            return $id === false || $id === null ? null : (int)$id;
        } catch (\Throwable) {
            return null;
        }
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
