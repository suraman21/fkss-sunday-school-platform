<?php
/**
 * ACM contracts (S2): the §4 activation ceremony seam. S3 provides
 * the implementation; controllers are written against this.
 */

namespace SSMS\Acm\Contracts;

interface ActivationService
{
    /**
     * Issue a purpose-bound code. Atomically invalidates any previous
     * active code for the same ticket + purpose.
     *
     * @throws \InvalidArgumentException bad ticket state or permission.
     */
    public function issueCode(AccountContext $ctx, string $ticketId, CodePurpose $purpose): IssuedCode;

    /**
     * @throws \InvalidArgumentException invalid/expired/consumed code,
     * purpose mismatch, or rate-limited (message is generic by design).
     */
    public function verifyCode(string $selector, string $secret, CodePurpose $purpose, RequestMeta $meta): ActivationGrant;

    /**
     * Consume the grant and provision exactly one account (idempotent
     * on $idempotencyKey: same key + same grant replays the result,
     * same key + different parameters is a conflict).
     *
     * @throws \InvalidArgumentException bad/consumed grant, weak
     * password, existing account, or idempotency conflict.
     */
    public function establishCredentials(string $grantToken, string $password, string $idempotencyKey): AccountRef;
}
