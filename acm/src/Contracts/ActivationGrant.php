<?php
/**
 * ACM contracts (S2): redeemed activation grant. Single-use,
 * short-lived, bound to ticket + person + operation. The token is
 * a full credential: hash-only storage, never logged.
 */

namespace SSMS\Acm\Contracts;

final class ActivationGrant
{
    public function __construct(
        public readonly string $token,
        public readonly CodePurpose $operation,
        public readonly string $expiresAt,
    ) {
    }
}
