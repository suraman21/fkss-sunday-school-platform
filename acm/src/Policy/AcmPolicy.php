<?php
/**
 * ACM S1 policy matrix — hardcoded, Casbin-shaped.
 * ------------------------------------------------------------
 * can($role, $resource, $action) mirrors the Casbin enforce()
 * call shape (subject, object, action) so a later increment can
 * swap this file for a casbin/casbin Enforcer without touching
 * callers. Deny-by-default: unknown role/resource/action => false.
 * super_admin bypasses everything (break-glass, audit-trailed).
 *
 * S1: one operational seat (accounts_office). Maker-checker survives
 * because the ticket service compares user IDs, not role titles.
 * The office deny-list (design §D1.1) is enforced HERE as empty
 * action sets: officers hold no office/approver/reviewer/attest
 * rights at all, so a future endpoint cannot accidentally grant
 * them by checking the wrong resource.
 */

namespace SSMS\Acm\Policy;

class AcmPolicy
{
    public const RES_TICKET   = 'ticket';
    public const RES_PERSON   = 'person';
    public const RES_ACCOUNT  = 'account';
    public const RES_AUDIT    = 'audit';
    public const RES_OFFICE   = 'office';
    public const RES_APPROVER = 'approver';
    public const RES_REVIEWER = 'reviewer';
    public const RES_ATTEST   = 'attest';
    public const RES_CODE     = 'code';
    public const RES_DEVICE   = 'device';
    public const RES_REQUEST  = 'request';

    /**
     * role => resource => actions. accounts_office holds the union of
     * the P0 seats' lifecycle rights and NONE of the governance rights
     * (mode, designations, networks, attestations).
     */
    private const MATRIX = [
        'accounts_office' => [
            // S3: 'cancel' aborts the ceremony (reason + cascade);
            // 'code' issue/view runs the D3.2 issuance panel.
            'ticket'   => ['create', 'view', 'claim', 'note', 'approve', 'reject', 'request_changes', 'cancel'],
            // NO 'create'. HR is the only source of truth for who exists;
            // ACM projects the register into acm_persons and then owns the
            // ACCOUNT, not the person (docs/design/acm-department-boundaries.md).
            // 'edit' stays: ACM annotates its own projection (ID document,
            // notes) without touching the register.
            'person'   => ['view', 'edit'],
            // S7: office suspends/reactivates member/parent
            // accounts (target-role guard lives in the service;
            // staff/officers stay in user administration).
            // S8: 'deactivate' is intentionally ABSENT: the
            // terminal action is super_admin-only (#25) via the
            // bypass above. Deactivating a seat here would need a
            // second thought, not a second entry.
            'account'  => ['view', 'suspend', 'reactivate'],
            'audit'    => ['view'],
            'code'     => ['issue', 'view'],
            // S4: console device list + revoke (officer-only;
            // self-service revoke is mobile-session-authed).
            'device'   => ['manage'],
            // S11: the self-service activation request queue. Members
            // submit these UNAUTHENTICATED (no policy applies); the
            // office reviews, claims and decides them here. Approving
            // is what binds a claim to a person, so it stays in the
            // office seat and is never granted to reviewers/approvers.
            'request'  => ['view', 'claim', 'note', 'approve', 'reject'],
            'office'   => [],
            'approver' => [],
            'reviewer' => [],
            'attest'   => [],
        ],
    ];

    public static function can(string $role, string $resource, string $action): bool
    {
        if ($role === 'super_admin') {
            return true;
        }
        $actions = self::MATRIX[$role][$resource] ?? null;
        if (!is_array($actions)) {
            return false;
        }
        return in_array($action, $actions, true);
    }

    /** Roles allowed to open the ACM console at all (S1). */
    public static function consoleRoles(): array
    {
        return ['super_admin', 'accounts_office'];
    }

    public static function roleLabel(string $role): string
    {
        $labels = [
            'super_admin'     => 'Super Admin',
            'accounts_office' => 'Accounts Office',
            'member'          => 'Member',
            'parent'          => 'Parent',
        ];
        return $labels[$role] ?? ucwords(str_replace('_', ' ', $role));
    }
}
