<?php
require_once __DIR__ . '/../config/session-security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Start secure session first
startSecureSession();
requireAuth();

// Ensure user is employee
if ($_SESSION['role'] !== 'employee') {
    header('Location: ../admin/dashboard');
    exit();
}

$ticketId = intval($_GET['id'] ?? 0);
$userId = $_SESSION['user_id'];

if ($ticketId <= 0) {
    echo '<div class="alert alert-danger">Invalid ticket ID.</div>';
    exit();
}

try {
    // Get ticket details - ensure employee can only view their own tickets
    $stmt = $db->prepare("
        SELECT t.*, 
               assigned_user.employee_number as assigned_to_number,
               assigned_profile.first_name as assigned_to_first_name,
               assigned_profile.last_name as assigned_to_last_name
        FROM tickets t 
        LEFT JOIN users assigned_user ON t.assigned_to = assigned_user.id
        LEFT JOIN employee_profiles assigned_profile ON assigned_user.id = assigned_profile.user_id
        WHERE t.id = ? AND t.employee_id = ?
    ");
    $stmt->execute([$ticketId, $userId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        echo '<div class="alert alert-danger">Ticket not found or access denied.</div>';
        exit();
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error loading ticket: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit();
}

// Get ticket responses
try {
    $stmt = $db->prepare("
        SELECT tr.*, u.employee_number, ep.first_name, ep.last_name, u.role
        FROM ticket_responses tr
        JOIN users u ON tr.user_id = u.id
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id
        WHERE tr.ticket_id = ?
        ORDER BY tr.created_at ASC
    ");
    $stmt->execute([$ticketId]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $responses = [];
    error_log('Error loading ticket responses: ' . $e->getMessage());
}

// Get ticket attachments
try {
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
} catch (Exception $e) {
    $attachments = [];
    error_log('Error loading ticket attachments: ' . $e->getMessage());
}
?>

<div class="ticket-details">
    <div class="row mb-3">
        <div class="col-md-8">
            <h5><?php echo safeOutput($ticket['subject']); ?></h5>
            <p class="text-muted mb-2"><?php echo safeOutput($ticket['description']); ?></p>
        </div>
        <div class="col-md-4 text-end">
            <span class="badge bg-<?php 
                echo $ticket['priority'] === 'urgent' ? 'danger' : 
                    ($ticket['priority'] === 'high' ? 'warning' : 
                    ($ticket['priority'] === 'medium' ? 'info' : 'success')); 
            ?> mb-2">
                <?php echo ucfirst($ticket['priority']); ?> Priority
            </span><br>
            <span class="badge bg-<?php 
                echo $ticket['status'] === 'open' ? 'danger' : 
                    ($ticket['status'] === 'in_progress' ? 'warning' : 
                    ($ticket['status'] === 'resolved' ? 'info' : 'success')); 
            ?>">
                <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
            </span>
        </div>
    </div>
    
    <div class="row mb-3">
        <div class="col-md-6">
            <small class="text-muted">
                <strong>Ticket #:</strong> <?php echo $ticket['ticket_number']; ?><br>
                <strong>Category:</strong> <?php echo ucfirst($ticket['category']); ?><br>
                <strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($ticket['created_at'])); ?>
            </small>
        </div>
        <div class="col-md-6">
            <small class="text-muted">
                <?php if ($ticket['assigned_to']): ?>
                    <strong>Assigned to:</strong> <?php echo safeOutput($ticket['assigned_to_first_name'] . ' ' . $ticket['assigned_to_last_name']); ?><br>
                <?php else: ?>
                    <strong>Status:</strong> Unassigned<br>
                <?php endif; ?>
                <?php if ($ticket['resolved_at']): ?>
                    <strong>Resolved:</strong> <?php echo date('M j, Y g:i A', strtotime($ticket['resolved_at'])); ?>
                <?php endif; ?>
            </small>
        </div>
    </div>
    
    <?php if (!empty($attachments)): ?>
        <div class="mb-4">
            <h6>Attachments</h6>
            <div class="row">
                <?php foreach ($attachments as $attachment): ?>
                    <div class="col-md-6 mb-2">
                        <div class="card">
                            <div class="card-body p-2">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-<?php 
                                        $ext = strtolower(pathinfo($attachment['original_filename'], PATHINFO_EXTENSION));
                                        echo $ext === 'pdf' ? 'file-pdf text-danger' : 
                                            (in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) ? 'file-image text-success' : 
                                            (in_array($ext, ['doc', 'docx']) ? 'file-word text-primary' : 'file text-secondary'));
                                    ?> me-2"></i>
                                    <div class="flex-grow-1">
                                        <small class="d-block"><?php echo htmlspecialchars($attachment['original_filename']); ?></small>
                                        <small class="text-muted"><?php echo number_format($attachment['file_size'] / 1024, 1); ?> KB</small>
                                    </div>
                                    <a href="../uploads/<?php echo $attachment['filename']; ?>" 
                                       class="btn btn-sm btn-outline-primary" target="_blank">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($responses)): ?>
        <div class="mb-3">
            <h6>Responses</h6>
            <?php foreach ($responses as $response): ?>
                <div class="card mb-2" style="background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%); border: 1px solid #404040;">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <strong style="color: #e0e0e0;"><?php echo htmlspecialchars($response['first_name'] . ' ' . $response['last_name']); ?></strong>
                                <small class="text-muted">(<?php echo ucfirst($response['role']); ?>)</small>
                            </div>
                            <small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($response['created_at'])); ?></small>
                        </div>
                        <p class="mb-0" style="color: #e0e0e0;"><?php echo nl2br(safeOutput($response['message'])); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
