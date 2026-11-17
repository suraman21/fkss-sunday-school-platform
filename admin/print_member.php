<?php
/**
 * Print Member Information - Single A4 Page PDF Export
 * Perfectly designed to fit on one A4 page
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/services/MemberCodeFormat.php';
require_once __DIR__ . '/backend/services/MemberTypeService.php';

// SECURITY (IDOR fix): full member dossier (PII incl. guardian contacts and
// addresses) — requires an authenticated admin session.
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Get member ID
$memberId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($memberId <= 0) {
    die('<h2 style="color:red;text-align:center;margin-top:50px;">Invalid member ID</h2>');
}

// Fetch member
$stmt = $conn->prepare('SELECT * FROM members WHERE id = ?');
$stmt->bind_param('i', $memberId);
$stmt->execute();
$m = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$m) {
    die('<h2 style="color:red;text-align:center;margin-top:50px;">Member not found</h2>');
}

// Ensure optional columns have safe defaults
$m['spiritual_education'] = $m['spiritual_education'] ?? '';
$m['age_group'] = $m['age_group'] ?? '';

// Helper for image paths
function fixPath($path, $memberId = 0) {
    if (empty($path)) return '';
    if (strpos($path, 'http') === 0) return $path;
    $path = ltrim($path, '/');
    if (strpos($path, 'admin/') === 0) {
        $path = substr($path, 6);
    }
    if (
        (strpos($path, 'private://members/photos/') === 0
            || preg_match('#^uploads/members/photos/#', $path))
        && (int)$memberId > 0
    ) {
        return '/admin/photo_file.php?member_id=' . (int)$memberId;
    }
    if (strpos($path, 'uploads/') === 0) return '/admin/' . $path;
    return '/' . $path;
}

// Ethiopian month names
$ethMonths = [1=>'መስከረም',2=>'ጥቅምት',3=>'ኅዳር',4=>'ታኅሣሥ',5=>'ጥር',6=>'የካቲት',7=>'መጋቢት',8=>'ሚያዝያ',9=>'ግንቦት',10=>'ሰኔ',11=>'ሐምሌ',12=>'ነሐሴ',13=>'ጳጉሜ'];

// Date of Birth Display
$dobDisplay = '—';
if ($m['dob_ec_day'] && $m['dob_ec_month'] && $m['dob_ec_year']) {
    $dobDisplay = $m['dob_ec_day'] . ' ' . ($ethMonths[(int)$m['dob_ec_month']] ?? '') . ' ' . $m['dob_ec_year'] . ' ዓ.ም';
}

// Current Ethiopian date
$now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$printDate = ethio_date_format($now, 'F j, Y');
$currentYear = (int)ethio_date_format($now, 'Y');
$currentMonth = (int)ethio_date_format($now, 'n');

// Full name
$fullName = trim($m['student_name'] . ' ' . $m['father_name'] . ' ' . $m['grandfather_name']);

// Calculate membership duration
$membershipDuration = '—';
$registeredAt = $m['registered_at'] ?? null;

if ($registeredAt) {
    try {
        $regDate = new DateTime($registeredAt);
        $interval = $now->diff($regDate);
        
        $years = $interval->y;
        $months = $interval->m;
        $days = $interval->d;
        
        if ($years > 0 && $months > 0) {
            $membershipDuration = $years . ' ዓመት ' . $months . ' ወር';
        } elseif ($years > 0) {
            $membershipDuration = $years . ' ዓመት';
        } elseif ($months > 0) {
            $membershipDuration = $months . ' ወር';
        } elseif ($days > 0) {
            $membershipDuration = $days . ' ቀን';
        } else {
            $membershipDuration = 'አዲስ አባል';
        }
    } catch (Exception $e) {
        $membershipDuration = '—';
    }
}

// Format registration date in Ethiopian
$regDateDisplay = '—';
if ($registeredAt) {
    try {
        $regDateTime = new DateTime($registeredAt);
        $regDateDisplay = ethio_date_format($regDateTime, 'F j, Y');
    } catch (Exception $e) {
        $regDateDisplay = $registeredAt;
    }
}

// Roles list
$rolesList = [];
if (!empty($m['is_teacher'])) $rolesList[] = 'መምህር';
if (!empty($m['is_staff'])) $rolesList[] = 'ሠራተኛ';
if (!empty($m['is_committee'])) $rolesList[] = 'ኮሚቴ';
if (!empty($m['is_volunteer'])) $rolesList[] = 'በጎ ፈቃደኛ';
$rolesDisplay = !empty($rolesList) ? implode('፣ ', $rolesList) : '—';

// M2: one canonical label source for both education vocabularies; unknown
// (imported) text falls back to the stored value verbatim — never rewritten.
require_once __DIR__ . '/backend/services/EducationLabels.php';
$spiritualEduDisplay = \App\Services\EducationLabels::spiritual($m['spiritual_education'] ?? null);
$eduDisplay = \App\Services\EducationLabels::education($m['education_level'] ?? null);

// Full address builder
$addressParts = [];
if (!empty($m['city'])) $addressParts[] = $m['city'];
if (!empty($m['sub_city'])) $addressParts[] = $m['sub_city'];
if (!empty($m['woreda'])) $addressParts[] = 'ወረዳ ' . $m['woreda'];
if (!empty($m['mender'])) $addressParts[] = 'መንደር ' . $m['mender'];
if (!empty($m['block_number'])) $addressParts[] = 'ብሎክ ' . $m['block_number'];
if (!empty($m['house_number'])) $addressParts[] = 'ቤት ቁ. ' . $m['house_number'];
$fullAddress = !empty($addressParts) ? implode('፣ ', $addressParts) : '—';
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>የአባል መረጃ - <?= e($m['student_name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <style>
        @page {
            size: A4 portrait;
            margin: 0;
        }
        
        * { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0; 
        }
        
        body {
            font-family: 'Segoe UI', 'Nyala', Arial, sans-serif;
            background: #64748b;
            padding: 15px;
            line-height: 1.4;
        }
        
        /* Print Button */
        .no-print {
            max-width: 210mm;
            margin: 0 auto 15px;
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 28px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-green {
            background: linear-gradient(135deg, #059669, #10b981);
            color: #fff;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
        
        .btn-green:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.5);
        }
        
        .btn-white {
            background: #fff;
            color: #475569;
        }
        
        .btn-white:hover {
            background: #f1f5f9;
        }
        
        /* A4 Page - Exactly 297mm height */
        .a4 {
            width: 210mm;
            height: 297mm;
            padding: 12mm 15mm;
            margin: 0 auto;
            background: #fff;
            box-shadow: 0 4px 25px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            position: relative;
        }
        
        /* Header */
        .header {
            text-align: center;
            padding-bottom: 10px;
            border-bottom: 3px solid #059669;
            margin-bottom: 12px;
        }
        
        .header-top {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 6px;
        }
        
        .logo {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #059669, #10b981);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 18px;
            font-weight: 700;
            flex-shrink: 0;
        }
        
        .org-info h1 {
            font-size: 16px;
            color: #059669;
            font-weight: 700;
            line-height: 1.3;
        }
        
        .org-info p {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }
        
        /* Title Bar */
        .title-bar {
            background: linear-gradient(135deg, #059669, #10b981);
            color: #fff;
            text-align: center;
            padding: 10px 20px;
            border-radius: 8px;
            margin-bottom: 12px;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 1px;
        }
        
        /* Main Content Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 95px 1fr;
            gap: 15px;
            margin-bottom: 12px;
        }
        
        /* Photo Section */
        .photo-section {
            text-align: center;
        }
        
        .photo-box {
            width: 90px;
            height: 115px;
            border: 2px solid #059669;
            border-radius: 6px;
            overflow: hidden;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
        }
        
        .photo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .photo-box i {
            font-size: 36px;
            color: #cbd5e1;
        }
        
        .member-id-box {
            background: #059669;
            color: #fff;
            padding: 6px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
        }
        
        .member-id-box .id-num {
            font-size: 12px;
            display: block;
            margin-top: 2px;
        }
        
        /* Info Section */
        .info-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        
        .info-box {
            background: #f8fafc;
            border-radius: 6px;
            padding: 8px 10px;
            border-left: 3px solid #10b981;
        }
        
        .info-box.full {
            grid-column: 1 / -1;
        }
        
        .info-label {
            font-size: 9px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
            font-weight: 600;
        }
        
        .info-value {
            font-size: 12px;
            color: #1e293b;
            font-weight: 600;
        }
        
        .info-value.large {
            font-size: 14px;
        }
        
        /* Sections */
        .section {
            margin-bottom: 10px;
        }
        
        .section-title {
            font-size: 12px;
            font-weight: 700;
            color: #059669;
            padding: 6px 10px;
            background: linear-gradient(to right, #ecfdf5, #fff);
            border-left: 3px solid #059669;
            border-radius: 0 6px 6px 0;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title i {
            font-size: 11px;
        }
        
        /* Two Column Table */
        .two-col-table {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        
        .col-table {
            width: 100%;
        }
        
        .col-table td {
            padding: 6px 8px;
            font-size: 11px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .col-table td:first-child {
            color: #64748b;
            font-weight: 500;
            width: 40%;
        }
        
        .col-table td:last-child {
            color: #1e293b;
            font-weight: 600;
        }
        
        .col-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Status Section */
        .status-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }
        
        .status-item {
            text-align: center;
            padding: 8px 6px;
            background: #f8fafc;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        
        .status-item.highlight {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border-color: #a7f3d0;
        }
        
        .status-item .label {
            font-size: 9px;
            color: #64748b;
            margin-bottom: 3px;
        }
        
        .status-item .value {
            font-size: 11px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .status-item.highlight .value {
            color: #059669;
        }
        
        /* Signatures */
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 15px;
            padding-top: 10px;
        }
        
        .sig-box {
            text-align: center;
        }
        
        .sig-line {
            border-top: 1px solid #1e293b;
            margin-top: 35px;
            padding-top: 6px;
            font-size: 10px;
            color: #475569;
        }
        
        /* Footer */
        .footer {
            position: absolute;
            bottom: 10mm;
            left: 15mm;
            right: 15mm;
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            color: #94a3b8;
            padding-top: 8px;
            border-top: 1px solid #e2e8f0;
        }
        
        /* Print Styles */
        @media print {
            body {
                background: #fff;
                padding: 0;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            .a4 {
                width: 210mm;
                height: 297mm;
                box-shadow: none;
                margin: 0;
                padding: 10mm 12mm;
            }
        }
    </style>
<link rel="stylesheet" href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>">
</head>
<body>
    <!-- Print Controls -->
    <div class="no-print">
        <button class="btn btn-green" onclick="window.print()">
            <i class="fas fa-print"></i> Print / Save PDF
        </button>
        <button class="btn btn-white" onclick="window.close()">
            <i class="fas fa-times"></i> Close
        </button>
    </div>

    <!-- A4 Page -->
    <div class="a4">
        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="logo"><?= ADMIN_LOGO_ICON ?></div>
                <div class="org-info">
                    <h1><?= PARISH_NAME_AM ?></h1>
                    <h1><?= SCHOOL_NAME_AMHARIC ?></h1>
                    <p><?= PARISH_NAME_EN ?> • <?= SCHOOL_TRANSLATION_EN ?> <?= SCHOOL_TYPE ?></p>
                </div>
            </div>
        </div>

        <!-- Title Bar -->
        <div class="title-bar">
            <i class="fas fa-user-circle"></i> የአባል መረጃ
        </div>

        <!-- Main Content: Photo + Basic Info -->
        <div class="main-grid">
            <!-- Photo -->
            <div class="photo-section">
                <div class="photo-box">
                    <?php if (!empty($m['student_photo_path'])): ?>
                        <img src="<?= fixPath($m['student_photo_path'], $m['id']) ?>" alt="Photo">
                    <?php else: ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                </div>
                <div class="member-id-box">
                    የአባልነት ቁጥር
                    <span class="id-num"><?= trim((string)$m['member_code']) !== '' ? \App\Services\MemberCodeFormat::html($m['member_code']) : 'በመጠባበቅ' ?></span>
                </div>
            </div>

            <!-- Basic Info Grid -->
            <div class="info-section">
                <div class="info-box full">
                    <div class="info-label">ሙሉ ስም / Full Name</div>
                    <div class="info-value large"><?= e($fullName) ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">ስመ ጥምቀት</div>
                    <div class="info-value"><?= esc($m['baptismal_name'], '—') ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">ፆታ / Gender</div>
                    <div class="info-value"><?= $m['gender'] === 'male' ? 'ወንድ' : 'ሴት' ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">የትውልድ ቀን</div>
                    <div class="info-value"><?= $dobDisplay ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">ዕድሜ / Age</div>
                    <div class="info-value"><?= $m['age'] ? $m['age'] . ' ዓመት' : '—' ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">ክፍል / Section</div>
                    <div class="info-value"><?= esc($m['current_section'], '—') ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">የትምህርት ደረጃ</div>
                    <div class="info-value"><?= e($eduDisplay) ?></div>
                </div>
                <div class="info-box" style="background: linear-gradient(135deg, #ecfdf5, #d1fae5); border-color: #10b981;">
                    <div class="info-label" style="color: #059669;">✝ የመንፈሳዊ ትምህርት</div>
                    <div class="info-value" style="color: #059669; font-weight: 700;"><?= e($spiritualEduDisplay) ?></div>
                </div>
            </div>
        </div>

        <!-- Contact & Address -->
        <div class="section">
            <div class="section-title"><i class="fas fa-phone"></i> የግንኙነት መረጃ እና አድራሻ</div>
            <div class="two-col-table">
                <table class="col-table">
                    <tr><td>ስልክ ቁጥር</td><td><?= esc($m['phone_number'], '—') ?></td></tr>
                    <tr><td>ተጨማሪ ስልክ</td><td><?= esc($m['alt_phone_number'], '—') ?></td></tr>
                    <tr><td>የአሳዳጊ ስም</td><td><?= esc($m['guardian_name'], '—') ?></td></tr>
                    <tr><td>የአሳዳጊ ስልክ</td><td><?= esc($m['guardian_phone1'], '—') ?></td></tr>
                </table>
                <table class="col-table">
                    <tr><td>ከተማ / City</td><td><?= esc($m['city'], '—') ?></td></tr>
                    <tr><td>ክፍለ ከተማ / Sub City</td><td><?= esc($m['sub_city'], '—') ?></td></tr>
                    <tr><td>ወረዳ / Woreda</td><td><?= esc($m['woreda'], '—') ?></td></tr>
                    <tr><td>መንደር / Mender</td><td><?= esc($m['mender'], '—') ?></td></tr>
                    <tr><td>ብሎክ / Block</td><td><?= esc($m['block_number'], '—') ?></td></tr>
                    <tr><td>የቤት ቁጥር / House No.</td><td><?= esc($m['house_number'], '—') ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Membership Status -->
        <div class="section">
            <div class="section-title"><i class="fas fa-id-badge"></i> የአባልነት ሁኔታ</div>
            <div class="status-grid">
                <div class="status-item">
                    <div class="label">የምዝገባ ዓይነት</div>
                    <div class="value"><?= $m['registration_type'] === 'waiting' ? 'በመጠባበቅ' : ($m['registration_type'] === 'transfer' ? 'ዝውውር' : 'ቀጥታ') ?></div>
                </div>
                <div class="status-item">
                    <div class="label">የአባልነት ዓይነት</div>
                    <div class="value"><?= esc(\App\Services\MemberTypeService::labelAm($conn, (string)$m['member_type'])) ?></div>
                </div>
                <div class="status-item highlight">
                    <div class="label">ሁኔታ / Status</div>
                    <div class="value"><?= $m['status'] === 'active' ? 'ንቁ' : ($m['status'] === 'warning' ? 'ማስጠንቀቂያ' : ($m['status'] === 'inactive' ? 'ቦዝ' : 'መዝገብ')) ?></div>
                </div>
                <div class="status-item">
                    <div class="label">ሚና / Roles</div>
                    <div class="value"><?= $rolesDisplay ?></div>
                </div>
            </div>
        </div>

        <!-- Registration Info -->
        <div class="section">
            <div class="section-title"><i class="fas fa-calendar-check"></i> የምዝገባ መረጃ</div>
            <div class="status-grid">
                <div class="status-item" style="grid-column: span 2;">
                    <div class="label">የተመዘገበበት ቀን / Registration Date</div>
                    <div class="value"><?= $regDateDisplay ?></div>
                </div>
                <div class="status-item highlight" style="grid-column: span 2;">
                    <div class="label">የአባልነት ጊዜ / Membership Duration</div>
                    <div class="value" style="font-size: 13px;"><?= $membershipDuration ?></div>
                </div>
            </div>
        </div>

        <!-- Signatures -->
        <div class="signatures">
            <div class="sig-box">
                <div class="sig-line">የአባል / የአሳዳጊ ፊርማ</div>
            </div>
            <div class="sig-box">
                <div class="sig-line">የጽ/ቤት ማህተም እና ፊርማ</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div><i class="fas fa-calendar"></i> የታተመበት ቀን: <?= $printDate ?></div>
            <div><?= ADMIN_LOGO_ICON ?> <?= SCHOOL_NAME_SHORT_AM ?> <?= SCHOOL_TYPE_AM ?></div>
        </div>
    </div>

    <script>
        // Optional: Auto-print when page loads
        // window.onload = () => window.print();
    </script>
</body>
</html>
