<?php
// FILE: /admin/id_cards/view_id_card.php

// TEMPORARY DEBUG: add &debug=1 to the URL to see the actual error
if (!empty($_GET['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/libs/eth_date_helper.php';
require_once __DIR__ . '/../backend/services/IdCardLayout.php';

// SECURITY (IDOR fix): this page renders member PII (photo, name, phone,
// address, guardian). It used to rely on nothing but obscurity — anyone
// could sweep ?member_id=1..N. Authenticated admin session is mandatory,
// mirroring the dashboard router convention.
if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

$member_id = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;
$backDashboard = match ((string)($_SESSION['admin_role'] ?? '')) {
    'super_admin' => '../dashboards/super-admin.php',
    'school_admin' => '../dashboards/school_admin.php',
    default => '../dashboards/hr-dept.php',
};
$stmt = $conn->prepare("SELECT * FROM members WHERE id = ?");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

if (!$member) die("Member not found");

// ASSETS — Load from system_branding table (managed via Super Admin > Branding)
$CONFIG = [
    'logo'      => '/admin/id_cards/assets/logos/school_logo.png',
    'seal'      => '',
    'sig_head'  => '',
    'sig_admin' => '',
];
$assetOnDisk = static function (string $web): bool {
    if ($web === '' || $web[0] !== '/') {
        return false;
    }
    $root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)), '/');
    foreach ([$root . $web, dirname(__DIR__, 2) . $web] as $disk) {
        if (is_file($disk) && filesize($disk) > 32) {
            return true;
        }
    }
    return false;
};
$DISPLAY = [
    'logo_size' => 100, 'logo_opacity' => 100,
    'seal_size' => 150, 'seal_opacity' => 85,
    'sig_head_size' => 140, 'sig_head_opacity' => 90,
    'sig_admin_size' => 140, 'sig_admin_opacity' => 90,
];

// Branding storage is deployment-managed by migration 012. Defaults remain
// available if a transient query fails.
if ($conn && !$conn->connect_error) {
    try {
        $br = $conn->query("SELECT asset_key, file_path, original_name FROM system_branding");
        if ($br) {
            while ($row = $br->fetch_assoc()) {
                if ($row['asset_key'] === '_id_card_settings' && !empty($row['original_name'])) {
                    $saved = json_decode($row['original_name'], true);
                    if (is_array($saved)) {
                        $allowedKeys = ['logo_size','logo_opacity','seal_size','seal_opacity',
                                        'sig_head_size','sig_head_opacity','sig_admin_size','sig_admin_opacity'];
                        foreach ($saved as $k => $v) {
                            if (in_array($k, $allowedKeys, true) && is_numeric($v)) {
                                $DISPLAY[$k] = max(0, min(1000, (int)$v));
                            }
                        }
                    }
                } elseif (isset($CONFIG[$row['asset_key']]) && !empty($row['file_path']) && $assetOnDisk($row['file_path'])) {
                    $CONFIG[$row['asset_key']] = $row['file_path'];
                }
            }
        }
    } catch (Throwable $error) {
        error_log('ID card branding lookup failed.');
    }
}

$layout = \App\Services\IdCardLayout::load($conn);
$idCardBg = \App\Services\IdCardLayout::background($conn);
$ID_CARD_STYLE = \App\Services\IdCardLayout::cssVars($layout, $idCardBg);
$ID_CARD_LAYOUT = $layout;
$DISPLAY['logo_size'] = (int)$layout['logo_size'];
$DISPLAY['logo_opacity'] = (int)$layout['logo_opacity'];
$DISPLAY['seal_size'] = (int)$layout['seal_size'];
$DISPLAY['seal_opacity'] = (int)$layout['seal_opacity'];
$DISPLAY['sig_head_size'] = (int)$layout['sig_head_size'];
$DISPLAY['sig_head_opacity'] = (int)$layout['sig_head_opacity'];
$DISPLAY['sig_admin_size'] = (int)$layout['sig_admin_size'];
$DISPLAY['sig_admin_opacity'] = (int)$layout['sig_admin_opacity'];

// --- ETHIOPIAN DATES ---
$issueDateGregorian = $member['id_card_generated_at'] ?? date('Y-m-d');
$issueDateEth = toEthiopianDate($issueDateGregorian);
$expiryDateGregorian = date('Y-m-d', strtotime($issueDateGregorian . ' + 4 years'));
$expiryDateEth = toEthiopianDate($expiryDateGregorian);
$isExpired = isExpired($member['id_card_generated_at']);

// DATA PREP
$full_name = ($member['student_name'] ?? '') . ' ' . ($member['father_name'] ?? '') . ' ' . ($member['grandfather_name'] ?? '');
$christian_name = !empty($member['baptismal_name']) ? $member['baptismal_name'] : '-----------';

// Age Calculation
// Calculate current Ethiopian year dynamically
// Ethiopian new year (Meskerem 1) falls on Sep 11 (or Sep 12 in leap years)
$gcYear = (int)date('Y');
$gcMonth = (int)date('n');
$gcDay = (int)date('j');
// Before Sep 11, Ethiopian year = Gregorian year - 8; after Sep 11, it's Gregorian year - 7
if ($gcMonth < 9 || ($gcMonth === 9 && $gcDay < 11)) {
    $currentYearEth = $gcYear - 8;
} else {
    $currentYearEth = $gcYear - 7;
}
if (!empty($member['dob_ec_year'])) {
    $age = $currentYearEth - $member['dob_ec_year'];
} elseif (!empty($member['date_of_birth'])) {
    $age = date('Y') - date('Y', strtotime($member['date_of_birth']));
} else {
    $age = '--';
}

$filename = "ID_" . $member['member_code'];

// Address Construction
$address_parts = [];
if (!empty($member['city'])) $address_parts[] = $member['city'];
if (!empty($member['sub_city'])) $address_parts[] = $member['sub_city'];
if (!empty($member['woreda'])) $address_parts[] = 'Wor. ' . $member['woreda'];
if (!empty($member['house_number'])) $address_parts[] = 'H.No ' . $member['house_number'];

$member['address'] = !empty($address_parts) ? implode(', ', $address_parts) : ($member['address'] ?? '---');

// Serve the photo through the authenticated endpoint, including legacy files.
if (!empty($member['student_photo_path'])) {
    $member['student_photo_path'] = '/admin/photo_file.php?member_id=' . (int)$member['id'];
}

// Fix QR Code Path (same logic as photo path above)
// DB stores: /admin/id_cards/assets/qr/qr_{MEMBER_CODE_FORMAT}XXXX.png (absolute web path)
// Template needs: relative path from id_cards/ folder OR absolute URL
if (!empty($member['qr_code_path'])) {
    $qp = ltrim($member['qr_code_path'], '/');
    
    // If path starts with 'admin/id_cards/', make it relative (we ARE in id_cards/)
    if (strpos($qp, 'admin/id_cards/') === 0) {
        $qp = substr($qp, strlen('admin/id_cards/'));
    } elseif (strpos($qp, 'admin/') === 0) {
        $qp = '../' . substr($qp, 6);
    } else {
        $qp = '../' . $qp;
    }
    
    $member['qr_code_path'] = $qp;
    
    // BULLETPROOF: Check if the QR file actually exists on disk
    // If NOT, try to regenerate it right now
    $qr_absolute_check = __DIR__ . '/' . $qp;
    if (!file_exists($qr_absolute_check)) {
        // Try to regenerate QR code on-the-fly
        require_once __DIR__ . '/libs/qr_loader.php';
        if (class_exists('QRcode')) {
            $qr_dir = __DIR__ . '/assets/qr/';
            if (!is_dir($qr_dir)) mkdir($qr_dir, 0755, true); // 0755, never world-writable
            $qr_content = rtrim((string)SITE_URL, '/') . '/member.php?code=' . rawurlencode($member['member_code']);
            $qr_file = $qr_dir . 'qr_' . $member['member_code'] . '.png';
            QRcode::png($qr_content, $qr_file, QR_ECLEVEL_L, 4, 2);
            $member['qr_code_path'] = 'assets/qr/qr_' . $member['member_code'] . '.png';
        }
    }
} else {
    // QR path is empty in DB — member card was never generated properly
    // Try to generate QR now if library exists
    if (!empty($member['member_code'])) {
        require_once __DIR__ . '/libs/qr_loader.php';
        if (class_exists('QRcode')) {
            $qr_dir = __DIR__ . '/assets/qr/';
            if (!is_dir($qr_dir)) mkdir($qr_dir, 0755, true); // 0755, never world-writable
            $qr_content = rtrim((string)SITE_URL, '/') . '/member.php?code=' . rawurlencode($member['member_code']);
            $qr_file = $qr_dir . 'qr_' . $member['member_code'] . '.png';
            QRcode::png($qr_content, $qr_file, QR_ECLEVEL_L, 4, 2);
            $member['qr_code_path'] = 'assets/qr/qr_' . $member['member_code'] . '.png';
            
            // Also update DB so it's saved for next time
            $db_path = '/admin/id_cards/assets/qr/qr_' . $member['member_code'] . '.png';
            $update_stmt = $conn->prepare("UPDATE members SET qr_code_path = ? WHERE id = ?");
            $update_stmt->bind_param("si", $db_path, $member_id);
            $update_stmt->execute();
        }
    }
}

// Map DB columns to Template variables
$member['emergency_name'] = $member['guardian_name'] ?? '---';
$member['emergency_phone'] = $member['guardian_phone1'] ?? '---';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ID Card - <?php echo htmlspecialchars((string)$member['member_code'], ENT_QUOTES, 'UTF-8'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" integrity="sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;700;900&display=swap">
    <link rel="stylesheet" href="/admin/css/id_card.css?v=20260823c">
    <style>
        /* page chrome only — card look lives in id_card.css */
        
        body { background: #374151; font-family: 'Noto Sans Ethiopic', 'Noto Serif Ethiopic', sans-serif; }
        /* PREVIEW ON SCREEN (Scaled Down CSS) */
        .preview-wrapper {
            transform: scale(0.6);
            transform-origin: top center;
            margin-bottom: -180px;
        }
        .preview-wrapper .id-card-template { margin-bottom: 28px; }
        #export-container .id-card-template { margin-bottom: 0; }
        
        /* EXPORT CONTAINER (Hidden from user, but visible to code) */
        #export-container {
            position: fixed;
            left: -9999px; /* Move off screen */
            top: 0;
        }
    </style>
<link rel="stylesheet" href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>">
</head>
<body class="flex flex-col items-center p-6">

    <!-- TOP MENU -->
    <div class="fixed top-0 left-0 w-full bg-white shadow-lg p-3 flex justify-between items-center z-50">
        <div class="flex items-center gap-4">
            <a href="<?= e($backDashboard) ?>?section=idcards" class="text-gray-600 hover:text-black font-bold flex items-center gap-2">&larr; Back</a>
            <?php if($isExpired): ?>
                <div class="bg-red-100 text-red-700 px-3 py-1 rounded font-bold text-sm flex items-center gap-2">
                    <span class="w-2 h-2 bg-red-600 rounded-full animate-pulse"></span> EXPIRED
                </div>
            <?php else: ?>
                <div class="bg-green-100 text-green-700 px-3 py-1 rounded font-bold text-sm">
                    Active (Exp: <?php echo $expiryDateEth; ?>)
                </div>
            <?php endif; ?>
        </div>
        <div class="flex gap-2">
            <!-- RENEW BUTTON -->
            <form method="post" action="generate_id_card.php" id="dcRenewCardForm">
                <?= csrf_field() ?>
                <input type="hidden" name="member_id" value="<?= (int)$member_id ?>">
                <input type="hidden" name="action" value="renew">
                <button type="submit"
                        class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded shadow font-semibold flex items-center gap-2 text-sm">
                    <i class="fa-solid fa-rotate"></i> Update Date
                </button>
            </form>
            <button onclick="downloadPNG('front')" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded shadow font-semibold text-sm">PNG Front</button>
            <button onclick="downloadPNG('back')" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded shadow font-semibold text-sm">PNG Back</button>
            <button onclick="downloadPDF()" class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded shadow font-semibold text-sm">Download PDF</button>
        </div>
    </div>

    <div class="h-24"></div>

    <!-- PREVIEW AREA (What you see on screen) -->
    <div class="text-white font-bold mb-2">Card Preview (Front & Back)</div>
    <div class="preview-wrapper">
        <!-- We clone the layout here just for viewing -->
        <?php include 'id_card_template_layout.php'; ?>
    </div>


    <!-- EXPORT AREA (Hidden off-screen, Full Size for High Quality) -->
    <div id="export-container">
        <!-- This includes the exact same HTML but 100% scale -->
        <?php include 'id_card_template_layout.php'; ?>
    </div>

    <script>
        const memberCode = "<?php echo $filename; ?>";
        
        // Helper to get the correct element from the HIDDEN container
        function getExportElement(side) {
            const container = document.getElementById('export-container');
            const cards = container.querySelectorAll('.id-card-template');
            return side === 'front' ? cards[0] : cards[1];
        }

        function downloadPNG(side) {
            const element = getExportElement(side);
            html2canvas(element, {
                scale: 2,
                useCORS: true,
                allowTaint: false,
                backgroundColor: "#f3e6c4",
                logging: false,
                // Ensure the off-screen container is rendered at correct size
                width: 1011,
                height: 638,
                onclone: function(clonedDoc) {
                    // Ensure the cloned export container is visible for rendering
                    const container = clonedDoc.getElementById('export-container');
                    if (container) {
                        container.style.position = 'absolute';
                        container.style.left = '0';
                        container.style.top = '0';
                    }
                }
            }).then(canvas => {
                const link = document.createElement('a');
                link.download = `${memberCode}_${side}.png`;
                link.href = canvas.toDataURL("image/png");
                link.click();
            }).catch(err => {
                console.error('Export error:', err);
                alert('Download failed. If images are missing, ensure they are uploaded to the server.');
            });
        }

        async function downloadPDF() {
            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF('l', 'mm', [85.6, 54]); 
            
            const frontEl = getExportElement('front');
            const backEl = getExportElement('back');

            try {
                const opts = { scale: 2, useCORS: true, allowTaint: false, backgroundColor: "#f3e6c4", width: 1011, height: 638, logging: false };

                const frontCanvas = await html2canvas(frontEl, opts);
                pdf.addImage(frontCanvas.toDataURL('image/jpeg', 0.95), 'JPEG', 0, 0, 85.6, 54);
                
                pdf.addPage();
                const backCanvas = await html2canvas(backEl, opts);
                pdf.addImage(backCanvas.toDataURL('image/jpeg', 0.95), 'JPEG', 0, 0, 85.6, 54);
                
                pdf.save(`${memberCode}_printable.pdf`);
            } catch(err) {
                console.error('PDF export error:', err);
                alert('PDF download failed.');
            }
        }
    </script>
<script src="/admin/js/danger_confirm.js?v=20261022dc4"></script>
<script>
(function () {
    var f = document.getElementById('dcRenewCardForm');
    if (f && window.DangerConfirm && window.DangerConfirm.guardForm) {
        window.DangerConfirm.guardForm(f, { tier: 'medium', title: 'Renew this ID card?', summary: 'A renewed card replaces the current one.', intent: 'I want to renew this card', consequences: ['The issue date is reset to TODAY and a new expiry date is calculated.', 'The card that was printed before shows old dates; print and hand out the new card.'], keeps: ['The member code and QR code stay the same.', 'Member data is not changed.'], phrase: 'RENEW CARD', confirmLabel: 'Renew card' });
    } else if (f) {
        f.addEventListener('submit', function (e) { e.preventDefault(); alert('The confirmation dialog did not load. Reload the page and try again.'); });
    }
})();
</script>
</body>
</html>