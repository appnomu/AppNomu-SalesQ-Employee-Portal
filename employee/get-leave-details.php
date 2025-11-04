<?php
require_once __DIR__ . '/../config/session-security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Start secure session first
startSecureSession();
requireAuth();

// Ensure user is employee
if ($_SESSION['role'] !== 'employee') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

$userId = $_SESSION['user_id'];
$leaveId = intval($_GET['id'] ?? 0);

if ($leaveId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid leave request ID']);
    exit();
}

try {
    // Get leave request details - only for current employee
    $stmt = $db->prepare("
        SELECT lr.*, lt.name as leave_type_name,
               approver.employee_number as approver_number,
               approver_profile.first_name as approver_first_name,
               approver_profile.last_name as approver_last_name
        FROM leave_requests lr 
        JOIN leave_types lt ON lr.leave_type_id = lt.id
        LEFT JOIN users approver ON lr.approved_by = approver.id
        LEFT JOIN employee_profiles approver_profile ON approver.id = approver_profile.user_id
        WHERE lr.id = ? AND lr.employee_id = ?
    ");
    $stmt->execute([$leaveId, $userId]);
    $leave = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($leave) {
        echo json_encode([
            'success' => true,
            'leave' => $leave
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Leave request not found or access denied'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
