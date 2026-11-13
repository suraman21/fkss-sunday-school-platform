<?php
/**
 * ACM S3 — typed ceremony failure (design §§D4.2–D4.3).
 * ------------------------------------------------------------
 * Every activation failure carries a STABLE machine-readable code
 * (never message-only) plus the HTTP status and a safe message.
 * Extends InvalidArgumentException so the S2 contract's @throws
 * annotations keep holding; routes translate via machineCode()
 * into `err($msg, $http, ['code' => $code])`.
 *
 * Oracle rule (§D4.5): verify() fails with the ONE generic
 * verifyFailed() whether the selector is unknown, the secret is
 * wrong, the code expired, or the purpose mismatches.
 */

namespace SSMS\Acm\Activation;

class ActivationException extends \InvalidArgumentException
{
    public const CODE_INVALID        = 'code_invalid';
    // Reserved vocabulary (D4.3): verify() stays generic by design,
    // so these specific codes are unthrown in S3 — they exist for
    // authorized-context specificity (console detail views, S4+).
    public const CODE_EXPIRED        = 'code_expired';
    public const CODE_CONSUMED       = 'code_consumed';
    public const PURPOSE_MISMATCH    = 'purpose_mismatch';
    public const GRANT_INVALID       = 'grant_invalid';
    public const GRANT_EXPIRED       = 'grant_expired';
    public const GRANT_CONSUMED      = 'grant_consumed';
    public const GRANT_REVOKED       = 'grant_revoked';
    public const ACCOUNT_EXISTS      = 'account_exists';
    public const RATE_LIMITED        = 'rate_limited';
    public const IDEMPOTENT_CONFLICT = 'idempotent_conflict';
    public const NETWORK_DENIED      = 'network_denied';
    public const TICKET_STATE        = 'ticket_state';
    public const WEAK_PASSWORD       = 'weak_password';
    public const INVALID_REQUEST     = 'invalid_request';

    private string $machineCode;
    private int $httpStatus;

    public function __construct(string $machineCode, int $httpStatus, string $safeMessage)
    {
        $this->machineCode = $machineCode;
        $this->httpStatus = $httpStatus;
        parent::__construct($safeMessage, $httpStatus);
    }

    public function machineCode(): string
    {
        return $this->machineCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** The single generic verify failure — no oracle (§D4.5). */
    public static function verifyFailed(): self
    {
        return new self(self::CODE_INVALID, 400, 'The code is invalid or expired.');
    }

    public static function networkDenied(): self
    {
        return new self(
            self::NETWORK_DENIED, 403,
            'Activation is not available from this network.'
        );
    }

    public static function rateLimited(): self
    {
        return new self(
            self::RATE_LIMITED, 429,
            'Too many attempts. Wait a while and try again.'
        );
    }
}
