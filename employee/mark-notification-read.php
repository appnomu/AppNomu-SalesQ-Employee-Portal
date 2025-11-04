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

// Handle AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    // Debug logging
    error_log("Raw input: " . $rawInput);
    error_log("Decoded input: " . print_r($input, true));
    
    $notificationId = $input['notification_id'] ?? null;
    $action = $input['action'] ?? 'mark_read';
    
    // Debug logging
    error_log("Notification ID: " . var_export($notificationId, true));
    error_log("Action: " . $action);
    
    if ($notificationId === null || ($action !== 'mark_all_read' && $notificationId <= 0)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Notification ID required',
            'debug' => [
                'notification_id' => $notificationId,
                'action' => $action,
                'raw_input' => $rawInput
            ]
        ]);
        exit();
    }
    
    try {
        if ($action === 'mark_read') {
            // Mark single notification as read
            $stmt = $db->prepare("
                UPDATE system_notifications 
                SET is_read = TRUE 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$notificationId, $userId]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Notification not found']);
            }
            
        } elseif ($action === 'mark_all_read') {
            // Mark all notifications as read for this user
            $stmt = $db->prepare("
                UPDATE system_notifications 
                SET is_read = TRUE 
                WHERE user_id = ? AND is_read = FALSE
            ");
            $stmt->execute([$userId]);
            
            echo json_encode(['success' => true, 'message' => 'All notifications marked as read', 'count' => $stmt->rowCount()]);
            
        } elseif ($action === 'delete') {
            // Delete notification
            $stmt = $db->prepare("
                DELETE FROM system_notifications 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$notificationId, $userId]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Notification deleted']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Notification not found']);
            }
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
