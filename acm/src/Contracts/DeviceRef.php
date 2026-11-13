<?php
/**
 * ACM contracts (S2): registered device handle. Carries the verified
 * public-key fingerprint — never any private key material.
 */

namespace SSMS\Acm\Contracts;

final class DeviceRef
{
    public function __construct(
        public readonly string $deviceUuid,
        public readonly string $fingerprint,
        public readonly string $status,
    ) {
    }
}
