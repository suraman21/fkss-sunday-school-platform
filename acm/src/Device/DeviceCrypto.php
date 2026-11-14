<?php
/**
 * ACM S4 — device cryptography, pure helpers (design §D3.6).
 * ------------------------------------------------------------
 * No PDO, no I/O: every method is a pure function of its inputs
 * so PHPUnit covers the crypto policy without a database.
 *
 * Wire recipe (established WebAuthn/FIDO2-style pattern, verified
 * against the Android Keystore docs at S4 plan time):
 *   pubkey    base64 of X.509 SubjectPublicKeyInfo DER bytes
 *             (Android: KeyStore certificate .getPublicKey()
 *             .getEncoded()).
 *   alg       detected server-side from the SPKI, never trusted
 *             from the client: EC P-256 -> ES256, RSA >=2048 ->
 *             RS256 (compat for pre-Android-6 devices, D10 #14).
 *   challenge 32 random bytes, transported base64url, stored
 *             hash-only (sha256 hex).
 *   signature base64 of the raw signer output: DER for ES256
 *             (Android SHA256withECDSA emits DER -- no P1363
 *             conversion on either side), raw bytes for RS256.
 * Fingerprint = sha256 hex of the SPKI DER bytes. Logs, audit
 * rows, and list responses carry truncated fingerprints; the
 * complete response returns the device's OWN full fingerprint
 * (the app asserts it at login -- D3.8).
 */

namespace SSMS\Acm\Device;

use InvalidArgumentException;
use RuntimeException;

final class DeviceCrypto
{
    public const ALG_ES256 = 'ES256';
    public const ALG_RS256 = 'RS256';
    /** Largest SPKI DER we accept (P-256 ~91 B, RSA-2048 ~294 B). */
    public const MAX_SPKI_BYTES = 4096;
    /** Largest signature we accept (DER ECDSA ~70 B, RSA-2048 256 B). */
    public const MAX_SIGNATURE_BYTES = 4096;
    public const CHALLENGE_BYTES = 32;
    /** Minimum RSA modulus, bits (D10 #14 compat floor). */
    public const RSA_MIN_BITS = 2048;
    /** The only EC curve we accept (OpenSSL short name). */
    public const EC_CURVE = 'prime256v1';
    // --- S5 refresh-time DPoP proofs (design D3.8; RFC 9449 shape). ---
    /** Largest DPoP proof (compact JWT) accepted on the refresh route. */
    public const MAX_DPOP_PROOF_BYTES = 4096;
    /** Largest decoded DPoP header/payload JSON accepted. */
    public const MAX_DPOP_JSON_BYTES = 2048;
    /** DPoP jti budget (mirrors the 086 VARCHAR(128)). */
    public const DPOP_JTI_MAX_CHARS = 128;
    /** Refresh route path a DPoP htu must end with (host not pinned). */
    public const DPOP_HTU_PATH_SUFFIX = '/auth/refresh-token';
    /** The only htm a refresh proof may carry. */
    public const DPOP_HTM = 'POST';
    /** Compiled-default freshness + replay windows (seeds override). */
    public const DEFAULT_PROOF_SKEW_SECONDS = 300;
    public const DEFAULT_PROOF_JTI_TTL_SECONDS = 900;

    /**
     * Parse + validate a client-submitted SPKI DER key.
     *
     * @return array{alg:string,pem:string} The DETECTED algorithm
     *         (never the client's claim) plus the PEM encoding.
     * @throws InvalidArgumentException structurally invalid key,
     *         wrong type/curve/size, or oversize input.
     * @throws RuntimeException ext-openssl missing (fail closed --
     *         key validation must never be skipped).
     */
    public static function parsePublicKey(string $spkiDer): array
    {
        self::requireOpenSsl();
        if ($spkiDer === '' || strlen($spkiDer) > self::MAX_SPKI_BYTES) {
            throw new InvalidArgumentException('The device key is invalid.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spkiDer), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new InvalidArgumentException('The device key is invalid.');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details)) {
            throw new InvalidArgumentException('The device key is invalid.');
        }
        $type = $details['type'] ?? -1;
        if ($type === OPENSSL_KEYTYPE_EC) {
            $curve = $details['ec']['curve_name'] ?? '';
            if ($curve !== self::EC_CURVE) {
                throw new InvalidArgumentException('The device key uses an unsupported curve.');
            }
            return ['alg' => self::ALG_ES256, 'pem' => $pem];
        }
        if ($type === OPENSSL_KEYTYPE_RSA) {
            if ((int)($details['bits'] ?? 0) < self::RSA_MIN_BITS) {
                throw new InvalidArgumentException('The device key is too small.');
            }
            return ['alg' => self::ALG_RS256, 'pem' => $pem];
        }
        throw new InvalidArgumentException('The device key type is not supported.');
    }

    /**
     * Verify a registration signature. Returns true ONLY on a
     * clean verification (openssl returns 1); every other outcome
     * -- bad signature, corrupt key, oversize input, openssl error
     * -- is false, so callers emit the ONE generic challenge
     * failure with no oracle. Never throws for signature input;
     * throws RuntimeException only when ext-openssl is missing.
     */
    public static function verify(
        string $spkiDer,
        string $challenge,
        string $signature
    ): bool {
        self::requireOpenSsl();
        if ($spkiDer === '' || strlen($spkiDer) > self::MAX_SPKI_BYTES) {
            return false;
        }
        if (strlen($challenge) !== self::CHALLENGE_BYTES) {
            return false;
        }
        if ($signature === '' || strlen($signature) > self::MAX_SIGNATURE_BYTES) {
            return false;
        }
        try {
            $parsed = self::parsePublicKey($spkiDer);
        } catch (InvalidArgumentException $e) {
            return false;
        }
        $result = openssl_verify($challenge, $signature, $parsed['pem'], OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    /** Stable device fingerprint: sha256 hex of the SPKI DER bytes. */
    public static function fingerprint(string $spkiDer): string
    {
        return hash('sha256', $spkiDer);
    }

    /** Truncated fingerprint for logs, audit rows, and responses. */
    public static function shortFingerprint(string $fingerprint): string
    {
        return substr($fingerprint, 0, 8) . '...';
    }

    /** @return array{bytes:string,hash:string} Fresh challenge + its stored hash. */
    public static function generateChallenge(): array
    {
        $bytes = random_bytes(self::CHALLENGE_BYTES);
        return ['bytes' => $bytes, 'hash' => hash('sha256', $bytes)];
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Strict base64url decode; null on any malformed input. */
    public static function base64UrlDecode(string $text): ?string
    {
        if ($text === '' || strlen($text) > 128) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $text)) {
            return null;
        }
        $padded = $text . str_repeat('=', (4 - strlen($text) % 4) % 4);
        $bytes = base64_decode(strtr($padded, '-_', '+/'), true);
        return $bytes === false ? null : $bytes;
    }

    /** Strict standard-alphabet base64 decode; null on malformed input. */
    public static function base64StrictDecode(string $text, int $maxBytes): ?string
    {
        if ($text === '' || strlen($text) > (int)ceil($maxBytes / 3) * 4 + 4) {
            return null;
        }
        $bytes = base64_decode($text, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > $maxBytes) {
            return null;
        }
        return $bytes;
    }

    /**
     * Split the S2 contract proof form "<b64url-challenge>.<b64-
     * signature>" into its raw parts (shape-checked only; the
     * service decodes strictly afterwards). Null on any malformed
     * shape. Routes use the explicit two-field form instead.
     *
     * @return array{challenge:string,signature:string}|null
     */
    public static function splitProof(string $proof): ?array
    {
        if (substr_count($proof, '.') !== 1) {
            return null;
        }
        [$challenge, $signature] = explode('.', $proof, 2);
        if ($challenge === '' || $signature === '') {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $challenge)) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9+\/=]{1,5464}$/', $signature)) {
            return null;
        }
        return ['challenge' => $challenge, 'signature' => $signature];
    }

    /**
     * Installation identifier validation. Same value the app already
     * sends as X-Installation-Id: UUID v1-5, lowercase. (Regex copied
     * from ApiSyncAttemptMonitorService::validatedInstallationId --
     * that helper is private in another team's service; the format
     * is a stable UUID and is pinned by tests on both sides.)
     */
    public static function isValidInstallationId(string $value): bool
    {
        $value = strtolower(trim($value));
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $value
        );
    }

    public static function normalizeInstallationId(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function isValidUuid(string $value): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',
            $value
        );
    }

    /**
     * Officer-visible device label. Trims, strips control characters,
     * caps at 80 chars; empty stays null. Callers must still HTML-escape
     * on render (defense in depth -- this is stored user input).
     */
    public static function cleanName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $clean = trim((string)$clean);
        if ($clean === '') {
            return null;
        }
        return mb_substr($clean, 0, 80);
    }

    /**
     * Auditable revoke reason. Same sanitization as cleanName but
     * the S3 ticket-cancel budget (3-2000 chars, enforced by the
     * service for officer revokes) -- reasons must never be cut to
     * the 80-char device-label width.
     */
    public static function cleanReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $reason);
        $clean = trim((string)$clean);
        if ($clean === '') {
            return null;
        }
        return mb_substr($clean, 0, 2000);
    }

    /**
     * Strict base64url decode with a caller-chosen output cap (the
     * fixed-cap base64UrlDecode stays challenge-sized for S4).
     */
    public static function base64UrlDecodeCapped(string $text, int $maxBytes): ?string
    {
        if ($text === '' || $maxBytes <= 0) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $text)) {
            return null;
        }
        if (strlen($text) > (int)ceil($maxBytes / 3) * 4 + 4) {
            return null;
        }
        $padded = $text . str_repeat('=', (4 - strlen($text) % 4) % 4);
        $bytes = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > $maxBytes) {
            return null;
        }
        return $bytes;
    }

    /**
     * Parse a refresh-time DPoP proof (compact JWT). Shape + header
     * policy only -- claims (htm/htu/iat) are validated separately
     * so tests can distinguish malformed proofs from stale ones; on
     * the wire both are the ONE generic proof failure (no oracle).
     *
     * The embedded `jwk` must be present with the shape of the
     * registered algorithm (catches confused clients loudly) but is
     * NEVER trusted: the caller verifies the signature against the
     * REGISTERED device key, so no attacker-supplied key material
     * can cause key confusion. A `d` (private) member is rejected.
     *
     * @return array{signingInput:string,signature:string,htm:string,htu:string,iat:int,jti:string}|null
     *         Null on ANY malformed input.
     */
    public static function parseDpopProof(string $proof, string $expectedAlg): ?array
    {
        if ($proof === '' || strlen($proof) > self::MAX_DPOP_PROOF_BYTES) {
            return null;
        }
        if ($expectedAlg !== self::ALG_ES256 && $expectedAlg !== self::ALG_RS256) {
            return null;
        }
        if (substr_count($proof, '.') !== 2) {
            return null;
        }
        [$headerPart, $payloadPart, $sigPart] = explode('.', $proof, 3);
        if ($headerPart === '' || $payloadPart === '' || $sigPart === '') {
            return null;
        }
        $headerJson = self::base64UrlDecodeCapped($headerPart, self::MAX_DPOP_JSON_BYTES);
        $payloadJson = self::base64UrlDecodeCapped($payloadPart, self::MAX_DPOP_JSON_BYTES);
        $signature = self::base64UrlDecodeCapped($sigPart, self::MAX_SIGNATURE_BYTES);
        if ($headerJson === null || $payloadJson === null || $signature === null) {
            return null;
        }
        $header = json_decode($headerJson, true);
        if (!is_array($header)) {
            return null;
        }
        $typ = $header['typ'] ?? null;
        if (!is_string($typ) || strtolower($typ) !== 'dpop+jwt') {
            return null;
        }
        $alg = $header['alg'] ?? null;
        if (!is_string($alg) || $alg !== $expectedAlg) {
            return null;
        }
        $jwk = $header['jwk'] ?? null;
        if (!is_array($jwk) || array_key_exists('d', $jwk)) {
            return null;
        }
        $kty = $jwk['kty'] ?? null;
        if ($expectedAlg === self::ALG_ES256) {
            if ($kty !== 'EC' || ($jwk['crv'] ?? null) !== 'P-256') {
                return null;
            }
        } elseif ($kty !== 'RSA') {
            return null;
        }
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }
        $htm = $payload['htm'] ?? null;
        $htu = $payload['htu'] ?? null;
        $iat = $payload['iat'] ?? null;
        $jti = $payload['jti'] ?? null;
        if (!is_string($htm) || $htm === '' || strlen($htm) > 16) {
            return null;
        }
        if (!is_string($htu) || $htu === '' || strlen($htu) > 2048) {
            return null;
        }
        if (!is_int($iat)) {
            return null;
        }
        if (!is_string($jti)
            || strlen($jti) > self::DPOP_JTI_MAX_CHARS
            || !preg_match('/^[\x20-\x7E]+$/D', $jti)
        ) {
            return null;
        }
        return [
            'signingInput' => $headerPart . '.' . $payloadPart,
            'signature' => $signature,
            'htm' => $htm,
            'htu' => $htu,
            'iat' => $iat,
            'jti' => $jti,
        ];
    }

    /**
     * Validate parsed DPoP claims against the refresh route: htm is
     * POST; htu is an http(s) URL whose path ends with the refresh
     * route (host deliberately NOT pinned -- beta/prod/test bases
     * differ and proxies rewrite hosts; route-pinning is what binds
     * the proof); iat is within $skewSeconds of $now either way.
     */
    public static function validateDpopClaims(array $parsed, int $now, int $skewSeconds): bool
    {
        if ($skewSeconds < 0) {
            return false;
        }
        if (($parsed['htm'] ?? null) !== self::DPOP_HTM) {
            return false;
        }
        $htu = $parsed['htu'] ?? null;
        if (!is_string($htu)) {
            return false;
        }
        $parts = parse_url($htu);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $path = (string)($parts['path'] ?? '');
        $suffix = self::DPOP_HTU_PATH_SUFFIX;
        if ($path === '' || substr($path, -strlen($suffix)) !== $suffix) {
            return false;
        }
        $iat = $parsed['iat'] ?? null;
        if (!is_int($iat) || $iat <= 0) {
            return false;
        }
        $delta = $now - $iat;
        if (!is_int($delta)) {
            return false;
        }
        return abs($delta) <= $skewSeconds;
    }

    /**
     * Verify a DPoP proof signature against the REGISTERED device
     * key (never the proof's embedded jwk). Same fail-closed shape
     * as verify(): true ONLY on openssl 1; every other outcome is
     * false so callers emit the ONE generic proof failure.
     */
    public static function verifyDpopSignature(
        string $spkiDer,
        string $signingInput,
        string $signature
    ): bool {
        self::requireOpenSsl();
        if ($spkiDer === '' || strlen($spkiDer) > self::MAX_SPKI_BYTES) {
            return false;
        }
        if ($signingInput === '' || strlen($signingInput) > self::MAX_DPOP_PROOF_BYTES) {
            return false;
        }
        if ($signature === '' || strlen($signature) > self::MAX_SIGNATURE_BYTES) {
            return false;
        }
        try {
            $parsed = self::parsePublicKey($spkiDer);
        } catch (InvalidArgumentException $e) {
            return false;
        }
        $result = openssl_verify($signingInput, $signature, $parsed['pem'], OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    private static function requireOpenSsl(): void
    {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('Device cryptography is unavailable.');
        }
    }
}
