<?php
/**
 * ACM S3 — activation ceremony implementation (design §§D3.1–D3.5).
 * ------------------------------------------------------------
 * Implements the S2 ActivationService contract:
 *   issueCode    officer issues a purpose-bound code (D3.2);
 *                atomically invalidates the previous active code
 *                for the ticket + purpose, revokes live grants.
 *   verifyCode   app redeems selector+secret (D3.3); generic
 *                failure everywhere (no oracle, D4.5); mints a
 *                single-use 15-minute grant on success.
 *   establishCredentials
 *                grant + password + idempotency key (D3.3/D3.4);
 *                ONE transaction consumes the grant, provisions
 *                the (inactive) account, links the person, moves
 *                the ticket to account_pending_device. The
 *                account waits for S4 device proof (resume path
 *                documented on markActivated()).
 *   completeRecovery (S6: purpose `reset`)
 *                grant + new password + confirmation + idem key;
 *                PDO-A consumes the grant + saves idem, mysqli
 *                runs the shared administrator reset (new hash +
 *                ALL sessions revoked), PDO-B closes
 *                grant_verified -> active with a `recovered`
 *                event. ACTIVE accounts only; devices untouched
 *                (owner D10 #18); crash windows converge via idem
 *                continuation (same key + same grant replays or
 *                resumes, never double-recovers).
 * Hashing: PASSWORD_DEFAULT (bcrypt today — the same recorded
 * assumption PasswordPolicy's 72-byte cap already documents).
 * User passwords match the shared AccountCredentialService
 * primitive; code secrets and grants are single-verify,
 * short-lived credentials hashed the same way.
 * Time: every expiry comparison stays in SQL (NOW()). PHP never
 * compares timestamps (clocks may differ by timezone).
 */

namespace SSMS\Acm\Activation;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use SSMS\Acm\Accounts\AccountProvisioner;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Contracts\AccountRef;
use SSMS\Acm\Contracts\ActivationGrant;
use SSMS\Acm\Contracts\ActivationService as ActivationServiceContract;
use SSMS\Acm\Contracts\CodePurpose;
use SSMS\Acm\Contracts\IssuedCode;
use SSMS\Acm\Contracts\RequestMeta;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\OfficeNetwork\OfficeNetworkSignal;
use SSMS\Acm\Policy\AcmPolicy;
use App\Services\PasswordPolicy;

require_once dirname(__DIR__, 3) . '/admin/backend/services/PasswordPolicy.php';

class ActivationService implements ActivationServiceContract
{
    /** Code TTLs in minutes (D3.2). */
    public const CODE_TTL_MIN = ['activation' => 30, 'device_add' => 1440, 'reset' => 1440];
    /** Failures before a code burns (D3.2). */
    public const CODE_MAX_ATTEMPTS = ['activation' => 5, 'device_add' => 10, 'reset' => 10];
    /** Grant TTL in minutes (D3.3: 15). */
    public const GRANT_TTL_MIN = 15;
    /** Per-(selector,IP) failures before throttling, inside the lazy window. */
    public const PAIR_LIMIT = 3;
    public const PAIR_WINDOW_MIN = 15;
    /** Per-ticket velocity cap with officer-visible alerting (D4.5). */
    public const TICKET_VELOCITY_LIMIT = 30;
    public const TICKET_VELOCITY_WINDOW_H = 1;
    /** Idempotency retention, hours (>= 24 per D3.3). */
    public const IDEMPOTENCY_TTL_H = 48;

    private PDO $pdo;
    private AcmTicketService $tickets;
    private AcmPersonService $persons;
    private AccountProvisioner $provisioner;
    private IdempotencyStore $idempotency;
    private OfficeNetworkSignal $network;
    /**
     * @var callable|null (int $officerId, int $userId, string $newPassword): void.
     * Mysqli-backed, injected by the route (the ACM package stays
     * PDO-only -- S4 familyRevoker precedent). Recovery FAILS
     * CLOSED without one: the grant is never consumed.
     */
    private $passwordResetter;

    public function __construct(
        PDO $pdo,
        AcmTicketService $tickets,
        AcmPersonService $persons,
        ?callable $passwordResetter = null
    ) {
        $this->pdo = $pdo;
        $this->tickets = $tickets;
        $this->persons = $persons;
        $this->passwordResetter = $passwordResetter;
        $this->provisioner = new AccountProvisioner($pdo);
        $this->idempotency = new IdempotencyStore($pdo);
        $this->network = new OfficeNetworkSignal($pdo);
    }

    public function networkSignal(): OfficeNetworkSignal
    {
        return $this->network;
    }

    // ------------------------------------------------------------
    // issueCode (D3.2): officer issues; old code dies atomically.
    // ------------------------------------------------------------
    public function issueCode(AccountContext $ctx, string $ticketId, CodePurpose $purpose): IssuedCode
    {
        if (!AcmPolicy::can($ctx->role, AcmPolicy::RES_CODE, 'issue')) {
            throw new InvalidArgumentException('Access denied.');
        }
        if ($purpose !== CodePurpose::Activation
            && $purpose !== CodePurpose::DeviceAdd
            && $purpose !== CodePurpose::Reset
        ) {
            // S6 opened reset (credential recovery). Unknown future
            // purposes stay closed until their own increment.
            throw new InvalidArgumentException('This code purpose is not available in this increment.');
        }
        if (!ctype_digit($ticketId) || (int)$ticketId <= 0) {
            throw new InvalidArgumentException('Ticket was not found.');
        }
        $ticket = $this->tickets->get((int)$ticketId);
        if ($ticket === null) {
            throw new InvalidArgumentException('Ticket was not found.');
        }
        if (!in_array($ticket['status'], AcmTicketService::ISSUABLE_STATUSES, true)) {
            throw new ActivationException(
                ActivationException::TICKET_STATE, 409,
                'This ticket is not awaiting a code (status: ' . $ticket['status'] . ').'
            );
        }

        $purposeValue = $purpose->value;
        $ttlMin = self::CODE_TTL_MIN[$purposeValue];
        $maxAttempts = self::CODE_MAX_ATTEMPTS[$purposeValue];

        $this->pdo->beginTransaction();
        try {
            $locked = $this->tickets->getForUpdate((int)$ticketId);
            if ($locked === null
                || !in_array($locked['status'], AcmTicketService::ISSUABLE_STATUSES, true)) {
                throw new ActivationException(
                    ActivationException::TICKET_STATE, 409,
                    'This ticket is not awaiting a code.'
                );
            }
            // Atomic reissue: the previous active code for this
            // ticket + purpose dies in the SAME transaction (D4.4).
            $prev = $this->pdo->prepare(
                'SELECT id FROM acm_activation_codes'
                . ' WHERE ticket_id = :t AND purpose = :p AND status = \'active\''
                . ' ORDER BY id DESC LIMIT 1 FOR UPDATE'
            );
            $prev->execute([':t' => (int)$ticketId, ':p' => $purposeValue]);
            $prevId = $prev->fetchColumn();
            $reissueOf = $prevId === false ? null : (int)$prevId;
            if ($reissueOf !== null) {
                $kill = $this->pdo->prepare(
                    'UPDATE acm_activation_codes SET status = \'revoked\''
                    . ' WHERE id = :id AND status = \'active\''
                );
                $kill->execute([':id' => $reissueOf]);
            }
            // A reissue must not leave two live grants behind: any
            // unconsumed grant for this ticket + operation dies too.
            $this->tickets->revokeCeremonyGrants((int)$ticketId, $purposeValue, 'reissue');

            $selector = '';
            $secret = '';
            $codeId = 0;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $selector = CodeAlphabet::random(CodeAlphabet::SELECTOR_LEN);
                $secret = CodeAlphabet::random(CodeAlphabet::SECRET_LEN);
                try {
                    $ins = $this->pdo->prepare(
                        'INSERT INTO acm_activation_codes'
                        . ' (uuid, ticket_id, person_id, purpose, prefix, selector, secret_hash,'
                        . '  issued_by, expires_at, max_attempts, status, reissue_of)'
                        . ' VALUES (:uuid, :t, :p, :purpose, :prefix, :sel, :hash,'
                        . '  :by, DATE_ADD(NOW(), INTERVAL ' . (int)$ttlMin . ' MINUTE),'
                        . '  :max, \'active\', :prev)'
                    );
                    $ins->execute([
                        ':uuid' => AcmPersonService::uuid4(),
                        ':t' => (int)$ticketId,
                        ':p' => (int)$ticket['person_id'],
                        ':purpose' => $purposeValue,
                        ':prefix' => CodeAlphabet::prefixFor($purpose),
                        ':sel' => $selector,
                        ':hash' => password_hash($secret, PASSWORD_DEFAULT),
                        ':by' => $ctx->userId > 0 ? $ctx->userId : null,
                        ':max' => $maxAttempts,
                        ':prev' => $reissueOf,
                    ]);
                    $codeId = (int)$this->pdo->lastInsertId();
                    break;
                } catch (\PDOException $e) {
                    if ($e->getCode() !== '23000' || $attempt === 4) {
                        throw $e;
                    }
                }
            }
            if ($codeId <= 0) {
                throw new RuntimeException('Code issuance failed.');
            }
            $expiresAt = $this->codeExpiry($codeId);
            $this->tickets->markCodeIssued(
                (int)$ticketId, $ctx->userId, $reissueOf !== null, $reissueOf,
                ['selector' => $selector, 'purpose' => $purposeValue]
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            // Guarded: a commit-time failure must not be masked by a
            // rollback against an already-closed transaction.
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        // Plaintext exists ONLY in this return: shown once to the
        // issuing officer, then masked everywhere (D3.2).
        return new IssuedCode($selector, $secret, $purpose, $expiresAt);
    }

    // ------------------------------------------------------------
    // verifyCode (D3.3): redeem selector+secret; mint a grant.
    // ------------------------------------------------------------
    public function verifyCode(
        string $selector,
        string $secret,
        CodePurpose $purpose,
        RequestMeta $meta
    ): ActivationGrant {
        $selector = CodeAlphabet::normalize($selector);
        $secret = CodeAlphabet::normalize($secret);
        if (!CodeAlphabet::isValid($selector, CodeAlphabet::SELECTOR_LEN)
            || !CodeAlphabet::isValid($secret, CodeAlphabet::SECRET_LEN)) {
            throw ActivationException::verifyFailed();
        }
        // Redemption-scope office-network gate (D3.7). Checked before
        // any code lookup: the verdict reveals nothing about the code.
        if (!$this->network->allows($meta->clientIp)) {
            throw ActivationException::networkDenied();
        }
        $ip = $meta->clientIp;

        $this->pdo->beginTransaction();
        try {
            if ($ip !== null && $ip !== '' && $this->pairBlocked($selector, $ip)) {
                throw ActivationException::rateLimited();
            }
            $code = $this->lockedCode($selector);
            if ($code === null) {
                // Unknown selector: no row to burn, nothing recorded
                // (recording would let anyone fill the attempts table
                // with garbage); the API-level IP gate still applies.
                throw ActivationException::verifyFailed();
            }
            if ($this->ticketVelocity((int)$code['ticket_id']) >= self::TICKET_VELOCITY_LIMIT) {
                throw ActivationException::rateLimited();
            }
            $ticket = $this->tickets->getForUpdate((int)$code['ticket_id']);
            $ticketOk = $ticket !== null && $ticket['status'] === 'code_issued';
            if ($code['purpose'] !== $purpose->value
                || $code['status'] !== 'active'
                || !$ticketOk
                || (int)$code['live'] !== 1) {
                $this->failRedemption($code, $ip);
            }
            // Correct secret?
            if (!password_verify($secret, (string)$code['secret_hash'])) {
                $this->failRedemption($code, $ip);
            }
            // Success path (D4.4: consume + grant + transition, one txn).
            $consume = $this->pdo->prepare(
                'UPDATE acm_activation_codes SET status = \'consumed\', consumed_at = NOW()'
                . ' WHERE id = :id AND status = \'active\''
            );
            $consume->execute([':id' => (int)$code['id']]);
            if ($consume->rowCount() !== 1) {
                // Lost a concurrent redemption race: identical generic
                // failure, no oracle.
                throw ActivationException::verifyFailed();
            }
            if ($ip !== null && $ip !== '') {
                $this->clearPair($selector, $ip);
            }
            $grant = $this->mintGrant($code, $purpose->value);
            $this->tickets->markGrantVerified((int)$code['ticket_id'], [
                'selector' => $selector,
                'grant_fp' => substr($grant['fingerprint'], 0, 6) . '...',
            ]);
            $this->pdo->commit();
        } catch (ActivationException $e) {
            // failRedemption() commits its own accounting before
            // throwing; anything else rolls back untouched.
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return new ActivationGrant($grant['token'], $purpose, $grant['expires_at']);
    }

    // ------------------------------------------------------------
    // establishCredentials (D3.3/D3.4): grant -> account.
    // ------------------------------------------------------------
    public function establishCredentials(
        string $grantToken,
        string $password,
        string $idempotencyKey
    ): AccountRef {
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $grantToken)) {
            throw new ActivationException(
                ActivationException::GRANT_INVALID, 401,
                'The activation session is invalid.'
            );
        }
        if (!preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $idempotencyKey
        )) {
            throw new ActivationException(
                ActivationException::INVALID_REQUEST, 400,
                'The idempotency key must be a UUID.'
            );
        }
        $fingerprint = substr(hash('sha256', $grantToken), 0, 12);
        $grant = $this->grantByFingerprint($fingerprint);
        if ($grant === null || !password_verify($grantToken, (string)$grant['grant_hash'])) {
            throw new ActivationException(
                ActivationException::GRANT_INVALID, 401,
                'The activation session is invalid.'
            );
        }
        if ($grant['operation'] !== CodePurpose::Activation->value) {
            // Operation binding (D3.3): a device_add/reset grant can
            // never satisfy credential establishment.
            throw new ActivationException(
                ActivationException::GRANT_INVALID, 401,
                'The activation session is invalid.'
            );
        }
        if ($grant['revoked_at'] !== null) {
            throw new ActivationException(
                ActivationException::GRANT_REVOKED, 403,
                'The activation session was revoked. Ask the office to reissue a code.'
            );
        }
        // Idempotency BEFORE the consumed/expired checks: after a
        // success the grant IS consumed, so an uncertain-response
        // retry (same key + same grant) must replay, not report
        // grant_consumed/grant_expired. A conflict consumes nothing.
        $prior = $this->idempotency->findValid($idempotencyKey);
        if ($prior !== null) {
            if (!hash_equals((string)$prior['grant_fingerprint'], $fingerprint)) {
                throw new ActivationException(
                    ActivationException::IDEMPOTENT_CONFLICT, 409,
                    'This idempotency key was already used with a different session.'
                );
            }
            // S4: the chained device grant cannot be reproduced
            // (hash-only storage), so replay mints a FRESH one after
            // revoking the ticket's outstanding unused device grant
            // -- or returns null when a device is already active.
            $deviceGrantToken = $this->replayDeviceGrant(
                (int)$grant['ticket_id'], (int)$prior['user_id'], (int)$grant['id']
            );
            return new AccountRef(
                (int)$prior['user_id'], (string)$prior['username'], (string)$prior['account_type'],
                $deviceGrantToken
            );
        }
        if ($grant['consumed_at'] !== null) {
            throw new ActivationException(
                ActivationException::GRANT_CONSUMED, 409,
                'The activation session was already used.'
            );
        }
        if ((int)$grant['live'] !== 1) {
            throw new ActivationException(
                ActivationException::GRANT_EXPIRED, 410,
                'The activation session expired. Ask the office to reissue a code.'
            );
        }
        $policyErrors = PasswordPolicy::errors($password);
        if ($policyErrors !== []) {
            throw new ActivationException(
                ActivationException::WEAK_PASSWORD, 422,
                implode(' ', $policyErrors)
            );
        }

        // S4: chained device-registration grant minted below (the
        // activation grant is consumed, and inactive accounts cannot
        // start sessions, so without this the device flow deadlocks).
        $deviceGrantToken = null;
        $this->pdo->beginTransaction();
        try {
            // Atomic single-use consumption (D3.3): affected-rows = 1
            // wins; every concurrent loser lands on grant_consumed.
            $consume = $this->pdo->prepare(
                'UPDATE acm_activation_grants SET consumed_at = NOW()'
                . ' WHERE id = :id AND consumed_at IS NULL'
                . ' AND revoked_at IS NULL AND expires_at > NOW()'
            );
            $consume->execute([':id' => (int)$grant['id']]);
            if ($consume->rowCount() !== 1) {
                throw new ActivationException(
                    ActivationException::GRANT_CONSUMED, 409,
                    'The activation session was already used.'
                );
            }
            $ticket = $this->tickets->getForUpdate((int)$grant['ticket_id']);
            if ($ticket === null || $ticket['status'] !== 'grant_verified') {
                throw new ActivationException(
                    ActivationException::TICKET_STATE, 409,
                    'The ticket is not awaiting credentials.'
                );
            }
            $person = $this->persons->find((int)$ticket['person_id']);
            if ($person === null) {
                throw new RuntimeException('Ticket person is missing.');
            }
            $accountType = (string)$ticket['account_type'];
            if ($this->provisioner->hasAccount((int)$ticket['person_id'], $accountType)) {
                // Existing-account collision (D3.4): provisioning
                // blocked, officer review queue. Never auto-merge,
                // never duplicate. The transition COMMITS before the
                // throw so the failure state survives.
                $this->tickets->failActivation(
                    (int)$ticket['id'], 'account_exists',
                    ['grant_fp' => substr($fingerprint, 0, 6) . '...']
                );
                $this->pdo->commit();
                throw new ActivationException(
                    ActivationException::ACCOUNT_EXISTS, 409,
                    'An account already exists for this person. The ticket is queued for officer review.'
                );
            }
            $userId = $this->provisioner->provision(
                $ticket, $person, password_hash($password, PASSWORD_DEFAULT), 0
            );
            $username = $this->provisioner->usernameForUser($userId);
            if ($username === null) {
                throw new RuntimeException('Account was created but could not be reloaded.');
            }
            $this->tickets->markAccountPendingDevice((int)$ticket['id'], [
                'user_id' => $userId,
                'grant_fp' => substr($fingerprint, 0, 6) . '...',
            ]);
            try {
                $this->idempotency->save(
                    $idempotencyKey, $fingerprint, $userId, $username, $accountType,
                    self::IDEMPOTENCY_TTL_H
                );
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                // Same-key concurrent winner committed first: roll
                // back (grant unconsumed, account unmade) and replay
                // or conflict from the winner's row.
                $this->pdo->rollBack();
                $winner = $this->idempotency->findValid($idempotencyKey);
                if ($winner !== null && hash_equals((string)$winner['grant_fingerprint'], $fingerprint)) {
                    $deviceGrantToken = $this->replayDeviceGrant(
                        (int)$grant['ticket_id'], (int)$winner['user_id'], (int)$grant['id']
                    );
                    return new AccountRef(
                        (int)$winner['user_id'], (string)$winner['username'],
                        (string)$winner['account_type'], $deviceGrantToken
                    );
                }
                throw new ActivationException(
                    ActivationException::IDEMPOTENT_CONFLICT, 409,
                    'This idempotency key was already used with a different session.'
                );
            }
            $chained = $this->mintGrant(
                [
                    'id' => (int)$grant['code_id'],
                    'ticket_id' => (int)$grant['ticket_id'],
                    'person_id' => (int)$grant['person_id'],
                ],
                CodePurpose::Activation->value
            );
            $deviceGrantToken = $chained['token'];
            $this->pdo->commit();
        } catch (ActivationException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return new AccountRef($userId, $username, $accountType, $deviceGrantToken);
    }

    // ------------------------------------------------------------
    // S6: completeRecovery (credential recovery for ACTIVE
    // accounts). A reset grant (operation = reset, minted by
    // verifyCode from an officer-issued R-code) authorizes ONE
    // password reset through the shared administrator primitive.
    // Crash order (mysqli password+sessions vs PDO grants+tickets
    // share no transaction -- safe direction, password first):
    //   PDO-A: consume grant + save idempotency row;
    //   MYSQL: lock user, set hash, revoke ALL sessions;
    //   PDO-B: markRecovered (grant_verified -> active).
    // Any crash leaves a state from which the same key + same
    // grant converges: idem row + recovered event replays, idem
    // row without the event resumes with the retry's params.
    // Policy is validated FIRST: a weak password never burns a
    // grant. Devices are untouched (owner D10 #18).
    //
    // @return array{user_id:int,ticket_id:int,username:string,
    //   officer_id:int,officer_username:string,replayed:bool}
    // ------------------------------------------------------------
    public function completeRecovery(
        string $grantToken,
        string $newPassword,
        string $confirmPassword,
        string $idempotencyKey
    ): array {
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $grantToken)) {
            throw new ActivationException(
                ActivationException::GRANT_INVALID, 401,
                'The recovery session is invalid.'
            );
        }
        if (!preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $idempotencyKey
        )) {
            throw new ActivationException(
                ActivationException::INVALID_REQUEST, 400,
                'The idempotency key must be a UUID.'
            );
        }
        // Confirmation + policy BEFORE any grant verdict: a
        // mistyped or weak password never burns a grant.
        if (!hash_equals($newPassword, $confirmPassword)) {
            throw new ActivationException(
                ActivationException::INVALID_REQUEST, 400,
                'The password confirmation does not match.'
            );
        }
        $policyErrors = PasswordPolicy::errors($newPassword);
        if ($policyErrors !== []) {
            throw new ActivationException(
                ActivationException::WEAK_PASSWORD, 422,
                implode(' ', $policyErrors)
            );
        }
        $fingerprint = substr(hash('sha256', $grantToken), 0, 12);
        $grant = $this->grantByFingerprint($fingerprint);
        if ($grant === null || !password_verify($grantToken, (string)$grant['grant_hash'])) {
            throw new ActivationException(
                ActivationException::GRANT_INVALID, 401,
                'The recovery session is invalid.'
            );
        }
        if ($grant['operation'] !== CodePurpose::Reset->value) {
            // Operation binding (D3.3), mirrored from
            // establishCredentials: an activation/device_add grant
            // can never satisfy recovery. Generic message -- no
            // oracle (same code + text as a wrong secret).
            throw new ActivationException(
                ActivationException::GRANT_INVALID, 401,
                'The recovery session is invalid.'
            );
        }
        if ($grant['revoked_at'] !== null) {
            throw new ActivationException(
                ActivationException::GRANT_REVOKED, 403,
                'The recovery session was revoked. Ask the office to reissue a code.'
            );
        }
        $ticketId = (int)$grant['ticket_id'];
        $fpShort = substr($fingerprint, 0, 6) . '...';
        // Idempotency BEFORE the consumed/expired checks (S3 W3
        // precedent): after a success the grant IS consumed, so an
        // uncertain-response retry (same key + same grant) must
        // replay-or-resume, never report grant_consumed.
        $prior = $this->idempotency->findValid($idempotencyKey);
        $resuming = false;
        if ($prior !== null) {
            if (!hash_equals((string)$prior['grant_fingerprint'], $fingerprint)) {
                throw new ActivationException(
                    ActivationException::IDEMPOTENT_CONFLICT, 409,
                    'This idempotency key was already used with a different session.'
                );
            }
            if ($this->hasRecoveredEvent($ticketId, $fpShort)) {
                return $this->recoveryResult($grant, $prior, true);
            }
            // Same key + same grant, no recovered event: a crash
            // (or a mysqli failure) interrupted the first attempt.
            // Resume below: skip consume + idem save, re-resolve,
            // re-run the reset, then close. Consumed/expired
            // verdicts are skipped too -- the grant was live when
            // first consumed (consume requires it), and S3 replays
            // skip verdicts the same way.
            $resuming = true;
        }
        if (!$resuming) {
            if ($grant['consumed_at'] !== null) {
                throw new ActivationException(
                    ActivationException::GRANT_CONSUMED, 409,
                    'The recovery session was already used.'
                );
            }
            if ((int)$grant['live'] !== 1) {
                throw new ActivationException(
                    ActivationException::GRANT_EXPIRED, 410,
                    'The recovery session expired. Ask the office to reissue a code.'
                );
            }
        }
        if ($this->passwordResetter === null) {
            // Fail closed BEFORE consuming anything (S4 revoker
            // precedent): recovery without the password writer
            // must 503, never half-complete.
            throw new RuntimeException('Credential recovery is unavailable.');
        }
        // Officer attribution (honest audit): the officer who
        // issued the code authorized this recovery after the
        // in-person ID check. A code without an issuer fails
        // closed -- never attributed to a substitute.
        $issuer = $this->codeIssuer((int)$grant['code_id']);
        if ($issuer === null) {
            throw new RuntimeException('Recovery authorization is unavailable.');
        }
        // PDO-A (+ retry net for a same-key concurrent winner):
        // conditional grant consume, ticket lock + state, ACTIVE
        // account resolution, idem save -- one transaction.
        $resolution = null;
        for ($attempt = 0; $attempt < 2 && $resolution === null; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                if (!$resuming) {
                    $consume = $this->pdo->prepare(
                        'UPDATE acm_activation_grants SET consumed_at = NOW()'
                        . ' WHERE id = :id AND consumed_at IS NULL'
                        . ' AND revoked_at IS NULL AND expires_at > NOW()'
                    );
                    $consume->execute([':id' => (int)$grant['id']]);
                    if ($consume->rowCount() !== 1) {
                        throw new ActivationException(
                            ActivationException::GRANT_CONSUMED, 409,
                            'The recovery session was already used.'
                        );
                    }
                }
                $ticket = $this->tickets->getForUpdate($ticketId);
                if ($ticket === null || $ticket['status'] !== 'grant_verified') {
                    throw new ActivationException(
                        ActivationException::TICKET_STATE, 409,
                        'The ticket is not awaiting recovery.'
                    );
                }
                $accountType = (string)$ticket['account_type'];
                $userId = $this->provisioner->userIdFor((int)$ticket['person_id'], $accountType);
                if ($userId === null || !$this->userIsActive($userId)) {
                    // Recovery is for ACTIVE accounts only: pending
                    // accounts finish activation first; suspended
                    // or deactivated accounts go through the
                    // reactivation lifecycle, never this ceremony.
                    throw new ActivationException(
                        ActivationException::TICKET_STATE, 409,
                        'This account cannot be recovered from this ticket.'
                    );
                }
                $username = $this->provisioner->usernameForUser($userId);
                if ($username === null) {
                    throw new RuntimeException('Account was resolved but could not be reloaded.');
                }
                if (!$resuming) {
                    $this->idempotency->save(
                        $idempotencyKey, $fingerprint, $userId, $username, $accountType,
                        self::IDEMPOTENCY_TTL_H
                    );
                }
                $this->pdo->commit();
                $resolution = ['user_id' => $userId, 'username' => $username];
            } catch (\PDOException $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($e->getCode() !== '23000' || $resuming) {
                    throw $e;
                }
                // Same-key concurrent winner committed first: roll
                // back (grant unconsumed, nothing written) and
                // replay-or-resume from the winner's row (a resumed
                // attempt saves nothing, so the loop below ends).
                $winner = $this->idempotency->findValid($idempotencyKey);
                if ($winner === null
                    || !hash_equals((string)$winner['grant_fingerprint'], $fingerprint)
                ) {
                    throw new ActivationException(
                        ActivationException::IDEMPOTENT_CONFLICT, 409,
                        'This idempotency key was already used with a different session.'
                    );
                }
                if ($this->hasRecoveredEvent($ticketId, $fpShort)) {
                    return $this->recoveryResult($grant, $winner, true);
                }
                $resuming = true;
            } catch (ActivationException $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }
        if ($resolution === null) {
            // Unreachable (a resumed attempt saves nothing and so
            // never 23000s) -- fail loud, never half-complete.
            throw new RuntimeException('Recovery could not begin.');
        }
        // MYSQL (outside every PDO txn -- the connections cannot
        // share one): lock the users row, set the hash, revoke ALL
        // refresh sessions. Repeat-safe: the same password
        // re-hashed, revocation COALESCE-guarded.
        ($this->passwordResetter)(
            (int)$issuer['id'], (int)$resolution['user_id'], $newPassword
        );
        // PDO-B: close the ceremony. markRecovered is idempotent
        // for this fingerprint (a same-fp double-submit that lost
        // the race converges here instead of 409ing).
        $this->pdo->beginTransaction();
        try {
            $this->tickets->markRecovered($ticketId, [
                'user_id' => (int)$resolution['user_id'],
                'grant_fp' => $fpShort,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return [
            'user_id' => (int)$resolution['user_id'],
            'ticket_id' => $ticketId,
            'username' => (string)$resolution['username'],
            'officer_id' => (int)$issuer['id'],
            'officer_username' => (string)$issuer['username'],
            'replayed' => false,
        ];
    }

    /**
     * S6 replay shape: the idem row carries the user, the loaded
     * grant carries the ticket, the code row carries the
     * officer. $row is an idempotency row (prior or winner).
     *
     * @return array{user_id:int,ticket_id:int,username:string,
     *   officer_id:int,officer_username:string,replayed:bool}
     */
    private function recoveryResult(array $grant, array $row, bool $replayed): array
    {
        $issuer = $this->codeIssuer((int)$grant['code_id']);
        if ($issuer === null) {
            throw new RuntimeException('Recovery authorization is unavailable.');
        }
        return [
            'user_id' => (int)$row['user_id'],
            'ticket_id' => (int)$grant['ticket_id'],
            'username' => (string)$row['username'],
            'officer_id' => (int)$issuer['id'],
            'officer_username' => (string)$issuer['username'],
            'replayed' => $replayed,
        ];
    }

    /**
     * S6: the officer who issued a code (honest audit
     * attribution). Null when the code or its issuer is gone --
     * callers fail closed, never substitute.
     *
     * @return array{id:int,username:string}|null
     */
    private function codeIssuer(int $codeId): ?array
    {
        if ($codeId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT c.issued_by AS id, u.username AS username'
            . ' FROM acm_activation_codes c LEFT JOIN users u ON u.id = c.issued_by'
            . ' WHERE c.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $codeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)$row['id'] <= 0) {
            return null;
        }
        return ['id' => (int)$row['id'], 'username' => (string)($row['username'] ?? '')];
    }

    /**
     * S6: has this ticket recorded a `recovered` event for this
     * grant fingerprint? Same truncated shape markRecovered()
     * writes (LOCATE = literal match, no LIKE semantics).
     */
    private function hasRecoveredEvent(int $ticketId, string $fpShort): bool
    {
        if ($ticketId <= 0 || $fpShort === '') {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM acm_ticket_events"
            . " WHERE ticket_id = :t AND event = 'recovered'"
            . ' AND LOCATE(:m, meta) > 0 LIMIT 1'
        );
        $stmt->execute([':t' => $ticketId, ':m' => '"grant_fp":"' . $fpShort . '"']);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * S6: the users row must be live (is_active = 1). Recovery
     * never revives deactivated accounts -- that is the
     * reactivation lifecycle, not this ceremony.
     */
    private function userIsActive(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT is_active FROM users WHERE id = :u LIMIT 1');
        $stmt->execute([':u' => $userId]);
        return (int)$stmt->fetchColumn() === 1;
    }

    // ------------------------------------------------------------
    // S4: grant-authorized registration context (D3.6 step 2).
    // Grants are owned HERE (single validation point); device
    // state is owned by DeviceService; the route passes this row
    // to DeviceService::beginFull(). Ticket-state here is a
    // fail-fast read -- complete() re-validates under lock.
    // ------------------------------------------------------------
    /**
     * @return array{id:int,ticket_id:int,user_id:int,operation:string}
     */
    public function resolveRegistrationGrant(string $grantToken): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $grantToken)) {
            throw new ActivationException(
                ActivationException::GRANT_INVALID, 401,
                'The registration session is invalid.'
            );
        }
        $fingerprint = substr(hash('sha256', $grantToken), 0, 12);
        $grant = $this->grantByFingerprint($fingerprint);
        if ($grant === null || !password_verify($grantToken, (string)$grant['grant_hash'])) {
            throw new ActivationException(
                ActivationException::GRANT_INVALID, 401,
                'The registration session is invalid.'
            );
        }
        if (!in_array(
            (string)$grant['operation'],
            \SSMS\Acm\Device\DeviceService::REGISTRATION_OPERATIONS,
            true
        )) {
            throw new ActivationException(
                ActivationException::GRANT_INVALID, 401,
                'The registration session is invalid.'
            );
        }
        if ($grant['revoked_at'] !== null) {
            throw new ActivationException(
                ActivationException::GRANT_REVOKED, 403,
                'The registration session was revoked. Ask the office to reissue a code.'
            );
        }
        if ($grant['consumed_at'] !== null) {
            throw new ActivationException(
                ActivationException::GRANT_CONSUMED, 409,
                'The registration session was already used.'
            );
        }
        if ((int)$grant['live'] !== 1) {
            throw new ActivationException(
                ActivationException::GRANT_EXPIRED, 410,
                'The registration session expired. Ask the office to reissue a code.'
            );
        }
        // S10: staff grants carry no ticket — the request row is the
        // provenance. Approved + live request, live staff account, or
        // the grant is unusable (fail-fast read; complete() re-checks
        // under lock).
        if ((string)$grant['operation'] === 'staff_enroll') {
            return $this->resolveStaffGrant((int)$grant['id']);
        }
        $ticket = $this->tickets->get((int)$grant['ticket_id']);
        $expected = $grant['operation'] === CodePurpose::Activation->value
            ? 'account_pending_device' : 'grant_verified';
        if ($ticket === null || $ticket['status'] !== $expected) {
            throw new ActivationException(
                ActivationException::TICKET_STATE, 409,
                'The ticket is not awaiting device registration.'
            );
        }
        // The provisioned account for this ticket's person + type
        // (activation path: created inactive by establishCredentials;
        // device_add path: the existing account). No is_active check:
        // activation is ALWAYS inactive here by design, and a device
        // row on an inactive account is inert (login still 403s).
        $userId = $this->provisioner->userIdFor(
            (int)$ticket['person_id'], (string)$ticket['account_type']
        );
        if ($userId === null) {
            throw new RuntimeException('Registration account link is missing.');
        }
        return [
            'id' => (int)$grant['id'],
            'ticket_id' => (int)$grant['ticket_id'],
            'user_id' => (int)$userId,
            'operation' => (string)$grant['operation'],
        ];
    }

    // ------------------------------------------------------------
    // Console read: code status (never secret/grant).
    // ------------------------------------------------------------
    public function codeStatus(int $ticketId): array
    {
        $ticket = $this->tickets->get($ticketId);
        if ($ticket === null) {
            throw new InvalidArgumentException('Ticket was not found.');
        }
        $active = $this->pdo->prepare(
            'SELECT id, purpose, prefix, selector, issued_by, issued_at, expires_at,'
            . ' attempts, max_attempts, reissue_of,'
            . ' (expires_at > NOW()) AS live'
            . ' FROM acm_activation_codes'
            . ' WHERE ticket_id = :t AND status = \'active\' ORDER BY id DESC LIMIT 1'
        );
        $active->execute([':t' => $ticketId]);
        $live = $active->fetch(PDO::FETCH_ASSOC);
        $last = $this->pdo->prepare(
            'SELECT id, purpose, prefix, selector, status, issued_at, expires_at,'
            . ' attempts, max_attempts, consumed_at'
            . ' FROM acm_activation_codes'
            . ' WHERE ticket_id = :t ORDER BY id DESC LIMIT 1'
        );
        $last->execute([':t' => $ticketId]);
        $lastRow = $last->fetch(PDO::FETCH_ASSOC);
        return [
            'ticket_status' => (string)$ticket['status'],
            'active' => is_array($live) ? $live : null,
            'last' => is_array($lastRow) ? $lastRow : null,
            'velocity_1h' => $this->ticketVelocity($ticketId),
            'velocity_limit' => self::TICKET_VELOCITY_LIMIT,
        ];
    }

    // ------------------------------------------------------------
    // Internal helpers.
    // ------------------------------------------------------------

    /**
     * Record a failed redemption, burn/expire when the budget is
     * spent, COMMIT the accounting, then throw the ONE generic
     * failure. Never returns normally.
     */
    private function failRedemption(array $code, ?string $ip): never
    {
        $codeId = (int)$code['id'];
        if ($code['status'] === 'active') {
            $inc = $this->pdo->prepare(
                'UPDATE acm_activation_codes SET attempts = attempts + 1 WHERE id = :id'
            );
            $inc->execute([':id' => $codeId]);
            $attempts = (int)$code['attempts'] + 1;
            if ($attempts >= (int)$code['max_attempts']) {
                $burn = $this->pdo->prepare(
                    'UPDATE acm_activation_codes SET status = \'revoked\''
                    . ' WHERE id = :id AND status = \'active\''
                );
                $burn->execute([':id' => $codeId]);
                $this->tickets->recordEvent(
                    (int)$code['ticket_id'], null, 'code_burned', '',
                    ['selector' => $code['selector'], 'attempts' => $attempts]
                );
                $this->tickets->markExpired((int)$code['ticket_id'], 'code_burned');
            } elseif ((int)$code['live'] !== 1) {
                // Lazy expiry: the row flips on access; the single
                // 'expired' ticket event comes from markExpired so
                // the timeline never shows it twice.
                $exp = $this->pdo->prepare(
                    'UPDATE acm_activation_codes SET status = \'expired\''
                    . ' WHERE id = :id AND status = \'active\''
                );
                $exp->execute([':id' => $codeId]);
                $this->tickets->markExpired((int)$code['ticket_id'], 'code_expired');
            }
        }
        if ($ip !== null && $ip !== '') {
            $this->recordPairFailure((string)$code['selector'], $ip);
        }
        $this->pdo->commit();
        throw ActivationException::verifyFailed();
    }

    private function lockedCode(string $selector): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, ticket_id, person_id, purpose, selector, secret_hash, status,'
            . ' attempts, max_attempts, (expires_at > NOW()) AS live'
            . ' FROM acm_activation_codes WHERE selector = :s LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':s' => $selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function pairBlocked(string $selector, string $ip): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT attempts FROM acm_activation_attempts WHERE selector = :s AND ip = :ip'
            . ' AND attempts >= ' . (int)self::PAIR_LIMIT
            . ' AND updated_at > DATE_SUB(NOW(), INTERVAL ' . (int)self::PAIR_WINDOW_MIN . ' MINUTE)'
            . ' LIMIT 1'
        );
        $stmt->execute([':s' => $selector, ':ip' => $ip]);
        return $stmt->fetchColumn() !== false;
    }

    private function recordPairFailure(string $selector, string $ip): void
    {
        // Lazy window: a stale counter restarts at 1 instead of
        // growing forever (no cron on shared hosting).
        $stmt = $this->pdo->prepare(
            'INSERT INTO acm_activation_attempts (selector, ip, attempts) VALUES (:s, :ip, 1)'
            . ' ON DUPLICATE KEY UPDATE attempts = IF(updated_at < DATE_SUB(NOW(), INTERVAL '
            . (int)self::PAIR_WINDOW_MIN . ' MINUTE), 1, attempts + 1), updated_at = NOW()'
        );
        $stmt->execute([':s' => $selector, ':ip' => $ip]);
    }

    private function clearPair(string $selector, string $ip): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM acm_activation_attempts WHERE selector = :s AND ip = :ip LIMIT 1'
        );
        $stmt->execute([':s' => $selector, ':ip' => $ip]);
    }

    private function ticketVelocity(int $ticketId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(a.attempts), 0) FROM acm_activation_attempts a'
            . ' JOIN acm_activation_codes c ON c.selector = a.selector'
            . ' WHERE c.ticket_id = :t'
            . ' AND a.updated_at > DATE_SUB(NOW(), INTERVAL '
            . (int)self::TICKET_VELOCITY_WINDOW_H . ' HOUR)'
        );
        $stmt->execute([':t' => $ticketId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Mint the 15-minute single-use grant. Fingerprint (12 hex of
     * the token's SHA-256) is the indexed lookup key; only a hash
     * of the full token is stored (D3.3).
     */
    private function mintGrant(array $code, string $operation): array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $fingerprint = substr(hash('sha256', $token), 0, 12);
            try {
                $ins = $this->pdo->prepare(
                    'INSERT INTO acm_activation_grants'
                    . ' (uuid, code_id, ticket_id, person_id, operation, grant_hash, fingerprint,'
                    . '  expires_at)'
                    . ' VALUES (:uuid, :code, :t, :p, :op, :hash, :fp,'
                    . '  DATE_ADD(NOW(), INTERVAL ' . (int)self::GRANT_TTL_MIN . ' MINUTE))'
                );
                $ins->execute([
                    ':uuid' => AcmPersonService::uuid4(),
                    ':code' => (int)$code['id'],
                    ':t' => (int)$code['ticket_id'],
                    ':p' => (int)$code['person_id'],
                    ':op' => $operation,
                    ':hash' => password_hash($token, PASSWORD_DEFAULT),
                    ':fp' => $fingerprint,
                ]);
                $id = (int)$this->pdo->lastInsertId();
                $sel = $this->pdo->prepare(
                    'SELECT expires_at FROM acm_activation_grants WHERE id = :id LIMIT 1'
                );
                $sel->execute([':id' => $id]);
                return [
                    'id' => $id,
                    'token' => $token,
                    'fingerprint' => $fingerprint,
                    'expires_at' => (string)$sel->fetchColumn(),
                ];
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000' || $attempt === 4) {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('Grant issuance failed.');
    }

    /**
     * S10: staff-enrollment grant. Same 15-minute single-use shape as
     * every other grant; staff have no code/ticket/person rows so the
     * entity links are 0-sentinels and the request row (grant_id) is
     * the provenance. Joins the caller's transaction (decide()).
     *
     * @return array{id:int,token:string,fingerprint:string,expires_at:string}
     */
    public function mintStaffEnrollGrant(array $request): array
    {
        if ((string)($request['status'] ?? '') !== 'requested'
            || (int)($request['user_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Enrollment request is not approvable.');
        }
        return $this->mintGrant(
            ['id' => 0, 'ticket_id' => 0, 'person_id' => 0],
            'staff_enroll'
        );
    }

    /**
     * S4: idempotent-replay device grant. REVOKE-then-MINT in one
     * transaction: the ticket's outstanding live-but-unused device
     * grant is revoked (the client retries BECAUSE it never got a
     * response, so the old grant is unreceivable) and a fresh one
     * minted. Returns null when a device is already active (the
     * route reports device_registered). Concurrent retries converge:
     * every retry invalidates the previous unused grant and the
     * client always uses its latest response. Pre-084 safe: a
     * missing acm_devices table reads as "no active device"
     * (42S02-only catch -- every other error propagates).
     */
    private function replayDeviceGrant(int $ticketId, int $userId, int $excludeGrantId): ?string
    {
        try {
            $chk = $this->pdo->prepare(
                'SELECT id FROM acm_devices WHERE user_id = :u AND status = \'active\' LIMIT 1'
            );
            $chk->execute([':u' => $userId]);
            if ($chk->fetchColumn() !== false) {
                return null;
            }
        } catch (\PDOException $e) {
            if ($e->getCode() !== '42S02') {
                throw $e;
            }
        }
        $this->pdo->beginTransaction();
        try {
            $revoke = $this->pdo->prepare(
                'UPDATE acm_activation_grants SET revoked_at = NOW(),'
                . ' revocation_reason = \'credentials_replay\''
                . ' WHERE ticket_id = :t AND operation = \'activation\' AND id <> :self'
                . ' AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at > NOW()'
            );
            $revoke->execute([':t' => $ticketId, ':self' => $excludeGrantId]);
            $src = $this->pdo->prepare(
                'SELECT code_id, ticket_id, person_id FROM acm_activation_grants'
                . ' WHERE id = :id LIMIT 1'
            );
            $src->execute([':id' => $excludeGrantId]);
            $lineage = $src->fetch(PDO::FETCH_ASSOC);
            if (!is_array($lineage)) {
                throw new RuntimeException('Replay grant lineage is missing.');
            }
            $fresh = $this->mintGrant(
                [
                    'id' => (int)$lineage['code_id'],
                    'ticket_id' => (int)$lineage['ticket_id'],
                    'person_id' => (int)$lineage['person_id'],
                ],
                CodePurpose::Activation->value
            );
            $this->pdo->commit();
            return $fresh['token'];
        } catch (ActivationException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * S10: resolve a `staff_enroll` grant to its staffer. Same return
     * shape as the family path (ticket_id 0: no ticket exists).
     *
     * @return array{id:int,ticket_id:int,user_id:int,operation:string}
     */
    private function resolveStaffGrant(int $grantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.user_id, r.status, (r.expires_at > NOW()) AS live,'
            . ' u.is_active AS staff_active'
            . ' FROM acm_staff_enrollment_requests r'
            . ' LEFT JOIN users u ON u.id = r.user_id'
            . ' WHERE r.grant_id = :g LIMIT 1'
        );
        $stmt->execute([':g' => $grantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || (string)$row['status'] !== 'approved'
            || (int)$row['live'] !== 1
            || (int)($row['staff_active'] ?? 0) !== 1) {
            throw new ActivationException(
                ActivationException::GRANT_INVALID, 401,
                'The registration session is invalid.'
            );
        }
        return [
            'id' => $grantId,
            'ticket_id' => 0,
            'user_id' => (int)$row['user_id'],
            'operation' => 'staff_enroll',
        ];
    }

    private function grantByFingerprint(string $fingerprint): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code_id, ticket_id, person_id, operation, grant_hash,'
            . ' consumed_at, revoked_at, (expires_at > NOW()) AS live'
            . ' FROM acm_activation_grants WHERE fingerprint = :fp LIMIT 1'
        );
        $stmt->execute([':fp' => $fingerprint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function codeExpiry(int $codeId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT expires_at FROM acm_activation_codes WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $codeId]);
        return (string)$stmt->fetchColumn();
    }
}
