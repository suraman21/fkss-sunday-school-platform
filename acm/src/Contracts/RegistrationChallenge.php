<?php
/**
 * ACM contracts (S2): device registration challenge. Single-use,
 * short TTL; the device signs it with its Keystore private key.
 */

namespace SSMS\Acm\Contracts;

final class RegistrationChallenge
{
    public function __construct(
        public readonly string $challengeId,
        public readonly string $challenge,
        public readonly string $expiresAt,
    ) {
    }
}
