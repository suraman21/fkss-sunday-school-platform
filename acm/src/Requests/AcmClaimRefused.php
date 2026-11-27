<?php
/**
 * A well-formed activation claim that did not resolve to a registered member.
 * ------------------------------------------------------------
 * WHY THIS IS AN EXCEPTION AND NOT A RETURN VALUE
 *   Callers must not be able to write a claim that failed detection. If
 *   submit() returned ['ok' => false], a future caller could ignore the key
 *   and insert anyway; throwing makes "no match means no request" a property
 *   of the flow rather than a convention.
 *
 * WHY IT CARRIES NO REASON
 *   The reason (no_member / name_mismatch / ambiguous / not_active /
 *   no_account) is recorded in the server-side audit trail and shown to the
 *   office. It is deliberately NOT part of this exception's message, so that
 *   a route that carelessly echoes the message cannot turn the public
 *   endpoint into a member-directory oracle ("that ID is real but the name is
 *   wrong"). Use `kind()` for logging and `getMessage()` for the wire, and
 *   never swap them.
 */

declare(strict_types=1);

namespace SSMS\Acm\Requests;

use RuntimeException;

final class AcmClaimRefused extends RuntimeException
{
    /**
     * The only text a caller may show. It names no cause and no field, so
     * it is identical whether the ID is unknown or the name is wrong.
     */
    public const PUBLIC_MESSAGE = 'We could not verify these details against our member records. '
        . 'Check the ID number and first name, or contact the church office.';

    public function __construct(private string $refusalKind)
    {
        parent::__construct(self::PUBLIC_MESSAGE, 422);
    }

    /**
     * For the audit trail and the office console. NEVER for the wire.
     */
    public function kind(): string
    {
        return $this->refusalKind;
    }
}
