<?php
/**
 * ACM S5 — refresh-time DPoP proof verifier (design D3.8).
 * ------------------------------------------------------------
 * Bound refresh families carry acm_device_id + acm_bound on the
 * session row; every rotation of such a family must present a
 * DPoP proof signed by the REGISTERED device key (baseline §7
 * "DPoP-style proofs"). This class runs INSIDE the rotation
 * transaction (injected callable, S4 revoker precedent), so the
 * jti replay-claim commits or rolls back WITH the rotation: a
 * proof is consumed if and only if its rotation commits.
 *
 * Deliberate handle choice: mysqli, not the ACM PDO handle. The
 * caller (RefreshTokenService::rotateTracked) owns a mysqli
 * transaction holding the session row FOR UPDATE; the key read +
 * jti claim must join THAT transaction, and a second connection
 * could neither see its uncommitted state nor share its fate.
 * Route-layer pre-verification would split proof consumption
 * from rotation (false replays on client retry-after-crash), so
 * the single-transaction shape wins over the one-handle idiom.
 *
 * Race note: the device row is read WITHOUT FOR UPDATE. Revocation
 * kills families FIRST (S4 order) while holding the family's
 * session rows FOR UPDATE, so a concurrent revoke either lands
 * before this rotation starts (rotate's revoked check fires first)
 * or blocks until it commits. No lock is needed on the device row
 * itself; the status re-check here is defense in depth.
 *
 * Never throws for proof input (any failure is RESULT_INVALID /
 * RESULT_REPLAY); throws RuntimeException only when ext-openssl
 * is missing or the database fails -- the caller maps both to
 * 'unavailable' (503, fail closed: verification is never skipped).
 *
 * S9 adds per-request proofs (verifyForRequest): the same
 * parse/validate/verify/claim pipeline with (method, request
 * path, token hash) claims instead of the rotation's fixed
 * (POST, refresh route). It runs OUTSIDE any ambient
 * transaction (each API request is its own autocommit unit);
 * replay atomicity comes from the jti PRIMARY KEY, not from a
 * shared commit. The rotation entry point is untouched.
 */

namespace SSMS\Acm\Device;

use RuntimeException;

final class DeviceRefreshProofs
{
    public const RESULT_OK = 'ok';
    public const RESULT_INVALID = 'invalid';
    public const RESULT_REPLAY = 'replay';

    /**
     * Verify a bound-family refresh proof inside the caller's mysqli
     * transaction.
     *
     * @param \mysqli $db          Caller's handle (transaction open).
     * @param int     $deviceId    Family's linked device id (> 0).
     * @param string  $proof       Raw DPoP compact JWT from the header.
     * @param int     $now         Server unix time (single clock read).
     * @param int     $skewSeconds Accepted iat drift either way.
     * @param int     $jtiTtlSeconds Replay-store TTL (covers 2x skew).
     */
    public function verifyForRotation(
        \mysqli $db,
        int $deviceId,
        string $proof,
        int $now,
        int $skewSeconds,
        int $jtiTtlSeconds
    ): string {
        if ($deviceId <= 0 || $proof === '' || $skewSeconds < 0 || $jtiTtlSeconds <= 0) {
            return self::RESULT_INVALID;
        }
        $device = $this->activeDeviceKey($db, $deviceId);
        if ($device === null) {
            // Unknown, revoked, or unreadable key: generic failure, no
            // oracle (a revoked device's families are already dead via
            // the families-first revoke; this branch is unreachable
            // defense in depth, never a distinct wire state).
            return self::RESULT_INVALID;
        }
        $parsed = DeviceCrypto::parseDpopProof($proof, $device['alg']);
        if ($parsed === null) {
            return self::RESULT_INVALID;
        }
        if (!DeviceCrypto::validateDpopClaims($parsed, $now, $skewSeconds)) {
            return self::RESULT_INVALID;
        }
        if (!DeviceCrypto::verifyDpopSignature($device['spki'], $parsed['signingInput'], $parsed['signature'])) {
            return self::RESULT_INVALID;
        }
        return $this->claimJti($db, $parsed['jti'], $deviceId, $jtiTtlSeconds);
    }

    /**
     * Verify a bound-family per-request proof (S9). Same pipeline
     * as rotation -- active key, parse, claims, signature,
     * single-use jti -- with the request's own (method, path,
     * token hash). The $htuPath is the server-reconstructed
     * request path (query already stripped by the caller).
     *
     * @param \mysqli $db       Caller handle (NO ambient
     *                         transaction; the jti claim is its own
     *                         autocommit unit, atomic via PRIMARY KEY).
     * @param int     $deviceId Family's linked device id (> 0).
     * @param string  $proof    Raw DPoP compact JWT from the header.
     * @param string  $accessToken Presented Bearer token (for ath).
     * @param int     $now      Server unix time (single clock read).
     * @param string  $htm      Request method (uppercase).
     * @param string  $htuPath  Request path (no query).
     * @param int     $skewSeconds   Accepted iat drift either way.
     * @param int     $jtiTtlSeconds Replay-store TTL (covers 2x skew).
     */
    public function verifyForRequest(
        \mysqli $db,
        int $deviceId,
        string $proof,
        string $accessToken,
        int $now,
        string $htm,
        string $htuPath,
        int $skewSeconds,
        int $jtiTtlSeconds
    ): string {
        if ($deviceId <= 0 || $proof === '' || $accessToken === ''
            || $htm === '' || $skewSeconds < 0 || $jtiTtlSeconds <= 0
        ) {
            return self::RESULT_INVALID;
        }
        $device = $this->activeDeviceKey($db, $deviceId);
        if ($device === null) {
            // Same no-oracle shape as rotation: unknown, revoked,
            // or unreadable key is one generic failure.
            return self::RESULT_INVALID;
        }
        $parsed = DeviceCrypto::parseDpopProof($proof, $device['alg']);
        if ($parsed === null) {
            return self::RESULT_INVALID;
        }
        $expectedAth = DeviceCrypto::base64UrlEncode(hash('sha256', $accessToken, true));
        if (!DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, $skewSeconds, $htm, $htuPath, $expectedAth
        )) {
            return self::RESULT_INVALID;
        }
        if (!DeviceCrypto::verifyDpopSignature($device['spki'], $parsed['signingInput'], $parsed['signature'])) {
            return self::RESULT_INVALID;
        }
        $result = $this->claimJti($db, $parsed['jti'], $deviceId, $jtiTtlSeconds);
        if ($result === self::RESULT_OK) {
            $this->sweepExpiredJtis($db);
        }
        return $result;
    }

    /**
     * Sampled, bounded replay-store sweep (S9): per-request proofs
     * claim ~10-50k jtis/day, which the rotation-piggybacked
     * 1/1000 sweep provably cannot drain (rotations are only
     * hundreds/day). 1/100 of verified requests delete up to
     * 1000 expired rows -- 10x headroom at peak (see plan §3).
     * Same contract as the 086 sweep: bounded, and a failure
     * NEVER changes the current auth decision.
     */
    private function sweepExpiredJtis(\mysqli $db): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }
        try {
            $db->query(
                'DELETE FROM acm_refresh_proof_jtis'
                . ' WHERE expires_at < CURRENT_TIMESTAMP LIMIT 1000'
            );
        } catch (\Throwable $ignored) {
            // Cleanup is bounded and never changes the auth decision.
        }
    }

    /**
     * Build the rotation verifier closure for the refresh route.
     * Resolves the proof windows once (ops-tunable seeds, compiled
     * defaults on ANY read failure -- the seeds tune timing only,
     * never the signature guarantee) and returns
     * fn($db, $deviceId, $proof) for rotate().
     *
     * @return callable(\mysqli,int,string):string 'ok' on success.
     */
    public static function rotationVerifier(\mysqli $db): callable
    {
        [$skew, $ttl] = self::proofWindows($db);
        $proofs = new self();
        return static function (\mysqli $rotDb, int $deviceId, string $proof) use ($proofs, $skew, $ttl): string {
            return $proofs->verifyForRotation($rotDb, $deviceId, $proof, time(), $skew, $ttl);
        };
    }

    /**
     * Build the per-request verifier closure for apiRequireAuth
     * (S9). Same windows as rotation (uniformity, #16); returns
     * fn($db, $deviceId, $proof, $accessToken, $htm, $htuPath).
     *
     * @return callable(\mysqli,int,string,string,string,string):string 'ok' on success.
     */
    public static function requestVerifier(\mysqli $db): callable
    {
        [$skew, $ttl] = self::proofWindows($db);
        $proofs = new self();
        return static function (
            \mysqli $reqDb,
            int $deviceId,
            string $proof,
            string $accessToken,
            string $htm,
            string $htuPath
        ) use ($proofs, $skew, $ttl): string {
            return $proofs->verifyForRequest(
                $reqDb, $deviceId, $proof, $accessToken, time(), $htm, $htuPath, $skew, $ttl
            );
        };
    }

    /**
     * Proof windows: seeds when sane, compiled defaults otherwise.
     * Misconfiguration fails SAFE (loud error_log + defaults), never
     * with a replay window smaller than the acceptance window.
     *
     * @return array{0:int,1:int} [skewSeconds, jtiTtlSeconds].
     */
    private static function proofWindows(\mysqli $db): array
    {
        $defaultSkew = DeviceCrypto::DEFAULT_PROOF_SKEW_SECONDS;
        $defaultTtl = DeviceCrypto::DEFAULT_PROOF_JTI_TTL_SECONDS;
        $readSkew = null;
        $readTtl = null;
        try {
            $stmt = $db->prepare(
                "SELECT setting_key, setting_value FROM system_settings
                 WHERE setting_key IN ('acm.refresh_proof_skew_seconds','acm.refresh_proof_jti_ttl_seconds')"
            );
            if ($stmt) {
                try {
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        if ($result) {
                            try {
                                while ($row = $result->fetch_assoc()) {
                                    $value = (int)($row['setting_value'] ?? 0);
                                    if ($value < 1) {
                                        continue;
                                    }
                                    if (($row['setting_key'] ?? '') === 'acm.refresh_proof_skew_seconds') {
                                        $readSkew = $value;
                                    } elseif (($row['setting_key'] ?? '') === 'acm.refresh_proof_jti_ttl_seconds') {
                                        $readTtl = $value;
                                    }
                                }
                            } finally {
                                $result->free();
                            }
                        }
                    }
                } finally {
                    $stmt->close();
                }
            }
        } catch (\Throwable $ignored) {
            // Read failure degrades to compiled defaults below (the
            // rotation's own queries stay the loud gate for a down
            // database); only misconfiguration logs here.
        }
        if ($readSkew !== null && $readSkew > 3600) {
            error_log('ACM refresh proof skew seed out of range (1-3600); using default.');
            $readSkew = null;
        }
        if ($readTtl !== null && $readTtl > 86400) {
            error_log('ACM refresh proof TTL seed out of range (1-86400); using default.');
            $readTtl = null;
        }
        $skew = $readSkew ?? $defaultSkew;
        $ttl = $readTtl ?? $defaultTtl;
        if ($ttl < 2 * $skew) {
            error_log('ACM refresh proof windows misconfigured (ttl < 2x skew); using defaults.');
            return [$defaultSkew, $defaultTtl];
        }
        return [$skew, $ttl];
    }

    /**
     * @return array{spki:string,alg:string}|null Active device's key
     *         only; null covers unknown id, non-active status, and
     *         corrupt stored keys (all one generic failure).
     */
    private function activeDeviceKey(\mysqli $db, int $deviceId): ?array
    {
        // mysqli error mode is process-global and mixed across the
        // codebase (silent vs STRICT): normalize every database
        // failure to RuntimeException so the caller has ONE fail-closed
        // mapping. (MezmurHymnService precedent for the dual check.)
        try {
            $stmt = $db->prepare(
                'SELECT pubkey, alg, status FROM acm_devices WHERE id = ? LIMIT 1'
            );
            if (!$stmt) {
                throw new RuntimeException('Device lookup unavailable.');
            }
            try {
                if (!$stmt->bind_param('i', $deviceId) || !$stmt->execute()) {
                    throw new RuntimeException('Device lookup failed.');
                }
                $result = $stmt->get_result();
                if (!$result) {
                    throw new RuntimeException('Device result unavailable.');
                }
                try {
                    $row = $result->fetch_assoc();
                } finally {
                    $result->free();
                }
            } finally {
                $stmt->close();
            }
        } catch (\mysqli_sql_exception $e) {
            throw new RuntimeException('Device lookup failed.');
        }
        if (!$row || ($row['status'] ?? '') !== 'active') {
            return null;
        }
        $alg = (string)($row['alg'] ?? '');
        if ($alg !== DeviceCrypto::ALG_ES256 && $alg !== DeviceCrypto::ALG_RS256) {
            return null;
        }
        $spki = DeviceCrypto::base64StrictDecode(
            (string)($row['pubkey'] ?? ''), DeviceCrypto::MAX_SPKI_BYTES
        );
        if ($spki === null) {
            return null;
        }
        return ['spki' => $spki, 'alg' => $alg];
    }

    /**
     * Atomically claim the proof jti (caller's transaction). A
     * duplicate-key 23000 means the proof was already used.
     */
    private function claimJti(\mysqli $db, string $jti, int $deviceId, int $jtiTtlSeconds): string
    {
        try {
            $stmt = $db->prepare(
                'INSERT INTO acm_refresh_proof_jtis (jti, device_id, expires_at)'
                . ' VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND))'
            );
            if (!$stmt) {
                throw new RuntimeException('Proof store unavailable.');
            }
            try {
                $claimed = $stmt->bind_param('sii', $jti, $deviceId, $jtiTtlSeconds)
                    && $stmt->execute();
                if (!$claimed) {
                    // Silent mode: 1062/23000 means the jti was already
                    // claimed (replay). Anything else fails closed.
                    if ((int)$stmt->errno === 1062 || (string)$stmt->sqlstate === '23000') {
                        return self::RESULT_REPLAY;
                    }
                    throw new RuntimeException('Proof claim failed.');
                }
            } finally {
                $stmt->close();
            }
        } catch (\mysqli_sql_exception $e) {
            // STRICT mode: the duplicate surfaces as an exception.
            if ((int)$e->getCode() === 1062 || stripos($e->getMessage(), 'duplicate entry') !== false) {
                return self::RESULT_REPLAY;
            }
            throw new RuntimeException('Proof claim failed.');
        }
        return self::RESULT_OK;
    }
}
