<?php
require_once __DIR__ . '/../config/session-security.php';

// Start secure session first
startSecureSession();

// Clear any output buffer to prevent HTML from leaking into JSON response
ob_start();
ob_clean();

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
require_once __DIR__ . '/../includes/infobip.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['ticket_id']) || !is_numeric($input['ticket_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid ticket ID']);
    exit;
}

$ticketId = intval($input['ticket_id']);
$response = sanitizeInput($input['response']);
$isInternal = isset($input['is_internal']) ? (bool)$input['is_internal'] : false;
$updateStatus = isset($input['update_status']) ? sanitizeInput($input['update_status']) : null;

if (empty($response)) {
    echo json_encode(['success' => false, 'message' => 'Response cannot be empty']);
    exit;
}

// Get ticket, employee, and admin details
$stmt = $db->prepare("
    SELECT t.*, u.email, u.phone, ep.first_name, ep.last_name 
    FROM tickets t 
    JOIN users u ON t.employee_id = u.id 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    WHERE t.id = ?
");
$stmt->execute([$ticketId]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    echo json_encode(['success' => false, 'message' => 'Ticket not found']);
    exit;
}

// Get admin details who is responding
$stmt = $db->prepare("
    SELECT ep.first_name, ep.last_name 
    FROM employee_profiles ep 
    JOIN users u ON ep.user_id = u.id 
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Start transaction
$db->beginTransaction();

// Insert response
$stmt = $db->prepare("
    INSERT INTO ticket_responses (ticket_id, user_id, response, is_internal, created_at) 
    VALUES (?, ?, ?, ?, NOW())
");
$stmt->execute([$ticketId, $_SESSION['user_id'], $response, $isInternal]);

// Update ticket status if provided
if ($updateStatus && in_array($updateStatus, ['open', 'in_progress', 'resolved', 'closed'])) {
    $resolvedAt = ($updateStatus === 'resolved' || $updateStatus === 'closed') ? date('Y-m-d H:i:s') : null;

    $stmt = $db->prepare("UPDATE tickets SET status = ?, resolved_at = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$updateStatus, $resolvedAt, $ticketId]);
}

// Update ticket's updated_at timestamp
$stmt = $db->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?");
$stmt->execute([$ticketId]);

// Send email notification to employee (only for non-internal responses)
$emailSent = false;
if (!$isInternal) {
    try {
        $subject = "Response to Your Support Ticket - " . strip_tags($ticket['subject']);
        $adminName = ($admin && $admin['first_name'] && $admin['last_name']) ? 
                    $admin['first_name'] . ' ' . $admin['last_name'] : 
                    'AppNomu Support Team';
        
        $message = "
            <h3>Support Ticket Update</h3>
            <p>Dear {$ticket['first_name']} {$ticket['last_name']},</p>
            <p>We have responded to your support ticket:</p>

            <div style='background-color: #f8f9fa; padding: 15px; border-left: 4px solid #007bff; margin: 20px 0;'>
                <h4>Ticket #{$ticketId}: {$ticket['subject']}</h4>
                <p><strong>Status:</strong> " . ucfirst(str_replace('_', ' ', $ticket['status'])) . "</p>
                <p><strong>Category:</strong> " . ucfirst($ticket['category']) . "</p>
            </div>

            <div style='background-color: #ffffff; padding: 15px; border: 1px solid #dee2e6; margin: 20px 0;'>
                <h5>Response:</h5>
                <p>" . nl2br(safeOutput($response)) . "</p>
            </div>

            <p>You can view the full ticket details and respond by logging into your employee portal.</p>
            <p>If you have any questions, please don't hesitate to contact us.</p>

            <p>Best regards,<br>{$adminName}</p>
        ";

        $emailResult = sendEmail($ticket['email'], $subject, $message);
        $emailSent = $emailResult['success'];

    } catch (Exception $e) {
        error_log("Email notification error: " . $e->getMessage());
    }
}

// Log activity
$activityDescription = $isInternal ? 
    "Internal note added to ticket #{$ticketId}" : 
    "Response sent to ticket #{$ticketId} - '{$ticket['subject']}'";

logActivity($_SESSION['user_id'], 'ticket_response', $activityDescription, [
    'ticket_id' => $ticketId,
    'is_internal' => $isInternal,
    'email_sent' => $emailSent
]);

// Commit transaction
$db->commit();

echo json_encode([
    'success' => true,
    'message' => $isInternal ? 'Internal note added successfully' : 'Response sent successfully',
    'email_sent' => $emailSent
]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollback();
    }
    
    error_log("Ticket response error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
?>
