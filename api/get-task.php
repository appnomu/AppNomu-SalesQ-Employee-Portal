<?php
require_once __DIR__ . '/../config/session-security.php';

// Start secure session first
startSecureSession();

// Set JSON header
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Debug session info (remove in production)
error_log('Session ID: ' . session_id());
error_log('Session data: ' . print_r($_SESSION, true));
error_log('Cookie data: ' . print_r($_COOKIE, true));

// Check user authentication
if (!isset($_SESSION['user_id'])) {
    error_log('Session user_id not found. Session status: ' . session_status());
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Please login.']);
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid task ID']);
    exit;
}

try {
    require_once __DIR__ . '/../config/database.php';
    
    $taskId = intval($_GET['id']);
    
    // Build query based on user role
    $whereClause = "WHERE t.id = ?";
    $params = [$taskId];
    
    // If employee, only allow access to their own tasks
    if ($_SESSION['role'] === 'employee') {
        $whereClause .= " AND t.assigned_to = ?";
        $params[] = $_SESSION['user_id'];
    }
    
    $stmt = $db->prepare("
        SELECT t.*, 
               assignee.employee_number as assignee_number,
               assignee_profile.first_name as assignee_first_name,
               assignee_profile.last_name as assignee_last_name,
               assignee_profile.department as assignee_department,
               assignee_profile.position as assignee_position,
               assigner.employee_number as assigner_number,
               assigner_profile.first_name as assigner_first_name,
               assigner_profile.last_name as assigner_last_name
        FROM tasks t 
        JOIN users assignee ON t.assigned_to = assignee.id 
        LEFT JOIN employee_profiles assignee_profile ON assignee.id = assignee_profile.user_id 
        JOIN users assigner ON t.assigned_by = assigner.id 
        LEFT JOIN employee_profiles assigner_profile ON assigner.id = assigner_profile.user_id 
        {$whereClause}
    ");
    $stmt->execute($params);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Task not found']);
        exit;
    }
    
    // Get task comments
    $stmt = $db->prepare("
        SELECT tc.*, u.employee_number, ep.first_name, ep.last_name
        FROM task_comments tc
        JOIN users u ON tc.user_id = u.id
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id
        WHERE tc.task_id = ?
        ORDER BY tc.created_at DESC
    ");
    $stmt->execute([$taskId]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $task['comments'] = $comments;
    
    echo json_encode([
        'success' => true,
        'task' => $task
    ]);
    
} catch (Exception $e) {
    error_log("Get task error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
?>
