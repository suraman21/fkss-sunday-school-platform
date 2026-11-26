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
        /**
         * NULL for an activation OTP, which genuinely has no selector.
         *
         * This was `string`, which forced the layer below to fabricate a
         * value: `issueCode()` wrote '' into a NOT NULL UNIQUE column, so
         * only the first activation code in a database could ever be
         * inserted (see sql/103). The row now stores NULL, and a type that
         * insists on a string would put the same lie back one level up --
         * it threw a TypeError on every activation issue. null is the
         * honest type, and `display()` never runs for an OTP: callers
         * render it with Otp::display() instead.
         */
        public readonly ?string $selector,
        public readonly string $secret,
        public readonly CodePurpose $purpose,
        public readonly string $expiresAt,
    ) {
    }

    /**
     * Human form: PREFIX-SELECTOR-SECRET (display once, then mask).
     *
     * Only for the selector+secret pair purposes. An activation code is a
     * six-digit OTP with no selector and is rendered by Otp::display().
     */
    public function display(string $prefix): string
    {
        if ($this->selector === null) {
            throw new \LogicException(
                'An activation OTP has no selector; render it with Otp::display().'
            );
        }
        return $prefix . '-' . $this->selector . '-' . $this->secret;
    }
}
