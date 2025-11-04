<?php
/**
 * API Endpoint: Get Employee Details
 */

require_once __DIR__ . '/../config/session-security.php';
header('Content-Type: application/json');

// Start secure session first
startSecureSession();

// Prevent any output before JSON
ob_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Clear any buffered output
ob_clean();

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid employee ID']);
    exit;
}

$employeeId = intval($_GET['id']);

try {
    // Get employee details with profile
    $stmt = $db->prepare("
        SELECT u.*, ep.first_name, ep.last_name, ep.middle_name, ep.date_of_birth, 
               ep.gender, ep.address, ep.department, ep.position, ep.hire_date, 
               ep.salary, ep.monthly_salary, ep.profile_picture, ep.emergency_contact_name, 
               ep.emergency_contact_phone, ep.bank_account_number, ep.bank_name
        FROM users u 
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
        WHERE u.id = ? AND u.role = 'employee'
    ");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
        exit;
    }
    
    // Format salary - use monthly_salary if available, otherwise salary
    $employee['salary'] = $employee['monthly_salary'] ?: $employee['salary'];
    
    // Format phone number
    if ($employee['phone']) {
        $employee['phone'] = formatPhoneNumber($employee['phone']);
    }
    
    // Format dates
    if ($employee['hire_date']) {
        $employee['hire_date'] = date('Y-m-d', strtotime($employee['hire_date']));
    }
    
    if ($employee['date_of_birth']) {
        $employee['date_of_birth'] = date('Y-m-d', strtotime($employee['date_of_birth']));
    }
    
    echo json_encode([
        'success' => true,
        'employee' => $employee
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
