<?php
/**
 * ACM S2: PolicyAuthorizer is exactly the AcmPolicy matrix. Any
 * divergence between the seam S3/S4 will wire and the behavior S1
 * shipped fails here first.
 */

namespace SSMS\Acm\Tests;

use PHPUnit\Framework\TestCase;
use SSMS\Acm\Contracts\AccountContext;
use SSMS\Acm\Contracts\ResourceRef;
use SSMS\Acm\Policy\AcmPolicy;
use SSMS\Acm\Policy\PolicyAuthorizer;

final class PolicyParityTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function matrixCases(): iterable
    {
        $roles = ['super_admin', 'accounts_office', 'member', 'parent', 'school_admin', 'nope'];
        // S3 adds the 'code' resource + 'issue' action to the sweep.
        $resources = ['ticket', 'person', 'account', 'audit', 'code', 'office', 'approver', 'reviewer', 'attest', 'bogus'];
        $actions = ['create', 'view', 'edit', 'claim', 'note', 'approve', 'reject', 'request_changes', 'cancel', 'issue', 'delete', ''];
        foreach ($roles as $role) {
            foreach ($resources as $resource) {
                foreach ($actions as $action) {
                    yield "$role/$resource/$action" => [$role, $resource, $action];
                }
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('matrixCases')]
    public function testCanMatchesMatrix(string $role, string $resource, string $action): void
    {
        $auth = new PolicyAuthorizer();
        $ctx = new AccountContext(7, $role);
        $this->assertSame(
            AcmPolicy::can($role, $resource, $action),
            $auth->can($ctx, $action, new ResourceRef($resource)),
            "$role/$resource/$action"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('matrixCases')]
    public function testDecideEnvelopeMatchesCan(string $role, string $resource, string $action): void
    {
        $auth = new PolicyAuthorizer();
        $decision = $auth->decide(new AccountContext(7, $role), $action, new ResourceRef($resource, 'r1'));
        $this->assertSame(AcmPolicy::can($role, $resource, $action), $decision->allowed);
        $this->assertSame($decision->allowed ? 'acm.matrix.allow' : 'acm.matrix.deny', $decision->policyId);
        $this->assertSame($decision->allowed ? null : 'matrix.deny', $decision->denialCategory);
        $this->assertStringContainsString($role, $decision->accountRef);
        $this->assertStringContainsString($resource, $decision->accountRef);
        // Client responses must never carry policy internals: the
        // envelope is internal-only by construction (no toArray/json).
        $this->assertFalse(method_exists($decision, 'toArray'));
        $this->assertFalse(method_exists($decision, 'jsonSerialize'));
    }

    public function testDenyByDefaultHoldsThroughTheSeam(): void
    {
        $auth = new PolicyAuthorizer();
        $this->assertFalse($auth->can(new AccountContext(1, 'accounts_office'), 'approve', new ResourceRef('office')));
        $this->assertFalse($auth->can(new AccountContext(1, 'accounts_office'), 'attest', new ResourceRef('attest')));
        $this->assertTrue($auth->can(new AccountContext(1, 'super_admin'), 'anything', new ResourceRef('anything')));
    }

    /** S3: officers run the ceremony (issue/view/cancel) — nothing else new. */
    public function testCeremonyRights(): void
    {
        $auth = new PolicyAuthorizer();
        $office = new AccountContext(1, 'accounts_office');
        $this->assertTrue($auth->can($office, 'issue', new ResourceRef('code')));
        $this->assertTrue($auth->can($office, 'view', new ResourceRef('code')));
        $this->assertTrue($auth->can($office, 'cancel', new ResourceRef('ticket')));
        $this->assertFalse($auth->can($office, 'delete', new ResourceRef('code')));
        $member = new AccountContext(2, 'member');
        $this->assertFalse($auth->can($member, 'issue', new ResourceRef('code')));
        $this->assertFalse($auth->can($member, 'view', new ResourceRef('code')));
    }
}
