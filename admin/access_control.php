<?php
/**
 * ============================================================
 * CENTRALIZED ACCESS CONTROL GUARD
 * ============================================================
 * This file is included automatically at the END of config.php.
 * It is the ONE place that decides who is allowed to open which
 * admin page or API endpoint.
 *
 * WHY THIS EXISTS
 * ---------------
 * Before this file, every admin script checked access on its own —
 * and several checked NOTHING. For example print_member.php and
 * info_manage_member.php could be opened by anyone on the internet,
 * exposing every student's full record. Other endpoints (finance,
 * education) only checked "is the person logged in?" — so a teacher
 * could read the finance ledger or delete a class.
 *
 * HOW IT DECIDES (in order)
 * -------------------------
 *   1. Command-line (cron) requests are never blocked.
 *   2. The mobile app API (api_mobile.php) is skipped here because
 *      it authenticates with its own token, not a web session.
 *   3. Only scripts inside /admin/ or /backend/ are guarded.
 *   4. A short list of PUBLIC pages (the login screen, logout, the
 *      login handler, the mobile session bridge, the theme file) is
 *      allowed through without a login.
 *   5. Every other admin page REQUIRES a logged-in user.
 *   6. If the page appears in ROLE_MAP, the user's role must be in
 *      the allowed list, otherwise access is denied.
 *
 * FAIL-SAFE BEHAVIOUR
 * -------------------
 * If a page is not listed in ROLE_MAP it still requires login, it
 * simply has no extra role restriction. That means forgetting to
 * list a page can never accidentally leave it open to the public —
 * the worst case is that it only requires login.
 * ============================================================
 */

if (!defined('ACCESS_CONTROL_LOADED')) {
    define('ACCESS_CONTROL_LOADED', true);

    // Run the check inside a closure so its variables never leak
    // into the pages that include config.php.
    (function () {

        // 1. Never block command-line / cron scripts.
        if (PHP_SAPI === 'cli') {
            return;
        }

        // 2. The mobile API authenticates with a token, not a session.
        //    api_mobile.php defines this before loading config.php.
        if (defined('WBWS_API_REQUEST')) {
            return;
        }

        // 3. Work out which script the browser actually requested.
        $script = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
        $base   = strtolower(basename($script));

        // Only guard the admin area (covers both /admin/ and the
        // legacy /backend/ shim folder).
        $inAdminArea = (strpos($script, '/admin/') !== false)
                    || (strpos($script, '/backend/') !== false);
        if (!$inAdminArea) {
            return; // public site, mobile app, /api/v1 — handled elsewhere
        }

        // Deployment-time schema mutators and diagnostics are never web
        // applications. This application-level denial remains effective when
        // Apache .htaccess rules are unavailable or accidentally disabled.
        $deploymentOnly = str_contains(strtolower(str_replace('\\', '/', $script)), '/migrations/')
            || in_array($base, ['get_schema.php', 'qr_diagnostic.php', 'leak_detector.php'], true);
        if ($deploymentOnly) {
            _ac_deny(404, 'This deployment tool is not available over HTTP.');
        }

        // 4. Pages that must stay reachable WITHOUT logging in.
        $PUBLIC_PAGES = [
            'index.php',        // the admin login screen itself
            'login.php',        // backend/login.php — processes the login form
            'logout.php',       // clears the session
            'app_session.php',  // retired phone-to-web bridge (returns 410)
            'theme.php',        // theme CSS, pulled in by the login screen
            'manifest.php',
        ];
        if (in_array($base, $PUBLIC_PAGES, true)) {
            return;
        }

        // 5. Which roles may use each page.
        //    Keys include BOTH the real name (api_finance.php) and the
        //    /backend/ shim name (finance.php) so neither path is a hole.
        $ROLE_MAP = [
            // ---- Finance: finance staff + admins ONLY ----
            'api_finance.php'   => ['super_admin', 'school_admin', 'finance_dept'],
            'finance.php'       => ['super_admin', 'school_admin', 'finance_dept'],
            'finance_department.php' => ['super_admin', 'school_admin', 'finance_dept'],
            'finance_dept.php'  => ['super_admin', 'school_admin', 'finance_dept'],
            'mezmur.php'        => ['super_admin', 'school_admin', 'mezmur_dept'],
            'api_mezmur.php'    => ['super_admin', 'school_admin', 'mezmur_dept'],
            'mezmur_dept.php'   => ['super_admin', 'school_admin', 'mezmur_dept'],

            // ---- Education / academics ----
            // Teachers & attendance-takers legitimately READ these and record
            // grades/attendance, so they are allowed IN. The destructive
            // class/year MANAGEMENT actions are blocked separately, inside
            // api_education.php / api_subjects.php (action-level check).
            // hr_dept is allowed onto the file so it can read get_classes
            // (HR registration class dropdown). Destructive / roster actions
            // are still blocked inside api_education.php for that role.
            'api_education.php' => ['super_admin', 'school_admin', 'edu_dept', 'hr_dept', 'teacher', 'attendance_taker'],
            'education.php'     => ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'attendance_taker'],
            'api_subjects.php'  => ['super_admin', 'school_admin', 'edu_dept', 'teacher'],
            'subjects.php'      => ['super_admin', 'school_admin', 'edu_dept', 'teacher'],
            'edu_dept.php'      => ['super_admin', 'school_admin', 'edu_dept'],
            'education_department.php' => ['super_admin', 'school_admin', 'edu_dept'],
            // Teachers manage their own assignments; the file refines further internally.
            'api_teachers.php'  => ['super_admin', 'school_admin', 'edu_dept', 'teacher'],
            'teachers.php'      => ['super_admin', 'school_admin', 'edu_dept', 'teacher'],
            'teacher.php'       => ['super_admin', 'school_admin', 'teacher'],
            'api_assignments.php' => ['super_admin', 'school_admin', 'edu_dept'],
            'assignments.php'     => ['super_admin', 'school_admin', 'edu_dept'],
            'edu_assignments.php' => ['super_admin', 'school_admin', 'edu_dept'],
            'edu_teachers.php'    => ['super_admin', 'school_admin', 'edu_dept'],
            'api_timetable.php'   => ['super_admin', 'school_admin', 'edu_dept', 'teacher'],
            'timetable.php'       => ['super_admin', 'school_admin', 'edu_dept', 'teacher'],

            // ---- Attendance ----
            'api_attendance.php'      => ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'attendance_taker'],
            'attendance.php'          => ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'attendance_taker'],
            'api_attendance_info.php' => ['super_admin', 'school_admin', 'info_dept', 'edu_dept', 'teacher', 'attendance_taker'],
            'attendance_info.php'     => ['super_admin', 'school_admin', 'info_dept', 'edu_dept', 'teacher', 'attendance_taker'],
            'attendance_taker.php'    => ['super_admin', 'school_admin', 'attendance_taker'],
            // Attendance alerting v2 (Phase 1): department status + policy.
            // Writes are restricted further inside each API (HR/Info; School Admin).
            'api_department_status.php' => ['super_admin', 'school_admin', 'hr_dept', 'info_dept', 'edu_dept', 'mezmur_dept'],
            'api_attendance_policy.php' => ['super_admin', 'school_admin', 'hr_dept', 'info_dept', 'edu_dept', 'mezmur_dept'],
            'api_attendance_warnings.php' => ['super_admin', 'school_admin', 'hr_dept', 'info_dept', 'edu_dept', 'mezmur_dept'],
            'attendance_warnings.php' => ['super_admin', 'school_admin', 'hr_dept', 'info_dept', 'edu_dept', 'mezmur_dept'],
            // Phase 4: shared monthly attendance report (read-only, department-scoped inside the API).
            'api_attendance_monthly.php' => ['super_admin', 'school_admin', 'hr_dept', 'info_dept', 'edu_dept', 'mezmur_dept'],
            'attendance_monthly.php' => ['super_admin', 'school_admin', 'hr_dept', 'info_dept', 'edu_dept', 'mezmur_dept'],
            // Phase 5: assessment follow-ups / make-up queue. Teachers are scoped to
            // their class + subject assignments and HR / Info / admins are read-only INSIDE the API.
            'api_assessment_followups.php' => ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'hr_dept', 'info_dept'],
            'assessment_followups.php' => ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'hr_dept', 'info_dept'],
            'api_lesson_attendance.php' => ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'hr_dept', 'info_dept'],
            'lesson_attendance.php' => ['super_admin', 'school_admin', 'edu_dept', 'teacher', 'hr_dept', 'info_dept'],

            // ---- Marklists / report cards ----
            'api_communication.php' => ['super_admin', 'school_admin', 'edu_dept', 'teacher'],
            'communication.php'     => ['super_admin', 'school_admin', 'edu_dept', 'teacher'],
            'export_class_report.php' => ['super_admin', 'school_admin', 'edu_dept', 'teacher'],
            'export_executive_report_excel.php' => ['super_admin', 'school_admin', 'edu_dept'],
            'export_executive_report_pdf.php' => ['super_admin', 'school_admin', 'edu_dept'],
            'export_filtered_students.php' => ['super_admin', 'school_admin', 'edu_dept', 'teacher'],

            // ---- Member management (Information department) ----
            // finance_dept included: the finance dashboard fetches the student
            // roster to assign fees (frontend/js/finance.js → members.php).
            'hr_register_member.php'  => ['super_admin', 'school_admin', 'hr_dept'],
            'api_list_members.php'    => ['super_admin', 'school_admin', 'info_dept', 'hr_dept', 'edu_dept', 'finance_dept'],
            'members.php'             => ['super_admin', 'school_admin', 'info_dept', 'hr_dept', 'edu_dept', 'finance_dept'],
            'api_check_duplicate.php' => ['super_admin', 'school_admin', 'hr_dept'],
            'members_check.php'       => ['super_admin', 'school_admin', 'hr_dept'],
            'info_manage_member.php'        => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],
            'member_file.php'               => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],
            'info_archive_member.php'       => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],
            'info_restore_member.php'       => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],
            'info_get_archived_members.php' => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],
            'print_member.php'  => ['super_admin', 'school_admin', 'info_dept', 'hr_dept', 'edu_dept'],
            'info-dept.php'     => ['super_admin', 'school_admin', 'info_dept'],
            'hr-dept.php'       => ['super_admin', 'school_admin', 'hr_dept'],

            // ---- Material department ----
            'api_material.php'      => ['super_admin', 'school_admin', 'material_dept'],
            'material.php'          => ['super_admin', 'school_admin', 'material_dept'],
            'material_department.php' => ['super_admin', 'school_admin', 'material_dept'],

            // ---- Groups / associations ----
            'groups.php'     => ['super_admin', 'school_admin', 'hr_dept'],

            // ---- P72 Communication Center: every staff role ----
            'notifications.php' => ['super_admin', 'school_admin', 'info_dept', 'edu_dept', 'finance_dept', 'material_dept', 'mezmur_dept', 'hr_dept', 'teacher', 'attendance_taker', 'mezmur_attendance_taker', 'hr_attendance_taker', 'content_editor'],
            'messages.php'      => ['super_admin', 'school_admin', 'info_dept', 'edu_dept', 'finance_dept', 'material_dept', 'mezmur_dept', 'hr_dept', 'teacher', 'attendance_taker', 'mezmur_attendance_taker', 'hr_attendance_taker', 'content_editor'],
            'groups_api.php' => ['super_admin', 'school_admin', 'hr_dept'],

            // ---- Reports & exports (contain all-member data) ----
            // Member analytics & export centre: Info + HR analyse and export
            // member demographics (2026-10-18). Both roles already had every
            // export below; the page itself is read-only.
            'api_reports.php' => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],
    'api_reports_v2.php' => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],
    'reports_v2.php'     => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],
    'api_report_views.php' => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],
            'reports.php'     => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],
            'export_pdf.php'  => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],
            'api_export_members.php' => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],
            'api_import_members.php' => ['super_admin', 'school_admin', 'hr_dept'],
            'api_test_seed.php'      => ['super_admin'],

            // ---- CMS (public website content) ----
            'api_cms.php'        => ['super_admin', 'school_admin', 'info_dept', 'hr_dept', 'content_editor'],
            'content_editor.php' => ['super_admin', 'school_admin', 'content_editor'],

            // ---- Branding / settings / health ----
            'api_branding.php'  => ['super_admin', 'school_admin'],
            // NOTE: api_settings.php is intentionally NOT role-restricted here.
            // Every logged-in user reads it for their own profile and shared
            // settings (e.g. calendar mode); it checks the role itself for the
            // few write actions. It still requires login (handled above).
            'system_health.php' => ['super_admin', 'school_admin'],

            // ---- Impersonation (already restricted internally too) ----
            'api_impersonate.php' => ['super_admin', 'school_admin'],
            'impersonate.php'     => ['super_admin', 'school_admin'],

            // ---- Year rollover: School Admin (owner) + Super Admin (break-glass) ----
            // Driven as a JSON endpoint by the School Admin dashboard's Academic
            // Year section; matches the rest of the year lifecycle ownership.
            'year_rollover.php' => ['super_admin', 'school_admin'],

            // ---- Backups: SUPER ADMIN ONLY (contain all sensitive records) ----
            'backup.php'          => ['super_admin'],
            'download_backup.php' => ['super_admin'],

            // ---- Mobile App Releases & Telemetry: SUPER ADMIN & SCHOOL ADMIN ----
            'api_app_release.php' => ['super_admin'],
            'api_telemetry.php'   => ['super_admin', 'school_admin'],
            'api_failure_intelligence.php' => ['super_admin'],

            // ---- Identity & Codes hub: SUPER ADMIN ONLY ----
            'api_identity.php'  => ['super_admin'],
            'api_identity_migration.php' => ['super_admin'],

            // ---- User management: SUPER ADMIN ONLY ----
            'users.php'       => ['super_admin'],
            'user-save.php'   => ['super_admin'],
            'user-delete.php' => ['super_admin'],
            'user-toggle.php' => ['super_admin'],

            // ---- ACM console S1: single accounts_office seat ----
            // Actions are restricted further inside api_acm.php via
            // AcmPolicy (mode-aware maker-checker by user ID) and
            // AcmOfficeService (solo designations, attestations).
            // Member/parent login roles are NOT listed here on
            // purpose: they must never open the admin console.
            'api_acm.php'      => ['super_admin', 'accounts_office'],
            'acm_overview.php' => ['super_admin', 'accounts_office'],
            'acm_queue.php'    => ['super_admin', 'accounts_office'],
            'acm_ticket.php'   => ['super_admin', 'accounts_office'],
            'acm_person.php'   => ['super_admin', 'accounts_office'],
            'acm_office.php'   => ['super_admin', 'accounts_office'],
            'acm_audit.php'    => ['super_admin', 'accounts_office'],
            'acm_layout.php'   => ['super_admin', 'accounts_office'],

            // ---- Department-owned attendance takers ----
            // Each department manages ONLY its own taker type; the
            // service layer re-enforces the attribution server-side.
            'api_dept_takers.php' => ['super_admin', 'school_admin', 'mezmur_dept'],

            // ---- HR attendance review console (HR data only) ----
            'api_hr_attendance.php' => ['super_admin', 'school_admin', 'hr_dept'],
            'api_hr_reports.php' => ['super_admin', 'school_admin', 'hr_dept'],
            // Shared attendance analytics (Education + Mezmur): Info + HR analyse, admins refresh.
            'api_info_analytics.php' => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],
            'api_info_reports.php' => ['super_admin', 'school_admin', 'info_dept', 'hr_dept'],

            // ---- QR roster printing (Phase 8): each department prints
            // ONLY its own carrier; ownership re-checked in the endpoint.
            'api_qr_roster.php' => ['super_admin', 'school_admin', 'edu_dept', 'mezmur_dept', 'hr_dept'],

            // ---- ID cards ----
            'view_id_card.php'     => ['super_admin', 'school_admin', 'hr_dept'],
            'generate_id_card.php' => ['super_admin', 'school_admin', 'hr_dept'],
            'preview.php'          => ['super_admin', 'school_admin'],

            // ---- Dashboards (also protected by dashboards/.htaccess) ----
            'super-admin.php'   => ['super_admin'],
            'school_admin.php'  => ['super_admin', 'school_admin'],
        ];

        // 6. Require a logged-in user for every non-public admin page.
        $loggedIn = function_exists('isLoggedIn')
            ? isLoggedIn()
            : (!empty($_SESSION['admin_logged_in']));

        if (!$loggedIn) {
            _ac_deny(401, 'Please log in to continue.');
        }

        // 7. Enforce the role list where one is defined for this page.
        if (isset($ROLE_MAP[$base])) {
            $role = $_SESSION['admin_role'] ?? '';
            // The assumed department must not strand an impersonating admin.
            // Only the switch/restore endpoint may authorize by the original
            // role; every other page keeps the restricted, assumed role.
            if (in_array($base, ['api_impersonate.php', 'impersonate.php'], true)
                && in_array($_SESSION['original_admin_role'] ?? '', ['super_admin', 'school_admin'], true)) {
                $role = $_SESSION['original_admin_role'];
            }
            if (!in_array($role, $ROLE_MAP[$base], true)) {
                _ac_deny(403, 'You do not have permission to use this page.');
            }
        }

        // 8. Deployment feature flags are server-side capabilities, not UI
        // preferences. A hidden menu alone must never leave its endpoint live.
        $feature = \App\Services\FeatureGate::forAdminRequest($script);
        if ($feature !== null && !\App\Services\FeatureGate::isEnabled($feature)) {
            _ac_deny(403, 'This feature is not enabled for this deployment.');
        }
    })();
}

/**
 * Send an "access denied" response and stop.
 * - For AJAX / API calls it returns JSON (so the front-end can react).
 * - For a normal page it redirects to the login screen.
 */
function _ac_deny($code, $msg) {
    $isAjax = function_exists('_isAjaxRequest') ? _isAjaxRequest() : false;

    if (!headers_sent()) {
        http_response_code($code);
    }

    if ($isAjax) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status'  => ($code === 401 ? 'session_expired' : 'error'),
            'message' => $msg,
            'action'  => ($code === 401 ? 'reload' : 'denied'),
        ]);
    } else {
        if (!headers_sent()) {
            $adminBase = ssms_app_url('admin');
            $suffix = ($code === 401) ? '?timeout=1' : '?error=access_denied';
            header('Location: ' . $adminBase . '/index.php' . $suffix);
        }
        echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    }
    exit;
}
