<?php
/**
 * Server-authoritative deployment feature capabilities.
 *
 * UI clients may use all() to hide unavailable controls, but every protected
 * route must also enforce the corresponding capability on the server.
 */
namespace App\Services;

final class FeatureGate
{
    private const CONSTANTS = [
        'ai' => 'FEATURE_AI_CHATBOT',
        'groups' => 'FEATURE_GROUPS',
        'finance' => 'FEATURE_FINANCE',
        'material' => 'FEATURE_MATERIAL',
        'mezmur' => 'FEATURE_MEZMUR',
        'id_cards' => 'FEATURE_ID_CARDS',
        'attendance' => 'FEATURE_ATTENDANCE',
        'grades' => 'FEATURE_GRADES',
        'reports' => 'FEATURE_REPORTS',
        // Attendance alerting v2 (department status, policies, warnings). Default OFF.
        'attendance_v2' => 'FEATURE_ATTENDANCE_V2',
        'export_pdf' => 'FEATURE_EXPORT_PDF',
        'monitor' => 'FEATURE_MONITOR',
        // Review-only Telegram registration. Default OFF; requires sql/080 and deployment-local secrets.
        'telegram_registration' => 'FEATURE_TELEGRAM_REGISTRATION',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::CONSTANTS);
    }

    public static function isEnabled(string $feature): bool
    {
        $constant = self::CONSTANTS[$feature] ?? null;
        if ($constant === null || !defined($constant)) {
            return false;
        }
        // Deployment values must be actual booleans. Ambiguous strings such as
        // "false" fail closed instead of becoming truthy in PHP.
        return constant($constant) === true;
    }

    /** @return array<string,bool> */
    public static function all(): array
    {
        $result = [];
        foreach (self::CONSTANTS as $feature => $_constant) {
            $result[$feature] = self::isEnabled($feature);
        }
        return $result;
    }

    /** @return array<string,bool> */
    public static function mobileCapabilities(): array
    {
        $result = [];
        foreach (['attendance', 'grades', 'finance', 'material', 'groups', 'id_cards', 'reports', 'mezmur', 'attendance_v2'] as $feature) {
            $result[$feature] = self::isEnabled($feature);
        }
        return $result;
    }

    /**
     * Resolve whole-module admin/browser endpoints. Mixed APIs (education,
     * subjects and communication) are gated by action in their controllers.
     */
    public static function forAdminRequest(string $script): ?string
    {
        $path = strtolower(str_replace('\\', '/', $script));
        $base = basename($path);
        if (str_contains($path, '/id_cards/')) {
            return 'id_cards';
        }
        $map = [
            'api_ai.php' => 'ai',
            'ai_assistant.php' => 'ai',
            'ai_chatbot_widget.php' => 'ai',
            'groups.php' => 'groups',
            'groups_api.php' => 'groups',
            'api_finance.php' => 'finance',
            'finance.php' => 'finance',
            'finance_dept.php' => 'finance',
            'finance_department.php' => 'finance',
            'api_material.php' => 'material',
            'material.php' => 'material',
            'material_department.php' => 'material',
            'api_mezmur.php' => 'mezmur',
            'mezmur.php' => 'mezmur',
            'mezmur_dept.php' => 'mezmur',
            'api_attendance.php' => 'attendance',
            'attendance.php' => 'attendance',
            'api_attendance_info.php' => 'attendance',
            'attendance_info.php' => 'attendance',
            'attendance_taker.php' => 'attendance',
            'api_department_status.php' => 'attendance',
            'api_attendance_policy.php' => 'attendance',
            'api_attendance_warnings.php' => 'attendance',
            'attendance_warnings.php' => 'attendance',
            'api_attendance_monthly.php' => 'attendance',
            'attendance_monthly.php' => 'attendance',
            'api_assessment_followups.php' => 'attendance',
            'assessment_followups.php' => 'attendance',
            'api_lesson_attendance.php' => 'attendance',
            'lesson_attendance.php' => 'attendance',
            'api_reports.php' => 'reports',
            'api_reports_v2.php' => 'reports',
            'reports_v2.php' => 'reports',
            'api_report_views.php' => 'reports',
            'reports.php' => 'reports',
            'api_export_members.php' => 'reports',
            'export_class_report.php' => 'reports',
            'export_pdf.php' => 'reports',
        ];
        return $map[$base] ?? null;
    }

    public static function forApiResource(string $resource): ?string
    {
        return match ($resource) {
            'attendance' => 'attendance',
            'grades' => 'grades',
            'mezmur' => 'mezmur',
            default => null,
        };
    }

    public static function forRoleDashboard(string $role): ?string
    {
        return match ($role) {
            'finance_dept' => 'finance',
            'material_dept' => 'material',
            'mezmur_dept' => 'mezmur',
            'attendance_taker' => 'attendance',
            default => null,
        };
    }

    /**
     * Remove mobile tile IDs whose server module is disabled.
     * Unknown tiles are preserved for forward-compatible app releases.
     *
     * @param array<string,mixed> $areas
     * @return array<string,list<string>>
     */
    public static function filterMobileTiles(array $areas): array
    {
        $tileFeatures = [
            'attendance' => 'attendance',
            'grades' => 'grades',
            'finance' => 'finance',
            'material' => 'material',
            'groups' => 'groups',
            'id_cards' => 'id_cards',
            'reports' => 'reports',
            'warnings' => 'attendance_v2',
        ];
        $filtered = [];
        foreach ($areas as $area => $tiles) {
            if (!is_string($area) || !is_array($tiles)) {
                continue;
            }
            $filtered[$area] = [];
            foreach (array_slice($tiles, 0, 50) as $tile) {
                if (!is_scalar($tile)) {
                    continue;
                }
                $id = trim((string)$tile);
                if ($id === '' || strlen($id) > 64) {
                    continue;
                }
                $feature = $tileFeatures[$id] ?? null;
                if ($feature === null || self::isEnabled($feature)) {
                    $filtered[$area][] = $id;
                }
            }
            $filtered[$area] = array_values(array_unique($filtered[$area]));
        }
        return $filtered;
    }
}
