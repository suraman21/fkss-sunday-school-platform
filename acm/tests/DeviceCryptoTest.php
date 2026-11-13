<?php
/**
 * ACM S4: device crypto unit tests (no database). SPKI parse +
 * algorithm detection, real openssl sign/verify round-trips (the
 * exact DER bytes Android Keystore emits), rejection of weak
 * curves/keys/garbage, and the codec/validator helpers.
 *
 * Requires ext-openssl (composer require + CI setup-php); the
 * production code fails closed without it, so the suite assumes
 * it here instead of skipping silently.
 */

namespace SSMS\Acm\Tests;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use SSMS\Acm\Device\DeviceCrypto;

final class DeviceCryptoTest extends TestCase
{
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

    public function testParseDetectsP256AndVerifiesDerSignature(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertNotFalse($key);
        $der = self::spkiDer($key);

        $parsed = DeviceCrypto::parsePublicKey($der);
        $this->assertSame(DeviceCrypto::ALG_ES256, $parsed['alg']);

        $challenge = random_bytes(DeviceCrypto::CHALLENGE_BYTES);
        // openssl_sign with SHA256 over EC emits ASN.1/DER -- the exact
        // encoding Android SHA256withECDSA produces (no P1363 step).
        $this->assertTrue(openssl_sign($challenge, $sig, $key, OPENSSL_ALGO_SHA256));
        $this->assertTrue(DeviceCrypto::verify($der, $challenge, $sig));

        // Tampered signature, wrong challenge, and truncated key all
        // fail closed (false, never an exception, no oracle).
        $bad = $sig;
        $bad[10] = chr(ord($bad[10]) ^ 0x01);
        $this->assertFalse(DeviceCrypto::verify($der, $challenge, $bad));
        $this->assertFalse(DeviceCrypto::verify($der, random_bytes(32), $sig));
        $this->assertFalse(DeviceCrypto::verify(substr($der, 0, 40), $challenge, $sig));
    }

    public function testParseDetectsRsa2048AndVerifies(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        self::assertNotFalse($key);
        $der = self::spkiDer($key);

        $parsed = DeviceCrypto::parsePublicKey($der);
        $this->assertSame(DeviceCrypto::ALG_RS256, $parsed['alg']);

        $challenge = random_bytes(DeviceCrypto::CHALLENGE_BYTES);
        $this->assertTrue(openssl_sign($challenge, $sig, $key, OPENSSL_ALGO_SHA256));
        $this->assertTrue(DeviceCrypto::verify($der, $challenge, $sig));
    }

    public function testParseRejectsWeakKeysAndGarbage(): void
    {
        // RSA-1024 is below the #14 compat floor.
        $rsa1024 = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 1024,
        ]);
        self::assertNotFalse($rsa1024);
        try {
            DeviceCrypto::parsePublicKey(self::spkiDer($rsa1024));
            $this->fail('RSA-1024 must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('too small', $e->getMessage());
        }

        // P-384 is outside the P-256-only allowlist.
        $p384 = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp384r1',
        ]);
        self::assertNotFalse($p384);
        try {
            DeviceCrypto::parsePublicKey(self::spkiDer($p384));
            $this->fail('P-384 must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unsupported curve', $e->getMessage());
        }

        // Garbage, empty, and oversize inputs.
        foreach (['not-a-key', '', str_repeat('A', 5000)] as $bad) {
            try {
                DeviceCrypto::parsePublicKey($bad);
                $this->fail('Garbage SPKI must be rejected.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
            $this->assertFalse(DeviceCrypto::verify(
                $bad, random_bytes(32), str_repeat('B', 64)
            ));
        }
    }

    public function testVerifyRejectsWrongShapesWithoutThrowing(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertNotFalse($key);
        $der = self::spkiDer($key);
        $challenge = random_bytes(32);
        $this->assertTrue(openssl_sign($challenge, $sig, $key, OPENSSL_ALGO_SHA256));

        $this->assertFalse(DeviceCrypto::verify($der, 'short', $sig));
        $this->assertFalse(DeviceCrypto::verify($der, $challenge, ''));
        $this->assertFalse(DeviceCrypto::verify($der, $challenge, str_repeat('C', 5000)));
        $this->assertFalse(DeviceCrypto::verify('', $challenge, $sig));
    }

    public function testFingerprintsAreStableAndTruncatedSafely(): void
    {
        $der = str_repeat("\x30", 91);  // shape only; hash needs no valid key
        $fp = DeviceCrypto::fingerprint($der);
        $this->assertSame(hash('sha256', $der), $fp);
        $this->assertSame(64, strlen($fp));
        $this->assertSame(substr($fp, 0, 8) . '...', DeviceCrypto::shortFingerprint($fp));
    }

    public function testChallengesAreFreshAndHashed(): void
    {
        $a = DeviceCrypto::generateChallenge();
        $b = DeviceCrypto::generateChallenge();
        $this->assertSame(32, strlen($a['bytes']));
        $this->assertSame(hash('sha256', $a['bytes']), $a['hash']);
        $this->assertNotSame($a['bytes'], $b['bytes']);
    }

    public function testBase64UrlCodecRoundTripsAndRejectsGarbage(): void
    {
        $bytes = random_bytes(32);
        $text = DeviceCrypto::base64UrlEncode($bytes);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $text);
        $this->assertSame($bytes, DeviceCrypto::base64UrlDecode($text));
        $this->assertNull(DeviceCrypto::base64UrlDecode(''));
        $this->assertNull(DeviceCrypto::base64UrlDecode('has space'));
        $this->assertNull(DeviceCrypto::base64UrlDecode('has+plus/slash=='));
    }

    public function testStrictBase64RespectsItsCap(): void
    {
        $bytes = random_bytes(64);
        $this->assertSame($bytes, DeviceCrypto::base64StrictDecode(base64_encode($bytes), 4096));
        $this->assertNull(DeviceCrypto::base64StrictDecode('', 4096));
        $this->assertNull(DeviceCrypto::base64StrictDecode('!!!', 4096));
        $this->assertNull(DeviceCrypto::base64StrictDecode(base64_encode($bytes), 32));
    }

    public function testSplitProofAcceptsOnlyTheContractShape(): void
    {
        $proof = DeviceCrypto::base64UrlEncode(random_bytes(32)) . '.' . base64_encode('sig');
        $parts = DeviceCrypto::splitProof($proof);
        $this->assertNotNull($parts);
        $this->assertSame(32, strlen((string)DeviceCrypto::base64UrlDecode($parts['challenge'])));
        $this->assertNull(DeviceCrypto::splitProof('no-separator'));
        $this->assertNull(DeviceCrypto::splitProof('a.b.c'));
        $this->assertNull(DeviceCrypto::splitProof('.sig'));
        $this->assertNull(DeviceCrypto::splitProof('chall.'));
    }

    public function testInstallationAndUuidValidators(): void
    {
        $this->assertTrue(DeviceCrypto::isValidInstallationId('f47ac10b-58cc-4372-a567-0e02b2c3d479'));
        $this->assertTrue(DeviceCrypto::isValidInstallationId('F47AC10B-58CC-4372-A567-0E02B2C3D479'));
        $this->assertFalse(DeviceCrypto::isValidInstallationId('not-a-uuid'));
        $this->assertFalse(DeviceCrypto::isValidInstallationId('f47ac10b-58cc-0372-a567-0e02b2c3d479'));  // v0
        $this->assertSame(
            'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            DeviceCrypto::normalizeInstallationId('  F47AC10B-58CC-4372-A567-0E02B2C3D479  ')
        );
        $this->assertTrue(DeviceCrypto::isValidUuid('f47ac10b-58cc-4372-a567-0e02b2c3d479'));
        $this->assertFalse(DeviceCrypto::isValidUuid('xyz'));
    }

    public function testCleanNameTrimsStripsAndCaps(): void
    {
        $this->assertNull(DeviceCrypto::cleanName(null));
        $this->assertNull(DeviceCrypto::cleanName("  \t "));
        $this->assertSame('Pixel 8', DeviceCrypto::cleanName("  Pixel\x008\n "));
        $this->assertSame(80, strlen((string)DeviceCrypto::cleanName(str_repeat('n', 200))));
    }

    public function testCleanReasonKeepsTheAuditBudget(): void
    {
        $this->assertNull(DeviceCrypto::cleanReason(null));
        $this->assertNull(DeviceCrypto::cleanReason('   '));
        $this->assertSame('Lost phone', DeviceCrypto::cleanReason("  Lost\x00 phone\n "));
        $this->assertSame(200, strlen((string)DeviceCrypto::cleanReason(str_repeat('r', 200))));
        $this->assertSame(2000, strlen((string)DeviceCrypto::cleanReason(str_repeat('r', 5000))));
    }
}
