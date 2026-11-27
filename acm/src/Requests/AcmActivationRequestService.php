<?php
/**
 * ACM self-service activation — the member-initiated request.
 * ------------------------------------------------------------
 * Design: docs/design/acm-self-service-activation.md
 * Migration: sql/099_acm_self_service_requests.sql
 *
 * WHAT THIS CLASS IS RESPONSIBLE FOR
 *   Accepting an unauthenticated claim ("I am member X, please
 *   activate my account"), collapsing duplicate submissions, and
 *   giving the office a queue to review it in. It does NOT decide
 *   whether the claim is true — an officer does that (§4.1 of the
 *   design), and AcmRequestDecisionService performs the binding.
 *
 * THE TWO PROPERTIES EVERY METHOD HERE PRESERVES
 *
 *   1. INDISTINGUISHABLE RESPONSES (anti-enumeration).
 *      S12 CHANGED THIS, DELIBERATELY, AND HERE IS WHY.
 *
 *      The original rule was: accept every well-formed claim and return
 *      the same answer, so that a caller cannot learn whether a member
 *      code is real. That protected the register but filled the review
 *      queue with claims that could never be matched -- unregistered IDs
 *      and wrong names became "pending review" items the office had to
 *      dismiss one by one.
 *
 *      Detection now happens BEFORE the insert. A claim either resolves to
 *      exactly one registered member whose name agrees, or it is refused
 *      and NOTHING is written. See AcmActivationDetector.
 *
 *      The anti-enumeration property is preserved at the layer where it
 *      belongs: the refusal carries no reason. `AcmClaimRefused` has a
 *      single public message and an empty reason, the route maps every
 *      kind to one wire response, and the reason survives only in the
 *      server-side audit trail. A caller learns "refused", never "that ID
 *      exists but the name is wrong".
 *
 *   2. EXACTLY-ONCE ROWS UNDER CONCURRENCY.
 *      Two identical requests landing in the same microsecond must
 *      produce one row and one request_ref. This is achieved by
 *      INSERT-first, not SELECT-then-INSERT: the UNIQUE key on
 *      dedupe_hash is the arbiter, and the loser of the race catches
 *      SQLSTATE 23000 and reads back the winner's row. A pre-check
 *      would leave the race open; the constraint cannot be bypassed
 *      by any future caller.
 *
 * PRIVACY
 *   Everything stored is what the caller TYPED, normalized. Nothing
 *   here is treated as verified until an officer approves it.
 *
 * PHP 8.4/8.5, PDO. The ACM package is PDO-only by convention; the
 * route layer owns mysqli and injects anything it needs.
 */

declare(strict_types=1);

namespace SSMS\Acm\Requests;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\Identity\AmharicNameMatcher;

final class AcmActivationRequestService
{
    /** Identical submissions collapse onto one row for this long. */
    public const COLLAPSE_WINDOW_HOURS = 24;

    /** Requests left pending before an officer reviews them expire. */
    public const PENDING_TTL_HOURS = 168; // 7 days

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ------------------------------------------------------------
    // Normalization — the boundary where user text becomes keys.
    // Kept public and static so tests can pin the exact behaviour
    // and so the console can show what the officer is comparing.
    // ------------------------------------------------------------

    /** Member code: uppercase, separators stripped ("M-0001" -> "M0001"). */
    public static function normalizeMemberCode(string $value): string
    {
        return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', trim($value)));
    }

    /**
     * Name key: lowercase, accents folded, non-alphanumerics removed,
     * single spaces. Used only for comparison, never for authorization.
     */
    public static function normalizeName(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        // Ethiopian script and Latin both reduce to letters/digits;
        // anything else (punctuation, honourifics' dots) is dropped
        // so "Abebe  Kebede." and "abebe kebede" agree.
        $value = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        return trim((string)preg_replace('/\s+/', ' ', $value));
    }

    /** Last four digits of a phone, or null. Digits only, never the full number. */
    public static function lastFour(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = (string)preg_replace('/\D+/', '', $phone);
        return strlen($digits) >= 4 ? substr($digits, -4) : null;
    }

    /**
     * The dedupe key. Deliberately covers account_type and the claim
     * fields so that a parent asking about the same child, or a
     * corrected spelling, is a DIFFERENT request rather than being
     * collapsed into an unrelated one.
     */
    public static function dedupeHash(string $accountType, string $memberCode, string $nameKey, ?string $phoneLast4): string
    {
        return hash('sha256', implode("\x1f", [
            $accountType,
            $memberCode,
            $nameKey,
            $phoneLast4 ?? '',
        ]));
    }

    // ------------------------------------------------------------
    // submit — the public entry point.
    // ------------------------------------------------------------

    /**
     * Record a claim. Idempotent under concurrency; the return value
     * is identical whether this call created the row or lost a race.
     *
     * @param array{member_code:string,full_name:string,phone?:?string,account_type?:string} $input
     * @param string|null $originIp
     * @return array{request_ref:string,created:bool}
     * @throws InvalidArgumentException on a malformed body (422 at the route)
     */
    /**
     * Accept a claim, but only if it resolves to a registered member.
     *
     * @throws InvalidArgumentException malformed input (a client bug)
     * @throws AcmClaimRefused          well-formed but not a registered
     *                                  member, or the name does not agree.
     *                                  Carries NO reason for the caller.
     */
    public function submit(array $input, ?string $originIp = null): array
    {
        $accountType = (string)($input['account_type'] ?? 'member');
        if (!in_array($accountType, ['member', 'parent'], true)) {
            throw new InvalidArgumentException('Unknown account type.');
        }

        $rawCode = (string)($input['member_code'] ?? '');
        $memberCode = self::normalizeMemberCode($rawCode);
        if ($memberCode === '' || strlen($memberCode) > 40) {
            throw new InvalidArgumentException('Enter your member ID number.');
        }

        // The app now asks for the FIRST name only. `full_name` is still
        // accepted (the shipped app sends it, and the API contract is not
        // broken for existing clients); whichever arrives is reduced to the
        // first word before comparison, because that is what the register is
        // matched on.
        $typedName = trim((string)($input['first_name'] ?? ($input['full_name'] ?? '')));
        $firstName = AmharicNameMatcher::firstName($typedName);
        if ($firstName === '' || mb_strlen($firstName) < 2 || mb_strlen($firstName) > 60) {
            throw new InvalidArgumentException('Enter your first name as registered.');
        }
        $nameKey = self::normalizeName($typedName);
        if ($nameKey === '') {
            throw new InvalidArgumentException('Enter your first name as registered.');
        }

        // DETECT FIRST. No match means no request: a claim that cannot be
        // resolved must not become work for the office.
        $detector = new AcmActivationDetector($this->pdo);
        $detection = $detector->detect($memberCode, $firstName, $accountType);
        if ($detection['kind'] !== AcmActivationDetector::RESOLVED) {
            // Audit the reason server-side only. The caller gets no reason.
            $this->recordRefusal($memberCode, $accountType, $detection, $originIp);
            throw new AcmClaimRefused($detection['kind']);
        }

        // THE CLAIM RESOLVED TO A REAL MEMBER, so make sure the person row
        // exists before the claim becomes work.
        //
        // Every member is registered but most have no account yet; the person
        // row is what an account hangs off (acm_person_accounts). It used to
        // be created only by hand in the persons console, which meant a claim
        // matched the register and then could not be approved for anybody the
        // office had not pre-registered. Creating it here -- from the register
        // the claim was just checked against -- is what makes "request, then
        // the office approves" work for the whole membership.
        //
        // Idempotent and race-safe (see AcmPersonService::ensureForMember), so
        // repeated or simultaneous claims converge on one person row.
        $personId = null;
        $memberId = $detection['member_id'] !== null ? (int)$detection['member_id'] : null;
        if ($memberId !== null) {
            try {
                $personId = (new AcmPersonService($this->pdo))->ensureForMember($memberId, 0);
            } catch (\Throwable $linkError) {
                // Never let person bookkeeping turn a valid claim into a
                // refusal: the officer can still act on it, and the console
                // derives the link on approval if it is still missing.
                $personId = null;
            }
        }

        $phoneLast4 = self::lastFour(isset($input['phone']) ? (string)$input['phone'] : null);
        $dedupe = self::dedupeHash($accountType, $memberCode, $nameKey, $phoneLast4);

        // INSERT-first: the UNIQUE key settles the race, not a check.
        try {
            [$id, $ref] = $this->insertRequest(
                $accountType, $memberCode, $nameKey, $phoneLast4, $dedupe, $originIp, $personId
            );
            $this->recordEvent($id, 'requested', null, null, [
                'account_type' => $accountType,
            ]);
            return ['request_ref' => $ref, 'created' => true];
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') {
                throw $error;
            }
            // Lost the race (or a duplicate inside the collapse window).
            // Read the winner and collapse onto it.
            return $this->collapseOnto($dedupe);
        }
    }

    /**
     * Record WHY a claim was refused, for the office and the audit trail.
     *
     * Only the normalised member code and the refusal kind are stored -- not
     * the typed name, not the phone. A refusal log that accumulates names
     * typed by someone who guessed an ID would itself be a privacy problem,
     * and the office does not need it: a refused claim creates no work.
     *
     * Failures here are swallowed. A refusal must never turn into a 500 and
     * must never be the reason a claim is accepted.
     */
    private function recordRefusal(string $memberCode, string $accountType, array $detection, ?string $originIp): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO acm_request_refusals'
                . ' (member_code, account_type, refusal_kind, match_kind, match_score, origin_ip)'
                . ' VALUES (:code, :at, :kind, :match, :score, :ip)'
            );
            $stmt->execute([
                ':code'  => substr($memberCode, 0, 40),
                ':at'    => $accountType,
                ':kind'  => (string)($detection['kind'] ?? 'unknown'),
                ':match' => (string)($detection['match'] ?? 'none'),
                ':score' => (float)($detection['score'] ?? 0.0),
                ':ip'    => $originIp !== null && $originIp !== '' ? substr($originIp, 0, 45) : null,
            ]);
        } catch (\Throwable) {
            // The refusals table is diagnostic. A deployment that has not
            // applied migration 104 still refuses claims correctly.
        }
    }

    /**
     * @return array{0:int,1:string} [id, request_ref]
     */
    private function insertRequest(
        string $accountType,
        string $memberCode,
        string $nameKey,
        ?string $phoneLast4,
        string $dedupe,
        ?string $originIp,
        ?int $personId = null
    ): array {
        $uuid = self::uuidv4();
        $ref = self::newRequestRef();

        $sql = 'INSERT INTO acm_activation_requests'
             . ' (uuid, request_ref, dedupe_hash, account_type, member_code, name_key,'
             . '  phone_last4, status, origin_ip, person_id)'
             . " VALUES (:uuid, :ref, :dedupe, :at, :code, :name, :phone, 'pending_review', :ip, :pid)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':uuid'   => $uuid,
            ':ref'    => $ref,
            ':dedupe' => $dedupe,
            ':at'     => $accountType,
            ':code'   => $memberCode,
            ':name'   => $nameKey,
            ':phone'  => $phoneLast4,
            ':ip'     => $originIp !== null ? substr($originIp, 0, 45) : null,
            ':pid'    => $personId,
        ]);

        return [(int)$this->pdo->lastInsertId(), $ref];
    }

    /**
     * A duplicate submission: the caller gets the same reference and the
     * same generic success. What happens to the row depends on where it
     * had got to, because the UNIQUE key is GLOBAL (MySQL has no partial
     * index), so one claim owns exactly one row for ever.
     *
     *   pending_review -> bump the counter. One row, one review.
     *   rejected/expired -> REOPEN. The person is asking again, which is
     *                      legitimate: their code expired, or they fixed a
     *                      typo. History is preserved because events are
     *                      append-only; only the decision fields clear.
     *   approved/canceled -> do NOT reopen. An account already exists (or
     *                      a ceremony is running); the right answer is
     *                      password reset or the office, not a second
     *                      ticket. The caller still sees a generic 202, so
     *                      this is not an enumeration oracle.
     *
     * Every branch is a compare-and-set, so two concurrent duplicates
     * cannot both reopen or both bump.
     *
     * @return array{request_ref:string,created:bool}
     */
    private function collapseOnto(string $dedupe): array
    {
        // 1. Reopen a dead request. Guarded on the OLD status so a
        //    concurrent approval that already won is not clobbered.
        $reopen = $this->pdo->prepare(
            'UPDATE acm_activation_requests'
            . ' SET status = :new, request_count = request_count + 1,'
            . '     decided_by = NULL, decided_at = NULL, decision_reason = NULL,'
            . '     claimed_by = NULL, claimed_at = NULL'
            . ' WHERE dedupe_hash = :d AND status IN (:st1, :st2)'
        );
        $reopen->execute([
            ':new' => 'pending_review',
            ':d'   => $dedupe,
            ':st1' => 'rejected',
            ':st2' => 'expired',
        ]);
        $reopened = $reopen->rowCount() === 1;

        // 2. Otherwise just count the repeat (pending, approved, canceled).
        if (!$reopened) {
            $bump = $this->pdo->prepare(
                'UPDATE acm_activation_requests SET request_count = request_count + 1'
                . ' WHERE dedupe_hash = :d'
            );
            $bump->execute([':d' => $dedupe]);
        }

        $sel = $this->pdo->prepare(
            'SELECT id, request_ref, status FROM acm_activation_requests WHERE dedupe_hash = :d LIMIT 1'
        );
        $sel->execute([':d' => $dedupe]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            // Vanished between the failed insert and this read: only
            // possible if an operator deleted it mid-flight. Fail
            // closed with a generic error rather than inventing a ref.
            throw new RuntimeException('Request could not be recorded.');
        }

        if ($reopened) {
            $this->recordEvent((int)$row['id'], 'requested', null, 'Resubmitted after rejection or expiry.');
        }

        return ['request_ref' => (string)$row['request_ref'], 'created' => $reopened];
    }

    // ------------------------------------------------------------
    // Office side
    // ------------------------------------------------------------

    /**
     * Queue page. Claimed rows are visible to the claimer and to
     * super_admin; unclaimed rows are visible to everyone with
     * request.view.
     *
     * @param string $q Free-text lookup. Matches the reference the member
     *                  was given, the ID number they claimed, their name, or
     *                  the last digits of their phone. Empty means no filter.
     * @return array<int,array<string,mixed>>
     */
    public function queue(
        int $viewerId,
        string $status = 'pending_review',
        int $limit = 25,
        int $offset = 0,
        string $q = ''
    ): array {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $where = ['r.status = :st'];
        $params = [':st' => $status];
        $search = self::searchClause($q, $params);
        if ($search !== '') {
            $where[] = $search;
        }

        $sql = 'SELECT r.id, r.request_ref, r.account_type, r.member_code, r.name_key,'
             . ' r.phone_last4, r.status, r.request_count, r.claimed_by, r.created_at,'
             . ' r.match_basis, r.person_id, r.ticket_id'
             . ' FROM acm_activation_requests r'
             . ' WHERE ' . implode(' AND ', $where)
             . ' ORDER BY r.created_at ASC, r.id ASC'
             . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * The lookup an officer actually needs: "a student is at the desk with
     * a reference".
     *
     * WHY THIS EXISTS. The reference (`request_ref`, 8 Crockford chars) is
     * the ONLY thing the member is given when they submit, so it is the
     * only string an officer can be handed. Before this, the requests page
     * had no search at all and `AcmActivationRequestService::queue()`
     * accepted no text filter, so finding one reference meant scrolling
     * status tabs. The ticket queue cannot help either: it searches
     * ticket number, name and phone, and never joins this table.
     *
     * WHAT IT MATCHES, AND IN WHICH CASE.
     *   - request_ref: exact, upper-cased. The column is ascii_bin, so a
     *     lower-case `mx7cvjnr` from a phone keyboard would NOT match it;
     *     upper-casing the term is what makes the officer's typed input
     *     work regardless of how the member wrote it down.
     *   - member_code: prefix. Stored already normalized (upper-case,
     *     alphanumerics only) by normalizeMemberCode().
     *   - name_key: substring. Stored normalized (lower-case, punctuation
     *     collapsed) by normalizeName(), so the term is normalized the
     *     same way -- otherwise "Nahom Alemu" would never match the
     *     stored "nahom alemu".
     *   - phone_last4: prefix, because only the last four digits are
     *     stored. A longer number is reduced to its last four.
     *
     * The term is escaped for LIKE before it is used, so `%` or `_` typed
     * by an officer searches for those characters instead of matching
     * everything.
     *
     * @param array<string,string> $params Receives the bound values.
     */
    private static function searchClause(string $q, array &$params): string
    {
        $q = trim($q);
        if ($q === '' || mb_strlen($q) > 120) {
            return '';
        }

        $clauses = [];

        // The reference: exact, and case-insensitive on input because the
        // column is not.
        $params[':sq_ref'] = strtoupper($q);
        $clauses[] = 'r.request_ref = :sq_ref';

        // The ID number the member typed. Normalized the same way the
        // column was written, so spaces/dashes in the officer's input do
        // not prevent a match.
        $codeNorm = self::normalizeMemberCode($q);
        if ($codeNorm !== '') {
            $params[':sq_code'] = self::escapeLike($codeNorm) . '%';
            $clauses[] = "r.member_code LIKE :sq_code ESCAPE '\\\\'";
        }

        // The name. Normalized to match how name_key is stored.
        $nameNorm = self::normalizeName($q);
        if ($nameNorm !== '') {
            $params[':sq_name'] = '%' . self::escapeLike($nameNorm) . '%';
            $clauses[] = "r.name_key LIKE :sq_name ESCAPE '\\\\'";
        }

        // Last four digits only; a full number reduces to them.
        //
        // ONLY when the term is nothing but digits. The reference alphabet
        // is alphanumeric, so a reference can end in four digits -- and
        // `ZZZZ9999` was matching every row whose phone ends 9999. That is
        // the worst kind of over-match here: the officer pastes the
        // reference in front of the student, the first row looks plausible,
        // and it belongs to somebody else. Restricting the phone branch to
        // an all-digit term makes the clauses disjoint by shape, so a term
        // containing letters can only match the reference, the ID number,
        // or the name.
        if (preg_match('/^\d+$/', $q) === 1) {
            if (strlen($q) >= 4) {
                $params[':sq_phone'] = substr($q, -4) . '%';
                $clauses[] = "r.phone_last4 LIKE :sq_phone ESCAPE '\\\\'";
            }
        }

        return '(' . implode(' OR ', $clauses) . ')';
    }

    /** Escape the LIKE wildcards so a typed `%` is a literal `%`. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function get(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM acm_activation_requests WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Claim for review. Compare-and-set: a row already claimed by
     * someone else is refused, and a concurrent second claim loses the
     * race on rowCount rather than both succeeding.
     *
     * @return bool true when this caller now holds the claim
     */
    public function claim(int $id, int $officerId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE acm_activation_requests'
            . " SET claimed_by = :uid, claimed_at = NOW()"
            . " WHERE id = :id AND status = 'pending_review'"
            . ' AND (claimed_by IS NULL OR claimed_by = :uid2)'
        );
        $stmt->execute([':uid' => $officerId, ':id' => $id, ':uid2' => $officerId]);
        $won = $stmt->rowCount() === 1;
        if ($won) {
            $this->recordEvent($id, 'claim', $officerId);
        }
        return $won;
    }

    public function release(int $id, int $officerId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE acm_activation_requests'
            . ' SET claimed_by = NULL, claimed_at = NULL'
            . " WHERE id = :id AND status = 'pending_review' AND claimed_by = :uid"
        );
        $stmt->execute([':id' => $id, ':uid' => $officerId]);
        $won = $stmt->rowCount() === 1;
        if ($won) {
            $this->recordEvent($id, 'release', $officerId);
        }
        return $won;
    }

    /**
     * Close a request WITHOUT creating an account. Compare-and-set so a
     * concurrent approval cannot also win.
     */
    public function reject(int $id, int $officerId, string $reason): bool
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('A reason (3-2000 characters) is required.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE acm_activation_requests'
            . " SET status = 'rejected', decided_by = :uid, decided_at = NOW(), decision_reason = :why"
            . " WHERE id = :id AND status = 'pending_review'"
        );
        $stmt->execute([':uid' => $officerId, ':why' => $reason, ':id' => $id]);
        $won = $stmt->rowCount() === 1;
        if ($won) {
            $this->recordEvent($id, 'rejected', $officerId, $reason);
        }
        return $won;
    }

    /**
     * Candidate people for a claim. Deterministic and indexed: no
     * fuzzy scoring in SQL (design §4.1). Returns candidates for the
     * OFFICER to choose from; it never picks one.
     *
     * @return array<int,array<string,mixed>>
     */
    public function candidates(array $request, int $limit = 10): array
    {
        $limit = max(1, min($limit, 25));
        $code = (string)($request['member_code'] ?? '');
        $last4 = $request['phone_last4'] ?? null;

        $sql = 'SELECT m.id, m.member_code, m.student_name, m.father_name,'
             . ' m.full_name_am, m.phone_primary, m.status'
             . ' FROM members m'
             . ' WHERE m.member_code = :code';
        $params = [':code' => $code];

        if (is_string($last4) && strlen($last4) === 4) {
            // Widen to a phone match only as an ADDITION: the code
            // match stays the primary signal.
            $sql .= ' OR (m.status = \'active\' AND RIGHT(REPLACE(REPLACE(COALESCE(m.phone_primary,\'\'),\'-\',\'\'),\' \',\'\'), 4) = :l4)';
            $params[':l4'] = $last4;
        }
        $sql .= ' ORDER BY (m.member_code = :code2) DESC, m.id ASC LIMIT ' . $limit;
        $params[':code2'] = $code;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Compute the match basis an approval should record, given the
     * officer's chosen person and the claim. Pure function so the
     * console and the service agree, and so it can be unit-tested.
     */
    public static function matchBasis(array $request, array $person): string
    {
        $codeByPerson = self::normalizeMemberCode((string)($person['member_code'] ?? ''));
        $codeClaimed  = self::normalizeMemberCode((string)($request['member_code'] ?? ''));
        if ($codeByPerson === '' || $codeByPerson !== $codeClaimed) {
            return 'manual';
        }
        $claimedName = (string)($request['name_key'] ?? '');
        $personNames = [
            self::normalizeName((string)($person['student_name'] ?? '')),
            self::normalizeName((string)($person['full_name_am'] ?? '')),
            self::normalizeName(trim(((string)($person['father_name'] ?? '')) . ' ' . ((string)($person['student_name'] ?? '')))),
        ];
        $nameAgrees = in_array($claimedName, array_filter($personNames), true);

        $last4 = (string)($request['phone_last4'] ?? '');
        $phoneAgrees = $last4 !== ''
            && self::lastFour((string)($person['phone_primary'] ?? '')) === $last4;

        if ($nameAgrees && $phoneAgrees) {
            return 'member_code+name';
        }
        if ($nameAgrees) {
            return 'member_code+name';
        }
        if ($phoneAgrees) {
            return 'member_code+phone';
        }
        return 'member_code';
    }

    // ------------------------------------------------------------
    // Housekeeping
    // ------------------------------------------------------------

    /**
     * Expire stale pending requests. Compare-and-set per row so a
     * request being decided concurrently is not clobbered; bounded
     * batch so the cron cannot lock the table.
     */
    public function expireStale(int $limit = 200): int
    {
        $limit = max(1, min($limit, 1000));
        $stmt = $this->pdo->prepare(
            'UPDATE acm_activation_requests'
            . " SET status = 'expired', decided_at = NOW(), decision_reason = 'Expired without review.'"
            . " WHERE status = 'pending_review'"
            . ' AND created_at < DATE_SUB(NOW(), INTERVAL ' . self::PENDING_TTL_HOURS . ' HOUR)'
            . ' ORDER BY created_at ASC LIMIT ' . $limit
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function recordEvent(int $requestId, string $event, ?int $actorId = null, ?string $note = null, ?array $meta = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO acm_request_events (request_id, event, actor_id, note, meta)'
            . ' VALUES (:rid, :ev, :actor, :note, :meta)'
        );
        $stmt->execute([
            ':rid'   => $requestId,
            ':ev'    => $event,
            ':actor' => $actorId,
            ':note'  => $note !== null ? mb_substr($note, 0, 2000) : null,
            ':meta'  => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    // ------------------------------------------------------------
    // Generators
    // ------------------------------------------------------------

    /**
     * Short reference the caller repeats to the office, and the officer
     * reads back. Crockford base32 without ambiguous characters, so it
     * survives being spoken (CodeAlphabet precedent, ACM D3.2).
     */
    public static function newRequestRef(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
        $base = strlen($alphabet);
        $limit = 256 - (256 % $base);
        $out = '';
        while (strlen($out) < 8) {
            $bytes = random_bytes(8 - strlen($out));
            for ($i = 0, $n = strlen($bytes); $i < $n && strlen($out) < 8; $i++) {
                $v = ord($bytes[$i]);
                if ($v < $limit) {
                    $out .= $alphabet[$v % $base];
                }
            }
        }
        return $out;
    }

    public static function uuidv4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
