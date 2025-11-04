<?php
require_once __DIR__ . '/../config/session-security.php';

// Start secure session first
startSecureSession();

// Set JSON header
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Check admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required.']);
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid leave request ID']);
    exit;
}

try {
    require_once __DIR__ . '/../config/database.php';
    
    $requestId = intval($_GET['id']);
    
    $stmt = $db->prepare("
        SELECT lr.*, u.employee_number, ep.first_name, ep.last_name, ep.department, ep.position,
               lt.name as leave_type_name,
               approver.employee_number as approver_number,
               approver_profile.first_name as approver_first_name,
               approver_profile.last_name as approver_last_name
        FROM leave_requests lr 
        JOIN users u ON lr.employee_id = u.id 
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
        JOIN leave_types lt ON lr.leave_type_id = lt.id
        LEFT JOIN users approver ON lr.approved_by = approver.id
        LEFT JOIN employee_profiles approver_profile ON approver.id = approver_profile.user_id
        WHERE lr.id = ?
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Leave request not found']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'request' => $request
    ]);
    
} catch (Exception $e) {
    error_log("Get leave request error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
?>
