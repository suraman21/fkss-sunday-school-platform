<?php
/**
 * ACM contracts (S2): person resolution. Implementations enforce the
 * caller's lookup permission and audit the resolution.
 */

namespace SSMS\Acm\Contracts;

interface PersonDirectory
{
    /**
     * @throws \InvalidArgumentException unknown code or lookup denied.
     */
    public function resolveByMemberCode(AccountContext $ctx, string $memberCode): PersonRef;
}
