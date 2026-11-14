<?php
/**
 * ACM S9: per-request DPoP proof unit tests (no database). Real
 * openssl sign/verify round-trips for ES256 and RS256 with the
 * generalized (method, request path, token hash) claims, plus
 * rejection of wrong-method/path/ath, missing or malformed ath,
 * non-http(s) schemes, iat window edges, and path-fold vectors.
 * Host is deliberately NOT pinned (S5 proxy precedent -- pinned
 * here by asserting two different hosts both verify).
 *
 * Requires ext-openssl (composer require + CI setup-php); the
 * production code fails closed without it, so the suite assumes
 * it here instead of skipping silently (S5 precedent).
 */

namespace SSMS\Acm\Tests;

use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use SSMS\Acm\Device\DeviceCrypto;

final class AccessProofTest extends TestCase
{
    private const TOKEN = 'e2e-access-token-value';
    private const HTU = 'https://school.example/api/v1/grades/summary';
    private const PATH = '/api/v1/grades/summary';

    private static function spkiDer(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $pem = (string)($details['key'] ?? '');
        $der = base64_decode(str_replace(
            ["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\n", "\r", ' '],
            '',
            $pem
        ), true);
        self::assertNotFalse($der);
        self::assertNotSame('', $der);
        return $der;
    }

    private static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function ath(string $token = self::TOKEN): string
    {
        return DeviceCrypto::base64UrlEncode(hash('sha256', $token, true));
    }

    /** JWK shape for the proof header (server shape-checks, never trusts). */
    private static function jwk(OpenSSLAsymmetricKey $key, string $alg): array
    {
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        if ($alg === DeviceCrypto::ALG_ES256) {
            return [
                'kty' => 'EC',
                'crv' => 'P-256',
                'x' => self::b64u(str_pad((string)$details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
                'y' => self::b64u(str_pad((string)$details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
            ];
        }
        return [
            'kty' => 'RSA',
            'n' => self::b64u((string)$details['rsa']['n']),
            'e' => self::b64u((string)$details['rsa']['e']),
        ];
    }

    /**
     * Build a compact per-request DPoP proof signed by $key.
     * Overrides replace individual header/payload fields (null
     * removes the field).
     */
    private static function proof(
        OpenSSLAsymmetricKey $key,
        string $alg,
        int $iat,
        array $headerOverrides = [],
        array $payloadOverrides = []
    ): string {
        $header = array_merge(
            ['typ' => 'dpop+jwt', 'alg' => $alg, 'jwk' => self::jwk($key, $alg)],
            $headerOverrides
        );
        foreach (array_keys($headerOverrides) as $k) {
            if ($headerOverrides[$k] === null) {
                unset($header[$k]);
            }
        }
        $payload = array_merge(
            [
                'htm' => 'GET',
                'htu' => self::HTU,
                'iat' => $iat,
                'jti' => bin2hex(random_bytes(16)),
                'ath' => self::ath(),
            ],
            $payloadOverrides
        );
        foreach (array_keys($payloadOverrides) as $k) {
            if ($payloadOverrides[$k] === null) {
                unset($payload[$k]);
            }
        }
        $input = self::b64u((string)json_encode($header)) . '.' . self::b64u((string)json_encode($payload));
        self::assertTrue(openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256));
        return $input . '.' . self::b64u($sig);
    }

    private static function ecKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertNotFalse($key);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        return $key;
    }

    private static function rsaKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        self::assertNotFalse($key);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        return $key;
    }

    /** @return array{0:array,1:string} [parsed, spkiDer] */
    private static function validEs256(int $now): array
    {
        $key = self::ecKey();
        $proof = self::proof($key, DeviceCrypto::ALG_ES256, $now);
        $parsed = DeviceCrypto::parseDpopProof($proof, DeviceCrypto::ALG_ES256);
        self::assertIsArray($parsed);
        return [$parsed, self::spkiDer($key)];
    }

    public function testEs256RequestRoundTrip(): void
    {
        $now = time();
        [$parsed, $der] = self::validEs256($now);
        $this->assertTrue(DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, 300, 'GET', self::PATH, self::ath()
        ));
        $this->assertTrue(DeviceCrypto::verifyDpopSignature(
            $der, $parsed['signingInput'], $parsed['signature']
        ));
    }

    public function testRs256RequestRoundTrip(): void
    {
        $now = time();
        $key = self::rsaKey();
        $proof = self::proof(
            $key, DeviceCrypto::ALG_RS256, $now,
            [],
            ['htm' => 'POST', 'htu' => 'https://school.example/api/v1/grades/save']
        );
        $parsed = DeviceCrypto::parseDpopProof($proof, DeviceCrypto::ALG_RS256);
        $this->assertIsArray($parsed);
        $this->assertTrue(DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, 300, 'POST', '/api/v1/grades/save', self::ath()
        ));
        $this->assertTrue(DeviceCrypto::verifyDpopSignature(
            self::spkiDer($key), $parsed['signingInput'], $parsed['signature']
        ));
    }

    public function testWrongMethodPathOrSchemeRefuses(): void
    {
        $now = time();
        [$parsed] = self::validEs256($now);
        $this->assertFalse(DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, 300, 'POST', self::PATH, self::ath()
        ));
        $this->assertFalse(DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, 300, 'GET', '/api/v1/grades/other', self::ath()
        ));
        $this->assertFalse(DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, 300, 'GET', '', self::ath()
        ));
        // Lowercase method is exact-match rejected (server signs the
        // wire method uppercased; the app must match it exactly).
        $this->assertFalse(DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, 300, 'get', self::PATH, self::ath()
        ));
    }

    public function testTrailingSlashFoldsAndQueryIsExcluded(): void
    {
        $now = time();
        $key = self::ecKey();
        $proof = self::proof(
            $key, DeviceCrypto::ALG_ES256, $now,
            [],
            ['htu' => 'https://school.example/api/v1/grades/summary/?class_id=3']
        );
        $parsed = DeviceCrypto::parseDpopProof($proof, DeviceCrypto::ALG_ES256);
        $this->assertIsArray($parsed);
        // Trailing slash + query fold to the bare request path.
        $this->assertTrue(DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, 300, 'GET', self::PATH, self::ath()
        ));
        // And the fold works the other way (server path with slash).
        $this->assertTrue(DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, 300, 'GET', self::PATH . '/', self::ath()
        ));
    }

    public function testHostIsNotPinned(): void
    {
        $now = time();
        foreach (['https://beta.example/x', 'http://10.0.0.9:8080/x'] as $htu) {
            $key = self::ecKey();
            $proof = self::proof($key, DeviceCrypto::ALG_ES256, $now, [], ['htu' => $htu]);
            $parsed = DeviceCrypto::parseDpopProof($proof, DeviceCrypto::ALG_ES256);
            $this->assertIsArray($parsed);
            // Different hosts, same path: both verify (S5 precedent:
            // proxies rewrite hosts; path + ath + jti is the binding).
            $this->assertTrue(DeviceCrypto::validateDpopRequestClaims(
                $parsed, $now, 300, 'GET', '/x', self::ath()
            ));
        }
        // But the scheme must still be http(s).
        $key = self::ecKey();
        $proof = self::proof(
            $key, DeviceCrypto::ALG_ES256, $now, [], ['htu' => 'ftp://school.example/x']
        );
        $parsed = DeviceCrypto::parseDpopProof($proof, DeviceCrypto::ALG_ES256);
        $this->assertIsArray($parsed);
        $this->assertFalse(DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, 300, 'GET', '/x', self::ath()
        ));
    }

    public function testAthMismatchMissingOrMalformedRefuses(): void
    {
        $now = time();
        [$parsed] = self::validEs256($now);
        // Another token's hash: refused (token binding asserted).
        $this->assertFalse(DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, 300, 'GET', self::PATH, self::ath('some-other-token')
        ));
        // Missing ath: parses (rotation shape) but never validates
        // per-request.
        $key = self::ecKey();
        $noAth = self::proof($key, DeviceCrypto::ALG_ES256, $now, [], ['ath' => null]);
        $parsedNoAth = DeviceCrypto::parseDpopProof($noAth, DeviceCrypto::ALG_ES256);
        $this->assertIsArray($parsedNoAth);
        $this->assertNull($parsedNoAth['ath']);
        $this->assertFalse(DeviceCrypto::validateDpopRequestClaims(
            $parsedNoAth, $now, 300, 'GET', self::PATH, self::ath()
        ));
        // Malformed ath kills the proof at parse (like its siblings).
        foreach (['not base64url!!', str_repeat('a', 129), 'has space', 'with+plus'] as $bad) {
            $badProof = self::proof($key, DeviceCrypto::ALG_ES256, $now, [], ['ath' => $bad]);
            $this->assertNull(
                DeviceCrypto::parseDpopProof($badProof, DeviceCrypto::ALG_ES256),
                'ath=' . substr($bad, 0, 20)
            );
        }
    }

    public function testIatWindowEdges(): void
    {
        $now = time();
        $key = self::ecKey();
        foreach ([-300 => true, 300 => true, -301 => false, 301 => false] as $delta => $ok) {
            $proof = self::proof($key, DeviceCrypto::ALG_ES256, $now + $delta);
            $parsed = DeviceCrypto::parseDpopProof($proof, DeviceCrypto::ALG_ES256);
            $this->assertIsArray($parsed);
            $this->assertSame(
                $ok,
                DeviceCrypto::validateDpopRequestClaims(
                    $parsed, $now, 300, 'GET', self::PATH, self::ath()
                ),
                'delta=' . $delta
            );
        }
    }

    public function testDegenerateInputsRefuse(): void
    {
        $now = time();
        [$parsed] = self::validEs256($now);
        $this->assertFalse(DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, -1, 'GET', self::PATH, self::ath()
        ));
        $this->assertFalse(DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, 300, '', self::PATH, self::ath()
        ));
        $this->assertFalse(DeviceCrypto::validateDpopRequestClaims(
            $parsed, $now, 300, 'GET', self::PATH, ''
        ));
    }

    public function testNormalizeDpopPathVectors(): void
    {
        $this->assertSame('/', DeviceCrypto::normalizeDpopPath(''));
        $this->assertSame('/', DeviceCrypto::normalizeDpopPath('/'));
        $this->assertSame('/a', DeviceCrypto::normalizeDpopPath('/a'));
        $this->assertSame('/a', DeviceCrypto::normalizeDpopPath('/a/'));
        $this->assertSame('/a', DeviceCrypto::normalizeDpopPath('a'));
        $this->assertSame('/a/b', DeviceCrypto::normalizeDpopPath('/a/b/'));
        $this->assertSame('/a/b', DeviceCrypto::normalizeDpopPath('/a/b'));
    }

    public function testRotationProofsStillParseWithoutAth(): void
    {
        $now = time();
        $key = self::ecKey();
        // S5 rotation shape: no ath claim at all.
        $proof = self::proof(
            $key, DeviceCrypto::ALG_ES256, $now,
            [],
            [
                'htm' => 'POST',
                'htu' => 'https://school.example/api/v1/auth/refresh-token',
                'ath' => null,
            ]
        );
        $parsed = DeviceCrypto::parseDpopProof($proof, DeviceCrypto::ALG_ES256);
        $this->assertIsArray($parsed);
        $this->assertNull($parsed['ath']);
        $this->assertTrue(DeviceCrypto::validateDpopClaims($parsed, $now, 300));
    }
}
