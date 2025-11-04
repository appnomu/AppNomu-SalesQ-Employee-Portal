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
    echo json_encode(['success' => false, 'message' => 'Invalid ticket ID']);
    exit;
}

try {
    require_once __DIR__ . '/../config/database.php';
    
    $ticketId = intval($_GET['id']);
    
    // Get ticket details with employee and assigned admin info
    $stmt = $db->prepare("
        SELECT t.*, 
               u.employee_number, u.email as employee_email, u.phone as employee_phone,
               ep.first_name, ep.last_name, ep.department, ep.position,
               au.employee_number as assigned_employee_number,
               aep.first_name as assigned_first_name,
               aep.last_name as assigned_last_name
        FROM tickets t 
        JOIN users u ON t.employee_id = u.id 
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
        LEFT JOIN users au ON t.assigned_to = au.id
        LEFT JOIN employee_profiles aep ON au.id = aep.user_id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ticket not found']);
        exit;
    }
    
    // Get ticket responses
    $stmt = $db->prepare("
        SELECT tr.*, u.employee_number, ep.first_name, ep.last_name, u.role
        FROM ticket_responses tr
        JOIN users u ON tr.user_id = u.id
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id
        WHERE tr.ticket_id = ? AND tr.is_internal = 0
        ORDER BY tr.created_at ASC
    ");
    $stmt->execute([$ticketId]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get internal notes (admin only)
    $stmt = $db->prepare("
        SELECT tr.*, u.employee_number, ep.first_name, ep.last_name
        FROM ticket_responses tr
        JOIN users u ON tr.user_id = u.id
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id
        WHERE tr.ticket_id = ? AND tr.is_internal = 1
        ORDER BY tr.created_at ASC
    ");
    $stmt->execute([$ticketId]);
    $internalNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get attachments
    $stmt = $db->prepare("
        SELECT ta.*, u.employee_number, ep.first_name, ep.last_name
        FROM ticket_attachments ta
        JOIN users u ON ta.uploaded_by = u.id
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id
        WHERE ta.ticket_id = ?
        ORDER BY ta.created_at ASC
    ");
    $stmt->execute([$ticketId]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $ticket['responses'] = $responses;
    $ticket['internal_notes'] = $internalNotes;
    $ticket['attachments'] = $attachments;
    
    echo json_encode([
        'success' => true,
        'ticket' => $ticket
    ]);
    
} catch (Exception $e) {
    error_log("Get ticket error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
?>
