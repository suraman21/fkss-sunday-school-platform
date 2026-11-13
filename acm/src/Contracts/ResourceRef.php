<?php
/**
 * ACM contracts (S2): what is being accessed. The resource vocabulary
 * is the AcmPolicy resource set (ticket, person, account, audit,
 * office, approver, reviewer, attest); unknown resources deny.
 */

namespace SSMS\Acm\Contracts;

final class ResourceRef
{
    public function __construct(
        public readonly string $resource,
        public readonly ?string $id = null,
    ) {
    }
}
