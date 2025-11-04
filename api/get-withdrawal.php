<?php
require_once __DIR__ . '/../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start secure session first
startSecureSession();

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get withdrawal ID from request
$withdrawalId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($withdrawalId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid withdrawal ID']);
    exit();
}

try {
    // Get withdrawal details with employee info
    $stmt = $db->prepare("
        SELECT sw.*, 
               u.employee_number,
               u.email,
               u.phone, 
               ep.first_name, 
               ep.last_name, 
               ep.department,
               ep.position,
               ep.salary
        FROM salary_withdrawals sw 
        JOIN users u ON sw.employee_id = u.id 
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
        WHERE sw.id = ?
    ");
    
    $stmt->execute([$withdrawalId]);
    $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$withdrawal) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Withdrawal not found']);
        exit();
    }
    
    // Format the response
    $response = [
        'success' => true,
        'withdrawal' => [
            'id' => $withdrawal['id'],
            'employee_id' => $withdrawal['employee_id'],
            'employee_number' => $withdrawal['employee_number'],
            'employee_name' => trim($withdrawal['first_name'] . ' ' . $withdrawal['last_name']),
            'department' => $withdrawal['department'],
            'position' => $withdrawal['position'],
            'email' => $withdrawal['email'],
            'phone' => $withdrawal['phone'],
            'salary' => $withdrawal['salary'],
            'amount' => $withdrawal['amount'],
            'charges' => $withdrawal['charges'] ?? 0,
            'net_amount' => $withdrawal['net_amount'] ?? ($withdrawal['amount'] - ($withdrawal['charges'] ?? 0)),
            'withdrawal_type' => $withdrawal['withdrawal_type'],
            'payment_method' => $withdrawal['payment_method'],
            'bank_name' => $withdrawal['bank_name'],
            'bank_account' => $withdrawal['bank_account'],
            'mobile_number' => $withdrawal['mobile_number'],
            'mobile_money_provider' => $withdrawal['mobile_money_provider'],
            'status' => $withdrawal['status'],
            'flutterwave_reference' => $withdrawal['flutterwave_reference'],
            'failure_reason' => $withdrawal['failure_reason'],
            'created_at' => $withdrawal['created_at'],
            'processed_at' => $withdrawal['processed_at'],
            'created_at_formatted' => date('M j, Y g:i A', strtotime($withdrawal['created_at'])),
            'processed_at_formatted' => $withdrawal['processed_at'] ? date('M j, Y g:i A', strtotime($withdrawal['processed_at'])) : null
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
