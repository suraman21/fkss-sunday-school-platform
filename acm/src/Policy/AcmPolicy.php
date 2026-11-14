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
            'person'   => ['create', 'view', 'edit'],
            'account'  => ['view'],
            'audit'    => ['view'],
            'code'     => ['issue', 'view'],
            // S4: console device list + revoke (officer-only;
            // self-service revoke is mobile-session-authed).
            'device'   => ['manage'],
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
