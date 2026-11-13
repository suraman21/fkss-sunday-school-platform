<?php
/**
 * ACM P0 policy matrix — hardcoded, Casbin-shaped.
 * ------------------------------------------------------------
 * can($role, $resource, $action) mirrors the Casbin enforce()
 * call shape (subject, object, action) so P1 can swap this file
 * for a casbin/casbin Enforcer without touching callers.
 * Deny-by-default: unknown role/resource/action => false.
 * super_admin bypasses everything (break-glass, audit-trailed).
 */

namespace App\Services\Acm;

class AcmPolicy
{
    public const RES_TICKET  = 'ticket';
    public const RES_PERSON  = 'person';
    public const RES_ACCOUNT = 'account';
    public const RES_AUDIT   = 'audit';

    /**
     * role => resource => actions. P0 roles only; P1 adds
     * acm_admin / helpdesk / auditor here (or in Casbin policy).
     */
    private const MATRIX = [
        'registrar' => [
            'ticket'  => ['create', 'view', 'note'],
            'person'  => ['create', 'view', 'edit'],
            'account' => ['view'],
            'audit'   => [],
        ],
        'account_activator' => [
            'ticket'  => ['view', 'claim', 'note', 'approve', 'reject', 'request_changes'],
            'person'  => ['view'],
            'account' => ['view'],
            'audit'   => ['view'],
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

    /** Roles allowed to open the ACM console at all (P0). */
    public static function consoleRoles(): array
    {
        return ['super_admin', 'registrar', 'account_activator'];
    }

    public static function roleLabel(string $role): string
    {
        $labels = [
            'super_admin'       => 'Super Admin',
            'registrar'         => 'Registrar',
            'account_activator' => 'Account Activator',
            'member'            => 'Member',
            'parent'            => 'Parent',
        ];
        return $labels[$role] ?? ucwords(str_replace('_', ' ', $role));
    }
}
