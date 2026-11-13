<?php
/**
 * ACM S3: ceremony unit tests (no database). Code alphabet,
 * error-code stability, grant/code value objects, and the
 * proxy-safe IP resolution + CIDR matcher.
 */

namespace SSMS\Acm\Tests;

use PHPUnit\Framework\TestCase;
use SSMS\Acm\Activation\ActivationException;
use SSMS\Acm\Activation\CodeAlphabet;
use SSMS\Acm\Contracts\ActivationGrant;
use SSMS\Acm\Contracts\CodePurpose;
use SSMS\Acm\Contracts\IssuedCode;
use SSMS\Acm\OfficeNetwork\OfficeNetworkSignal;

final class CeremonyTest extends TestCase
{
    public function testAlphabetHasThirtyUnambiguousSymbols(): void
    {
        $alphabet = CodeAlphabet::ALPHABET;
        $this->assertSame(30, strlen($alphabet));
        $this->assertSame(30, count(array_unique(str_split($alphabet))));
        foreach (['0', 'O', '1', 'I', 'L'] as $ambiguous) {
            $this->assertStringNotContainsString($ambiguous, $alphabet);
        }
    }

    public function testRandomUsesOnlyAlphabetSymbols(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $value = CodeAlphabet::random(CodeAlphabet::SELECTOR_LEN);
            $this->assertSame(CodeAlphabet::SELECTOR_LEN, strlen($value));
            $this->assertTrue(CodeAlphabet::isValid($value, CodeAlphabet::SELECTOR_LEN));
            $seen[$value] = true;
        }
        // 30^6 space: 50 draws must not collide.
        $this->assertCount(50, $seen);
        $secret = CodeAlphabet::random(CodeAlphabet::SECRET_LEN);
        $this->assertTrue(CodeAlphabet::isValid($secret, CodeAlphabet::SECRET_LEN));
    }

    public function testNormalizeAndValidate(): void
    {
        $this->assertSame('A7K4P2', CodeAlphabet::normalize(' a-7k4p2 '));
        $this->assertTrue(CodeAlphabet::isValid('A7K4P2', 6));
        $this->assertFalse(CodeAlphabet::isValid('A7K4P2X', 6));
        $this->assertFalse(CodeAlphabet::isValid('A7K4P!', 6));
        $this->assertFalse(CodeAlphabet::isValid('a7k4p2', 6));
    }

    public function testPrefixesAreDistinct(): void
    {
        $this->assertSame('A', CodeAlphabet::prefixFor(CodePurpose::Activation));
        $this->assertSame('D', CodeAlphabet::prefixFor(CodePurpose::DeviceAdd));
        $this->assertSame('R', CodeAlphabet::prefixFor(CodePurpose::Reset));
    }

    public function testErrorCodesAreStable(): void
    {
        $this->assertSame('code_invalid', ActivationException::CODE_INVALID);
        $this->assertSame('grant_consumed', ActivationException::GRANT_CONSUMED);
        $this->assertSame('account_exists', ActivationException::ACCOUNT_EXISTS);
        $this->assertSame('idempotent_conflict', ActivationException::IDEMPOTENT_CONFLICT);
        $this->assertSame('network_denied', ActivationException::NETWORK_DENIED);
        $this->assertSame('ticket_state', ActivationException::TICKET_STATE);
        $this->assertSame('weak_password', ActivationException::WEAK_PASSWORD);
    }

    public function testVerifyFailureIsSingleAndGeneric(): void
    {
        $e = ActivationException::verifyFailed();
        $this->assertSame('code_invalid', $e->machineCode());
        $this->assertSame(400, $e->httpStatus());
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        // Generic by design: reveals nothing about which check failed.
        $this->assertSame('The code is invalid or expired.', $e->getMessage());
        $this->assertSame(429, ActivationException::rateLimited()->httpStatus());
        $this->assertSame(403, ActivationException::networkDenied()->httpStatus());
    }

    public function testIssuedCodeDisplaysOnce(): void
    {
        $code = new IssuedCode('7K4P2M', '9QX2KD47', CodePurpose::Activation, '2026-01-01 00:30:00');
        $this->assertSame('A-7K4P2M-9QX2KD47', $code->display('A'));
    }

    public function testGrantValueObject(): void
    {
        $grant = new ActivationGrant('tok', CodePurpose::Activation, '2026-01-01 00:15:00');
        $this->assertSame(CodePurpose::Activation, $grant->operation);
    }

    // ---- office-network signal: pure matcher + resolution ----

    public function testIpInCidrV4(): void
    {
        $this->assertTrue(OfficeNetworkSignal::ipInCidr('10.0.5.7', '10.0.0.0/16'));
        $this->assertTrue(OfficeNetworkSignal::ipInCidr('10.0.5.7', '10.0.5.7'));
        $this->assertFalse(OfficeNetworkSignal::ipInCidr('10.1.5.7', '10.0.0.0/16'));
        $this->assertFalse(OfficeNetworkSignal::ipInCidr('10.0.5.7', '10.0.5.8'));
        $this->assertFalse(OfficeNetworkSignal::ipInCidr('not-an-ip', '10.0.0.0/8'));
        $this->assertFalse(OfficeNetworkSignal::ipInCidr('10.0.0.1', 'not-a-cidr'));
        $this->assertFalse(OfficeNetworkSignal::ipInCidr('10.0.0.1', '10.0.0.0/33'));
    }

    public function testIpInCidrV6(): void
    {
        $this->assertTrue(OfficeNetworkSignal::ipInCidr('2001:db8::1', '2001:db8::/32'));
        $this->assertFalse(OfficeNetworkSignal::ipInCidr('2001:db9::1', '2001:db8::/32'));
        // Families never mix.
        $this->assertFalse(OfficeNetworkSignal::ipInCidr('10.0.0.1', '2001:db8::/32'));
        $this->assertFalse(OfficeNetworkSignal::ipInCidr('2001:db8::1', '10.0.0.0/8'));
    }

    public function testResolveClientIpDirect(): void
    {
        $this->assertSame(
            '197.156.1.2',
            OfficeNetworkSignal::resolveClientIp(['REMOTE_ADDR' => '197.156.1.2'], '')
        );
        // No peer, no client.
        $this->assertNull(OfficeNetworkSignal::resolveClientIp([], ''));
        $this->assertNull(OfficeNetworkSignal::resolveClientIp(['REMOTE_ADDR' => 'junk'], ''));
    }

    public function testResolveClientIpProxied(): void
    {
        $proxies = '203.0.113.10, 203.0.113.11';
        // Trusted peer + agreed header: header wins.
        $this->assertSame(
            '197.156.1.2',
            OfficeNetworkSignal::resolveClientIp([
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_CF_CONNECTING_IP' => '197.156.1.2',
            ], $proxies)
        );
        // Untrusted peer in a proxied deployment: unknown, never the peer.
        $this->assertNull(OfficeNetworkSignal::resolveClientIp([
            'REMOTE_ADDR' => '198.51.100.9',
            'HTTP_CF_CONNECTING_IP' => '197.156.1.2',
        ], $proxies));
        // Trusted peer but no agreed header: unknown (no blind XFF).
        $this->assertNull(OfficeNetworkSignal::resolveClientIp([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '197.156.1.2',
        ], $proxies));
        // Invalid header value: unknown.
        $this->assertNull(OfficeNetworkSignal::resolveClientIp([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_CF_CONNECTING_IP' => 'junk',
        ], $proxies));
        // Proxy set accepts CIDRs too.
        $this->assertSame(
            '197.156.1.2',
            OfficeNetworkSignal::resolveClientIp([
                'REMOTE_ADDR' => '203.0.113.55',
                'HTTP_CF_CONNECTING_IP' => '197.156.1.2',
            ], '203.0.113.0/24')
        );
    }
}
