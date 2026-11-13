<?php
/**
 * ACM S2: Authorizer implementation backed by the AcmPolicy matrix.
 * ------------------------------------------------------------
 * can() is exactly AcmPolicy::can() (parity is PHPUnit-pinned);
 * decide() adds the explainable envelope for audit/diagnostics.
 * No caller uses this yet in S2 — it is the seam S3/S4 wire up,
 * shipped now so the contract is real and tested, not a sketch.
 */

namespace SSMS\Acm\Policy;

use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Contracts\AuthorizationDecision;
use SSMS\Acm\Contracts\Authorizer;
use SSMS\Acm\Contracts\ResourceRef;

final class PolicyAuthorizer implements Authorizer
{
    public function can(AccountContext $ctx, string $action, ResourceRef $resource): bool
    {
        return AcmPolicy::can($ctx->role, $resource->resource, $action);
    }

    public function decide(AccountContext $ctx, string $action, ResourceRef $resource): AuthorizationDecision
    {
        $allowed = $this->can($ctx, $action, $resource);
        return new AuthorizationDecision(
            $allowed,
            $allowed ? 'acm.matrix.allow' : 'acm.matrix.deny',
            $ctx->role . '#' . $ctx->userId . '/' . $resource->resource
                . ($resource->id !== null ? ':' . $resource->id : ''),
            $allowed ? null : 'matrix.deny',
            new \DateTimeImmutable(),
        );
    }
}
