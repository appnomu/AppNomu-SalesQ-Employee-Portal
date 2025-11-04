<?php
require_once __DIR__ . '/../config/session-security.php';

// Start secure session first
startSecureSession();

// Prevent any output
ob_start();
ob_clean();

// Set JSON header
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Check admin access
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

// Database connection
try {
    require_once __DIR__ . '/../config/database.php';
    
    $employeeId = intval($_GET['id']);
    
    $stmt = $db->prepare("
        SELECT u.*, ep.first_name, ep.last_name, ep.middle_name, ep.department, ep.position, 
               ep.hire_date, ep.salary, ep.monthly_salary, ep.date_of_birth, ep.gender, 
               ep.address, ep.profile_picture
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
    
    // Format data - prioritize monthly_salary, fallback to salary
    $finalSalary = null;
    if (!empty($employee['monthly_salary']) && $employee['monthly_salary'] > 0) {
        $finalSalary = $employee['monthly_salary'];
    } elseif (!empty($employee['salary']) && $employee['salary'] > 0) {
        $finalSalary = $employee['salary'];
    }
    
    $employee['salary'] = $finalSalary;
    
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
