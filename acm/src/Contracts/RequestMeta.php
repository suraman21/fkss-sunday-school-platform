<?php
/**
 * ACM contracts (S2): server-observed request metadata for
 * rate-limiting and audit (resolved client IP, installation).
 */

namespace SSMS\Acm\Contracts;

final class RequestMeta
{
    public function __construct(
        public readonly ?string $clientIp,
        public readonly ?string $installationId = null,
    ) {
    }
}
