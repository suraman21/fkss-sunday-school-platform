<?php
/**
 * ACM contracts (S2): device lifecycle. Registration completes only
 * after challenge–signature proof; revocation is idempotent.
 */

namespace SSMS\Acm\Contracts;

interface DeviceRegistry
{
    /**
     * @throws \InvalidArgumentException invalid key, limit hit, unknown installation.
     */
    public function beginRegistration(AccountContext $ctx, string $installationId, string $publicKey): RegistrationChallenge;

    /**
     * @throws \InvalidArgumentException bad/expired signature or challenge.
     */
    public function completeRegistration(AccountContext $ctx, string $challengeId, string $signature): DeviceRef;

    /** Idempotent: revoking twice is a no-op, never an error. */
    public function revoke(AccountContext $ctx, string $deviceId, string $reason): void;
}
