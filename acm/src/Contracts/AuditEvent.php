<?php
/**
 * ACM contracts (S2): audit event. Details must never carry secrets
 * (code material, grants, passwords, private keys).
 */

namespace SSMS\Acm\Contracts;

final class AuditEvent
{
    /**
     * @param array<string,mixed> $details Secret-free metadata.
     */
    public function __construct(
        public readonly string $action,
        public readonly array $details,
        public readonly ?string $entityType = null,
        public readonly ?int $entityId = null,
    ) {
    }
}
