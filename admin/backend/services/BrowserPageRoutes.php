<?php
/** Browser-page aliases only. This registry is not an authorization policy. */
namespace App\Services;

final class BrowserPageRoutes
{
    // Preserve containing directories: relative browser and PHP targets must
    // resolve exactly as they do when the legacy script is requested directly.
    private const PAGES = [
        '/' => '/index.php',
        '/verify-member' => '/member.php',
        '/admin/login' => '/admin/index.php',
        '/admin/dashboard' => '/admin/dashboard.php',
        '/admin/account' => '/admin/account.php',
        '/admin/member' => '/admin/info_manage_member.php',
        '/admin/member-print' => '/admin/print_member.php',
        '/admin/messages' => '/admin/messages.php',
        '/admin/notifications' => '/admin/notifications.php',
        '/admin/groups' => '/admin/groups.php',
        '/admin/reports' => '/admin/reports.php',
        '/admin/report-center' => '/admin/reports_v2.php',
        '/admin/calendar' => '/admin/school_calendar.php',
        '/admin/lesson-attendance' => '/admin/lesson_attendance.php',
        '/admin/monthly-attendance' => '/admin/attendance_monthly.php',
        '/admin/attendance-warnings' => '/admin/attendance_warnings.php',
        '/admin/assessment-followups' => '/admin/assessment_followups.php',
        '/admin/users' => '/admin/users.php',
        '/admin/system-health' => '/admin/system_health.php',
        '/admin/id_cards/card' => '/admin/id_cards/view_id_card.php',
        '/admin/id_cards/preview' => '/admin/id_cards/preview.php',
        '/monitor/dashboard' => '/monitor/index.php',
        '/admin/dashboards/school' => '/admin/dashboards/school_admin.php',
        '/admin/dashboards/super-admin' => '/admin/dashboards/super-admin.php',
        '/admin/dashboards/hr' => '/admin/dashboards/hr-dept.php',
        '/admin/dashboards/information' => '/admin/dashboards/info-dept.php',
        '/admin/dashboards/education' => '/admin/dashboards/edu_dept.php',
        '/admin/dashboards/finance' => '/admin/dashboards/finance_department.php',
        '/admin/dashboards/materials' => '/admin/dashboards/material_department.php',
        '/admin/dashboards/teacher' => '/admin/dashboards/teacher.php',
        '/admin/dashboards/attendance' => '/admin/dashboards/attendance_taker.php',
        '/admin/dashboards/department-attendance' => '/admin/dashboards/dept_taker.php',
        '/admin/dashboards/content' => '/admin/dashboards/content_editor.php',
        '/admin/dashboards/groups' => '/admin/dashboards/groups.php',
        '/admin/dashboards/assistant' => '/admin/dashboards/ai_assistant.php',
        '/frontend/pages/login' => '/frontend/pages/login.php',
        '/frontend/pages/dashboard' => '/frontend/pages/dashboard.php',
        '/frontend/pages/finance' => '/frontend/pages/finance_dept.php',
        '/frontend/pages/mezmur' => '/frontend/pages/mezmur_dept.php',
        '/frontend/pages/teachers' => '/frontend/pages/edu_teachers.php',
        '/frontend/pages/assignments' => '/frontend/pages/edu_assignments.php',
    ];

    public static function pages(): array { return self::PAGES; }

    /** Determine an optional deployment prefix from a trusted script identity. */
    public static function basePath(string $script): ?string
    {
        $targets = array_merge(['/browser_page.php'], array_values(self::PAGES));
        // /admin/index.php must win over the shorter /index.php suffix.
        usort($targets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($targets as $target) {
            if (!str_ends_with($script, $target)) continue;
            $base = substr($script, 0, -strlen($target));
            if (!preg_match('~^(?:/[A-Za-z0-9_-]+)*$~D', $base)) return null;
            return $base;
        }
        return null;
    }

    /** Strict path matching: no decoding, path-info, slash or dot normalization. */
    public static function path(string $requestUri, string $base): ?string
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $requestUri)) return null;
        $path = explode('?', $requestUri, 2)[0];
        if ($base !== '' && !str_starts_with($path, $base . '/')) return null;
        return $base === '' ? $path : substr($path, strlen($base));
    }

    /** Build handler identity before any existing application bootstrap executes. */
    public static function dispatchIdentity(array $server, string $root): ?array
    {
        // The admin per-directory rewrite records its deployment prefix:
        // Apache Alias may otherwise collapse SCRIPT_NAME to /browser_page.php.
        // These are server rewrite variables, not client HTTP_* headers.
        $base = $server['FKSS_BROWSER_PREFIX'] ?? $server['REDIRECT_FKSS_BROWSER_PREFIX']
            ?? self::basePath((string)($server['SCRIPT_NAME'] ?? ''));
        if (!is_string($base) || !preg_match('~^(?:/[A-Za-z0-9_-]+)*$~D', $base)) return null;
        $path = self::path((string)($server['REQUEST_URI'] ?? ''), $base);
        $target = self::PAGES[$path ?? ''] ?? null;
        if ($target === null) return null;
        $server['SCRIPT_NAME'] = $base . $target;
        $server['PHP_SELF'] = $base . $target;
        $server['SCRIPT_FILENAME'] = $root . $target;
        return $server;
    }

    /** Only document navigation may redirect; old AJAX/POST/API requests stay put. */
    public static function navigationRedirect(array $server): ?string
    {
        $enabled = ($server['FKSS_BROWSER_ROUTES'] ?? $server['REDIRECT_FKSS_BROWSER_ROUTES'] ?? '') === '1';
        if (!$enabled || !in_array($server['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) return null;
        if (!empty($server['HTTP_X_REQUESTED_WITH']) || !empty($server['HTTP_X_CSRF_TOKEN'])) return null;
        $accept = strtolower((string)($server['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json')) return null;
        $dest = strtolower((string)($server['HTTP_SEC_FETCH_DEST'] ?? ''));
        if ($dest !== '') {
            if (!in_array($dest, ['document', 'iframe'], true)
                || ($server['HTTP_SEC_FETCH_MODE'] ?? '') !== 'navigate') return null;
        } elseif (!str_contains($accept, 'text/html')) {
            return null;
        }
        // Physical identity prevents /api/v1/index.php being mistaken for
        // a subdirectory installation of the public /index.php homepage.
        $root = dirname(__DIR__, 3);
        $file = realpath((string)($server['SCRIPT_FILENAME'] ?? ''));
        if ($file === false || !str_starts_with($file, $root . '/')) return null;
        $legacy = substr($file, strlen($root));
        if (!in_array($legacy, self::PAGES, true)) return null;
        $script = (string)($server['SCRIPT_NAME'] ?? '');
        if (!str_ends_with($script, $legacy)) return null;
        $base = substr($script, 0, -strlen($legacy));
        if (!preg_match('~^(?:/[A-Za-z0-9_-]+)*$~D', $base)) return null;
        $uri = (string)($server['REQUEST_URI'] ?? '');
        $path = self::path($uri, $base);
        $clean = array_search($path, self::PAGES, true);
        if ($clean === false) return null;
        $query = strpos($uri, '?');
        return $base . $clean . ($query === false ? '' : substr($uri, $query));
    }
}
