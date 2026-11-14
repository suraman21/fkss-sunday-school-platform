<?php
/**
 * ACM contracts (S2): the authorization seam. can() is the bool
 * compatibility check over the current matrix; decide() explains.
 * A future Casbin enforcer implements this interface — callers do
 * not change.
 */

namespace SSMS\Acm\Contracts;

interface Authorizer
{
    public function can(AccountContext $ctx, string $action, ResourceRef $resource): bool;

    public function decide(AccountContext $ctx, string $action, ResourceRef $resource): AuthorizationDecision;
}
