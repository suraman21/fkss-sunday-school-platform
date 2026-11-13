<?php
/**
 * ACM contracts (S2): append-only audit sink. There is deliberately
 * no update/delete API — corrections are new events.
 */

namespace SSMS\Acm\Contracts;

interface AuditTrail
{
    public function record(AuditEvent $event): void;
}
