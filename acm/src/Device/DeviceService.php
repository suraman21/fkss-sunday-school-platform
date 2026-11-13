<?php
/**
 * ACM S4 — device registry implementation (design §§D3.6, D3.8).
 * ------------------------------------------------------------
 * Implements the S2 DeviceRegistry contract:
 *   beginRegistration    validated key + installation -> pending
 *                        device + hash-only challenge (D3.6
 *                        steps 1-3). Re-begin supersedes prior
 *                        pending rows + open challenges for the
 *                        same (user, installation) -- the S3
 *                        reissue-invalidate pattern, so no cron
 *                        is needed and crashes recover by retry.
 *   completeRegistration challenge echo + signature -> verified
 *                        device (D3.6 steps 4-6). ONE transaction
 *                        re-validates the grant/ticket under
 *                        named locks, enforces both caps, consumes
 *                        the challenge, activates the device and
 *                        (grant paths) transitions the ticket.
 *   revoke               families FIRST (injected mysqli-backed
 *                        revoker), then the device row. Any crash
 *                        leaves sessions dead (safe) with at worst
 *                        an idempotent retry. Repeat-safe.
 *
 * Contract-shape notes (honest deltas from the S2 sketch):
 * - The S2 begin() carries no name/grant binding, so routes call
 *   the concrete beginFull(); begin() delegates as session-bound
 *   with no label. complete() needs the challenge bytes (D3.6
 *   stores hash-only, so the client echoes them); routes call the
 *   concrete completeFull(), while complete() accepts the combined
 *   "<b64url-challenge>.<b64-signature>" form and splits it via
 *   DeviceCrypto::splitProof(). revoke() delegates to
 *   revokeWithReport(), which additionally reports families.
 * - The S2 contract itself is frozen; all richness lives on this
 *   concrete class (listForUser, assertion lookup, last-seen).
 * Time: every expiry comparison stays in SQL (NOW()). PHP never
 * compares timestamps (clocks may differ by timezone).
 */

namespace SSMS\Acm\Device;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Activation\ActivationException;
use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Contracts\DeviceRef;
use SSMS\Acm\Contracts\DeviceRegistry;
use SSMS\Acm\Contracts\RegistrationChallenge;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\Policy\AcmPolicy;

class DeviceService implements DeviceRegistry
{
    /** Named-lock wait, seconds (complete serialization). */
    public const LOCK_TIMEOUT_S = 5;
    /** Safe defaults when a setting row is missing (pre-seed state). */
    public const DEFAULT_CHALLENGE_TTL_MIN = 10;
    public const DEFAULT_CHALLENGE_MAX_ATTEMPTS = 5;
    public const DEFAULT_MAX_ACCOUNTS_PER_DEVICE = 5;
    public const DEFAULT_MAX_DEVICES_PER_ACCOUNT = 5;
    /** Grant operations that may authorize registration (never reset). */
    public const REGISTRATION_OPERATIONS = ['activation', 'device_add'];

    private PDO $pdo;
    private AcmTicketService $tickets;
    /**
     * @var callable|null (int $deviceId): int families revoked.
     * Mysqli-backed, injected by the route/console layer (the ACM
     * package stays PDO-only). Revoke FAILS CLOSED without one.
     */
    private $familyRevoker;

    public function __construct(PDO $pdo, AcmTicketService $tickets, ?callable $familyRevoker = null)
    {
        $this->pdo = $pdo;
        $this->tickets = $tickets;
        $this->familyRevoker = $familyRevoker;
    }

    // ------------------------------------------------------------
    // S2 contract (thin delegates; routes use the Full variants).
    // ------------------------------------------------------------
    public function beginRegistration(
        AccountContext $ctx,
        string $installationId,
        string $publicKey
    ): RegistrationChallenge {
        return $this->beginFull($ctx, $installationId, $publicKey, null, null);
    }

    public function completeRegistration(
        AccountContext $ctx,
        string $challengeId,
        string $signature
    ): DeviceRef {
        $proof = DeviceCrypto::splitProof($signature);
        if ($proof === null) {
            throw ActivationException::challengeFailed();
        }
        return $this->completeFull($ctx, $challengeId, $proof['challenge'], $proof['signature']);
    }

    public function revoke(AccountContext $ctx, string $deviceId, string $reason): void
    {
        $this->revokeWithReport($ctx, $deviceId, $reason);
    }

    // ------------------------------------------------------------
    // begin (D3.6 steps 1-3). $grant is the route-resolved grant
    // row (id, ticket_id, operation) or null for session-authed
    // calls. Security properties are enforced at complete(); begin
    // creates only inert rows, so no re-validation here.
    // ------------------------------------------------------------
    /**
     * @param array{id:int,ticket_id:int,operation:string}|null $grant
     */
    public function beginFull(
        AccountContext $ctx,
        string $installationId,
        string $publicKeyB64,
        ?string $name,
        ?array $grant
    ): RegistrationChallenge {
        $userId = $ctx->userId;
        if ($userId <= 0) {
            throw new InvalidArgumentException('Not authenticated.');
        }
        $installationId = DeviceCrypto::normalizeInstallationId($installationId);
        if (!DeviceCrypto::isValidInstallationId($installationId)) {
            throw new InvalidArgumentException('The installation identifier is invalid.');
        }
        $spki = DeviceCrypto::base64StrictDecode($publicKeyB64, DeviceCrypto::MAX_SPKI_BYTES);
        if ($spki === null) {
            throw new InvalidArgumentException('The device key is invalid.');
        }
        // Algorithm is DETECTED from the key, never trusted from
        // the client (authorized context: specific errors are OK).
        $parsed = DeviceCrypto::parsePublicKey($spki);
        $fingerprint = DeviceCrypto::fingerprint($spki);
        $label = DeviceCrypto::cleanName($name);
        $grantId = null;
        if ($grant !== null) {
            if (!isset($grant['id'], $grant['ticket_id'], $grant['operation'])
                || (int)$grant['id'] <= 0 || (int)$grant['ticket_id'] <= 0
                || !in_array($grant['operation'], self::REGISTRATION_OPERATIONS, true)) {
                throw new InvalidArgumentException('The registration grant is invalid.');
            }
            $grantId = (int)$grant['id'];
        }

        // Fail-fast conflict check (the lock-held check at complete
        // is the real gate): same installation already active under
        // a DIFFERENT key never silently overwrites (standing
        // multi-device rule). Same key re-proves (covers a lost
        // complete response: re-begin, then complete again).
        $existing = $this->activeFor($userId, $installationId);
        if ($existing !== null && !hash_equals((string)$existing['fingerprint'], $fingerprint)) {
            throw new ActivationException(
                ActivationException::DEVICE_CONFLICT, 409,
                'This installation is already registered with a different device key.'
                . ' Revoke the old registration first.'
            );
        }
        if ($existing !== null) {
            // Same-key re-begin after a completed registration: the
            // UNIQUE(user_id, fingerprint) row already exists, so the
            // INSERT below would 23000. Clean 400 instead (the client
            // already holds device_uuid -- replay complete on an
            // uncertain response, never re-begin after success).
            throw new InvalidArgumentException('This installation is already registered.');
        }

        $challenge = DeviceCrypto::generateChallenge();
        $ttlMin = $this->intSetting(
            'acm.device_challenge_ttl_minutes', self::DEFAULT_CHALLENGE_TTL_MIN, 1, 1440
        );
        $maxAttempts = $this->intSetting(
            'acm.device_challenge_max_attempts', self::DEFAULT_CHALLENGE_MAX_ATTEMPTS, 1, 100
        );
        $deviceUuid = AcmPersonService::uuid4();
        $challengeUuid = AcmPersonService::uuid4();

        $this->pdo->beginTransaction();
        try {
            // Supersede: prior pending rows + open challenges for
            // this (user, installation) die atomically. Consumed
            // challenges are KEPT (complete-replay + audit trail).
            $delCh = $this->pdo->prepare(
                'DELETE FROM acm_device_challenges'
                . ' WHERE user_id = :u AND installation_id = :inst AND consumed_at IS NULL'
            );
            $delCh->execute([':u' => $userId, ':inst' => $installationId]);
            $delDev = $this->pdo->prepare(
                'DELETE FROM acm_devices'
                . ' WHERE user_id = :u AND installation_id = :inst AND status = \'pending\''
            );
            $delDev->execute([':u' => $userId, ':inst' => $installationId]);
            $insDev = $this->pdo->prepare(
                'INSERT INTO acm_devices'
                . ' (uuid, user_id, installation_id, pubkey, fingerprint, alg, name, status)'
                . ' VALUES (:uuid, :u, :inst, :pk, :fp, :alg, :name, \'pending\')'
            );
            $insDev->execute([
                ':uuid' => $deviceUuid, ':u' => $userId, ':inst' => $installationId,
                ':pk' => base64_encode($spki), ':fp' => $fingerprint,
                ':alg' => $parsed['alg'], ':name' => $label,
            ]);
            $deviceId = (int)$this->pdo->lastInsertId();
            $insCh = $this->pdo->prepare(
                'INSERT INTO acm_device_challenges'
                . ' (uuid, device_id, user_id, installation_id, pubkey, fingerprint, alg,'
                . '  grant_id, session_bound, challenge_hash, expires_at, max_attempts)'
                . ' VALUES (:uuid, :dev, :u, :inst, :pk, :fp, :alg,'
                . '  :grant, :sb, :hash,'
                . '  DATE_ADD(NOW(), INTERVAL ' . $ttlMin . ' MINUTE), :max)'
            );
            $insCh->execute([
                ':uuid' => $challengeUuid, ':dev' => $deviceId, ':u' => $userId,
                ':inst' => $installationId, ':pk' => base64_encode($spki),
                ':fp' => $fingerprint, ':alg' => $parsed['alg'],
                ':grant' => $grantId, ':sb' => $grantId === null ? 1 : 0,
                ':hash' => $challenge['hash'], ':max' => $maxAttempts,
            ]);
            $sel = $this->pdo->prepare(
                'SELECT expires_at FROM acm_device_challenges WHERE uuid = :uuid LIMIT 1'
            );
            $sel->execute([':uuid' => $challengeUuid]);
            $expiresAt = (string)$sel->fetchColumn();
            $this->pdo->commit();
        } catch (ActivationException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                // Concurrent-begin race: both passes cleared the
                // fail-fast, one INSERT won. Retry converges (the
                // retry supersedes the winner's pending row).
                throw ActivationException::rateLimited();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return new RegistrationChallenge(
            $challengeUuid,
            DeviceCrypto::base64UrlEncode($challenge['bytes']),
            $expiresAt
        );
    }

    // ------------------------------------------------------------
    // complete (D3.6 steps 4-6). $challengeB64u is the client's echo
    // of the begin bytes (hash-compared: the signature must bind to
    // the challenge WE issued); $signatureB64 is base64 of the raw
    // signer output. Every proof failure is the ONE generic
    // challenge_failed -- no oracle.
    // ------------------------------------------------------------
    public function completeFull(
        AccountContext $ctx,
        string $challengeId,
        string $challengeB64u,
        string $signatureB64
    ): DeviceRef {
        if (!DeviceCrypto::isValidUuid($challengeId)) {
            throw ActivationException::challengeFailed();
        }
        $challengeBytes = DeviceCrypto::base64UrlDecode($challengeB64u);
        if ($challengeBytes === null || strlen($challengeBytes) !== DeviceCrypto::CHALLENGE_BYTES) {
            throw ActivationException::challengeFailed();
        }
        $signature = DeviceCrypto::base64StrictDecode($signatureB64, DeviceCrypto::MAX_SIGNATURE_BYTES);
        if ($signature === null) {
            throw ActivationException::challengeFailed();
        }

        $this->pdo->beginTransaction();
        try {
            $challenge = $this->lockedChallenge($challengeId);
            if ($challenge === null) {
                throw ActivationException::challengeFailed();
            }
            if ($challenge['consumed_at'] !== null) {
                // Idempotent replay: consumed challenge + valid
                // signature re-presented -> the same device. The
                // signature is verified AGAIN (possession, not just
                // knowledge of the challenge id).
                $row = $challenge;
                $this->pdo->rollBack();
                return $this->replayCompleted($row, $challengeBytes, $signature);
            }
            if ((int)$challenge['live'] !== 1) {
                // Expired: dead without spending anything (the row
                // stays as the attempt audit trail).
                throw ActivationException::challengeFailed();
            }
            if ((int)$challenge['attempts'] >= (int)$challenge['max_attempts']) {
                throw ActivationException::challengeFailed();
            }
            if ((int)$challenge['user_id'] !== $ctx->userId) {
                // Cross-user probing: NO attempt increment (anti-DoS:
                // strangers must not burn another user's challenge),
                // mirroring S3's unknown-selector rule.
                throw ActivationException::challengeFailed();
            }
            $spki = base64_decode((string)$challenge['pubkey'], true);
            if ($spki === false || $spki === '') {
                throw new RuntimeException('Stored device key is corrupt.');
            }
            if (!hash_equals((string)$challenge['challenge_hash'], hash('sha256', $challengeBytes))) {
                $this->spendAttempt((int)$challenge['id'], (int)$challenge['attempts']);
            }
            if (!DeviceCrypto::verify($spki, $challengeBytes, $signature)) {
                $this->spendAttempt((int)$challenge['id'], (int)$challenge['attempts']);
            }

            // Proof valid. Serialize cap enforcement per user AND per
            // installation (fixed order: user, then installation --
            // pinned, never reversed: reversed order deadlocks under
            // concurrent completes).
            $locks = $this->acquireLocks((int)$challenge['user_id'], (string)$challenge['installation_id']);
            try {
                // Re-validate the authorization under the locks (an
                // officer may have canceled/rejected mid-flight):
                // grant rows FOR UPDATE, ticket row FOR UPDATE.
                $grant = null;
                $ticketId = null;
                $operation = null;
                if ($challenge['grant_id'] !== null) {
                    $grant = $this->lockedGrant((int)$challenge['grant_id']);
                    if ($grant === null) {
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
                    $operation = (string)$grant['operation'];
                    if (!in_array($operation, self::REGISTRATION_OPERATIONS, true)) {
                        throw new ActivationException(
                            ActivationException::GRANT_INVALID, 401,
                            'The registration session is invalid.'
                        );
                    }
                    $ticketId = (int)$grant['ticket_id'];
                    $ticket = $this->tickets->getForUpdate($ticketId);
                    $expected = $operation === 'activation' ? 'account_pending_device' : 'grant_verified';
                    if ($ticket === null || $ticket['status'] !== $expected) {
                        throw new ActivationException(
                            ActivationException::TICKET_STATE, 409,
                            'The ticket is not awaiting device registration.'
                        );
                    }
                }

                // Both caps, excluding the device being activated so
                // a same-key re-proof never self-blocks (D10.3 #12).
                $deviceId = (int)$challenge['device_id'];
                $maxAccounts = $this->intSetting(
                    'acm.max_accounts_per_device', self::DEFAULT_MAX_ACCOUNTS_PER_DEVICE, 1, 100
                );
                $maxDevices = $this->intSetting(
                    'acm.max_devices_per_account', self::DEFAULT_MAX_DEVICES_PER_ACCOUNT, 1, 100
                );
                if ($this->installationAccountCount(
                    (string)$challenge['installation_id'], $deviceId
                ) >= $maxAccounts) {
                    throw new ActivationException(
                        ActivationException::DEVICE_LIMIT, 409,
                        'Too many accounts are registered on this device.'
                    );
                }
                if ($this->userActiveCount((int)$challenge['user_id'], $deviceId) >= $maxDevices) {
                    throw new ActivationException(
                        ActivationException::DEVICE_LIMIT, 409,
                        'Too many devices are registered on this account.'
                    );
                }

                // Atomic single-use consumption; a concurrent loser
                // falls into the deterministic replay branch below.
                $consume = $this->pdo->prepare(
                    'UPDATE acm_device_challenges SET consumed_at = NOW()'
                    . ' WHERE id = :id AND consumed_at IS NULL'
                );
                $consume->execute([':id' => (int)$challenge['id']]);
                if ($consume->rowCount() !== 1) {
                    $this->pdo->rollBack();
                    $fresh = $this->openChallenge($challengeId);
                    if ($fresh !== null && $fresh['consumed_at'] !== null) {
                        return $this->replayCompleted($fresh, $challengeBytes, $signature);
                    }
                    throw ActivationException::challengeFailed();
                }
                $activate = $this->pdo->prepare(
                    'UPDATE acm_devices SET status = \'active\', last_seen = NOW()'
                    . ' WHERE id = :id AND status = \'pending\''
                );
                $activate->execute([':id' => $deviceId]);
                if ($activate->rowCount() !== 1) {
                    // Reachable only if the pending row was revoked
                    // mid-flight (console). Authorized context, so a
                    // specific verdict is safe.
                    throw new ActivationException(
                        ActivationException::DEVICE_REVOKED, 403,
                        'This device registration was revoked before it completed.'
                    );
                }

                $meta = [
                    'device_uuid' => (string)$challenge['device_uuid'],
                    'device_fp' => DeviceCrypto::shortFingerprint((string)$challenge['fingerprint']),
                    'installation' => substr((string)$challenge['installation_id'], 0, 8) . '...',
                ];
                if ($operation === 'activation' && $ticketId !== null) {
                    // The ONLY path that flips users.is_active: a
                    // fresh activation ceremony (S4 plan §1). Session
                    // and device_add paths must never reactivate.
                    $flip = $this->pdo->prepare(
                        'UPDATE users SET is_active = 1 WHERE id = :u AND is_active = 0'
                    );
                    $flip->execute([':u' => (int)$challenge['user_id']]);
                    $this->tickets->markActivated($ticketId, $meta);
                } elseif ($operation === 'device_add' && $ticketId !== null) {
                    $this->tickets->markDeviceRegistered($ticketId, $meta);
                }
                if ($grant !== null) {
                    $consumeGrant = $this->pdo->prepare(
                        'UPDATE acm_activation_grants SET consumed_at = NOW()'
                        . ' WHERE id = :id AND consumed_at IS NULL'
                        . ' AND revoked_at IS NULL AND expires_at > NOW()'
                    );
                    $consumeGrant->execute([':id' => (int)$grant['id']]);
                    if ($consumeGrant->rowCount() !== 1) {
                        throw new ActivationException(
                            ActivationException::GRANT_CONSUMED, 409,
                            'The registration session was already used.'
                        );
                    }
                }
                $sel = $this->pdo->prepare(
                    'SELECT uuid, fingerprint, status FROM acm_devices WHERE id = :id LIMIT 1'
                );
                $sel->execute([':id' => $deviceId]);
                $device = $sel->fetch(PDO::FETCH_ASSOC);
                if (!is_array($device)) {
                    throw new RuntimeException('Activated device could not be reloaded.');
                }
                $this->pdo->commit();
            } finally {
                $this->releaseLocks($locks);
            }
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
        return new DeviceRef(
            (string)$device['uuid'], (string)$device['fingerprint'], (string)$device['status']
        );
    }

    // ------------------------------------------------------------
    // revoke (+ report). Families FIRST via the injected revoker,
    // then the device row: the crash-safe direction. Repeat-safe.
    // ------------------------------------------------------------
    /**
     * @return array{revoked:bool,families:int,already:bool}
     */
    public function revokeWithReport(AccountContext $ctx, string $deviceId, string $reason): array
    {
        if ($this->familyRevoker === null) {
            // Fail closed: a revocation that silently skipped the
            // session cascade would be worse than an error.
            throw new RuntimeException('Device revocation is unavailable.');
        }
        if (!DeviceCrypto::isValidUuid($deviceId)) {
            throw new InvalidArgumentException('Device was not found.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, uuid, user_id, status FROM acm_devices WHERE uuid = :uuid LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([':uuid' => $deviceId]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($device)) {
                throw new InvalidArgumentException('Device was not found.');
            }
            $officer = AcmPolicy::can($ctx->role, AcmPolicy::RES_DEVICE, 'manage');
            if (!$officer && (int)$device['user_id'] !== $ctx->userId) {
                // Same generic verdict as unknown: no cross-account
                // oracle for self-service callers.
                throw new InvalidArgumentException('Device was not found.');
            }
            $cleanReason = DeviceCrypto::cleanReason($reason);
            if ($officer) {
                // Console revocation needs an auditable reason (the
                // S3 ticket-cancel rule, 3-2000 chars).
                $len = $cleanReason === null ? 0 : mb_strlen($cleanReason);
                if ($len < 3 || $len > 2000) {
                    throw new InvalidArgumentException('A reason of 3-2000 characters is required.');
                }
            }
            if ($device['status'] === 'revoked') {
                // Provably complete: families-first order means a
                // revoked row already ran its cascade.
                $this->pdo->commit();
                return ['revoked' => true, 'families' => 0, 'already' => true];
            }
            // Families FIRST (own mysqli transaction inside the
            // revoker -- it cannot join this PDO transaction). A
            // throw rolls back below with the device untouched.
            $families = (int)($this->familyRevoker)((int)$device['id']);
            $upd = $this->pdo->prepare(
                'UPDATE acm_devices SET status = \'revoked\', revoked_by = :by,'
                . ' revoked_at = NOW(), revoke_reason = :reason'
                . ' WHERE id = :id AND status <> \'revoked\''
            );
            $upd->execute([
                ':by' => $officer ? $ctx->userId : null,
                ':reason' => $cleanReason,
                ':id' => (int)$device['id'],
            ]);
            // rowCount 0 = a concurrent revoke won: still success
            // (the cascade above ran twice, harmlessly).
            $this->pdo->commit();
            return ['revoked' => true, 'families' => $families, 'already' => false];
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

    // ------------------------------------------------------------
    // Concrete readers (safe shapes; views truncate for display).
    // ------------------------------------------------------------
    /** @return array<int,array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Not authenticated.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT uuid, name, fingerprint, alg, status, first_seen, last_seen, installation_id'
            . ' FROM acm_devices WHERE user_id = :u ORDER BY status, id'
        );
        $stmt->execute([':u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Validate a login-time device assertion (D3.8 link). Returns the
     * device id on an exact active match, null otherwise (login still
     * succeeds unlinked -- S4 links, S5 enforces).
     */
    public function findActiveForAssertion(
        int $userId,
        string $installationId,
        string $fingerprint
    ): ?int {
        if ($userId <= 0) {
            return null;
        }
        $installationId = DeviceCrypto::normalizeInstallationId($installationId);
        if (!DeviceCrypto::isValidInstallationId($installationId)) {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{64}$/D', $fingerprint)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id FROM acm_devices'
            . ' WHERE user_id = :u AND installation_id = :inst AND fingerprint = :fp'
            . ' AND status = \'active\' LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':inst' => $installationId, ':fp' => $fingerprint]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** Best-effort last-seen touch (login seam); never throws. */
    public function touchLastSeen(int $deviceId): void
    {
        if ($deviceId <= 0) {
            return;
        }
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE acm_devices SET last_seen = NOW() WHERE id = :id AND status = \'active\''
            );
            $stmt->execute([':id' => $deviceId]);
        } catch (\Throwable $ignored) {
            // Telemetry must never break authentication.
        }
    }

    // ------------------------------------------------------------
    // Internal helpers.
    // ------------------------------------------------------------
    private function activeFor(int $userId, string $installationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, fingerprint FROM acm_devices'
            . ' WHERE user_id = :u AND installation_id = :inst AND status = \'active\' LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':inst' => $installationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function lockedChallenge(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.uuid, c.device_id, c.user_id, c.installation_id, c.pubkey,'
            . ' c.fingerprint, c.alg, c.grant_id, c.challenge_hash,'
            . ' c.consumed_at, c.attempts, c.max_attempts,'
            . ' (c.expires_at > NOW()) AS live, d.uuid AS device_uuid'
            . ' FROM acm_device_challenges c'
            . ' LEFT JOIN acm_devices d ON d.id = c.device_id'
            . ' WHERE c.uuid = :uuid LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function openChallenge(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.uuid, c.device_id, c.user_id, c.pubkey,'
            . ' c.fingerprint, c.challenge_hash, c.consumed_at,'
            . ' d.uuid AS device_uuid, d.status AS device_status'
            . ' FROM acm_device_challenges c'
            . ' LEFT JOIN acm_devices d ON d.id = c.device_id'
            . ' WHERE c.uuid = :uuid LIMIT 1'
        );
        $stmt->execute([':uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Replay a consumed challenge: the signature must verify AGAIN
     * against the stored key (possession, not id knowledge), then
     * the CURRENT device state is returned truthfully (a device
     * revoked after registration reports revoked, so the app can
     * show "re-register" instead of failing mysteriously).
     */
    private function replayCompleted(array $challenge, string $challengeBytes, string $signature): DeviceRef
    {
        $spki = base64_decode((string)$challenge['pubkey'], true);
        if ($spki === false || $spki === ''
            || !hash_equals((string)$challenge['challenge_hash'], hash('sha256', $challengeBytes))
            || !DeviceCrypto::verify($spki, $challengeBytes, $signature)) {
            throw ActivationException::challengeFailed();
        }
        if (!isset($challenge['device_uuid']) || $challenge['device_uuid'] === null) {
            throw ActivationException::challengeFailed();
        }
        $stmt = $this->pdo->prepare(
            'SELECT uuid, fingerprint, status FROM acm_devices WHERE uuid = :uuid LIMIT 1'
        );
        $stmt->execute([':uuid' => (string)$challenge['device_uuid']]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($device)) {
            throw ActivationException::challengeFailed();
        }
        return new DeviceRef(
            (string)$device['uuid'], (string)$device['fingerprint'], (string)$device['status']
        );
    }

    /**
     * Spend one proof attempt, COMMIT the accounting, then throw
     * the generic failure (the S3 failRedemption pattern).
     */
    private function spendAttempt(int $challengeId, int $seenAttempts): never
    {
        $inc = $this->pdo->prepare(
            'UPDATE acm_device_challenges SET attempts = attempts + 1 WHERE id = :id'
        );
        $inc->execute([':id' => $challengeId]);
        $this->pdo->commit();
        throw ActivationException::challengeFailed();
    }

    private function lockedGrant(int $grantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, ticket_id, operation, consumed_at, revoked_at,'
            . ' (expires_at > NOW()) AS live'
            . ' FROM acm_activation_grants WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':id' => $grantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function installationAccountCount(string $installationId, int $excludeDeviceId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM acm_devices'
            . ' WHERE installation_id = :inst AND status = \'active\' AND id <> :self'
        );
        $stmt->execute([':inst' => $installationId, ':self' => $excludeDeviceId]);
        return (int)$stmt->fetchColumn();
    }

    private function userActiveCount(int $userId, int $excludeDeviceId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM acm_devices'
            . ' WHERE user_id = :u AND status = \'active\' AND id <> :self'
        );
        $stmt->execute([':u' => $userId, ':self' => $excludeDeviceId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Serialize cap enforcement. Fixed order (user, installation);
     * lock names stay under MySQL's 64-char limit (46 worst case).
     *
     * @return array<int,string> Acquired lock names (for release).
     */
    private function acquireLocks(int $userId, string $installationId): array
    {
        $names = ['acm_dev_u_' . $userId, 'acm_dev_i_' . $installationId];
        $held = [];
        foreach ($names as $name) {
            $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, ' . (int)self::LOCK_TIMEOUT_S . ')');
            $stmt->execute([':name' => $name]);
            if ((int)$stmt->fetchColumn() !== 1) {
                $this->releaseLocks($held);
                throw ActivationException::rateLimited();
            }
            $held[] = $name;
        }
        return $held;
    }

    /** @param array<int,string> $names */
    private function releaseLocks(array $names): void
    {
        foreach ($names as $name) {
            try {
                $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
                $stmt->execute([':name' => $name]);
            } catch (\Throwable $ignored) {
                // Connection end releases named locks anyway.
            }
        }
    }

    private function setting(string $key): ?string
    {
        // No catch (the S3 OfficeNetworkSignal rule): a missing key
        // returns null (the legitimate pre-seed state -> safe
        // default); a genuine database error propagates (500, fail
        // closed), never a permissive fallback.
        $stmt = $this->pdo->prepare(
            'SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1'
        );
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    }

    private function intSetting(string $key, int $default, int $min, int $max): int
    {
        $raw = $this->setting($key);
        if ($raw === null) {
            return $default;
        }
        $trimmed = trim($raw);
        if (!ctype_digit($trimmed)) {
            throw new RuntimeException('Server setting "' . $key . '" is invalid.');
        }
        $value = (int)$trimmed;
        if ($value < $min || $value > $max) {
            throw new RuntimeException('Server setting "' . $key . '" is out of range.');
        }
        return $value;
    }
}
