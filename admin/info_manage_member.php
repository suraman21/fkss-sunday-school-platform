<?php
/**
 * Manage Member - View/Edit Member Details
 * Works with parent page's JavaScript functions
 * IDs must match: tab-preview, tab-edit, view-preview, view-edit
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/services/MemberFileService.php';
require_once __DIR__ . '/backend/services/MemberImportService.php';

// Helper functions
if (!function_exists('sel')) {
    function sel($current, $value) { return ((string)$current === (string)$value) ? 'selected' : ''; }
}
if (!function_exists('chk')) {
    function chk($val) { return (!empty($val) && (int)$val === 1) ? 'checked' : ''; }
}
if (!function_exists('fixPath')) {
    function fixPath($path) {
        if (empty($path)) return '';
        if (strpos($path, 'http') === 0) return $path;
        $path = ltrim($path, '/');
        if (strpos($path, 'uploads/') === 0) return '/admin/' . $path;
        if (strpos($path, 'admin/') === 0) return '/' . $path;
        return '/' . $path;
    }
}
if (!function_exists('isImage')) {
    function isImage($path) { 
        if (empty($path)) return false; 
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)); 
        return in_array($ext, ['jpg','jpeg','png','gif','webp','jfif','bmp']); 
    }
}
if (!function_exists('isPDF')) {
    function isPDF($path) { 
        if (empty($path)) return false; 
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf'; 
    }
}
if (!function_exists('memberFileUrl')) {
    function memberFileUrl($memberId, $field) {
        return '/admin/member_file.php?member_id=' . (int)$memberId . '&field=' . rawurlencode($field);
    }
}
if (!function_exists('field')) { 
    function field($n, $d = '') { return isset($_POST[$n]) ? trim($_POST[$n]) : $d; } 
}


// ============================================================
// HANDLE POST REQUEST (Update Member)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    // Global error handler for this request
    set_error_handler(function($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) return false;
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    
    $newUploadedPaths = [];
    $uploadsCommitted = false;
    try {
        // Validate CSRF token
        if (!validateCsrf()) {
            echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh and try again.']);
            exit;
        }
        if (function_exists('isRateLimited') && isRateLimited('info_manage', 60, 300)) {
            echo json_encode(['status' => 'error', 'message' => 'Too many requests. Please try again later.']);
            exit;
        }
        if (function_exists('recordAttempt')) recordAttempt('info_manage');
        
        if (!isset($conn) || $conn->connect_error) { 
            echo json_encode(['status' => 'error', 'message' => 'Database connection not available']); 
            exit; 
        }

        // Role gating: only roles permitted by access_control may edit members.
        // Info dept is a data-updater (viewer+edit); registration/promotion stay HR-only.
        $allowedEditRoles = ['super_admin', 'school_admin', 'hr_dept', 'info_dept'];
        if (!hasRole($allowedEditRoles)) {
            echo json_encode(['status' => 'error', 'message' => 'You do not have permission to edit members.']);
            http_response_code(403);
            exit;
        }
        
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0; 
        if ($id <= 0) { 
            echo json_encode(['status' => 'error', 'message' => 'Invalid member ID']); 
            exit; 
        }
        
        $stmt = $conn->prepare('SELECT * FROM members WHERE id = ?'); 
        $stmt->bind_param('i', $id); 
        $stmt->execute(); 
        $cur = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 
        
        if (!$cur) { 
            echo json_encode(['status' => 'error', 'message' => 'Member not found']); 
            exit; 
        }
        
        // Migration 012 guarantees the education compatibility columns.

    // Get form values
    $registration_type = field('registration_type', $cur['registration_type'] ?? 'waiting');
    $member_type       = field('member_type', $cur['member_type'] ?? 'regular');
    // Member status (members.status) is a manual HR / Information decision
    // (Attendance alerting requirements §4): only those roles may change it,
    // only between active and inactive, and archived members are left alone.
    // Every other role keeps the stored value regardless of what was posted.
    $status            = (string)($cur['status'] ?? 'active');
    $statusChanged     = false;
    if (hasRole(['hr_dept', 'info_dept']) && $status !== 'archived') {
        $postedStatus = field('status', $status);
        if (in_array($postedStatus, ['active', 'inactive'], true) && $postedStatus !== $status) {
            $statusChanged = true;
            $status = $postedStatus;
        }
    }
    $student_name      = field('student_name', $cur['student_name'] ?? '');
    $baptismal_name    = field('baptismal_name', $cur['baptismal_name'] ?? '');
    $father_name       = field('father_name', $cur['father_name'] ?? '');
    $grandfather_name  = field('grandfather_name', $cur['grandfather_name'] ?? '');
    $gender            = field('gender', $cur['gender'] ?? 'male');
    $dob_day           = (int) field('dob_day', $cur['dob_ec_day'] ?? 0);
    $dob_month         = (int) field('dob_month', $cur['dob_ec_month'] ?? 0);
    $dob_year          = (int) field('dob_year', $cur['dob_ec_year'] ?? 0);
    
    // Age (optional) — Auto-age sectioning DISABLED by design
    $age = null;
    if ($dob_year > 0) { 
        $now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')); 
        $y = (int)ethio_date_format($now, 'Y'); 
        $age = max(0, $y - $dob_year);
    }
    // Keep the Gregorian date_of_birth (used for exact age everywhere) in
    // step with the Ethiopian fields. Only a complete, valid EC date updates
    // it; otherwise the stored value is left unchanged (COALESCE below).
    $dob_gregorian = null;
    if ($dob_day > 0 && $dob_month > 0 && $dob_year > 0) {
        $dobParsed = \App\Services\MemberImportService::parseEcDate(sprintf('%04d-%02d-%02d', $dob_year, $dob_month, $dob_day));
        if (!empty($dobParsed['ok'])) {
            $dob_gregorian = (string)$dobParsed['gregorian'];
            $exactAge = \App\Services\MemberImportService::ageFromGregorian($dob_gregorian);
            if ($exactAge !== null) {
                $age = $exactAge;
            }
        }
    }
    // Respect explicitly provided values; never auto-override
    $age_group       = field('age_group', $cur['age_group'] ?? null) ?: null;
    $current_section = field('current_section', $cur['current_section'] ?? null) ?: null;
    
    $education_level  = field('education_level', $cur['education_level'] ?? '');
    $spiritual_education = field('spiritual_education', $cur['spiritual_education'] ?? '');
    $work_profession  = field('work_profession', $cur['work_profession'] ?? '');
    $city             = field('city', $cur['city'] ?? '');
    $sub_city         = field('sub_city', $cur['sub_city'] ?? '');
    $woreda           = field('woreda', $cur['woreda'] ?? '');
    $mender           = field('mender', $cur['mender'] ?? '');
    $block_number     = field('block_number', $cur['block_number'] ?? '');
    $house_number     = field('house_number', $cur['house_number'] ?? '');
    $phone_number     = field('phone_number', $cur['phone_number'] ?? '');
    $alt_phone_number = field('alt_phone_number', $cur['alt_phone_number'] ?? '');
    $guardian_name    = field('guardian_name', $cur['guardian_name'] ?? '');
    $guardian_phone1  = field('guardian_phone1', $cur['guardian_phone1'] ?? '');
    $guardian_phone2  = field('guardian_phone2', $cur['guardian_phone2'] ?? '');

    // Input length caps (consistent with MemberRegistrationPolicy limits).
    $caps = [
        'student_name' => 100, 'father_name' => 100, 'grandfather_name' => 100,
        'baptismal_name' => 100, 'work_profession' => 100, 'city' => 100,
        'sub_city' => 100, 'woreda' => 100, 'mender' => 100, 'current_section' => 100,
        'guardian_name' => 100, 'education_level' => 50, 'spiritual_education' => 50,
        'phone_number' => 30, 'alt_phone_number' => 30, 'guardian_phone1' => 30,
        'guardian_phone2' => 30, 'block_number' => 20, 'house_number' => 20,
    ];
    foreach ($caps as $fname => $max) {
        $val = ${$fname} ?? '';
        if (is_string($val) && function_exists('mb_strlen') && mb_strlen($val, 'UTF-8') > $max) {
            echo json_encode(['status' => 'error', 'message' => ucfirst(str_replace('_', ' ', $fname)) . ' is too long (max ' . $max . ').']);
            exit;
        }
    }
    

    // File validation/storage is isolated from the UI and database update.
    $storeUpload = static function (string $field) use (&$newUploadedPaths): ?string {
        $result = \App\Services\MemberFileService::storeRequestUpload($field);
        if ($result['error'] !== null) {
            throw new InvalidArgumentException($result['error']);
        }
        if ($result['path'] !== null) {
            $newUploadedPaths[] = $result['path'];
        }
        return $result['path'];
    };
    $student_photo_path      = $storeUpload('student_photo') ?? $cur['student_photo_path'];
    $guardian_photo_path     = $storeUpload('guardian_photo') ?? $cur['guardian_photo_path'];
    $doc_school_records_path = $storeUpload('doc_school_records') ?? $cur['doc_school_records_path'];
    $doc_spiritual_path      = $storeUpload('doc_spiritual') ?? $cur['doc_spiritual_path'];
    $doc_signed_form_path    = $storeUpload('doc_signed_form') ?? $cur['doc_signed_form_path'];

    $full_name_am = trim($student_name . ' ' . $father_name . ' ' . $grandfather_name);

    // Update the migration-backed member schema.
        // Legacy role flags (is_teacher, …) are no longer written here:
        // they are DERIVED from the member's positions right after the
        // update (PositionSyncService), keeping every old consumer in sync.
        $sql = "UPDATE members SET 
            registration_type=?, member_type=?, status=?,
            student_name=?, baptismal_name=?, father_name=?, grandfather_name=?,
            full_name_am=?, gender=?, 
            dob_ec_day=?, dob_ec_month=?, dob_ec_year=?, date_of_birth=COALESCE(?, date_of_birth), age=?, age_group=?, current_section=?,
            education_level=?, spiritual_education=?, work_profession=?,
            city=?, sub_city=?, woreda=?, mender=?, block_number=?, house_number=?,
            phone_number=?, phone_primary=?, alt_phone_number=?,
            guardian_name=?, guardian_phone1=?, guardian_phone2=?, phone_guardian=?,
            student_photo_path=?, guardian_photo_path=?,
            doc_school_records_path=?, doc_spiritual_path=?, doc_signed_form_path=?
            WHERE id=?";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Member update prepare failed: ' . $conn->error);
        }
        $ageVal = $age !== null ? $age : 0;
        $bindValues = [
            $registration_type, $member_type, $status,
            $student_name, $baptismal_name, $father_name, $grandfather_name,
            $full_name_am, $gender,
            $dob_day, $dob_month, $dob_year, $dob_gregorian, $ageVal, $age_group, $current_section,
            $education_level, $spiritual_education, $work_profession,
            $city, $sub_city, $woreda, $mender, $block_number, $house_number,
            $phone_number, $phone_number, $alt_phone_number,
            $guardian_name, $guardian_phone1, $guardian_phone2, $guardian_phone1,
            $student_photo_path, $guardian_photo_path,
            $doc_school_records_path, $doc_spiritual_path, $doc_signed_form_path,
            $id
        ];
        $bindTypes = '';
        foreach ($bindValues as $bv) {
            $bindTypes .= is_int($bv) ? 'i' : 's';
        }
        $stmt->bind_param($bindTypes, ...$bindValues);
    
    $conn->begin_transaction();
    if ($stmt->execute()) { 
        $uploadsCommitted = true;
        // Family Link reverse-sync: if this member has a primary linked
        // guardian and the contact fields were edited here, push the new
        // values into the guardian record so every sibling follows
        // (the guardian row is the single source of truth).
        try {
            $glChanged = trim((string)$guardian_name) !== trim((string)($cur['guardian_name'] ?? ''))
                || trim((string)$guardian_phone1) !== trim((string)($cur['guardian_phone1'] ?? ''))
                || trim((string)$guardian_phone2) !== trim((string)($cur['guardian_phone2'] ?? ''));
            if ($glChanged && trim((string)$guardian_name) !== '') {
                require_once __DIR__ . '/backend/services/GuardianService.php';
                $glStmt = $conn->prepare('SELECT guardian_id FROM member_guardians WHERE member_id = ? AND is_primary = 1 LIMIT 1');
                $glGid = 0;
                if ($glStmt) {
                    $glStmt->bind_param('i', $id);
                    $glStmt->execute();
                    $glRow = $glStmt->get_result()->fetch_assoc();
                    $glStmt->close();
                    $glGid = $glRow ? (int)$glRow['guardian_id'] : 0;
                }
                if ($glGid > 0) {
                    \App\Services\GuardianService::update($conn, $glGid, [
                        'display_name' => $guardian_name,
                        'phone_primary' => $guardian_phone1,
                        'phone_alt' => $guardian_phone2,
                    ]);
                } else {
                    // Guardian details entered for the first time (or member
                    // never linked): match-or-create exactly like registration.
                    \App\Services\GuardianService::attachFromLegacy($conn, (int)$id, 0, 'hr');
                }
            }
        } catch (\Throwable $glError) {
            error_log('Family Link reverse-sync failed for member ' . $id . ': ' . $glError->getMessage());
        }
        foreach ([
            'student_photo_path' => $student_photo_path,
            'guardian_photo_path' => $guardian_photo_path,
            'doc_school_records_path' => $doc_school_records_path,
            'doc_spiritual_path' => $doc_spiritual_path,
            'doc_signed_form_path' => $doc_signed_form_path,
        ] as $column => $newPath) {
            $oldPath = $cur[$column] ?? null;
            if ($newPath && $oldPath && $newPath !== $oldPath) {
                \App\Services\MemberFileService::discard($oldPath);
            }
        }
        // Audit the member update (enterprise accountability).
        if (file_exists(__DIR__ . '/backend/services/SecurityAuditService.php')) {
            require_once __DIR__ . '/backend/services/SecurityAuditService.php';
            \App\Services\SecurityAuditService::record(
                $conn,
                'Member Updated',
                ['profile' => 'full'],
                'member',
                $id
            );
            if ($statusChanged) {
                \App\Services\SecurityAuditService::record(
                    $conn,
                    'Member Status Changed',
                    ['member_id' => (int)$id, 'from' => (string)($cur['status'] ?? ''), 'to' => $status, 'via' => 'edit_form'],
                    'member',
                    $id
                );
            }
        }
        $conn->commit();
        // Identity v2: apply submitted positions, re-code if needed and
        // derive legacy flags without changing the manual member type.
        require_once __DIR__ . '/backend/services/PositionSyncService.php';
        $editPositionIds = [];
        if (isset($_POST['position_ids']) && is_array($_POST['position_ids'])) {
            foreach ($_POST['position_ids'] as $pid) {
                $pid = filter_var($pid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($pid) { $editPositionIds[] = (int)$pid; }
            }
            $editPositionIds = array_values(array_unique($editPositionIds));
            $posRes = \App\Services\PositionSyncService::applyPositions(
                $conn, $id, $editPositionIds, (int)($_SESSION['admin_id'] ?? 0)
            );
            if (($posRes['status'] ?? '') !== 'success') {
                error_log('Member edit position sync failed for #' . $id);
            }
        }
        echo json_encode(['status' => 'success', 'message' => 'Member updated successfully']); 
    } else { 
        $conn->rollback();
        foreach ($newUploadedPaths as $uploadedPath) \App\Services\MemberFileService::discard($uploadedPath);
        reportInternalError('Member update execution failed', $stmt->error);
        echo json_encode(['status' => 'error', 'message' => 'Unable to update the member.']);
    }
    $stmt->close();
    
    } catch (InvalidArgumentException $e) {
        try { $conn->rollback(); } catch (Throwable $r) {}
        foreach ($newUploadedPaths as $uploadedPath) \App\Services\MemberFileService::discard($uploadedPath);
        $uploadMessage = $e->getMessage();
        echo json_encode(['status' => 'error', 'message' => 'Upload error: ' . $uploadMessage]);
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $r) {}
        if (!$uploadsCommitted) {
            foreach ($newUploadedPaths as $uploadedPath) \App\Services\MemberFileService::discard($uploadedPath);
        }
        reportInternalError('Member update failed', $e);
        echo json_encode(['status' => 'error', 'message' => 'Unable to update the member.']);
    }
    exit;
}

// ============================================================
// GET REQUEST - Display Member Details
// ============================================================
$memberId = isset($_GET['id']) ? (int)$_GET['id'] : 0; 
if ($memberId <= 0) die('<div style="padding:20px;color:#dc2626;">Invalid member ID</div>');

$stmt = $conn->prepare('SELECT * FROM members WHERE id = ?'); 
$stmt->bind_param('i', $memberId); 
$stmt->execute(); 
$m = $stmt->get_result()->fetch_assoc(); 
$stmt->close(); 

if (!$m) die('<div style="padding:20px;color:#dc2626;">Member not found</div>');

// Ensure optional columns have safe defaults (in case column doesn't exist in DB yet)
$m['spiritual_education'] = $m['spiritual_education'] ?? '';
$m['age_group'] = $m['age_group'] ?? '';

// JSON mode for AJAX consumers (e.g. upgrade modal prefill)
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'member' => $m], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ethiopian month names
$ethMonths = [1=>'መስከረም',2=>'ጥቅምት',3=>'ኅዳር',4=>'ታኅሣሥ',5=>'ጥር',6=>'የካቲት',7=>'መጋቢት',8=>'ሚያዝያ',9=>'ግንቦት',10=>'ሰኔ',11=>'ሐምሌ',12=>'ነሐሴ',13=>'ጳጉሜ'];
$dobDisplay = '';
if ($m['dob_ec_day'] && $m['dob_ec_month'] && $m['dob_ec_year']) {
    $dobDisplay = $m['dob_ec_day'] . ' ' . ($ethMonths[(int)$m['dob_ec_month']] ?? '') . ' ' . $m['dob_ec_year'] . ' ዓ.ም';
}

$now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$printDate = ethio_date_format($now, 'F j, Y');
$fullName = trim($m['student_name'] . ' ' . $m['father_name'] . ' ' . $m['grandfather_name']);
?>
<style>
/* ========== MANAGE MEMBER STYLES ========== */
.mm-wrap { font-family: 'Segoe UI', system-ui, sans-serif; background: #f1f5f9; }

/* Header */
.mm-header { 
    background: linear-gradient(135deg, #047857 0%, #059669 50%, #10b981 100%); 
    padding: 20px; color: #fff; position: relative; 
}
.mm-header::after { 
    content: ''; position: absolute; bottom: 0; left: 0; right: 0; 
    height: 4px; background: linear-gradient(90deg, #34d399, #6ee7b7, #34d399); 
}
.mm-hdr-content { display: flex; align-items: center; gap: 16px; }
.mm-avatar { 
    width: 80px; height: 100px; /* Passport ratio 4:5 */
    border-radius: 8px; background: rgba(255,255,255,.15); 
    border: 3px solid rgba(255,255,255,.4); overflow: hidden; 
    display: flex; align-items: center; justify-content: center; cursor: pointer; 
    flex-shrink: 0; transition: all .2s;
}
.mm-avatar:hover { border-color: #fff; box-shadow: 0 0 20px rgba(255,255,255,.3); transform: scale(1.02); }
.mm-avatar img { width: 100%; height: 100%; object-fit: cover; }
.mm-avatar i { font-size: 32px; color: rgba(255,255,255,.5); }
.mm-hdr-info { flex: 1; }
.mm-hdr-name { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
.mm-hdr-id { font-size: 13px; opacity: .9; display: flex; align-items: center; gap: 6px; }
.mm-status { 
    padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; 
    text-transform: uppercase; letter-spacing: .5px; 
}
.mm-status-active { background: #fff; color: #059669; }
.mm-status-warning { background: #fef3c7; color: #b45309; }
.mm-status-inactive { background: #fee2e2; color: #b91c1c; }
.mm-status-archived { background: #e5e7eb; color: #4b5563; }

/* Tabs - MUST use correct IDs: tab-preview, tab-edit */
.mm-tabs { 
    display: flex; background: #fff; position: sticky; top: 0; z-index: 20; 
    box-shadow: 0 2px 8px rgba(0,0,0,.06); border-bottom: 1px solid #e2e8f0;
}
.mm-tab { 
    flex: 1; padding: 14px 12px; display: flex; align-items: center; justify-content: center; gap: 8px;
    font-size: 14px; font-weight: 600; background: transparent; border: none; cursor: pointer;
    border-bottom: 3px solid transparent; color: #64748b; transition: all .2s;
}
.mm-tab:hover { background: #f8fafc; color: #334155; }
.mm-tab.border-emerald-600 { border-bottom-color: #059669 !important; }
.mm-tab.text-emerald-700 { color: #047857 !important; background: linear-gradient(to bottom, #ecfdf5, #fff); }
.mm-tab i { font-size: 16px; }

/* Action Buttons */
.mm-actions { display: flex; gap: 8px; padding: 12px 16px; background: #fff; border-bottom: 1px solid #e2e8f0; }
.mm-btn { 
    display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; 
    font-size: 13px; font-weight: 600; border-radius: 10px; border: none; cursor: pointer; transition: all .2s; 
}
.mm-btn-green { background: linear-gradient(135deg, #059669, #10b981); color: #fff; box-shadow: 0 2px 8px rgba(16,185,129,.25); }
.mm-btn-green:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,.35); }
.mm-btn-gray { background: #f1f5f9; color: #475569; }
.mm-btn-gray:hover { background: #e2e8f0; }

/* Content - MUST use correct IDs: view-preview, view-edit */
.mm-content { padding: 16px; padding-bottom: 100px; }

/* Cards */
.mm-card { 
    background: #fff; border-radius: 16px; margin-bottom: 16px; overflow: hidden; 
    box-shadow: 0 1px 3px rgba(0,0,0,.06); border: 1px solid #e5e7eb;
}
.mm-card-hdr { 
    display: flex; align-items: center; gap: 12px; padding: 14px 16px; 
    background: #fafbfc; border-bottom: 1px solid #f1f5f9; 
}
.mm-card-icon { 
    width: 40px; height: 40px; border-radius: 10px; 
    display: flex; align-items: center; justify-content: center; font-size: 16px; 
}
.mm-icon-green { background: #d1fae5; color: #059669; }
.mm-icon-blue { background: #dbeafe; color: #2563eb; }
.mm-icon-purple { background: #ede9fe; color: #7c3aed; }
.mm-icon-red { background: #fee2e2; color: #dc2626; }
.mm-icon-amber { background: #fef3c7; color: #d97706; }
.mm-card-title { font-size: 15px; font-weight: 700; color: #1e293b; }
.mm-card-body { padding: 16px; }

/* Photo Grid - Passport Size */
.mm-photos { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.mm-photo-wrap { text-align: center; }
.mm-photo-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 10px; }
.mm-photo-box { 
    width: 100%; max-width: 120px; height: 150px; /* Passport 4:5 ratio */
    margin: 0 auto; border-radius: 10px; background: linear-gradient(135deg, #f8fafc, #f1f5f9); 
    border: 2px solid #e2e8f0; overflow: hidden; cursor: pointer; 
    display: flex; align-items: center; justify-content: center; position: relative; transition: all .2s;
}
.mm-photo-box:hover { border-color: #10b981; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16,185,129,.15); }
.mm-photo-box img { width: 100%; height: 100%; object-fit: cover; }
.mm-photo-box .mm-photo-overlay { 
    position: absolute; inset: 0; background: rgba(0,0,0,.5); 
    display: flex; align-items: center; justify-content: center; 
    opacity: 0; transition: opacity .2s; 
}
.mm-photo-box:hover .mm-photo-overlay { opacity: 1; }
.mm-photo-overlay i { color: #fff; font-size: 24px; }
.mm-photo-empty { display: flex; flex-direction: column; align-items: center; color: #94a3b8; }
.mm-photo-empty i { font-size: 36px; margin-bottom: 6px; }
.mm-photo-empty span { font-size: 11px; }

/* Documents */
.mm-docs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.mm-doc { 
    padding: 16px 8px; background: #f8fafc; border-radius: 12px; text-align: center; 
    cursor: pointer; border: 2px solid transparent; transition: all .2s; 
}
.mm-doc:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.08); }
.mm-doc.has-file { background: linear-gradient(135deg, #ecfdf5, #d1fae5); border-color: #a7f3d0; }
.mm-doc-icon { 
    width: 48px; height: 48px; border-radius: 12px; background: #fff; 
    display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-size: 20px; 
}
.mm-doc.has-file .mm-doc-icon { background: #059669; color: #fff; }
.mm-doc-name { font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px; }
.mm-doc-status { font-size: 10px; color: #64748b; }
.mm-doc.has-file .mm-doc-status { color: #059669; font-weight: 600; }

/* Info Rows */
.mm-info-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
.mm-info-row:last-child { border-bottom: none; }
.mm-info-label { font-size: 13px; color: #64748b; font-weight: 500; }
.mm-info-value { font-size: 14px; color: #1e293b; font-weight: 600; text-align: right; max-width: 55%; }

/* Badges */
.mm-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.mm-badge { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.mm-badge-slate { background: #f1f5f9; color: #475569; }
.mm-badge-green { background: #d1fae5; color: #047857; }
.mm-badge-blue { background: #dbeafe; color: #1d4ed8; }
.mm-badge-amber { background: #fef3c7; color: #b45309; }
.mm-status { display: inline-flex; align-items: center; gap: 6px; }
.mm-status-dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
.mm-tier-badge { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; background: #e0e7ff; color: #3730a3; }
.mm-tier-badge.mm-tier-permanent { background: #d1fae5; color: #047857; }
.mm-tier-badge.mm-tier-temporary { background: #fef3c7; color: #b45309; }

/* Role Grid */
.mm-roles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }

/* Responsive 2-column flow for preview cards on wide screens */
@media (min-width: 900px) {
    #view-preview { column-count: 2; column-gap: 16px; }
    #view-preview .mm-card { break-inside: avoid; -webkit-column-break-inside: avoid; margin-bottom: 16px; display: inline-block; width: 100%; vertical-align: top; }
    #view-preview .mm-card.mm-card-full { column-span: all; -webkit-column-span: all; }
}
.mm-role { text-align: center; padding: 14px 6px; border-radius: 12px; background: #f8fafc; }
.mm-role.active { background: linear-gradient(135deg, #d1fae5, #a7f3d0); }
.mm-role i { font-size: 20px; color: #94a3b8; margin-bottom: 6px; display: block; }
.mm-role.active i { color: #059669; }
.mm-role span { font-size: 10px; font-weight: 600; color: #64748b; }
.mm-role.active span { color: #047857; }

/* Form Styles */
.mm-form-group { margin-bottom: 16px; }
.mm-label { display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .5px; }
.mm-input { 
    width: 100%; padding: 12px 14px; border: 2px solid #e2e8f0; border-radius: 10px; 
    font-size: 14px; background: #f8fafc; transition: all .2s; 
}
.mm-input:focus { outline: none; border-color: #10b981; background: #fff; box-shadow: 0 0 0 3px rgba(16,185,129,.1); }
.mm-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.mm-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }

/* Checkboxes */
.mm-checks { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.mm-check { 
    display: flex; align-items: center; gap: 12px; padding: 14px; 
    background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent; transition: all .2s; 
}
.mm-check:hover { background: #f1f5f9; }
.mm-check:has(input:checked) { background: #ecfdf5; border-color: #a7f3d0; }
.mm-check input { width: 20px; height: 20px; accent-color: #10b981; }
.mm-check span { font-size: 14px; font-weight: 500; color: #334155; }

/* File Status */
.mm-file-ok { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #059669; margin-bottom: 8px; font-weight: 600; }

/* Save Footer */
.mm-footer { 
    position: fixed; bottom: 0; left: 0; right: 0; padding: 16px; 
/* Cache-hardening fallback (values = themes/design-system.css). The canonical
   scale lives there via mobile.css @import; this inline copy guarantees modal
   stacking even when a browser serves a stale cached mobile.css (no ?v= before
   2026-10-08) or the import fails. Keep in sync with themes/design-system.css. */
:root{--z-content:1;--z-sticky:100;--z-header:200;--z-nav:900;--z-dock:950;--z-fab:1000;--z-toast:1100;--z-overlay:1200;--z-impersonate:1300;--z-tooltip:1400}
    background: #fff; border-top: 1px solid #e2e8f0; box-shadow: 0 -4px 12px rgba(0,0,0,.05); z-index: var(--z-dock); 
}
.mm-save { 
    width: 100%; padding: 16px; background: linear-gradient(135deg, #059669, #10b981); 
    color: #fff; font-size: 15px; font-weight: 700; border: none; border-radius: 12px; 
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all .2s; 
}
.mm-save:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(5,150,105,.3); }
.mm-save:disabled { opacity: .7; cursor: not-allowed; transform: none; }
.mm-back-btn { display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,.18); color:#fff; border:1px solid rgba(255,255,255,.35); border-radius:999px; padding:3px 10px; font-size:11px; font-weight:600; cursor:pointer; margin-bottom:6px; }
.mm-back-btn:hover { background:rgba(255,255,255,.3); }
.mm-standalone-bar { display:flex; gap:14px; align-items:center; background:#fffbeb; border:1px solid #fde68a; color:#92400e; font-size:12px; padding:8px 14px; }
.mm-standalone-bar a { color:#92400e; font-weight:700; text-decoration:underline; }
.mm-fam-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 0; border-bottom:1px solid #f1f5f9; }
.mm-fam-row:last-child { border-bottom:none; }
.mm-fam-who { min-width:0; }
.mm-fam-name { font-weight:700; color:#0f172a; font-size:13px; }
.mm-fam-meta { font-size:11px; color:#64748b; }
.mm-fam-tag { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#047857; background:#d1fae5; border-radius:999px; padding:1px 7px; margin-right:4px; }
.mm-fam-tag-soft { color:#475569; background:#f1f5f9; }
.mm-fam-btn { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:600; color:#047857; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; padding:4px 9px; cursor:pointer; white-space:nowrap; }
.mm-fam-btn:hover { background:#d1fae5; }
.mm-fam-sib { display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid #f1f5f9; text-decoration:none; color:inherit; }
.mm-fam-sib:last-child { border-bottom:none; }
.mm-fam-sib:hover .mm-fam-name { color:#047857; }
.mm-fam-avatar { width:30px; height:30px; border-radius:10px; background:linear-gradient(135deg,#059669,#0ea5e9); color:#fff; font-weight:800; font-size:13px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.mm-fam-empty { font-size:12px; color:#64748b; background:#f8fafc; border:1px dashed #e2e8f0; border-radius:10px; padding:10px 12px; }
/* ── MOBILE RESPONSIVE ── */
@media (max-width: 768px) {
    .mm-wrap { font-size: 14px; }
    .mm-header { padding: 12px; }
    .mm-hdr-content { flex-direction: column; text-align: center; gap: 8px; }
    .mm-photo-area { width: 70px !important; height: 70px !important; }
    .mm-tabs { overflow-x: auto; -webkit-overflow-scrolling: touch; gap: 2px; flex-wrap: nowrap; }
    .mm-tab { white-space: nowrap; font-size: 0.7rem; padding: 6px 10px; flex-shrink: 0; }
    .mm-row, .mm-row-3, .mm-photos, .mm-docs, .mm-roles, .mm-checks {
        grid-template-columns: 1fr !important;
    }
    .mm-field label { font-size: 0.7rem; }
    .mm-field input, .mm-field select, .mm-field textarea {
        font-size: 16px !important; /* prevent iOS zoom */
        padding: 8px !important;
    }
    .mm-actions { flex-wrap: wrap; gap: 6px; }
    .mm-actions button { font-size: 0.8rem; flex: 1; min-width: 120px; }
}
</style>

<div class="mm-wrap">
    <!-- Header -->
    <div class="mm-header">
        <div class="mm-hdr-content">
            <div class="mm-avatar" onclick="openDocFullscreen('<?= fixPath($m['student_photo_path']) ?>')">
                <?php if (!empty($m['student_photo_path'])): ?>
                    <img src="<?= fixPath($m['student_photo_path']) ?>" alt="Photo">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            <div class="mm-hdr-info">
                <button type="button" id="mmBackBtn" class="mm-back-btn" style="display:none" onclick="return window.MemberNav ? MemberNav.back() : false;"><i class="fas fa-arrow-left"></i> <span data-label>Back to previous member</span></button>
                <div class="mm-hdr-name"><?= e($m['student_name'] . ' ' . $m['father_name']) ?></div>
                <div class="mm-hdr-id">
                    <?php if ($m['member_code']): ?>
                        <i class="fas fa-id-card"></i> <?= e($m['member_code']) ?>
                    <?php else: ?>
                        <i class="fas fa-hourglass-half"></i> Waiting for ID
                    <?php endif; ?>
                </div>
            </div>
            <span class="mm-status mm-status-<?= e($m['status']) ?>"><span class="mm-status-dot"></span><?= e(ucfirst($m['status'])) ?></span>
            <span class="mm-tier-badge mm-tier-<?= e($m['membership_tier'] ?? 'temporary') ?>"><?= ucfirst($m['membership_tier'] ?? 'temporary') ?></span>
        </div>
    </div>

    <?php if (empty($_GET['embed'])): ?>
    <!-- Opened outside the dashboard (direct link / old bookmark): hand over to the dashboard, which loads this profile in its panel -->
    <div class="mm-standalone-bar">
        <span><i class="fas fa-circle-info"></i> Opening this member inside the dashboard…</span>
        <a href="/admin/dashboard.php?member=<?= (int)$m['id'] ?>"><i class="fas fa-arrow-right"></i> Continue</a>
        <a href="javascript:history.back()"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
    <script>try { window.location.replace('/admin/dashboard.php?member=<?= (int)$m['id'] ?>'); } catch (e) {}</script>
    <?php endif; ?>

    <!-- Tabs - IDs MUST be: tab-preview, tab-edit -->
    <div class="mm-tabs">
        <button id="tab-preview" class="mm-tab border-emerald-600 text-emerald-700" onclick="switchManageTab('preview')">
            <i class="fas fa-eye"></i> Preview
        </button>
        <button id="tab-edit" class="mm-tab border-transparent text-slate-500" onclick="switchManageTab('edit')">
            <i class="fas fa-edit"></i> Edit
        </button>
    </div>

    <!-- Action Buttons -->
    <div class="mm-actions">
        <button class="mm-btn mm-btn-green" onclick="window.open('/admin/print_member.php?id=<?= $m['id'] ?>', '_blank', 'width=900,height=700')">
            <i class="fas fa-file-pdf"></i> Export PDF
        </button>
        <button class="mm-btn mm-btn-gray" onclick="window.print()">
            <i class="fas fa-print"></i> Print
        </button>
    </div>

    <!-- Content -->
    <div class="mm-content">
        <!-- PREVIEW PANEL - ID MUST be: view-preview -->
        <div id="view-preview" style="display: block;">
            <!-- Department status (Education / Mezmur) — Attendance alerting Phase 1.
                 Rendered by /admin/js/department_status.js (DeptStatus.mount) because this
                 page is injected into the dashboard panel via innerHTML. -->
            <div class="mm-card mm-card-full">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-amber"><i class="fas fa-user-clock"></i></div>
                    <div class="mm-card-title">Department Status</div>
                </div>
                <div class="mm-card-body">
                    <div data-dept-status="<?= (int)$m['id'] ?>"><span style="font-size:12px;color:#64748b">Department status loads inside the dashboard.</span></div>
                </div>
            </div>
            <!-- Photos -->
            <div class="mm-card mm-card-full">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-green"><i class="fas fa-camera"></i></div>
                    <div class="mm-card-title">Photos</div>
                </div>
                <div class="mm-card-body">
                    <div class="mm-photos">
                        <div class="mm-photo-wrap">
                            <div class="mm-photo-label">Student Photo</div>
                            <div class="mm-photo-box" onclick="openDocFullscreen('<?= fixPath($m['student_photo_path']) ?>')">
                                <?php if (!empty($m['student_photo_path'])): ?>
                                    <img src="<?= fixPath($m['student_photo_path']) ?>" alt="Student">
                                    <div class="mm-photo-overlay"><i class="fas fa-search-plus"></i></div>
                                <?php else: ?>
                                    <div class="mm-photo-empty"><i class="fas fa-user"></i><span>No Photo</span></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mm-photo-wrap">
                            <div class="mm-photo-label">Guardian Photo</div>
                            <div class="mm-photo-box" onclick="openDocFullscreen('<?= memberFileUrl($m['id'], 'guardian_photo_path') ?>')">
                                <?php if (!empty($m['guardian_photo_path'])): ?>
                                    <img src="<?= memberFileUrl($m['id'], 'guardian_photo_path') ?>" alt="Guardian">
                                    <div class="mm-photo-overlay"><i class="fas fa-search-plus"></i></div>
                                <?php else: ?>
                                    <div class="mm-photo-empty"><i class="fas fa-user"></i><span>No Photo</span></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Documents -->
            <div class="mm-card mm-card-full">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-blue"><i class="fas fa-folder-open"></i></div>
                    <div class="mm-card-title">Documents</div>
                </div>
                <div class="mm-card-body">
                    <div class="mm-docs">
                        <?php 
                        $docs = [
                            'doc_school_records_path' => ['School Records', 'fa-graduation-cap', '#2563eb'],
                            'doc_spiritual_path' => ['Spiritual', 'fa-church', '#7c3aed'],
                            'doc_signed_form_path' => ['Signed Form', 'fa-file-signature', '#059669']
                        ];
                        foreach ($docs as $key => $info): 
                            $path = $m[$key] ?? '';
                            $has = !empty($path);
                            $fileUrl = memberFileUrl($m['id'], $key);
                            $viewAction = $has ? (isPDF($path) ? "window.open('".$fileUrl."','_blank')" : "openDocFullscreen('".$fileUrl."')") : '';
                        ?>
                        <div class="mm-doc <?= $has ? 'has-file' : '' ?>" onclick="<?= $viewAction ?>">
                            <div class="mm-doc-icon" style="<?= !$has ? 'color:'.$info[2] : '' ?>">
                                <i class="fas <?= $info[1] ?>"></i>
                            </div>
                            <div class="mm-doc-name"><?= $info[0] ?></div>
                            <div class="mm-doc-status">
                                <?= $has ? '<i class="fas fa-check-circle"></i> View' : 'Not uploaded' ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Personal Info -->
            <div class="mm-card">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-green"><i class="fas fa-user"></i></div>
                    <div class="mm-card-title">Personal Information</div>
                </div>
                <div class="mm-card-body">
                    <div class="mm-info-row"><span class="mm-info-label">Full Name</span><span class="mm-info-value"><?= e($m['student_name']) ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Father Name</span><span class="mm-info-value"><?= e($m['father_name']) ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Grandfather</span><span class="mm-info-value"><?= esc($m['grandfather_name'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Christian Name (የክርስትና ስም)</span><span class="mm-info-value"><?= esc($m['baptismal_name'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Gender</span><span class="mm-info-value"><?= $m['gender'] === 'male' ? 'ወንድ (Male)' : 'ሴት (Female)' ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Date of Birth</span><span class="mm-info-value"><?= $dobDisplay ?: '—' ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Age</span><span class="mm-info-value"><?= $m['age'] ? $m['age'] . ' years' : '—' ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Section</span><span class="mm-info-value"><?= esc($m['current_section'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Education</span><span class="mm-info-value"><?= ucfirst(str_replace('_', ' ', $m['education_level'] ?: '—')) ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Spiritual Education</span><span class="mm-info-value" style="color: #059669; font-weight: 700;">
                        <?php 
                        $spEduLabels = [
                            'grade_1' => '1ኛ ክፍል', 'grade_2' => '2ኛ ክፍል', 'grade_3' => '3ኛ ክፍል',
                            'grade_4' => '4ኛ ክፍል', 'grade_5' => '5ኛ ክፍል', 'grade_6' => '6ኛ ክፍል',
                            'grade_7' => '7ኛ ክፍል', 'grade_8' => '8ኛ ክፍል', 'grade_9' => '9ኛ ክፍል',
                            'grade_10' => '10ኛ ክፍል', 'grade_11' => '11ኛ ክፍል', 'grade_12' => '12ኛ ክፍል',
                            'diploma' => 'ዲፕሎማ', 'degree' => 'ዲግሪ'
                        ];
                        echo $spEduLabels[$m['spiritual_education'] ?? ''] ?? ($m['spiritual_education'] ?: '—');
                        ?>
                    </span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Profession</span><span class="mm-info-value"><?= esc($m['work_profession'], '—') ?></span></div>
                </div>
            </div>

            <!-- Contact -->
            <div class="mm-card">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-blue"><i class="fas fa-phone"></i></div>
                    <div class="mm-card-title">Contact Information</div>
                </div>
                <div class="mm-card-body">
                    <div class="mm-info-row"><span class="mm-info-label">Phone</span><span class="mm-info-value"><?= esc($m['phone_number'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Alt Phone</span><span class="mm-info-value"><?= esc($m['alt_phone_number'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Guardian</span><span class="mm-info-value"><?= esc($m['guardian_name'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Guardian Phone</span><span class="mm-info-value"><?= esc($m['guardian_phone1'], '—') ?></span></div>
                </div>
            </div>

            <!-- Family Link (guardian records + siblings). Links stay inside the dashboard panel via MemberNav. -->
            <?php
            $famGuardians = [];
            $famSiblings = [];
            try {
                require_once __DIR__ . '/backend/services/GuardianService.php';
                $famGuardians = \App\Services\GuardianService::familyOfMember($conn, (int)$m['id']);
                $famSiblings = \App\Services\GuardianService::siblingsOf($conn, (int)$m['id']);
            } catch (\Throwable $famError) {
                $famGuardians = [];
                $famSiblings = [];
            }
            $famCanManage = in_array((string)($_SESSION['admin_role'] ?? ''), ['super_admin', 'school_admin', 'hr_dept'], true);
            $famRelLabel = static function (string $r): string {
                return $r === 'father' ? 'Father' : ($r === 'mother' ? 'Mother' : 'Guardian');
            };
            ?>
            <div class="mm-card">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-green"><i class="fas fa-people-roof"></i></div>
                    <div class="mm-card-title">Family</div>
                </div>
                <div class="mm-card-body">
                    <?php if (!$famGuardians): ?>
                        <div class="mm-fam-empty">
                            <?php if (trim((string)($m['guardian_name'] ?? '')) !== ''): ?>
                                <b><?= e($m['guardian_name']) ?></b> is typed on this record but has no guardian record yet, so siblings cannot be shown.
                                <?php if ($famCanManage): ?><div style="margin-top:6px"><a href="/admin/dashboard.php?section=family" class="mm-fam-btn" onclick="if(window.FamilyLink&&window.navigateToSection){closeManageSheet();navigateToSection('family');FamilyLink.go('unlinked');return false;}"><i class="fas fa-link"></i> Link it in Family Link → Not linked yet</a></div><?php endif; ?>
                            <?php else: ?>
                                No guardian details on this record. Add the guardian's name and phone in <b>Edit</b> — a guardian record is created and siblings are detected automatically.
                            <?php endif; ?>
                        </div>
                    <?php else: foreach ($famGuardians as $famG): ?>
                        <div class="mm-fam-row">
                            <div class="mm-fam-who">
                                <div class="mm-fam-name"><?= e((string)$famG['display_name']) ?></div>
                                <div class="mm-fam-meta">
                                    <span class="mm-fam-tag <?= (int)$famG['is_primary'] === 1 ? '' : 'mm-fam-tag-soft' ?>" title="<?= (int)$famG['is_primary'] === 1 ? 'Primary contact — this phone is shown on the member record' : 'Additional guardian' ?>"><?= e($famRelLabel((string)$famG['relation'])) ?><?= (int)$famG['is_primary'] === 1 ? ' · primary' : '' ?></span>
                                    <?= trim((string)($famG['phone_primary'] ?? '')) !== '' ? '<i class="fas fa-phone" style="color:#94a3b8"></i> ' . e((string)$famG['phone_primary']) : '<span style="color:#d97706">no phone</span>' ?>
                                    <?= trim((string)($famG['phone_alt'] ?? '')) !== '' ? ' · ' . e((string)$famG['phone_alt']) : '' ?>
                                    <?= !empty($famG['aliases']) ? '<div>aka ' . e(implode(', ', $famG['aliases'])) . '</div>' : '' ?>
                                </div>
                            </div>
                            <?php if ($famCanManage): ?>
                                <a href="/admin/dashboard.php?section=family&guardian=<?= (int)$famG['id'] ?>" class="mm-fam-btn" title="Open this guardian in Family Link: edit details once for all children, add or remove children, merge duplicates" onclick="return window.MemberNav ? MemberNav.manageFamily(<?= (int)$famG['id'] ?>) : true;"><i class="fas fa-people-roof"></i> Manage family</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>

                    <div style="margin-top:10px;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em">Siblings<?= $famSiblings ? ' (' . count($famSiblings) . ')' : '' ?></div>
                    <?php if (!$famSiblings): ?>
                        <div class="mm-fam-meta" style="margin-top:4px"><?= $famGuardians ? 'No other member is linked to this guardian yet.' : '—' ?></div>
                    <?php else: foreach ($famSiblings as $famS): $famSName = (string)($famS['student_name'] ?: $famS['full_name_am']); ?>
                        <a class="mm-fam-sib" href="/admin/dashboard.php?member=<?= (int)$famS['id'] ?>" onclick="return window.MemberNav ? MemberNav.go(<?= (int)$m['id'] ?>, <?= (int)$famS['id'] ?>) : true;" title="Open <?= e($famSName) ?>'s profile (you can come back with the Back button)">
                            <div class="mm-fam-avatar"><?= e(mb_strtoupper(mb_substr(trim($famSName), 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
                            <div class="mm-fam-who">
                                <div class="mm-fam-name"><?= e($famSName) ?></div>
                                <div class="mm-fam-meta"><?= e((string)($famS['member_code'] ?: 'no code yet')) ?><?= trim((string)($famS['current_section'] ?? '')) !== '' ? ' · ' . e((string)$famS['current_section']) : '' ?></div>
                            </div>
                            <i class="fas fa-chevron-right" style="margin-left:auto;color:#cbd5e1"></i>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- Address -->
            <div class="mm-card">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-red"><i class="fas fa-map-marker-alt"></i></div>
                    <div class="mm-card-title">Address</div>
                </div>
                <div class="mm-card-body">
                    <div class="mm-info-row"><span class="mm-info-label">City</span><span class="mm-info-value"><?= esc($m['city'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Sub City</span><span class="mm-info-value"><?= esc($m['sub_city'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Woreda</span><span class="mm-info-value"><?= esc($m['woreda'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Mender</span><span class="mm-info-value"><?= esc($m['mender'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">Block No.</span><span class="mm-info-value"><?= esc($m['block_number'], '—') ?></span></div>
                    <div class="mm-info-row"><span class="mm-info-label">House No.</span><span class="mm-info-value"><?= esc($m['house_number'], '—') ?></span></div>
                </div>
            </div>

            <!-- Status & Roles -->
            <div class="mm-card mm-card-full">
                <div class="mm-card-hdr">
                    <div class="mm-card-icon mm-icon-purple"><i class="fas fa-id-badge"></i></div>
                    <div class="mm-card-title">Status & Roles</div>
                </div>
                <div class="mm-card-body">
                    <div class="mm-badges">
                        <span class="mm-badge mm-badge-slate"><?= e(ucfirst($m['registration_type'])) ?></span>
                        <span class="mm-badge mm-badge-green"><?= e(ucfirst(str_replace('_', ' ', $m['member_type']))) ?></span>
                        <span class="mm-badge mm-badge-blue"><?= e($m['current_section'] ?: $m['age_group']) ?></span>
                        <span class="mm-badge mm-badge-amber"><?= e(ucfirst($m['membership_tier'] ?? 'temporary')) ?> member</span>
                    </div>
                    <div class="mm-roles">
                        <?php 
                        $roles = [
                            ['is_teacher', 'Teacher', 'chalkboard-user'],
                            ['is_staff', 'Staff', 'user-tie'],
                            ['is_committee', 'Committee', 'users'],
                            ['is_volunteer', 'Volunteer', 'hand-holding-heart']
                        ];
                        foreach ($roles as $r): $active = !empty($m[$r[0]]); 
                        ?>
                        <div class="mm-role <?= $active ? 'active' : '' ?>">
                            <i class="fas fa-<?= $r[2] ?>"></i>
                            <span><?= $r[1] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- EDIT PANEL - ID MUST be: view-edit -->
        <div id="view-edit" class="hidden" style="display: none;">
            <form id="editMemberForm" onsubmit="submitEditForm(event)" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">

                <!-- Photos -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-green"><i class="fas fa-camera"></i></div>
                        <div class="mm-card-title">Photos</div>
                    </div>
                    <div class="mm-card-body">
                        <div class="mm-photos">
                            <div class="mm-photo-wrap">
                                <div class="mm-photo-label">Student Photo</div>
                                <div class="mm-photo-box" style="cursor: default;">
                                    <?php if (!empty($m['student_photo_path'])): ?>
                                        <img src="<?= fixPath($m['student_photo_path']) ?>" alt="Student">
                                    <?php else: ?>
                                        <div class="mm-photo-empty"><i class="fas fa-user"></i></div>
                                    <?php endif; ?>
                                </div>
                                <input type="file" name="student_photo" accept="image/*" class="mm-input" style="margin-top:10px;padding:8px;">
                            </div>
                            <div class="mm-photo-wrap">
                                <div class="mm-photo-label">Guardian Photo</div>
                                <div class="mm-photo-box" style="cursor: default;">
                                    <?php if (!empty($m['guardian_photo_path'])): ?>
                                        <img src="<?= memberFileUrl($m['id'], 'guardian_photo_path') ?>" alt="Guardian">
                                    <?php else: ?>
                                        <div class="mm-photo-empty"><i class="fas fa-user"></i></div>
                                    <?php endif; ?>
                                </div>
                                <input type="file" name="guardian_photo" accept="image/*" class="mm-input" style="margin-top:10px;padding:8px;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Documents -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-blue"><i class="fas fa-folder-open"></i></div>
                        <div class="mm-card-title">Documents</div>
                    </div>
                    <div class="mm-card-body">
                        <div class="mm-form-group">
                            <label class="mm-label">School Records</label>
                            <?php if (!empty($m['doc_school_records_path'])): ?>
                                <div class="mm-file-ok"><i class="fas fa-check-circle"></i> File uploaded</div>
                            <?php endif; ?>
                            <input type="file" name="doc_school_records" accept="image/*,.pdf" class="mm-input" style="padding:10px;">
                        </div>
                        <div class="mm-form-group">
                            <label class="mm-label">Spiritual Records</label>
                            <?php if (!empty($m['doc_spiritual_path'])): ?>
                                <div class="mm-file-ok"><i class="fas fa-check-circle"></i> File uploaded</div>
                            <?php endif; ?>
                            <input type="file" name="doc_spiritual" accept="image/*,.pdf" class="mm-input" style="padding:10px;">
                        </div>
                        <div class="mm-form-group">
                            <label class="mm-label">Signed Form</label>
                            <?php if (!empty($m['doc_signed_form_path'])): ?>
                                <div class="mm-file-ok"><i class="fas fa-check-circle"></i> File uploaded</div>
                            <?php endif; ?>
                            <input type="file" name="doc_signed_form" accept="image/*,.pdf" class="mm-input" style="padding:10px;">
                        </div>
                    </div>
                </div>

                <!-- Status -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-amber"><i class="fas fa-cog"></i></div>
                        <div class="mm-card-title">Status & Type</div>
                    </div>
                    <div class="mm-card-body">
                        <div class="mm-form-group">
                            <label class="mm-label">Status</label>
                            <?php $canEditStatus = hasRole(['hr_dept', 'info_dept']) && ($m['status'] ?? '') !== 'archived'; ?>
                            <?php if ($canEditStatus): ?>
                            <select name="status" class="mm-input">
                                <option value="active" <?= sel($m['status'], 'active') ?>>Active</option>
                                <option value="inactive" <?= sel($m['status'], 'inactive') ?>>Inactive</option>
                                <?php if (!in_array($m['status'] ?? '', ['active', 'inactive'], true)): ?>
                                <option value="<?= htmlspecialchars((string)$m['status']) ?>" selected><?= htmlspecialchars(ucfirst((string)$m['status'])) ?> (current)</option>
                                <?php endif; ?>
                            </select>
                            <small style="color:#6b7280;display:block;margin-top:4px">Manual status is an HR / Information decision (active or inactive). Attendance warnings never change it automatically.</small>
                            <?php else: ?>
                            <input type="text" class="mm-input" value="<?= htmlspecialchars(ucfirst((string)($m['status'] ?? 'active'))) ?>" readonly disabled>
                            <small style="color:#6b7280;display:block;margin-top:4px">Only HR or the Information department can change a member's status.</small>
                            <?php endif; ?>
                        </div>
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Registration Type</label>
                                <select name="registration_type" class="mm-input">
                                    <option value="waiting" <?= sel($m['registration_type'], 'waiting') ?>>Waiting</option>
                                    <option value="transfer" <?= sel($m['registration_type'], 'transfer') ?>>Transfer</option>
                                    <option value="direct" <?= sel($m['registration_type'], 'direct') ?>>Direct</option>
                                </select>
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Member Type</label>
                                <select name="member_type" class="mm-input">
                                    <option value="regular" <?= sel($m['member_type'], 'regular') ?>>Regular</option>
                                    <option value="special_regular" <?= sel($m['member_type'], 'special_regular') ?>>Special Regular</option>
                                    <option value="honorary" <?= sel($m['member_type'], 'honorary') ?>>Honorary</option>
                                </select>
                                <small>Selected manually. Positions and teacher roles do not change this type.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personal Info -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-green"><i class="fas fa-user"></i></div>
                        <div class="mm-card-title">Personal Information</div>
                    </div>
                    <div class="mm-card-body">
                        <div class="mm-form-group">
                            <label class="mm-label">Student Name *</label>
                            <input type="text" name="student_name" value="<?= e($m['student_name']) ?>" required class="mm-input">
                        </div>
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Father Name *</label>
                                <input type="text" name="father_name" value="<?= e($m['father_name']) ?>" required class="mm-input">
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Grandfather</label>
                                <input type="text" name="grandfather_name" value="<?= e($m['grandfather_name']) ?>" class="mm-input">
                            </div>
                        </div>
                        <div class="mm-form-group">
                            <label class="mm-label">Christian Name (የክርስትና ስም)</label>
                            <input type="text" name="baptismal_name" value="<?= e($m['baptismal_name']) ?>" class="mm-input">
                        </div>
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Gender</label>
                                <select name="gender" class="mm-input">
                                    <option value="male" <?= sel($m['gender'], 'male') ?>>Male</option>
                                    <option value="female" <?= sel($m['gender'], 'female') ?>>Female</option>
                                </select>
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Education</label>
                                <select name="education_level" class="mm-input">
                                    <option value="">Select...</option>
                                    <option value="kg" <?= sel($m['education_level'], 'kg') ?>>KG</option>
                                    <option value="elementary" <?= sel($m['education_level'], 'elementary') ?>>Elementary (1-8)</option>
                                    <option value="high" <?= sel($m['education_level'], 'high') ?>>High School (9-12)</option>
                                    <option value="diploma" <?= sel($m['education_level'], 'diploma') ?>>Diploma</option>
                                    <option value="degree" <?= sel($m['education_level'], 'degree') ?>>Degree</option>
                                    <option value="masters" <?= sel($m['education_level'], 'masters') ?>>Masters</option>
                                    <option value="phd" <?= sel($m['education_level'], 'phd') ?>>PhD</option>
                                </select>
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label" style="color: #059669;">✝ Spiritual Education</label>
                                <select name="spiritual_education" class="mm-input" style="border-color: #10b981; background: #ecfdf5;">
                                    <option value="">Select Level...</option>
                                    <option value="grade_1" <?= sel($m['spiritual_education'], 'grade_1') ?>>1ኛ ክፍል (Grade 1)</option>
                                    <option value="grade_2" <?= sel($m['spiritual_education'], 'grade_2') ?>>2ኛ ክፍል (Grade 2)</option>
                                    <option value="grade_3" <?= sel($m['spiritual_education'], 'grade_3') ?>>3ኛ ክፍል (Grade 3)</option>
                                    <option value="grade_4" <?= sel($m['spiritual_education'], 'grade_4') ?>>4ኛ ክፍል (Grade 4)</option>
                                    <option value="grade_5" <?= sel($m['spiritual_education'], 'grade_5') ?>>5ኛ ክፍል (Grade 5)</option>
                                    <option value="grade_6" <?= sel($m['spiritual_education'], 'grade_6') ?>>6ኛ ክፍል (Grade 6)</option>
                                    <option value="grade_7" <?= sel($m['spiritual_education'], 'grade_7') ?>>7ኛ ክፍል (Grade 7)</option>
                                    <option value="grade_8" <?= sel($m['spiritual_education'], 'grade_8') ?>>8ኛ ክፍል (Grade 8)</option>
                                    <option value="grade_9" <?= sel($m['spiritual_education'], 'grade_9') ?>>9ኛ ክፍል (Grade 9)</option>
                                    <option value="grade_10" <?= sel($m['spiritual_education'], 'grade_10') ?>>10ኛ ክፍል (Grade 10)</option>
                                    <option value="grade_11" <?= sel($m['spiritual_education'], 'grade_11') ?>>11ኛ ክፍል (Grade 11)</option>
                                    <option value="grade_12" <?= sel($m['spiritual_education'], 'grade_12') ?>>12ኛ ክፍል (Grade 12)</option>
                                    <option value="diploma" <?= sel($m['spiritual_education'], 'diploma') ?>>ዲፕሎማ (Diploma)</option>
                                    <option value="degree" <?= sel($m['spiritual_education'], 'degree') ?>>ዲግሪ (Degree)</option>
                                </select>
                            </div>
                        </div>
                        <div class="mm-form-group">
                            <label class="mm-label">Date of Birth (EC)</label>
                            <div class="mm-row-3">
                                <input type="number" name="dob_day" value="<?= $m['dob_ec_day'] ?>" placeholder="Day" min="1" max="30" class="mm-input">
                                <input type="number" name="dob_month" value="<?= $m['dob_ec_month'] ?>" placeholder="Month" min="1" max="13" class="mm-input">
                                <input type="number" name="dob_year" value="<?= $m['dob_ec_year'] ?>" placeholder="Year" class="mm-input">
                            </div>
                        </div>
                        <div class="mm-form-group">
                            <label class="mm-label">Profession</label>
                            <input type="text" name="work_profession" value="<?= e($m['work_profession']) ?>" class="mm-input">
                        </div>
                    </div>
                </div>

                <!-- Contact -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-blue"><i class="fas fa-phone"></i></div>
                        <div class="mm-card-title">Contact</div>
                    </div>
                    <div class="mm-card-body">
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Phone</label>
                                <input type="tel" name="phone_number" value="<?= e($m['phone_number']) ?>" class="mm-input">
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Alt Phone</label>
                                <input type="tel" name="alt_phone_number" value="<?= e($m['alt_phone_number']) ?>" class="mm-input">
                            </div>
                        </div>
                        <div class="mm-form-group">
                            <label class="mm-label">Guardian Name</label>
                            <input type="text" name="guardian_name" value="<?= e($m['guardian_name']) ?>" class="mm-input">
                        </div>
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Guardian Phone 1</label>
                                <input type="tel" name="guardian_phone1" value="<?= e($m['guardian_phone1']) ?>" class="mm-input">
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Guardian Phone 2</label>
                                <input type="tel" name="guardian_phone2" value="<?= e($m['guardian_phone2']) ?>" class="mm-input">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Address -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-red"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="mm-card-title">Address</div>
                    </div>
                    <div class="mm-card-body">
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">City</label>
                                <input type="text" name="city" value="<?= e($m['city']) ?>" class="mm-input">
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Sub City</label>
                                <input type="text" name="sub_city" value="<?= e($m['sub_city']) ?>" class="mm-input">
                            </div>
                        </div>
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Woreda</label>
                                <input type="text" name="woreda" value="<?= e($m['woreda']) ?>" class="mm-input">
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">Mender</label>
                                <input type="text" name="mender" value="<?= e($m['mender']) ?>" class="mm-input">
                            </div>
                        </div>
                        <div class="mm-row">
                            <div class="mm-form-group">
                                <label class="mm-label">Block No.</label>
                                <input type="text" name="block_number" value="<?= e($m['block_number']) ?>" class="mm-input">
                            </div>
                            <div class="mm-form-group">
                                <label class="mm-label">House No.</label>
                                <input type="text" name="house_number" value="<?= e($m['house_number']) ?>" class="mm-input">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Roles -->
                <div class="mm-card">
                    <div class="mm-card-hdr">
                        <div class="mm-card-icon mm-icon-purple"><i class="fas fa-user-tag"></i></div>
                        <div class="mm-card-title">Roles</div>
                    </div>
                    <div class="mm-card-body">
                        <?php
                            require_once __DIR__ . '/backend/services/PositionSyncService.php';
                            $mmCat = \App\Services\PositionSyncService::catalogue($conn);
                            $mmHeld = \App\Services\PositionSyncService::currentPositionIds($conn, (int)$m['id']);
                            ?>
                        <div class="mm-checks">
                            <?php if (empty($mmCat['departments']) && empty($mmCat['free'])): ?>
                                <p style="font-size:.72rem;color:#94a3b8">No positions defined yet (Super Admin → Identity &amp; Codes).</p>
                            <?php else: ?>
                                <?php foreach ($mmCat['free'] as $pos): ?>
                                <label class="mm-check"><input type="checkbox" name="position_ids[]" value="<?= (int)$pos['id'] ?>" <?= in_array((int)$pos['id'], $mmHeld, true) ? 'checked' : '' ?>><span class="eth"><?= htmlspecialchars($pos['title_am']) ?> [<?= htmlspecialchars($pos['role_code']) ?>]</span></label>
                                <?php endforeach; ?>
                                <?php foreach ($mmCat['departments'] as $pos): ?>
                                <label class="mm-check"><input type="checkbox" name="position_ids[]" value="<?= (int)$pos['id'] ?>" <?= in_array((int)$pos['id'], $mmHeld, true) ? 'checked' : '' ?>><span class="eth"><?= htmlspecialchars($pos['dept_code']) ?> · <?= htmlspecialchars($pos['title_am']) ?> [<?= htmlspecialchars($pos['role_code']) ?>]</span></label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Save Footer -->
                <div class="mm-footer">
                    <button type="submit" class="mm-save"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
