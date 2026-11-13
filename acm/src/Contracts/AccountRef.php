<?php
/**
 * ACM contracts (S2): provisioned account handle.
 */

namespace SSMS\Acm\Contracts;

final class AccountRef
{
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string $accountType,
    ) {
    }
}
