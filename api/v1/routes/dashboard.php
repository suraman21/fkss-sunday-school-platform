<?php
/**
 * School API v1 — Dashboard Routes (FIXED v3)
 * 
 * Fixes:
 * - Handles teacher_assignments where academic_year_id IS NULL
 * - Teachers see stats scoped to their assigned classes
 */

$auth = apiRequireAuth();
$action = $ROUTE['id'] ?? '';

if (!apiRoleIs($auth, apiRolesMobileDashboard())) {
    err('Dashboard data is not available for this role.', 403);
}

// ============================================================
// GET /dashboard/stats — Role-aware dashboard stats
// ============================================================
if ($action === 'stats' && $method === 'GET') {
    $userId = $auth['uid'];
    $userRole = $auth['rol'];
    $year = getCurrentAcademicYear();
    $yearId = $year ? (int)$year['id'] : 0;
    $today = date('Y-m-d');
    $data = [];
    
    $restrictedRoles = ['teacher', 'attendance_taker'];
    $isRestricted = in_array($userRole, $restrictedRoles);
    
    // --------------------------------------------------
    // 1. Get class IDs this user can see
    // --------------------------------------------------
    $classIds = [];
    
    if ($isRestricted) {
        try {
            $stmt = $conn->prepare("SELECT class_id FROM teacher_assignments 
                                    WHERE teacher_id = ? 
                                    AND (academic_year_id IS NULL OR academic_year_id = ?)
                                    AND (status = 'active' OR is_active = 1)");
            $stmt->bind_param('ii', $userId, $yearId);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $classIds[] = (int)$row['class_id'];
            }
            $stmt->close();
        } catch (Exception $e) {}
    }
    
    // --------------------------------------------------
    // 2. Member counts
    // --------------------------------------------------
    if ($isRestricted && !empty($classIds)) {
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $types = str_repeat('i', count($classIds));
        
        try {
            $sql = "SELECT 
                        COUNT(DISTINCT m.id) as total,
                        COUNT(DISTINCT CASE WHEN m.status = 'active' THEN m.id END) as active,
                        COUNT(DISTINCT CASE WHEN m.gender = 'male' THEN m.id END) as male,
                        COUNT(DISTINCT CASE WHEN m.gender = 'female' THEN m.id END) as female
                    FROM class_enrollments ce
                    JOIN members m ON ce.member_id = m.id
                    WHERE ce.class_id IN ({$placeholders})
                      AND ce.status = 'active'"
                    . ($year ? " AND ce.academic_year_id = {$yearId}" : "");
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$classIds);
            $stmt->execute();
            $data['members'] = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Exception $e) {
            $data['members'] = ['total' => 0, 'active' => 0, 'male' => 0, 'female' => 0];
        }
        
    } elseif ($isRestricted) {
        $data['members'] = ['total' => 0, 'active' => 0, 'male' => 0, 'female' => 0];
        
    } else {
        try {
            $r = $conn->query("SELECT COUNT(*) as total,
                                      SUM(status='active') as active,
                                      SUM(gender='male') as male,
                                      SUM(gender='female') as female
                               FROM members WHERE status != 'archived'");
            $data['members'] = $r ? $r->fetch_assoc() : ['total'=>0,'active'=>0,'male'=>0,'female'=>0];
        } catch (Exception $e) {
            $data['members'] = ['total' => 0, 'active' => 0, 'male' => 0, 'female' => 0];
        }
    }
    
    foreach (['total', 'active', 'male', 'female'] as $k) {
        $data['members'][$k] = (int)($data['members'][$k] ?? 0);
    }
    
    // --------------------------------------------------
    // 3. Class count
    // --------------------------------------------------
    if ($isRestricted) {
        $data['classes_count'] = count($classIds);
    } else {
        try {
            $r = $conn->query("SELECT COUNT(*) as cnt FROM classes WHERE is_active = 1");
            $data['classes_count'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;
        } catch (Exception $e) { $data['classes_count'] = 0; }
    }
    
    // --------------------------------------------------
    // 4. Today's attendance
    // --------------------------------------------------
    if (!\App\Services\FeatureGate::isEnabled('attendance')) {
        $data['today_attendance'] = ['recorded'=>0,'present'=>0,'absent'=>0,'late'=>0];
    } elseif ($isRestricted && !empty($classIds)) {
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $types = str_repeat('i', count($classIds));
        
        try {
            $sql = "SELECT 
                        COUNT(*) as recorded,
                        SUM(status='present') as present,
                        SUM(status='absent') as absent,
                        SUM(status='late') as late
                    FROM attendance 
                    WHERE class_id IN ({$placeholders}) AND attendance_date = ?";
            $stmt = $conn->prepare($sql);
            $params = array_merge($classIds, [$today]);
            $types .= 's';
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $att = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $data['today_attendance'] = [
                'recorded' => (int)($att['recorded'] ?? 0),
                'present'  => (int)($att['present'] ?? 0),
                'absent'   => (int)($att['absent'] ?? 0),
                'late'     => (int)($att['late'] ?? 0),
            ];
        } catch (Exception $e) {
            $data['today_attendance'] = ['recorded'=>0,'present'=>0,'absent'=>0,'late'=>0];
        }
    } elseif ($isRestricted) {
        $data['today_attendance'] = ['recorded'=>0,'present'=>0,'absent'=>0,'late'=>0];
    } else {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) as recorded,
                                           SUM(status='present') as present,
                                           SUM(status='absent') as absent,
                                           SUM(status='late') as late
                                    FROM attendance WHERE attendance_date = ?");
            $stmt->bind_param('s', $today);
            $stmt->execute();
            $att = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $data['today_attendance'] = [
                'recorded' => (int)($att['recorded'] ?? 0),
                'present'  => (int)($att['present'] ?? 0),
                'absent'   => (int)($att['absent'] ?? 0),
                'late'     => (int)($att['late'] ?? 0),
            ];
        } catch (Exception $e) {
            $data['today_attendance'] = ['recorded'=>0,'present'=>0,'absent'=>0,'late'=>0];
        }
    }
    
    // --------------------------------------------------
    // 5. Users count (admin only)
    // --------------------------------------------------
    if (!$isRestricted) {
        try {
            $r = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE is_active = 1");
            $data['users_count'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;
        } catch (Exception $e) { $data['users_count'] = 0; }
    }
    
    // --------------------------------------------------
    // 6. Recent registrations (admin only)
    // --------------------------------------------------
    if (!$isRestricted) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM members WHERE created_at >= DATE_SUB(?, INTERVAL 7 DAY)");
            $stmt->bind_param('s', $today);
            $stmt->execute();
            $r = $stmt->get_result();
            $data['recent_registrations'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;
        } catch (Exception $e) { $data['recent_registrations'] = 0; }
    }
    
    // 1.6.5: current semester of the active year — the teacher dashboard's
    // hero box shows it ("now running: 1ኛ ሴሚስተር"), cached with the stats.
    $currentTerm = null;
    try {
        if (class_exists('\\App\\Services\\SubmissionService')) {
            $currentTerm = \App\Services\SubmissionService::currentTermOfActiveYear($conn);
        }
    } catch (Exception $eTerm) { $currentTerm = null; }

    ok([
        'stats' => $data,
        'current_term' => $currentTerm,
        'features' => \App\Services\FeatureGate::mobileCapabilities(),
        'server_time' => date('c'),
        'role' => $userRole
    ]);
}

// ============================================================
// GET /dashboard/recent — Recent activity feed
// ============================================================
if ($action === 'recent' && $method === 'GET') {
    $limit = min(50, max(5, (int)($_GET['limit'] ?? 20)));
    $userId = $auth['uid'];
    $userRole = $auth['rol'];
    
    $items = [];
    
    try {
        if (in_array($userRole, ['teacher', 'attendance_taker'])) {
            $stmt = $conn->prepare("SELECT username, action, details, created_at 
                                    FROM activity_logs 
                                    WHERE user_id = ?
                                    ORDER BY created_at DESC LIMIT ?");
            $stmt->bind_param('ii', $userId, $limit);
        } else {
            $stmt = $conn->prepare("SELECT username, action, details, created_at 
                                    FROM activity_logs 
                                    ORDER BY created_at DESC LIMIT ?");
            $stmt->bind_param('i', $limit);
        }
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $items[] = [
                'type' => 'activity',
                'action' => $row['action'] ?? '',
                'detail' => $row['details'] ?? '',
                'username' => $row['username'] ?? '',
                'created_at' => $row['created_at'] ?? '',
            ];
        }
        $stmt->close();
    } catch (Exception $e) {}
    
    ok(['items' => $items, 'recent_activity' => $items]);
}

err("Unknown dashboard action: {$action}. Use: stats, recent", 404);
