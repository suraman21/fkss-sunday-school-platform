<?php
/**
 * Smart Member Import API (Excel .xlsx UPSERT) — v2 (2026-10-21)
 * - CSRF + role gated (super_admin / hr_dept; school_admin removed 2026-11-18)
 * - Human headers AND legacy snake_case headers
 * - dry_run=1 ("Check file") validates the whole file and writes nothing.
 * - All-or-nothing: every row is validated first; ANY error rejects the
 *   whole file with a row list. Members, member codes and class
 *   enrollments are then written in ONE database transaction.
 * - Filled Excel cells update an existing member. Blank cells are left unchanged.
 * - Existing members are matched by member code → name + phone → name + birth date.
 * - Birth date is an Ethiopian date; date_of_birth (Gregorian) and the
 *   dob_ec_* fields are both stored. Age, age group and section are
 *   calculated from age (section = age group; it never decides the class).
 * - Class labels match active class names/codes exactly (spaces ignored);
 *   never by numeric id. Empty class = imported unassigned.
 * - Member code is never overwritten. Permanent members get a code from
 *   their age group; temporary members join the waiting list.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/workflow.php';
require_once __DIR__ . '/backend/services/ExcelColumnMap.php';
require_once __DIR__ . '/backend/services/EnrollmentService.php';
require_once __DIR__ . '/backend/services/MemberCategory.php';
require_once __DIR__ . '/backend/services/MemberImportService.php';
require_once __DIR__ . '/backend/services/MemberSearchService.php';
require_once __DIR__ . '/backend/services/SecurityAuditService.php';
require_once __DIR__ . '/backend/danger_confirm.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Services\ExcelColumnMap;
use App\Services\EnrollmentService;
use App\Services\MemberCategory;
use App\Services\MemberImportService;

header('Content-Type: application/json; charset=utf-8');

function import_json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    import_json(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$role = $_SESSION['admin_role'] ?? '';
if (empty($_SESSION['admin_id']) || !in_array($role, ['super_admin', 'hr_dept'], true)) {
    import_json(['success' => false, 'message' => 'Access denied.'], 403);
}

$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!function_exists('validateCsrf') || !validateCsrf($csrf)) {
    import_json(['success' => false, 'message' => 'Security token expired. Please refresh and try again.'], 403);
}

if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    import_json(['success' => false, 'message' => 'File upload failed.']);
}

$file = $_FILES['import_file'];
$tmpPath = (string)($file['tmp_name'] ?? '');
$actualSize = $tmpPath !== '' ? @filesize($tmpPath) : false;
if ($tmpPath === '' || !is_uploaded_file($tmpPath) || $actualSize === false || $actualSize <= 0) {
    import_json(['success' => false, 'message' => 'The uploaded workbook could not be verified.']);
}
if ($actualSize > 5 * 1024 * 1024) {
    import_json(['success' => false, 'message' => 'File too large (max 5 MB).']);
}

$fileName = $file['name'] ?? '';
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if ($ext !== 'xlsx') {
    import_json(['success' => false, 'message' => 'Only Excel files (.xlsx) are supported.']);
}

$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
if ($finfo) {
    $mime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    $okMime = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream',
        'application/zip',
    ];
    if ($mime && !in_array($mime, $okMime, true)) {
        import_json(['success' => false, 'message' => 'File does not look like a valid Excel workbook.']);
    }
}

// XLSX files are ZIP containers. Bound expanded data before PhpSpreadsheet
// parses it to prevent small compressed uploads from exhausting server memory.
if (!class_exists('ZipArchive')) {
    import_json(['success' => false, 'message' => 'Excel validation is unavailable on this server.'], 503);
}
$zip = new ZipArchive();
if ($zip->open($tmpPath) !== true || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
    if ($zip->status === ZipArchive::ER_OK) $zip->close();
    import_json(['success' => false, 'message' => 'File is not a valid XLSX workbook.']);
}
$expandedBytes = 0;
if ($zip->numFiles > 5000) {
    $zip->close();
    import_json(['success' => false, 'message' => 'Workbook contains too many internal files.']);
}
for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    $entrySize = (int)($stat['size'] ?? 0);
    $expandedBytes += $entrySize;
    if ($entrySize > 20 * 1024 * 1024 || $expandedBytes > 50 * 1024 * 1024) {
        $zip->close();
        import_json(['success' => false, 'message' => 'Workbook expands beyond the safe processing limit.']);
    }
}
$zip->close();

$tier = $_POST['tier'] ?? 'permanent';
if (!in_array($tier, ['temporary', 'permanent'], true)) {
    $tier = 'permanent';
}
$dryRun = in_array(strtolower((string)($_POST['dry_run'] ?? '')), ['1', 'true', 'yes'], true);
// Danger confirmation (HIGH): a real import writes many members at once and
// needs the typed phrase from the 3-step dialog. "Check file" (dry run) does not.
if (!$dryRun) {
    dc_require_phrase('members.import');
}

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    import_json(['success' => false, 'message' => 'Database connection error.'], 503);
}

try {
    $spreadsheet = IOFactory::load($file['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);
} catch (\Throwable $e) {
    import_json(['success' => false, 'message' => 'Error reading Excel file.']);
}

if (count($rows) < 2) {
    import_json(['success' => false, 'message' => 'Excel file is empty or missing data rows.']);
}

$headers = [];
$headerIndex = -1;
foreach ($rows as $index => $row) {
    $mapped = 0;
    foreach ($row as $cell) {
        if (ExcelColumnMap::resolveHeader((string)$cell) !== null) {
            $mapped++;
        }
    }
    if ($mapped >= 2) {
        $headers = $row;
        $headerIndex = $index;
        break;
    }
}
if (empty($headers)) {
    import_json(['success' => false, 'message' => 'No header row found. Use the template downloaded from this page.']);
}
foreach (array_keys($rows) as $idx) {
    if ($idx <= $headerIndex) {
        unset($rows[$idx]);
    }
}
$rows = array_filter($rows, function ($row) {
    foreach ($row as $v) {
        if ($v !== null && trim((string)$v) !== '') {
            return true;
        }
    }
    return false;
});
if (count($rows) === 0) {
    import_json(['success' => false, 'message' => 'The file has a header row but no member rows.']);
}
if (count($rows) > 2000) {
    import_json(['success' => false, 'message' => 'Too many rows (max 2,000). Split the file and try again.']);
}

// ── Header mapping ──────────────────────────────────────────────────
$resolvedHeaders = [];
$seenKeys = [];
$headerErrors = [];
foreach ($headers as $i => $raw) {
    $key = ExcelColumnMap::resolveHeader((string)$raw);
    $resolvedHeaders[$i] = $key;
    if ($key === null) {
        continue;
    }
    if (isset($seenKeys[$key])) {
        $headerErrors[] = 'Column "' . trim((string)$raw) . '" appears more than once.';
    }
    $seenKeys[$key] = true;
}
if (!isset($seenKeys['full_name_am']) && !isset($seenKeys['member_code'])) {
    $headerErrors[] = 'The file needs a "Full Name" (or "Member Code") column.';
}
if (!empty($headerErrors)) {
    import_json(['success' => false, 'message' => 'The file was not imported.', 'errors' => $headerErrors, 'error_count' => count($headerErrors)]);
}

// ── Schema (types, lengths, allowed values) ─────────────────────────
$colMeta = [];
$notNullCols = [];
$colRes = $conn->query('SHOW COLUMNS FROM members');
while ($colRes && ($col = $colRes->fetch_assoc())) {
    $type = strtolower((string)$col['Type']);
    $meta = ['type' => $type, 'max' => null, 'enum' => null];
    if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $m)) {
        $meta['max'] = (int)$m[1];
    } elseif (preg_match("/^enum\((.*)\)$/", $type, $m)) {
        $meta['enum'] = array_map(function ($v) { return trim($v, "'"); }, str_getcsv($m[1], ',', "'"));
    }
    $colMeta[$col['Field']] = $meta;
    if ($col['Null'] === 'NO' && $col['Default'] === null && $col['Extra'] !== 'auto_increment') {
        $notNullCols[] = $col['Field'];
    }
}
$requiredSchema = ['full_name_am', 'student_name', 'father_name', 'gender', 'date_of_birth', 'dob_ec_day', 'dob_ec_month',
    'dob_ec_year', 'age', 'age_group', 'current_section', 'registration_type', 'membership_tier', 'waiting_since', 'member_code'];
$missingSchema = array_values(array_diff($requiredSchema, array_keys($colMeta)));
foreach (['emergency_name', 'emergency_phone'] as $optional) {
    if (isset($seenKeys[$optional]) && !isset($colMeta[$optional])) {
        $missingSchema[] = $optional;
    }
}
if (!empty($missingSchema)) {
    error_log('api_import_members: members table is missing columns: ' . implode(', ', $missingSchema));
    import_json(['success' => false, 'message' => 'The system database needs an update before importing (missing member fields). No changes were saved.'], 503);
}

// Columns the importer computes itself or never writes from a sheet.
$computedKeys = ['id', 'member_code', 'age', 'age_group', 'current_section', 'date_of_birth', 'dob_ec_day', 'dob_ec_month',
    'dob_ec_year', 'gender', 'registration_type', 'created_by', 'created_at', 'current_class_id', 'legacy_member_code'];

$adminId = (int)$_SESSION['admin_id'];
$zone = new DateTimeZone('Africa/Addis_Ababa');
$today = new DateTimeImmutable('now', $zone);
$todayGregorian = $today->format('Y-m-d');

$activeClasses = EnrollmentService::activeClassesForLookup($conn);
$classLabelCache = [];
$activeYear = EnrollmentService::activeYear($conn);

$errors = [];
$errorCount = 0;
$warnings = [];
$fail = function (int $excelRow, string $message) use (&$errors, &$errorCount) {
    $errorCount++;
    if (count($errors) < 300) {
        $errors[] = 'Row ' . $excelRow . ': ' . $message;
    }
};
$warn = function (int $excelRow, string $message) use (&$warnings) {
    if (count($warnings) < 300) {
        $warnings[] = 'Row ' . $excelRow . ': ' . $message;
    }
};
$fetchOne = function (string $sql, string $types, array $params) use ($conn): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('prepare failed');
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $found = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $found[] = $r;
    }
    $stmt->close();
    return $found;
};
$sectionDecision = function (?int $age, string $sectionCell): array {
    // Section = age group, decided by age (owner decision D2/D3/D6).
    if ($age !== null) {
        $group = MemberCategory::groupForAge($age);
        return [$group, MemberCategory::sectionAm($group), 'age'];
    }
    if ($sectionCell !== '') {
        $canonical = MemberCategory::normalizeSectionAm($sectionCell);
        return [MemberCategory::ageGroupForSectionAm($canonical), $canonical, 'sheet'];
    }
    return [null, null, 'none'];
};

$plan = [];
$inFileKeys = [];
$newNamesInFile = [];
$matchedIds = [];
$summary = [
    'rows' => 0, 'new_permanent' => 0, 'new_temporary' => 0, 'updated' => 0, 'unchanged' => 0,
    'skipped_archived' => 0, 'to_enroll' => 0, 'unassigned_new' => 0, 'codes_to_issue' => 0, 'codes_pending' => 0,
    'sections' => [], 'classes' => [],
];

// ── Phase 1: validate every row (no writes) ─────────────────────────
try {
    foreach ($rows as $rowIndex => $row) {
        $excelRow = (int)$rowIndex + 1;
        $summary['rows']++;
        $rowErrorsBefore = $errorCount;

        $cells = [];
        foreach ($resolvedHeaders as $i => $key) {
            if ($key !== null) {
                $cells[$key] = MemberImportService::cleanCell($row[$i] ?? '');
            }
        }
        $name = MemberImportService::cleanName($cells['full_name_am'] ?? '');
        $code = $cells['member_code'] ?? '';
        if ($name === '' && $code === '' && ($cells['system_id'] ?? '') === '') {
            $fail($excelRow, 'missing Full Name and Member Code.');
            continue;
        }

        // Gender
        $gender = null;
        if (($cells['gender'] ?? '') !== '') {
            $gender = MemberImportService::normalizeGender($cells['gender']);
            if ($gender === null) {
                $fail($excelRow, 'Gender "' . $cells['gender'] . '" is not valid (use ወንድ/ሴት or Male/Female).');
            }
        }

        // Birth date (Ethiopian) → Gregorian + EC fields
        $dob = null;
        if (($cells['date_of_birth'] ?? '') !== '') {
            $parsed = MemberImportService::parseEcDate($cells['date_of_birth'], $today);
            if ($parsed['ok']) {
                $dob = $parsed;
            } else {
                $fail($excelRow, 'Date of Birth "' . $cells['date_of_birth'] . '": ' . MemberImportService::dateErrorText($parsed['error']) . '.');
            }
        }

        // Age: from the birth date; a numeric Age cell is used only without one.
        $age = null;
        if ($dob !== null) {
            $age = MemberImportService::ageFromGregorian($dob['gregorian'], $today);
        } elseif (($cells['age'] ?? '') !== '') {
            if (preg_match('/^\d{1,3}$/', $cells['age']) && (int)$cells['age'] <= 150) {
                $age = (int)$cells['age'];
            } else {
                $fail($excelRow, 'Age "' . $cells['age'] . '" is not a whole number.');
            }
        }
        $sectionCell = $cells['current_section'] ?? '';
        if ($age === null && $sectionCell !== '' && MemberCategory::normalizeSectionAm($sectionCell) === null) {
            $fail($excelRow, 'Age Section "' . $sectionCell . '" is not a known section.');
        }

        // Other dates (registered_at / waiting_since …) are Ethiopian too.
        $otherDates = [];
        foreach ($cells as $key => $val) {
            if ($key !== 'date_of_birth' && ExcelColumnMap::isDateColumn($key) && $val !== '' && isset($colMeta[$key])) {
                $parsed = MemberImportService::parseEcDate($val, $today);
                if ($parsed['ok']) {
                    $otherDates[$key] = $parsed['gregorian'];
                } else {
                    $fail($excelRow, ExcelColumnMap::header($key) . ' "' . $val . '": ' . MemberImportService::dateErrorText($parsed['error']) . '.');
                }
            }
        }

        // Class (exact label; empty = unassigned)
        $classLabel = '';
        foreach (['class', 'class_name', 'class_code'] as $classKey) {
            if (($cells[$classKey] ?? '') !== '') {
                $classLabel = $cells[$classKey];
                break;
            }
        }
        $class = null;
        if ($classLabel !== '') {
            $cacheKey = EnrollmentService::classLabelKey($classLabel);
            if (!isset($classLabelCache[$cacheKey])) {
                $classLabelCache[$cacheKey] = EnrollmentService::resolveClassByLabel($conn, $classLabel, $activeClasses);
            }
            $resolved = $classLabelCache[$cacheKey];
            if ($resolved['status'] === 'ok') {
                $class = $resolved['class'];
                if ($activeYear === null) {
                    $fail($excelRow, 'there is no active academic year, so the class cannot be assigned.');
                }
            } elseif ($resolved['status'] === 'ambiguous') {
                $fail($excelRow, 'Class "' . $classLabel . '" matches ' . (int)$resolved['matches'] . ' active classes. Rename the classes so each name is unique.');
            } else {
                $fail($excelRow, 'Class "' . $classLabel . '" is not an active class. Pick it from the Class list.');
            }
        }

        // Plain columns: allowed values + length
        $fields = [];
        foreach ($cells as $key => $val) {
            if ($val === '' || ExcelColumnMap::isVirtual($key) || in_array($key, $computedKeys, true)
                || ExcelColumnMap::isDateColumn($key) || !isset($colMeta[$key]) || $key === 'full_name_am') {
                continue;
            }
            $meta = $colMeta[$key];
            $val = MemberImportService::cleanName($val); // collapse repeated spaces
            if ($meta['enum'] !== null) {
                $normalizedEnum = strtolower(str_replace(' ', '_', $val));
                if (!in_array($normalizedEnum, $meta['enum'], true)) {
                    $fail($excelRow, ExcelColumnMap::header($key) . ' "' . $val . '" is not allowed (use: ' . implode(', ', $meta['enum']) . ').');
                    continue;
                }
                $val = $normalizedEnum;
            }
            if ($meta['max'] !== null && mb_strlen($val, 'UTF-8') > $meta['max']) {
                $fail($excelRow, ExcelColumnMap::header($key) . ' is too long (max ' . $meta['max'] . ' characters).');
                continue;
            }
            $fields[$key] = $val;
        }
        foreach ($otherDates as $key => $val) {
            $fields[$key] = $val;
        }
        if (($fields['status'] ?? '') === 'archived') {
            // Archiving records who/when/why — it must go through the Archive action.
            $fail($excelRow, 'Status "archived" cannot be set from Excel. Use the Archive action on the member.');
        }
        if ($name !== '' && mb_strlen($name, 'UTF-8') > (int)($colMeta['full_name_am']['max'] ?? 150)) {
            $fail($excelRow, 'Full Name is too long.');
        }

        // ── Match an existing member ──
        // Order: System ID → member code → name + phone → name + birth date.
        $existing = null;
        $systemId = $cells['system_id'] ?? '';
        if ($systemId !== '') {
            $found = preg_match('/^\d{1,10}$/', $systemId)
                ? $fetchOne('SELECT * FROM members WHERE id = ? LIMIT 1', 'i', [(int)$systemId])
                : [];
            if (count($found) !== 1) {
                $fail($excelRow, 'System ID "' . $systemId . '" was not found. Do not type in the System ID column; leave it empty for new members.');
                continue;
            }
            $existing = $found[0];
            if ($code !== '' && (string)$existing['member_code'] !== $code) {
                $fail($excelRow, 'Member Code "' . $code . '" does not belong to System ID ' . $systemId . '.');
                continue;
            }
        }
        if ($existing === null && $code !== '') {
            $found = $fetchOne('SELECT * FROM members WHERE member_code = ? LIMIT 2', 's', [$code]);
            if (count($found) === 1) {
                $existing = $found[0];
            } else {
                $fail($excelRow, 'Member Code "' . $code . '" was not found. Clear the code to add a new member.');
                continue;
            }
        }
        if ($existing === null && $name !== '' && ($fields['phone_number'] ?? '') !== '') {
            $found = $fetchOne('SELECT * FROM members WHERE full_name_am = ? AND phone_number = ? LIMIT 2', 'ss', [$name, $fields['phone_number']]);
            if (count($found) > 1) {
                $fail($excelRow, 'more than one existing member has this name and phone. Add the Member Code to choose one.');
                continue;
            }
            $existing = $found[0] ?? null;
        }
        if ($existing === null && $name !== '' && $dob !== null) {
            $found = $fetchOne(
                'SELECT * FROM members WHERE full_name_am = ? AND (date_of_birth = ? OR (dob_ec_year = ? AND dob_ec_month = ? AND dob_ec_day = ?)) LIMIT 2',
                'ssiii',
                [$name, $dob['gregorian'], $dob['y'], $dob['m'], $dob['d']]
            );
            if (count($found) > 1) {
                $fail($excelRow, 'more than one existing member has this name and birth date. Add the Member Code to choose one.');
                continue;
            }
            $existing = $found[0] ?? null;
        }

        // In-file duplicates
        if ($existing !== null) {
            $dupKey = 'id:' . (int)$existing['id'];
        } elseif ($dob !== null) {
            $dupKey = 'dob:' . mb_strtolower($name, 'UTF-8') . '|' . $dob['gregorian'];
        } elseif (($fields['phone_number'] ?? '') !== '') {
            $dupKey = 'phone:' . mb_strtolower($name, 'UTF-8') . '|' . $fields['phone_number'];
        } else {
            $dupKey = null;
        }
        if ($dupKey !== null) {
            if (isset($inFileKeys[$dupKey])) {
                $fail($excelRow, 'same member as row ' . $inFileKeys[$dupKey] . ' (same name and birth date/phone).');
                continue;
            }
            $inFileKeys[$dupKey] = $excelRow;
        }

        if ($errorCount > $rowErrorsBefore) {
            continue;
        }

        [$ageGroup, $section, $sectionSource] = $sectionDecision($age, $sectionCell);

        if ($existing !== null) {
            // ── Update: only filled cells; never the member code ──
            if (($existing['status'] ?? '') === 'archived') {
                $summary['skipped_archived']++;
                $warn($excelRow, 'member ' . ($existing['member_code'] ?: ('#' . $existing['id'])) . ' is archived and was skipped. Restore the member first to update.');
                continue;
            }
            $rowData = $fields;
            if ($name !== '' && $name !== MemberImportService::cleanName((string)$existing['full_name_am'])) {
                [$student, $father, $grandfather] = MemberImportService::splitName($name);
                $rowData['full_name_am'] = $name;
                $rowData += ['student_name' => $student, 'father_name' => $father];
                if ($grandfather !== null) {
                    $rowData += ['grandfather_name' => $grandfather];
                }
            }
            if ($gender !== null) {
                $rowData['gender'] = $gender;
            }
            if ($dob !== null) {
                $rowData['date_of_birth'] = $dob['gregorian'];
                $rowData['dob_ec_year'] = (string)$dob['y'];
                $rowData['dob_ec_month'] = (string)$dob['m'];
                $rowData['dob_ec_day'] = (string)$dob['d'];
            }
            if ($age !== null) {
                $rowData['age'] = (string)$age;
            }
            if ($section !== null) {
                $rowData['age_group'] = (string)$ageGroup;
                $rowData['current_section'] = $section;
            }
            $changes = [];
            foreach ($rowData as $col => $val) {
                if (trim((string)($existing[$col] ?? '')) !== (string)$val) {
                    $changes[$col] = $val;
                }
            }
            // PROMOTION CONSISTENCY (2026-11-18, mirror of the insert rule):
            // an update can never leave tier and type split — permanent is
            // never waiting type, temporary is always waiting type.
            $resTier = (string)($changes['membership_tier'] ?? $existing['membership_tier'] ?? '');
            $resType = (string)($changes['registration_type'] ?? $existing['registration_type'] ?? '');
            if ($resTier === 'permanent' && $resType === 'waiting') {
                $changes['registration_type'] = 'direct';
            } elseif ($resTier === 'temporary' && $resType !== '' && $resType !== 'waiting') {
                $changes['registration_type'] = 'waiting';
            }
            $plan[] = ['action' => 'update', 'row' => $excelRow, 'id' => (int)$existing['id'], 'data' => $changes, 'class' => $class];
            $summary[empty($changes) ? 'unchanged' : 'updated']++;
        } else {
            // ── New member ──
            if ($name === '') {
                $fail($excelRow, 'Full Name is required for a new member.');
                continue;
            }
            [$student, $father, $grandfather] = MemberImportService::splitName($name);
            if ($father === '') {
                $fail($excelRow, 'Full Name must include at least the member\'s name and father\'s name.');
                continue;
            }
            if ($gender === null) {
                $fail($excelRow, 'Gender is required for a new member.');
                continue;
            }
            // Never silently create a second copy of someone already in the
            // system: a same-name member is only treated as a different
            // person when both birth dates are known and differ.
            $sameName = $fetchOne('SELECT id, member_code, date_of_birth, dob_ec_year, dob_ec_month, dob_ec_day FROM members WHERE full_name_am = ? LIMIT 20', 's', [$name]);
            if (!empty($sameName)) {
                $provablyDifferent = $dob !== null;
                foreach ($sameName as $other) {
                    $otherDob = (string)($other['date_of_birth'] ?? '');
                    if ($otherDob === '' && (int)$other['dob_ec_year'] > 0 && (int)$other['dob_ec_month'] > 0 && (int)$other['dob_ec_day'] > 0) {
                        $otherDob = ethiopian_to_gregorian((int)$other['dob_ec_year'], (int)$other['dob_ec_month'], (int)$other['dob_ec_day'])->format('Y-m-d');
                    }
                    if ($otherDob === '' || $dob === null || $otherDob === $dob['gregorian']) {
                        $provablyDifferent = false;
                    }
                }
                $who = (string)($sameName[0]['member_code'] ?: ('System ID ' . $sameName[0]['id']));
                if (!$provablyDifferent) {
                    $fail($excelRow, '"' . $name . '" already exists (' . $who . '). If it is the same person, use the exported sheet (System ID) or add the Member Code; if it is a different person, both members need a Date of Birth.');
                    continue;
                }
                $warn($excelRow, 'a member with the same name already exists (' . $who . ', different birth date); this row is added as a new member.');
            }
            // Same rule inside the file: two new rows with the same name must
            // both have different birth dates.
            $nameKey = mb_strtolower($name, 'UTF-8');
            foreach ($newNamesInFile[$nameKey] ?? [] as [$seenRow, $seenDob]) {
                if ($dob === null || $seenDob === null || $seenDob === $dob['gregorian']) {
                    $fail($excelRow, '"' . $name . '" also appears on row ' . $seenRow . '. If they are different people, give both a Date of Birth; otherwise remove one row.');
                    continue 2;
                }
            }
            $newNamesInFile[$nameKey][] = [$excelRow, $dob !== null ? $dob['gregorian'] : null];
            $rowTier = $fields['membership_tier'] ?? $tier;
            $rowData = $fields;
            $rowData['full_name_am'] = $name;
            $rowData += ['student_name' => $student, 'father_name' => $father];
            if ($grandfather !== null) {
                $rowData += ['grandfather_name' => $grandfather];
            }
            $rowData['gender'] = $gender;
            $rowData['membership_tier'] = $rowTier;
            if ($dob !== null) {
                $rowData['date_of_birth'] = $dob['gregorian'];
                $rowData['dob_ec_year'] = (string)$dob['y'];
                $rowData['dob_ec_month'] = (string)$dob['m'];
                $rowData['dob_ec_day'] = (string)$dob['d'];
            } else {
                $warn($excelRow, 'no Date of Birth.');
            }
            if ($age !== null) {
                $rowData['age'] = (string)$age;
            }
            $rowData['age_group'] = $ageGroup;
            $rowData['current_section'] = $section;
            if ($sectionSource === 'none') {
                $warn($excelRow, 'no birth date, age or section — section' . ($rowTier === 'permanent' ? ' and member code stay' : ' stays') . ' pending.');
            }
            if ($rowTier === 'temporary') {
                $rowData['registration_type'] = 'waiting';
                $rowData['waiting_since'] = $rowData['waiting_since'] ?? $todayGregorian;
            } else {
                $rowData['registration_type'] = 'direct';
                $summary[$ageGroup !== null ? 'codes_to_issue' : 'codes_pending']++;
            }
            // The member's own phone lives in phone_number AND phone_primary
            // (same as HR registration); mirror whichever one was filled.
            if (empty($rowData['phone_primary']) && !empty($rowData['phone_number'])) {
                $rowData['phone_primary'] = $rowData['phone_number'];
            } elseif (empty($rowData['phone_number']) && !empty($rowData['phone_primary'])) {
                $rowData['phone_number'] = $rowData['phone_primary'];
            }
            // Guardian Phone 1 is the main guardian phone (same as HR registration).
            if (empty($rowData['phone_guardian']) && !empty($rowData['guardian_phone1'])) {
                $rowData['phone_guardian'] = $rowData['guardian_phone1'];
            }
            if (isset($colMeta['created_by'])) {
                $rowData['created_by'] = (string)$adminId;
            }
            $plan[] = ['action' => 'insert', 'row' => $excelRow, 'data' => $rowData, 'class' => $class];
            $summary[$rowTier === 'temporary' ? 'new_temporary' : 'new_permanent']++;
            $sectionName = $section ?? 'pending';
            $summary['sections'][$sectionName] = ($summary['sections'][$sectionName] ?? 0) + 1;
            if ($class === null) {
                $summary['unassigned_new']++;
            }
        }
        if ($class !== null) {
            $summary['to_enroll']++;
            $label = (string)$class['class_name'];
            $summary['classes'][$label] = ($summary['classes'][$label] ?? 0) + 1;
        }
    }
} catch (\Throwable $e) {
    error_log('api_import_members (check): ' . $e->getMessage());
    import_json(['success' => false, 'message' => 'Database error while checking the file. No changes were saved.'], 500);
}

if ($errorCount > 0) {
    import_json([
        'success' => false,
        'dry_run' => $dryRun,
        'message' => 'The file was not imported: ' . $errorCount . ' problem(s) found. Fix them and upload again. Nothing was saved.',
        'errors' => $errors,
        'error_count' => $errorCount,
        'warnings' => $warnings,
        'summary' => $summary,
    ]);
}

if ($dryRun) {
    import_json([
        'success' => true,
        'dry_run' => true,
        'message' => 'Check passed: ' . ($summary['new_permanent'] + $summary['new_temporary']) . ' new, '
            . $summary['updated'] . ' to update, ' . $summary['unchanged'] . ' unchanged, '
            . $summary['to_enroll'] . ' class assignments. Nothing was saved yet.',
        'warnings' => $warnings,
        'summary' => $summary,
    ]);
}

// ── Phase 2: write everything in ONE transaction ────────────────────
$stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'enrolled' => 0, 'enroll_skipped' => 0,
    'codes_issued' => 0, 'codes_pending' => 0, 'unassigned' => 0, 'skipped_archived' => $summary['skipped_archived']];
$currentRow = 0;
try {
    $conn->begin_transaction();
    $searchIndexIds = [];
    foreach ($plan as $item) {
        $currentRow = (int)$item['row'];
        $rowData = $item['data'];
        $memberId = 0;
        if ($item['action'] === 'insert') {
            if (($rowData['membership_tier'] ?? '') === 'permanent') {
                // Code letter from the member's own age group; unknown group →
                // pending code (issued later from the Identity hub), never guessed.
                $rowData['member_code'] = EnrollmentService::generateMemberCode(
                    $conn,
                    isset($rowData['age_group']) ? (string)$rowData['age_group'] : null
                );
                $stats[$rowData['member_code'] !== null ? 'codes_issued' : 'codes_pending']++;
            }
            foreach ($notNullCols as $reqCol) {
                if (!isset($rowData[$reqCol])) {
                    $rowData[$reqCol] = '';
                }
            }
            $cols = array_keys($rowData);
            $sql = 'INSERT INTO members (`' . implode('`,`', $cols) . '`) VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')';
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('insert prepare failed');
            }
            $values = array_values($rowData);
            $stmt->bind_param(str_repeat('s', count($values)), ...$values);
            if (!$stmt->execute()) {
                throw new RuntimeException('insert failed');
            }
            $memberId = (int)$conn->insert_id;
            $stmt->close();
            $stats['inserted']++;
            if ($item['class'] === null) {
                $stats['unassigned']++;
            }
        } else {
            $memberId = (int)$item['id'];
            if (!empty($rowData)) {
                $sets = [];
                foreach (array_keys($rowData) as $col) {
                    $sets[] = '`' . $col . '` = ?';
                }
                $stmt = $conn->prepare('UPDATE members SET ' . implode(', ', $sets) . ' WHERE id = ?');
                if (!$stmt) {
                    throw new RuntimeException('update prepare failed');
                }
                $values = array_values($rowData);
                $values[] = (string)$memberId;
                $stmt->bind_param(str_repeat('s', count($values)), ...$values);
                if (!$stmt->execute()) {
                    throw new RuntimeException('update failed');
                }
                $stmt->close();
                $stats['updated']++;
            } else {
                $stats['unchanged']++;
            }
        }
        if ($memberId > 0 && $item['class'] !== null) {
            $enr = EnrollmentService::enroll($conn, $memberId, (int)$item['class']['id'], null, $adminId, true);
            if (($enr['status'] ?? '') !== 'success') {
                throw new DomainException('Class "' . $item['class']['class_name'] . '": ' . ($enr['message'] ?? 'enrollment failed'));
            }
            $stats[!empty($enr['skipped']) ? 'enroll_skipped' : 'enrolled']++;
        }
        if ($memberId > 0 && ($item['action'] === 'insert' || !empty($item['data']))) {
            $searchIndexIds[] = $memberId;
        }
    }
    $currentRow = 0;
    if (!\App\Services\SecurityAuditService::record($conn, 'Members Imported', [
        'membership_tier' => $tier,
        'inserted' => $stats['inserted'],
        'updated' => $stats['updated'],
        'enrolled' => $stats['enrolled'],
        'codes_issued' => $stats['codes_issued'],
        'rows' => $summary['rows'],
    ], 'member', null)) {
        throw new RuntimeException('Import audit recording failed.');
    }
    $conn->commit();
    // P0 search engine: write-through for imported members (never fatal;
    // the backfill endpoint catches up on any failure).
    try {
        foreach (array_unique($searchIndexIds) as $searchMemberId) {
            try {
                \App\Services\MemberSearchService::indexMember($conn, (int)$searchMemberId);
            } catch (\Throwable $one) {
                error_log('Search write-through failed for imported member ' . $searchMemberId . ': ' . $one->getMessage());
            }
        }
    } catch (\Throwable $searchError) {
        error_log('Search write-through failed after import: ' . $searchError->getMessage());
    }
} catch (\Throwable $e) {
    try {
        $conn->rollback();
    } catch (\Throwable $ignored) {
    }
    error_log('api_import_members (write, row ' . $currentRow . '): ' . $e->getMessage());
    $detail = ($e instanceof DomainException && $currentRow > 0) ? ['Row ' . $currentRow . ': ' . $e->getMessage()] : [];
    import_json([
        'success' => false,
        'message' => 'Import failed' . ($currentRow > 0 ? ' at row ' . $currentRow : '') . '. Nothing was saved.',
        'errors' => $detail,
        'error_count' => count($detail),
    ]);
}

$msg = "Import complete. Added {$stats['inserted']}, updated {$stats['updated']}, enrolled {$stats['enrolled']}";
if ($stats['enroll_skipped']) {
    $msg .= ", already in class {$stats['enroll_skipped']}";
}
if ($stats['unassigned']) {
    $msg .= ", without class {$stats['unassigned']}";
}
if ($stats['unchanged']) {
    $msg .= ", unchanged {$stats['unchanged']}";
}
$msg .= '.';

import_json([
    'success' => true,
    'message' => $msg,
    'stats' => $stats,
    'warnings' => $warnings,
    'summary' => $summary,
]);
