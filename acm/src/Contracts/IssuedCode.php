<?php
/**
 * ACM contracts (S2): freshly issued code. The ONLY object that ever
 * carries plaintext code material, returned once to the issuing
 * officer and never persisted or logged.
 */

namespace SSMS\Acm\Contracts;

final class IssuedCode
{
    public function __construct(
        public readonly string $selector,
        public readonly string $secret,
        public readonly CodePurpose $purpose,
        public readonly string $expiresAt,
    ) {
    }

    /** Human form: PREFIX-SELECTOR-SECRET (display once, then mask). */
    public function display(string $prefix): string
    {
        return $prefix . '-' . $this->selector . '-' . $this->secret;
    }
}
