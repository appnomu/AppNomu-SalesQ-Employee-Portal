<?php
require_once __DIR__ . '/../config/session-security.php';

// Start secure session first
startSecureSession();

// Set JSON header
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// CSRF Protection for state-changing operations
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF token validation failed']);
    exit;
}

// Check admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required.']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['task_id']) || !is_numeric($input['task_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid task ID']);
    exit;
}

try {
    
    $taskId = intval($input['task_id']);
    $title = sanitizeInput($input['title']);
    $description = sanitizeInput($input['description']);
    $priority = sanitizeInput($input['priority']);
    $status = sanitizeInput($input['status']);
    $progressPercentage = isset($input['progress_percentage']) ? intval($input['progress_percentage']) : 0;
    $dueDate = sanitizeInput($input['due_date']);
    $estimatedHours = !empty($input['estimated_hours']) ? floatval($input['estimated_hours']) : null;
    $actualHours = !empty($input['actual_hours']) ? floatval($input['actual_hours']) : null;
    $notes = sanitizeInput($input['notes']);
    $comment = sanitizeInput($input['comment']);
    
    // Validate required fields
    if (empty($title) || empty($priority) || empty($status) || empty($dueDate)) {
        echo json_encode(['success' => false, 'message' => 'Required fields are missing']);
        exit;
    }
    
    // Validate priority and status values
    $validPriorities = ['low', 'medium', 'high', 'urgent'];
    $validStatuses = ['pending', 'in_progress', 'completed'];
    
    if (!in_array($priority, $validPriorities)) {
        echo json_encode(['success' => false, 'message' => 'Invalid priority value']);
        exit;
    }
    
    if (!in_array($status, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status value']);
        exit;
    }
    
    // Validate progress percentage
    if ($progressPercentage < 0 || $progressPercentage > 100) {
        echo json_encode(['success' => false, 'message' => 'Progress percentage must be between 0 and 100']);
        exit;
    }
    
    // Get current task data for comparison
    $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->execute([$taskId]);
    $currentTask = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentTask) {
        echo json_encode(['success' => false, 'message' => 'Task not found']);
        exit;
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Set completion date if status changed to completed
    $completionDate = null;
    if ($status === 'completed' && $currentTask['status'] !== 'completed') {
        $completionDate = date('Y-m-d H:i:s');
    } elseif ($status !== 'completed') {
        $completionDate = null; // Clear completion date if status changed from completed
    } else {
        $completionDate = $currentTask['completion_date']; // Keep existing completion date
    }
    
    // Note: start_date column doesn't exist in tasks table
    
    // Update task
    $stmt = $db->prepare("
        UPDATE tasks SET 
            title = ?, 
            description = ?, 
            priority = ?, 
            status = ?, 
            progress_percentage = ?, 
            due_date = ?, 
            estimated_hours = ?, 
            actual_hours = ?, 
            notes = ?, 
            completion_date = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    if (!$stmt) {
        throw new Exception('Failed to prepare update statement: ' . implode(', ', $db->errorInfo()));
    }
    
    $result = $stmt->execute([
        $title,
        $description,
        $priority,
        $status,
        $progressPercentage,
        $dueDate,
        $estimatedHours,
        $actualHours,
        $notes,
        $completionDate,
        $taskId
    ]);
    
    if (!$result) {
        throw new Exception('Failed to execute update statement: ' . implode(', ', $stmt->errorInfo()));
    }
    
    // Add comment if provided
    if (!empty($comment)) {
        $stmt = $db->prepare("
            INSERT INTO task_comments (task_id, user_id, comment, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$taskId, $_SESSION['user_id'], $comment]);
    }
    
    // Log activity
    $activityDescription = "Task '{$title}' updated by admin";
    if ($currentTask['status'] !== $status) {
        $activityDescription .= " - Status changed from '{$currentTask['status']}' to '{$status}'";
    }
    if ($currentTask['progress_percentage'] != $progressPercentage) {
        $activityDescription .= " - Progress updated to {$progressPercentage}%";
    }
    
    logActivity($_SESSION['user_id'], 'task_update', $activityDescription, [
        'task_id' => $taskId,
        'old_status' => $currentTask['status'],
        'new_status' => $status,
        'old_progress' => $currentTask['progress_percentage'],
        'new_progress' => $progressPercentage
    ]);
    
    // Commit transaction
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Task updated successfully',
        'task_id' => $taskId
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollback();
    }
    
    error_log("Update task error: " . $e->getMessage());
    error_log("Task ID: $taskId, Status: $status, Progress: $progressPercentage");
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while updating the task: ' . $e->getMessage()]);
}
?>
