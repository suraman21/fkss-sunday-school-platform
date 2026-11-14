<?php
/**
 * ACM contracts (S2): explainable internal authorization decision.
 * For audit/diagnostics only — never returned to clients verbatim.
 * Client responses use stable error codes without policy internals.
 */

namespace SSMS\Acm\Contracts;

final class AuthorizationDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $policyId,
        public readonly string $accountRef,
        public readonly ?string $denialCategory,
        public readonly \DateTimeImmutable $evaluatedAt,
    ) {
    }
}
