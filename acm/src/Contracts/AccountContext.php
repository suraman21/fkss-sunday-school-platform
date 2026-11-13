<?php
/**
 * ACM contracts (S2): who is asking. Immutable value object.
 * Callers build this from the verified session/grant — never from
 * raw client input.
 */

namespace SSMS\Acm\Contracts;

final class AccountContext
{
    public function __construct(
        public readonly int $userId,
        public readonly string $role,
    ) {
    }
}
