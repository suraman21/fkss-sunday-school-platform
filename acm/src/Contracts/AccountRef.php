<?php
/**
 * ACM contracts (S2): provisioned account handle.
 */

namespace SSMS\Acm\Contracts;

final class AccountRef
{
    /**
     * @param string|null $deviceGrant S4: one-time chained device-
     *        registration grant plaintext (show-once, like all
     *        secrets). Null on idempotent replay when a device is
     *        already active (the route reports device_registered).
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string $accountType,
        public readonly ?string $deviceGrant = null,
    ) {
    }
}
