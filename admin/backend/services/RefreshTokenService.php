<?php
/**
 * Persistent one-time refresh-token sessions.
 *
 * The signed token format remains in API auth.php. This service owns session
 * persistence, atomic rotation, replay detection, account revalidation, legacy
 * one-time exchange, and family revocation.
 * S4 adds an OPTIONAL device link (NULLABLE acm_device_id): linked families
 * die on device revocation; legacy NULL families behave exactly as before.
 */
namespace App\Services;

require_once __DIR__ . '/ProfileService.php';

final class RefreshTokenService
{
    private \mysqli $database;
    /** @var callable */
    private $tokenIssuer;
    private int $refreshTtl;
    /**
     * S4: memoized acm_device_id column probe (per request; PHP-FPM
     * statics reset every request, so a mid-deploy migration flips
     * safely). Null = not probed yet this request.
     */
    private static ?bool $deviceLinkAvailable = null;

    public function __construct(\mysqli $database, callable $tokenIssuer, int $refreshTtl)
    {
        $this->database = $database;
        $this->tokenIssuer = $tokenIssuer;
        $this->refreshTtl = max(300, min($refreshTtl, 31536000));
    }

    /**
     * @param array{id:mixed,username:mixed,role:mixed,full_name:mixed,authorization_version:mixed} $user
     * @param int|null $acmDeviceId S4 device link for the new family (null = legacy unlinked).
     */
    public function issue(array $user, string $clientIp, string $userAgent, ?int $acmDeviceId = null): string
    {
        $familyId = bin2hex(random_bytes(32));
        $issued = $this->issueWithinTransaction($user, $familyId, $clientIp, $userAgent, $acmDeviceId);
        return $issued['token'];
    }

    /**
     * @param array<string,mixed> $payload Verified signed refresh payload
     * @return array{state:string,token?:string,user?:array<string,mixed>}
     */
    public function rotate(string $presentedToken, array $payload, string $clientIp, string $userAgent): array
    {
        $sessionId = (string)($payload['jti'] ?? '');
        $familyId = (string)($payload['fid'] ?? '');
        if ($this->isIdentifier($sessionId) && $this->isIdentifier($familyId)) {
            return $this->rotateTracked($presentedToken, $payload, $clientIp, $userAgent);
        }

        // Compatibility adapter: pre-rotation tokens had no jti/fid. They may
        // enter a tracked family exactly once.
        return $this->exchangeLegacy($presentedToken, $payload, $clientIp, $userAgent);
    }

    // Existing migration010 session primary key provides a bounded lookup;
    // do not scan a family's accumulated rotation history on every API call.
    private const ACCESS_SESSION_SQL =
        'SELECT session_id FROM api_refresh_sessions
         WHERE session_id=? AND family_id=? AND user_id=?
           AND revoked_at IS NULL AND expires_at>CURRENT_TIMESTAMP
         LIMIT 2';

    /**
     * The signed access token's issuing refresh session must still exist,
     * match owner/family, be unrevoked and unexpired. Consumption is NOT
     * revocation: legitimate refresh rotation must not invalidate unexpired
     * access. Family revocation marks ALL sessions, including consumed ones.
     * Query failure throws, never masquerades as revoked/empty. No transaction
     * ownership; future mutators must serialize revocation in their transaction.
     * This does not certify a complete preparation grant/current family roster.
     */
    public static function accessSessionIsActive(\mysqli $database, int $userId, string $familyId, string $sessionId): bool
    {
        if ($userId <= 0 || !preg_match('/^[a-f0-9]{64}$/D', $familyId)
            || !preg_match('/^[a-f0-9]{64}$/D', $sessionId)) {
            return false;
        }
        $statement = $database->prepare(self::ACCESS_SESSION_SQL);
        if (!$statement) throw new \RuntimeException('Refresh-family lookup unavailable.');
        try {
            if (!$statement->bind_param('ssi', $sessionId, $familyId, $userId) || !$statement->execute()) {
                throw new \RuntimeException('Refresh-family lookup failed.');
            }
            $result = $statement->get_result();
            if (!$result) throw new \RuntimeException('Refresh-family result unavailable.');
            try {
                $row = $result->fetch_assoc();
                return $result->num_rows === 1 && ($row['session_id'] ?? null) === $sessionId;
            } finally {
                $result->free();
            }
        } finally {
            $statement->close();
        }
    }

    /** @param array<string,mixed> $payload */
    public function revokePresented(string $presentedToken, array $payload): void
    {
        $tokenHash = hash('sha256', $presentedToken);
        $sessionId = (string)($payload['jti'] ?? '');
        try {
            $this->database->begin_transaction();
            if ($this->isIdentifier($sessionId)) {
                $statement = $this->database->prepare(
                    'SELECT family_id FROM api_refresh_sessions WHERE session_id=? AND token_hash=? LIMIT 1 FOR UPDATE'
                );
                $statement->bind_param('ss', $sessionId, $tokenHash);
                $statement->execute();
                $row = $statement->get_result()->fetch_assoc();
                $statement->close();
                if ($row) {
                    $this->revokeFamily((string)$row['family_id']);
                }
            } else {
                $statement = $this->database->prepare(
                    'SELECT family_id FROM api_refresh_legacy_exchanges WHERE token_hash=? LIMIT 1 FOR UPDATE'
                );
                $statement->bind_param('s', $tokenHash);
                $statement->execute();
                $row = $statement->get_result()->fetch_assoc();
                $statement->close();
                if ($row) {
                    $this->revokeFamily((string)$row['family_id']);
                }
            }
            $this->database->commit();
        } catch (\Throwable $error) {
            try {
                $this->database->rollback();
            } catch (\Throwable $ignored) {
            }
        }
    }

    /**
     * S4: revoke every refresh family with at least one session
     * linked to the device. Legacy (all-NULL) families never match.
     * Atomic: all-or-nothing in one transaction. Idempotent: repeat
     * calls revoke nothing new.
     *
     * Unlike revokePresented (logout UX, which swallows errors),
     * failures PROPAGATE: the device row must not flip to revoked
     * while sessions stay live (fail closed).
     *
     * @return int Distinct families revoked (0 if none / pre-084).
     */
    public function revokeDeviceFamilies(int $deviceId): int
    {
        if ($deviceId <= 0 || !$this->deviceLinkAvailable()) {
            return 0;
        }
        try {
            $this->database->begin_transaction();
            $statement = $this->database->prepare(
                'SELECT DISTINCT family_id FROM api_refresh_sessions WHERE acm_device_id=? FOR UPDATE'
            );
            $statement->bind_param('i', $deviceId);
            $statement->execute();
            $result = $statement->get_result();
            $families = [];
            while ($row = $result->fetch_assoc()) {
                $families[] = (string)$row['family_id'];
            }
            $statement->close();
            foreach ($families as $familyId) {
                $this->revokeFamily($familyId);
            }
            $this->database->commit();
            return count($families);
        } catch (\Throwable $error) {
            try {
                $this->database->rollback();
            } catch (\Throwable $ignored) {
            }
            throw $error;
        }
    }

    /**
     * S4: memoized pre-084 probe. Only consulted when a link was
     * actually requested (or during rotation); unlinked logins pay
     * zero extra queries.
     */
    private function deviceLinkAvailable(): bool
    {
        if (self::$deviceLinkAvailable !== null) {
            return self::$deviceLinkAvailable;
        }
        try {
            $result = $this->database->query(
                "SHOW COLUMNS FROM api_refresh_sessions LIKE 'acm_device_id'"
            );
            self::$deviceLinkAvailable = $result && $result->num_rows === 1;
            if ($result) {
                $result->free();
            }
        } catch (\Throwable $ignored) {
            // Probe failure reads as absent: the INSERT/SELECT right
            // after is the loud gate for genuine database failures,
            // so a down database still fails loudly there -- never
            // as a silent unlinked session.
            self::$deviceLinkAvailable = false;
        }
        return self::$deviceLinkAvailable ?? false;
    }

    /** @return array{state:string,token?:string,user?:array<string,mixed>} */
    private function rotateTracked(
        string $presentedToken,
        array $payload,
        string $clientIp,
        string $userAgent
    ): array {
        $sessionId = (string)$payload['jti'];
        $claimedFamily = (string)$payload['fid'];
        $tokenHash = hash('sha256', $presentedToken);

        try {
            $this->database->begin_transaction();
            // S4: select the device link when the 084 column exists
            // (rotation inherits it below; pre-084 keeps the legacy
            // shape so refresh never breaks on schema skew).
            $linkSelect = $this->deviceLinkAvailable() ? ', acm_device_id' : '';
            $statement = $this->database->prepare(
                'SELECT session_id, family_id, user_id, token_hash, consumed_at, revoked_at,
                        expires_at' . $linkSelect . '
                 FROM api_refresh_sessions WHERE session_id=? LIMIT 1 FOR UPDATE'
            );
            $statement->bind_param('s', $sessionId);
            $statement->execute();
            $session = $statement->get_result()->fetch_assoc();
            $statement->close();

            if (!$session
                || !hash_equals((string)$session['token_hash'], $tokenHash)
                || !hash_equals((string)$session['family_id'], $claimedFamily)
                || (int)$session['user_id'] !== (int)($payload['uid'] ?? 0)) {
                $this->database->rollback();
                return ['state' => 'invalid'];
            }

            if ($session['consumed_at'] !== null) {
                $this->revokeFamily((string)$session['family_id']);
                $this->database->commit();
                return ['state' => 'reused'];
            }
            if ($session['revoked_at'] !== null) {
                $this->database->commit();
                return ['state' => 'revoked'];
            }
            if (strtotime((string)$session['expires_at']) <= time()) {
                $this->revokeFamily((string)$session['family_id']);
                $this->database->commit();
                return ['state' => 'expired'];
            }

            $account = $this->findUserForUpdate((int)$session['user_id']);
            if (($account['state'] ?? '') !== 'active') {
                $this->revokeFamily((string)$session['family_id']);
                $this->database->commit();
                return ['state' => (string)($account['state'] ?? 'account_removed')];
            }
            $user = $account['user'];

            $inheritDevice = isset($session['acm_device_id']) && $session['acm_device_id'] !== null
                ? (int)$session['acm_device_id'] : null;
            $issued = $this->issueWithinTransaction(
                $user,
                (string)$session['family_id'],
                $clientIp,
                $userAgent,
                $inheritDevice
            );
            $statement = $this->database->prepare(
                'UPDATE api_refresh_sessions
                 SET consumed_at=CURRENT_TIMESTAMP, last_used_at=CURRENT_TIMESTAMP, replaced_by=?
                 WHERE session_id=? AND consumed_at IS NULL AND revoked_at IS NULL'
            );
            $statement->bind_param('ss', $issued['session_id'], $sessionId);
            $statement->execute();
            if ($statement->affected_rows !== 1) {
                $statement->close();
                $this->database->rollback();
                return ['state' => 'invalid'];
            }
            $statement->close();
            $this->database->commit();
            $this->cleanupExpiredSessions();

            return ['state' => 'rotated', 'token' => $issued['token'], 'user' => $user];
        } catch (\Throwable $error) {
            try {
                $this->database->rollback();
            } catch (\Throwable $ignored) {
            }
            return ['state' => 'unavailable'];
        }
    }

    /** @return array{state:string,token?:string,user?:array<string,mixed>} */
    private function exchangeLegacy(
        string $presentedToken,
        array $payload,
        string $clientIp,
        string $userAgent
    ): array {
        $userId = (int)($payload['uid'] ?? 0);
        if ($userId <= 0) {
            return ['state' => 'invalid'];
        }
        $tokenHash = hash('sha256', $presentedToken);
        $familyId = bin2hex(random_bytes(32));

        try {
            $this->database->begin_transaction();
            $statement = $this->database->prepare(
                'INSERT IGNORE INTO api_refresh_legacy_exchanges (token_hash, user_id, family_id)
                 VALUES (?, ?, ?)'
            );
            $statement->bind_param('sis', $tokenHash, $userId, $familyId);
            $statement->execute();
            $inserted = $statement->affected_rows === 1;
            $statement->close();

            if (!$inserted) {
                $statement = $this->database->prepare(
                    'SELECT family_id FROM api_refresh_legacy_exchanges WHERE token_hash=? LIMIT 1 FOR UPDATE'
                );
                $statement->bind_param('s', $tokenHash);
                $statement->execute();
                $exchange = $statement->get_result()->fetch_assoc();
                $statement->close();
                if ($exchange) {
                    $this->revokeFamily((string)$exchange['family_id']);
                }
                $this->database->commit();
                return ['state' => 'reused'];
            }

            $account = $this->findUserForUpdate($userId);
            if (($account['state'] ?? '') !== 'active') {
                // Consume the one-time legacy exchange even when the account is
                // gone/disabled so this signed token cannot later bootstrap a
                // fresh tracked family after an account-state transition.
                $this->database->commit();
                return ['state' => (string)($account['state'] ?? 'account_removed')];
            }
            $user = $account['user'];
            // Legacy families stay UNLINKED (S4/D3.8, test 10).
            $issued = $this->issueWithinTransaction($user, $familyId, $clientIp, $userAgent);
            $this->database->commit();
            return ['state' => 'rotated', 'token' => $issued['token'], 'user' => $user];
        } catch (\Throwable $error) {
            try {
                $this->database->rollback();
            } catch (\Throwable $ignored) {
            }
            return ['state' => 'unavailable'];
        }
    }

    /**
     * Caller owns transaction boundaries when applicable.
     *
     * @param array{id:mixed,username:mixed,role:mixed,full_name:mixed,authorization_version:mixed} $user
     * @param int|null $acmDeviceId S4 device link (null = legacy unlinked).
     * @return array{token:string,session_id:string}
     */
    private function issueWithinTransaction(
        array $user,
        string $familyId,
        string $clientIp,
        string $userAgent,
        ?int $acmDeviceId = null
    ): array {
        $sessionId = bin2hex(random_bytes(32));
        $expiresAt = time() + $this->refreshTtl;
        $token = ($this->tokenIssuer)(
            (int)$user['id'],
            (string)$user['username'],
            (string)$user['role'],
            (string)($user['full_name'] ?? ''),
            max(1, (int)($user['authorization_version'] ?? 1)),
            $sessionId,
            $familyId,
            $expiresAt
        );
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Refresh token issuer failed.');
        }

        $tokenHash = hash('sha256', $token);
        $safeIp = substr($clientIp, 0, 45);
        $agentHash = hash('sha256', $userAgent);
        $userId = (int)$user['id'];
        // S4: record the link only when one was requested AND the
        // 084 column exists (pre-084 databases take the legacy shape
        // so S4 code keeps working before the migration -- login must
        // never break on schema skew; the INSERT itself stays the
        // loud gate for genuine database failures).
        $link = ($acmDeviceId !== null && $acmDeviceId > 0 && $this->deviceLinkAvailable())
            ? $acmDeviceId : null;
        if ($link !== null) {
            $statement = $this->database->prepare(
                'INSERT INTO api_refresh_sessions
                    (session_id, family_id, user_id, token_hash, expires_at, created_ip,
                     user_agent_hash, acm_device_id)
                 VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), ?, ?, ?)'
            );
            $statement->bind_param(
                'ssisissi',
                $sessionId,
                $familyId,
                $userId,
                $tokenHash,
                $expiresAt,
                $safeIp,
                $agentHash,
                $link
            );
        } else {
            $statement = $this->database->prepare(
                'INSERT INTO api_refresh_sessions
                    (session_id, family_id, user_id, token_hash, expires_at, created_ip, user_agent_hash)
                 VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), ?, ?)'
            );
            $statement->bind_param(
                'ssisiss',
                $sessionId,
                $familyId,
                $userId,
                $tokenHash,
                $expiresAt,
                $safeIp,
                $agentHash
            );
        }
        $statement->execute();
        $statement->close();

        return ['token' => $token, 'session_id' => $sessionId];
    }

    /**
     * @return array{state:string,user?:array<string,mixed>}
     */
    private function findUserForUpdate(int $userId): array
    {
        $selectSql = 'SELECT id, username, full_name, role, is_active, authorization_version FROM users WHERE id=? LIMIT 1 FOR UPDATE';
        $statement = $this->database->prepare($selectSql);
        $statement->bind_param('i', $userId);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!$user) {
            return ['state' => 'account_removed'];
        }
        if ((int)$user['is_active'] !== 1) {
            return ['state' => 'account_disabled'];
        }
        return [
            'state' => 'active',
            'user' => [
                'id' => (int)$user['id'],
                'username' => (string)$user['username'],
                'email' => isset($user['email']) ? (string)$user['email'] : null,
                'full_name' => (string)($user['full_name'] ?? ''),
                'role' => (string)$user['role'],
                'is_active' => (int)($user['is_active'] ?? 0) === 1,
                'profile_image_path' => $user['profile_image_path'] ?? null,
                'authorization_version' => max(1, (int)$user['authorization_version']),
            ],
        ];
    }

    private function revokeFamily(string $familyId): void
    {
        $statement = $this->database->prepare(
            'UPDATE api_refresh_sessions SET revoked_at=COALESCE(revoked_at, CURRENT_TIMESTAMP)
             WHERE family_id=? AND revoked_at IS NULL'
        );
        $statement->bind_param('s', $familyId);
        $statement->execute();
        $statement->close();
    }

    private function cleanupExpiredSessions(): void
    {
        if (random_int(1, 1000) !== 1) {
            return;
        }
        try {
            $this->database->query(
                'DELETE FROM api_refresh_sessions
                 WHERE expires_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY) LIMIT 1000'
            );
            $this->database->query(
                'DELETE FROM api_refresh_legacy_exchanges
                 WHERE exchanged_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 120 DAY) LIMIT 1000'
            );
        } catch (\Throwable $error) {
            // Cleanup is bounded and never changes the current auth decision.
        }
    }

    private function isIdentifier(string $value): bool
    {
        return strlen($value) === 64 && ctype_xdigit($value);
    }
}
