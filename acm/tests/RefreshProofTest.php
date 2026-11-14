<?php
/**
 * ACM S5: refresh-time DPoP proof unit tests (no database). Real
 * openssl sign/verify round-trips for ES256 (DER, as Android
 * SHA256withECDSA emits) and RS256, plus rejection of malformed
 * proofs, stale/freshness claims, wrong route/method, algorithm
 * mismatch, and untrusted-embedded-key confusion.
 *
 * Requires ext-openssl (composer require + CI setup-php); the
 * production code fails closed without it, so the suite assumes
 * it here instead of skipping silently.
 */

namespace SSMS\Acm\Tests;

use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use SSMS\Acm\Device\DeviceCrypto;

final class RefreshProofTest extends TestCase
{
    private const HTU = 'https://school.example/api/v1/auth/refresh-token';

    /** SPKI DER bytes from any openssl key (PEM armor stripped). */
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
     * Build a compact DPoP proof signed by $key. Overrides replace
     * individual header/payload fields (null removes the field).
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
            ['htm' => 'POST', 'htu' => self::HTU, 'iat' => $iat, 'jti' => bin2hex(random_bytes(16))],
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

    public function testEs256ProofRoundTrip(): void
    {
        $key = self::ecKey();
        $der = self::spkiDer($key);
        $now = time();
        $proof = self::proof($key, DeviceCrypto::ALG_ES256, $now);

        $parsed = DeviceCrypto::parseDpopProof($proof, DeviceCrypto::ALG_ES256);
        $this->assertIsArray($parsed);
        $this->assertTrue(DeviceCrypto::validateDpopClaims($parsed, $now, 300));
        $this->assertTrue(DeviceCrypto::verifyDpopSignature(
            $der, $parsed['signingInput'], $parsed['signature']
        ));

        // Tampered signature fails closed (false, never an exception).
        $bad = $parsed['signature'];
        $bad[10] = chr(ord($bad[10]) ^ 0x01);
        $this->assertFalse(DeviceCrypto::verifyDpopSignature($der, $parsed['signingInput'], $bad));
        // Wrong registered key fails closed.
        $this->assertFalse(DeviceCrypto::verifyDpopSignature(
            self::spkiDer(self::ecKey()), $parsed['signingInput'], $parsed['signature']
        ));
    }

    public function testRs256ProofRoundTrip(): void
    {
        $key = self::rsaKey();
        $der = self::spkiDer($key);
        $now = time();
        $proof = self::proof($key, DeviceCrypto::ALG_RS256, $now);

        $parsed = DeviceCrypto::parseDpopProof($proof, DeviceCrypto::ALG_RS256);
        $this->assertIsArray($parsed);
        $this->assertTrue(DeviceCrypto::validateDpopClaims($parsed, $now, 300));
        $this->assertTrue(DeviceCrypto::verifyDpopSignature(
            $der, $parsed['signingInput'], $parsed['signature']
        ));
    }

    public function testParseRejectsMalformedProofs(): void
    {
        $key = self::ecKey();
        $now = time();
        $good = self::proof($key, DeviceCrypto::ALG_ES256, $now);
        $alg = DeviceCrypto::ALG_ES256;
        $this->assertNull(DeviceCrypto::parseDpopProof('', $alg));
        $this->assertNull(DeviceCrypto::parseDpopProof('only.two', $alg));
        $this->assertNull(DeviceCrypto::parseDpopProof('a.b.c.d', $alg));
        $this->assertNull(DeviceCrypto::parseDpopProof(str_repeat('a', 5000), $alg));
        [$h, $p, $s] = explode('.', $good, 3);
        $this->assertNull(DeviceCrypto::parseDpopProof('!!!.' . $p . '.' . $s, $alg));
        $this->assertNull(DeviceCrypto::parseDpopProof($h . '.!!!.' . $s, $alg));
        $this->assertNull(DeviceCrypto::parseDpopProof($h . '.' . $p . '.!!!', $alg));
        // Valid base64url, invalid JSON.
        $notJson = self::b64u('not json');
        $this->assertNull(DeviceCrypto::parseDpopProof($notJson . '.' . $p . '.' . $s, $alg));
        $this->assertNull(DeviceCrypto::parseDpopProof($h . '.' . $notJson . '.' . $s, $alg));
    }

    public function testParseRejectsHeaderPolicyViolations(): void
    {
        $key = self::ecKey();
        $now = time();
        $alg = DeviceCrypto::ALG_ES256;
        // Wrong typ, none-alg, or alg mismatch with the registered key.
        $this->assertNull(DeviceCrypto::parseDpopProof(
            self::proof($key, $alg, $now, ['typ' => 'jwt']), $alg
        ));
        $this->assertNull(DeviceCrypto::parseDpopProof(
            self::proof($key, $alg, $now, ['alg' => 'none']), $alg
        ));
        $this->assertNull(DeviceCrypto::parseDpopProof(
            self::proof($key, $alg, $now, ['alg' => DeviceCrypto::ALG_RS256]), $alg
        ));
        // Missing jwk, private material in jwk, or wrong key shape.
        $this->assertNull(DeviceCrypto::parseDpopProof(
            self::proof($key, $alg, $now, ['jwk' => null]), $alg
        ));
        $this->assertNull(DeviceCrypto::parseDpopProof(
            self::proof($key, $alg, $now, ['jwk' => ['kty' => 'EC', 'crv' => 'P-256', 'd' => 'leak']]), $alg
        ));
        $this->assertNull(DeviceCrypto::parseDpopProof(
            self::proof($key, $alg, $now, ['jwk' => ['kty' => 'RSA', 'n' => 'x', 'e' => 'y']]), $alg
        ));
        // Payload type violations: non-int iat, missing/oversize jti.
        $this->assertNull(DeviceCrypto::parseDpopProof(
            self::proof($key, $alg, $now, [], ['iat' => (float)$now]), $alg
        ));
        $this->assertNull(DeviceCrypto::parseDpopProof(
            self::proof($key, $alg, $now, [], ['iat' => (string)$now]), $alg
        ));
        $this->assertNull(DeviceCrypto::parseDpopProof(
            self::proof($key, $alg, $now, [], ['jti' => null]), $alg
        ));
        $this->assertNull(DeviceCrypto::parseDpopProof(
            self::proof($key, $alg, $now, [], ['jti' => str_repeat('j', 129)]), $alg
        ));
        $this->assertNull(DeviceCrypto::parseDpopProof(
            self::proof($key, $alg, $now, [], ['jti' => "a\nb"]), $alg
        ));
    }

    public function testClaimsEnforceRouteMethodAndFreshness(): void
    {
        $key = self::ecKey();
        $now = time();
        $alg = DeviceCrypto::ALG_ES256;
        $parse = static fn (string $proof): array => DeviceCrypto::parseDpopProof($proof, $alg) ?? [];

        $fresh = $parse(self::proof($key, $alg, $now));
        $this->assertTrue(DeviceCrypto::validateDpopClaims($fresh, $now, 300));
        // Boundary iat (exactly skew) still passes.
        $edge = $parse(self::proof($key, $alg, $now - 300));
        $this->assertTrue(DeviceCrypto::validateDpopClaims($edge, $now, 300));
        // Stale past / skewed future fail.
        $stale = $parse(self::proof($key, $alg, $now - 301));
        $this->assertFalse(DeviceCrypto::validateDpopClaims($stale, $now, 300));
        $future = $parse(self::proof($key, $alg, $now + 301));
        $this->assertFalse(DeviceCrypto::validateDpopClaims($future, $now, 300));
        // Wrong method, wrong route, or non-http(s) URL fail.
        $get = $parse(self::proof($key, $alg, $now, [], ['htm' => 'GET']));
        $this->assertFalse(DeviceCrypto::validateDpopClaims($get, $now, 300));
        $other = $parse(self::proof($key, $alg, $now, [], ['htu' => 'https://school.example/api/v1/auth/login']));
        $this->assertFalse(DeviceCrypto::validateDpopClaims($other, $now, 300));
        $ftp = $parse(self::proof($key, $alg, $now, [], ['htu' => 'ftp://x/auth/refresh-token']));
        $this->assertFalse(DeviceCrypto::validateDpopClaims($ftp, $now, 300));
        // Host is deliberately NOT pinned (beta/prod/test bases differ);
        // query strings do not disturb the path match.
        $beta = $parse(self::proof($key, $alg, $now, [], ['htu' => 'http://127.0.0.1:8080/auth/refresh-token']));
        $this->assertTrue(DeviceCrypto::validateDpopClaims($beta, $now, 300));
        $query = $parse(self::proof($key, $alg, $now, [], ['htu' => self::HTU . '?x=1']));
        $this->assertTrue(DeviceCrypto::validateDpopClaims($query, $now, 300));
    }

    public function testCappedDecoderRejectsGarbage(): void
    {
        $this->assertSame('hi', DeviceCrypto::base64UrlDecodeCapped('aGk', 8));
        $this->assertNull(DeviceCrypto::base64UrlDecodeCapped('', 8));
        $this->assertNull(DeviceCrypto::base64UrlDecodeCapped('aGk!', 8));
        $this->assertNull(DeviceCrypto::base64UrlDecodeCapped('aGk', 0));
        $this->assertNull(DeviceCrypto::base64UrlDecodeCapped(self::b64u('toolong'), 3));
    }
}
