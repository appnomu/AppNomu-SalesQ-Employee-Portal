<?php
require_once '../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/system-settings.php';

// Start secure session first
startSecureSession();
requireAdmin();

// Clear any output buffer to prevent HTML from leaking into email subjects
ob_start();
ob_clean();

// Handle ticket actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_status':
                $ticketId = intval($_POST['ticket_id']);
                $status = sanitizeInput($_POST['status']);
                $validStatuses = ['open', 'in_progress', 'resolved', 'closed'];
                
                if (in_array($status, $validStatuses)) {
                    $resolvedAt = ($status === 'resolved' || $status === 'closed') ? date('Y-m-d H:i:s') : null;
                    
                    $stmt = $db->prepare("UPDATE tickets SET status = ?, resolved_at = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$status, $resolvedAt, $ticketId]);
                    
                    logActivity($_SESSION['user_id'], 'ticket_status_update', 'tickets', $ticketId, "Ticket #{$ticketId} status changed to {$status}");
                    $_SESSION['success_message'] = "Ticket status updated successfully.";
                }
                break;
                
            case 'assign_ticket':
                $ticketId = intval($_POST['ticket_id']);
                $assignedTo = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
                
                $stmt = $db->prepare("UPDATE tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$assignedTo, $ticketId]);
                
                logActivity($_SESSION['user_id'], 'ticket_assignment', 'tickets', $ticketId, "Ticket #{$ticketId} assigned");
                $_SESSION['success_message'] = "Ticket assigned successfully.";
                break;
                
            case 'add_response':
                $ticketId = intval($_POST['ticket_id']);
                $response = sanitizeInput($_POST['response']);
                $isInternal = isset($_POST['is_internal']) ? 1 : 0;
                
                if (!empty($response)) {
                    // Insert response
                    $stmt = $db->prepare("
                        INSERT INTO ticket_responses (ticket_id, user_id, message, is_internal) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$ticketId, $_SESSION['user_id'], $response, $isInternal]);
                    $responseId = $db->lastInsertId();
                    
                    // Handle file uploads
                    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
                        $allowedTypes = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'xlsx', 'xls'];
                        $maxFileSize = 5 * 1024 * 1024; // 5MB
                        
                        for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
                            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                                $fileName = $_FILES['attachments']['name'][$i];
                                $fileSize = $_FILES['attachments']['size'][$i];
                                $fileTmp = $_FILES['attachments']['tmp_name'][$i];
                                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                                
                                if (in_array($fileExt, $allowedTypes) && $fileSize <= $maxFileSize) {
                                    $uniqueFileName = uniqid() . '_' . $fileName;
                                    $uploadPath = '../uploads/' . $uniqueFileName;
                                    
                                    if (move_uploaded_file($fileTmp, $uploadPath)) {
                                        $stmt = $db->prepare("
                                            INSERT INTO ticket_attachments (ticket_id, response_id, filename, original_filename, file_size, mime_type, uploaded_by) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?)
                                        ");
                                        $stmt->execute([$ticketId, $responseId, $uniqueFileName, $fileName, $fileSize, mime_content_type($uploadPath), $_SESSION['user_id']]);
                                    }
                                }
                            }
                        }
                    }
                    
                    // Send email notification to ticket creator
                    try {
                        require_once '../includes/infobip.php';
                        require_once '../includes/email-templates.php';
                        
                        $stmt = $db->prepare("
                            SELECT t.ticket_number, t.subject, u.email, ep.first_name, ep.last_name 
                            FROM tickets t 
                            JOIN users u ON t.employee_id = u.id 
                            LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
                            WHERE t.id = ?
                        ");
                        $stmt->execute([$ticketId]);
                        $ticketInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($ticketInfo && !$isInternal) {
                            // Get responder information
                            $responderStmt = $db->prepare("
                                SELECT ep.first_name, ep.last_name 
                                FROM employee_profiles ep 
                                WHERE ep.user_id = ?
                            ");
                            $responderStmt->execute([$_SESSION['user_id']]);
                            $responder = $responderStmt->fetch(PDO::FETCH_ASSOC);
                            
                            $responderName = $responder ? 
                                trim($responder['first_name'] . ' ' . $responder['last_name']) : 
                                'AppNomu Support Team';
                            
                            $employeeName = trim($ticketInfo['first_name'] . ' ' . $ticketInfo['last_name']);
                            
                            $emailSubject = 'Response to Ticket #' . $ticketInfo['ticket_number'] . ' - ' . $ticketInfo['subject'];
                            $emailMessage = generateTicketResponseEmailTemplate(
                                $employeeName,
                                $ticketInfo['ticket_number'],
                                $ticketInfo['subject'],
                                $response,
                                $responderName
                            );
                            
                            sendEmail($ticketInfo['email'], $emailSubject, $emailMessage);
                        }
                    } catch (Exception $e) {
                        // Email sending failed, but response was added
                        error_log('Ticket response email notification failed: ' . $e->getMessage());
                        error_log("Failed to send ticket response notification: " . $e->getMessage());
                    }
                    
                    logActivity($_SESSION['user_id'], 'ticket_response_added', 'ticket_responses', $responseId, "Response added to ticket #{$ticketId}");
                    $_SESSION['success_message'] = "Response added successfully.";
                }
                break;
        }
        
        header('Location: tickets.php');
        exit();
    }
}

// Get filter parameters
$statusFilter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';
$categoryFilter = isset($_GET['category']) ? sanitizeInput($_GET['category']) : '';
$priorityFilter = isset($_GET['priority']) ? sanitizeInput($_GET['priority']) : '';
$assignedFilter = isset($_GET['assigned']) ? sanitizeInput($_GET['assigned']) : '';

// Build query with filters
$whereConditions = [];
$params = [];

if (!empty($statusFilter)) {
    if ($statusFilter === 'resolved') {
        // Show both resolved and closed tickets for resolved filter
        $whereConditions[] = "t.status IN ('resolved', 'closed')";
    } else {
        $whereConditions[] = "t.status = ?";
        $params[] = $statusFilter;
    }
}

if (!empty($categoryFilter)) {
    $whereConditions[] = "t.category = ?";
    $params[] = $categoryFilter;
}

if (!empty($priorityFilter)) {
    $whereConditions[] = "t.priority = ?";
    $params[] = $priorityFilter;
}

if (!empty($assignedFilter)) {
    if ($assignedFilter === 'unassigned') {
        $whereConditions[] = "t.assigned_to IS NULL";
    } else {
        $whereConditions[] = "t.assigned_to = ?";
        $params[] = $assignedFilter;
    }
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Pagination setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get total count for pagination
$countStmt = $db->prepare("
    SELECT COUNT(*) as total
    FROM tickets t 
    JOIN users u ON t.employee_id = u.id 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    {$whereClause}
");
$countStmt->execute($params);
$totalTickets = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalTickets / $perPage);

// Get tickets with employee info - prioritize newest first within each priority level
$stmt = $db->prepare("
    SELECT t.*, 
           u.employee_number, 
           ep.first_name, 
           ep.last_name, 
           ep.department,
           au.employee_number as assigned_employee_number,
           aep.first_name as assigned_first_name,
           aep.last_name as assigned_last_name,
           (SELECT COUNT(*) FROM ticket_responses tr WHERE tr.ticket_id = t.id) as response_count
    FROM tickets t 
    JOIN users u ON t.employee_id = u.id 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    LEFT JOIN users au ON t.assigned_to = au.id
    LEFT JOIN employee_profiles aep ON au.id = aep.user_id
    {$whereClause}
    ORDER BY 
        CASE t.status 
            WHEN 'open' THEN 1 
            WHEN 'in_progress' THEN 2 
            WHEN 'resolved' THEN 3 
            WHEN 'closed' THEN 4 
        END,
        CASE t.priority 
            WHEN 'urgent' THEN 1 
            WHEN 'high' THEN 2 
            WHEN 'medium' THEN 3 
            WHEN 'low' THEN 4 
        END,
        t.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get admin users for assignment
$stmt = $db->prepare("
    SELECT u.id, u.employee_number, ep.first_name, ep.last_name 
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    WHERE u.role = 'admin' 
    ORDER BY ep.first_name, ep.last_name
");
$stmt->execute();
$adminUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get ticket statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_count,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_count,
        SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned_count
    FROM tickets
");
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Ticket Management - AppNomu SalesQ Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 12px 20px;
            margin: 5px 0;
            border-radius: 10px;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .main-content {
            background-color: #f8f9fa;
            min-height: 100vh;
        }
        
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
        }
        
        .table-responsive {
            border-radius: 8px;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            font-size: 0.875rem;
            color: #495057;
        }
        
        .table td {
            vertical-align: middle;
            font-size: 0.875rem;
        }
        
        .priority-row-urgent {
            border-left: 4px solid #dc3545;
        }
        
        .priority-row-high {
            border-left: 4px solid #fd7e14;
        }
        
        .priority-row-medium {
            border-left: 4px solid #ffc107;
        }
        
        .priority-row-low {
            border-left: 4px solid #6c757d;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .btn-group-sm .btn {
            padding: 0.25rem 0.5rem;
        }
        
        .pagination-sm .page-link {
            padding: 0.25rem 0.5rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 px-0">
                <div class="sidebar p-3">
                    <div class="text-center text-white mb-4">
                        <img src="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png" 
                             alt="AppNomu SalesQ" 
                             style="max-height: 60px; margin-bottom: 15px;">
                        <h4>AppNomu SalesQ</h4>
                        <small>Admin Panel</small>
                    </div>
                    
                    <nav class="nav flex-column">
                        <a class="nav-link" href="dashboard">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                        <a class="nav-link" href="employees.php">
                            <i class="fas fa-users me-2"></i>Employees
                        </a>
                        <a class="nav-link" href="leave-requests.php">
                            <i class="fas fa-calendar-alt me-2"></i>Leave Requests
                        </a>
                        <a class="nav-link" href="tasks.php">
                            <i class="fas fa-tasks me-2"></i>Tasks
                        </a>
                        <a class="nav-link active" href="tickets.php">
                            <i class="fas fa-ticket-alt me-2"></i>Tickets
                        </a>
                        <a class="nav-link" href="documents.php">
                            <i class="fas fa-file-pdf me-2"></i>Documents
                        </a>
                        <a class="nav-link" href="salary-management.php">
                            <i class="fas fa-dollar-sign me-2"></i>Salary Management
                        </a>
                        <a class="nav-link" href="withdrawals.php">
                            <i class="fas fa-money-bill-wave me-2"></i>Withdrawals
                        </a>
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar me-2"></i>Reports
                        </a>
                        <a class="nav-link" href="settings.php">
                            <i class="fas fa-cog me-2"></i>Settings
                        </a>
                    </nav>
                    
                    <div class="mt-auto pt-4">
                        <div class="text-white-50 small">
                            <i class="fas fa-user me-2"></i>
                            <?php echo htmlspecialchars($_SESSION['employee_number'] ?? 'Admin'); ?>
                        </div>
                        <a href="../auth/logout.php" class="nav-link text-white-50 small">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 main-content">
                <div class="container-fluid py-4">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2><i class="fas fa-ticket-alt me-2"></i>Support Tickets</h2>
                            <p class="text-muted">Manage employee support tickets and responses</p>
                        </div>
                    </div>
                    
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-2">
                            <div class="stats-card text-center">
                                <h3 class="text-primary"><?php echo $stats['total']; ?></h3>
                                <small class="text-muted">Total Tickets</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="stats-card text-center">
                                <h3 class="text-warning"><?php echo $stats['open_count']; ?></h3>
                                <small class="text-muted">Open</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="stats-card text-center">
                                <h3 class="text-info"><?php echo $stats['in_progress_count']; ?></h3>
                                <small class="text-muted">In Progress</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="stats-card text-center">
                                <h3 class="text-success"><?php echo $stats['resolved_count']; ?></h3>
                                <small class="text-muted">Resolved</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="stats-card text-center">
                                <h3 class="text-secondary"><?php echo $stats['closed_count']; ?></h3>
                                <small class="text-muted">Closed</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="stats-card text-center">
                                <h3 class="text-danger"><?php echo $stats['unassigned_count']; ?></h3>
                                <small class="text-muted">Unassigned</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="">All Statuses</option>
                                        <option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>>Open</option>
                                        <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="resolved" <?php echo $statusFilter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                        <option value="closed" <?php echo $statusFilter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Category</label>
                                    <select name="category" class="form-select">
                                        <option value="">All Categories</option>
                                        <option value="technical" <?php echo $categoryFilter === 'technical' ? 'selected' : ''; ?>>Technical</option>
                                        <option value="hr" <?php echo $categoryFilter === 'hr' ? 'selected' : ''; ?>>HR</option>
                                        <option value="payroll" <?php echo $categoryFilter === 'payroll' ? 'selected' : ''; ?>>Payroll</option>
                                        <option value="leave" <?php echo $categoryFilter === 'leave' ? 'selected' : ''; ?>>Leave</option>
                                        <option value="general" <?php echo $categoryFilter === 'general' ? 'selected' : ''; ?>>General</option>
                                        <option value="complaint" <?php echo $categoryFilter === 'complaint' ? 'selected' : ''; ?>>Complaint</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Priority</label>
                                    <select name="priority" class="form-select">
                                        <option value="">All Priorities</option>
                                        <option value="urgent" <?php echo $priorityFilter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                        <option value="high" <?php echo $priorityFilter === 'high' ? 'selected' : ''; ?>>High</option>
                                        <option value="medium" <?php echo $priorityFilter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="low" <?php echo $priorityFilter === 'low' ? 'selected' : ''; ?>>Low</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Assigned To</label>
                                    <select name="assigned" class="form-select">
                                        <option value="">All Assignments</option>
                                        <option value="unassigned" <?php echo $assignedFilter === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                                        <?php foreach ($adminUsers as $admin): ?>
                                            <option value="<?php echo $admin['id']; ?>" <?php echo $assignedFilter == $admin['id'] ? 'selected' : ''; ?>>
                                                <?php echo safeOutput($admin['first_name'] . ' ' . $admin['last_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">&nbsp;</label>
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter me-2"></i>Filter
                                        </button>
                                        <a href="tickets.php" class="btn btn-outline-secondary">Clear</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Tickets Table -->
                    <div class="card">
                        <div class="card-body p-0">
                            <?php if (empty($tickets)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-ticket-alt fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No tickets found</h5>
                                    <p class="text-muted">No tickets match your current filters.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="8%">Ticket #</th>
                                                <th width="25%">Subject & Employee</th>
                                                <th width="12%">Category</th>
                                                <th width="10%">Priority</th>
                                                <th width="12%">Status</th>
                                                <th width="15%">Assigned To</th>
                                                <th width="8%">Responses</th>
                                                <th width="10%">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tickets as $ticket): ?>
                                                <tr class="priority-row-<?php echo $ticket['priority']; ?>">
                                                    <td>
                                                        <strong>#<?php echo $ticket['id']; ?></strong>
                                                        <br><small class="text-muted"><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <div class="fw-bold text-truncate" style="max-width: 200px;" title="<?php echo safeOutput($ticket['subject']); ?>">
                                                            <?php echo safeOutput($ticket['subject']); ?>
                                                        </div>
                                                        <small class="text-muted">
                                                            <?php echo safeOutput($ticket['first_name'] . ' ' . $ticket['last_name']); ?>
                                                            (<?php echo safeOutput($ticket['employee_number']); ?>)
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark">
                                                            <?php echo ucfirst($ticket['category']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $ticket['priority'] === 'urgent' ? 'danger' : 
                                                                ($ticket['priority'] === 'high' ? 'warning' : 
                                                                ($ticket['priority'] === 'medium' ? 'info' : 'secondary')); 
                                                        ?>">
                                                            <?php echo ucfirst($ticket['priority']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $ticket['status'] === 'open' ? 'danger' : 
                                                                ($ticket['status'] === 'in_progress' ? 'warning' : 
                                                                ($ticket['status'] === 'resolved' ? 'success' : 'secondary')); 
                                                        ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($ticket['assigned_to']) && !empty($ticket['assigned_first_name'])): ?>
                                                            <small>
                                                                <i class="fas fa-user-tie me-1"></i>
                                                                <?php echo safeOutput($ticket['assigned_first_name'] . ' ' . $ticket['assigned_last_name']); ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <small class="text-warning">
                                                                <i class="fas fa-exclamation-triangle me-1"></i>
                                                                Unassigned
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-primary"><?php echo $ticket['response_count']; ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <button type="button" class="btn btn-outline-primary" onclick="viewTicket(<?php echo $ticket['id']; ?>)" title="View Details">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-outline-success" onclick="respondToTicket(<?php echo $ticket['id']; ?>)" title="Add Response">
                                                                <i class="fas fa-reply"></i>
                                                            </button>
                                                            <div class="btn-group btn-group-sm" role="group">
                                                                <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" title="More Actions">
                                                                    <i class="fas fa-cog"></i>
                                                                </button>
                                                                <ul class="dropdown-menu">
                                                                    <li><a class="dropdown-item" href="#" onclick="updateStatus(<?php echo $ticket['id']; ?>, 'open')"><i class="fas fa-folder-open me-2"></i>Mark Open</a></li>
                                                                    <li><a class="dropdown-item" href="#" onclick="updateStatus(<?php echo $ticket['id']; ?>, 'in_progress')"><i class="fas fa-spinner me-2"></i>Mark In Progress</a></li>
                                                                    <li><a class="dropdown-item" href="#" onclick="updateStatus(<?php echo $ticket['id']; ?>, 'resolved')"><i class="fas fa-check-circle me-2"></i>Mark Resolved</a></li>
                                                                    <li><a class="dropdown-item" href="#" onclick="updateStatus(<?php echo $ticket['id']; ?>, 'closed')"><i class="fas fa-times-circle me-2"></i>Mark Closed</a></li>
                                                                    <li><hr class="dropdown-divider"></li>
                                                                    <li><a class="dropdown-item" href="#" onclick="assignTicket(<?php echo $ticket['id']; ?>)"><i class="fas fa-user-plus me-2"></i>Assign/Reassign</a></li>
                                                                </ul>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <?php if ($totalPages > 1): ?>
                                    <div class="card-footer">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="text-muted small">
                                                Showing <?php echo (($page - 1) * $perPage) + 1; ?> to <?php echo min($page * $perPage, $totalTickets); ?> of <?php echo $totalTickets; ?> tickets
                                            </div>
                                            <nav>
                                                <ul class="pagination pagination-sm mb-0">
                                                    <?php if ($page > 1): ?>
                                                        <li class="page-item">
                                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                                                <i class="fas fa-chevron-left"></i>
                                                            </a>
                                                        </li>
                                                    <?php endif; ?>
                                                    
                                                    <?php 
                                                    $startPage = max(1, $page - 2);
                                                    $endPage = min($totalPages, $page + 2);
                                                    
                                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                                    ?>
                                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                                <?php echo $i; ?>
                                                            </a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <?php if ($page < $totalPages): ?>
                                                        <li class="page-item">
                                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                                                <i class="fas fa-chevron-right"></i>
                                                            </a>
                                                        </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </nav>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewTicket(id) {
            // Load full ticket details with responses
            fetch(`ticket-details?id=${id}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('ticketDetailsContent').innerHTML = data;
                    new bootstrap.Modal(document.getElementById('ticketDetailsModal')).show();
                })
                .catch(error => {
                    console.error('Error loading ticket details:', error);
                    alert('Failed to load ticket details');
                });
        }
        
        function respondToTicket(id) {
            // Show response modal
            showResponseModal(id);
        }
        
        function updateStatus(ticketId, status) {
            if (confirm(`Are you sure you want to change the ticket status to "${status.replace('_', ' ')}"?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="ticket_id" value="${ticketId}">
                    <input type="hidden" name="status" value="${status}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function assignTicket(ticketId) {
            // Show assignment modal
            showAssignmentModal(ticketId);
        }
        
        function showTicketModal(ticket) {
            // Populate ticket details modal
            const modalContent = `
                <div class="ticket-details">
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <h5>${ticket.subject}</h5>
                            <p class="text-muted mb-2">${ticket.description}</p>
                        </div>
                        <div class="col-md-4 text-end">
                            <span class="badge bg-${getPriorityColor(ticket.priority)} mb-2">
                                ${ticket.priority.charAt(0).toUpperCase() + ticket.priority.slice(1)} Priority
                            </span><br>
                            <span class="badge bg-${getStatusColor(ticket.status)}">
                                ${ticket.status.replace('_', ' ').charAt(0).toUpperCase() + ticket.status.replace('_', ' ').slice(1)}
                            </span>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <small class="text-muted">
                                <strong>Ticket #:</strong> ${ticket.ticket_number || ticket.id}<br>
                                <strong>Category:</strong> ${ticket.category}<br>
                                <strong>Created:</strong> ${new Date(ticket.created_at).toLocaleDateString()}
                            </small>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">
                                <strong>Employee:</strong> ${ticket.first_name} ${ticket.last_name}<br>
                                <strong>Employee ID:</strong> ${ticket.employee_number}
                            </small>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('ticketDetailsContent').innerHTML = modalContent;
            new bootstrap.Modal(document.getElementById('ticketDetailsModal')).show();
        }
        
        function showResponseModal(ticketId) {
            document.getElementById('responseTicketId').value = ticketId;
            new bootstrap.Modal(document.getElementById('responseModal')).show();
        }
        
        function showAssignmentModal(ticketId) {
            document.getElementById('assignTicketId').value = ticketId;
            new bootstrap.Modal(document.getElementById('assignmentModal')).show();
        }
        
        function getPriorityColor(priority) {
            switch(priority) {
                case 'urgent': return 'danger';
                case 'high': return 'warning';
                case 'medium': return 'info';
                case 'low': return 'success';
                default: return 'secondary';
            }
        }
        
        function getStatusColor(status) {
            switch(status) {
                case 'open': return 'danger';
                case 'in_progress': return 'warning';
                case 'resolved': return 'info';
                case 'closed': return 'success';
                default: return 'secondary';
            }
        }
    </script>
    
    <!-- Ticket Details Modal -->
    <div class="modal fade" id="ticketDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ticket Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="ticketDetailsContent">
                    <!-- Ticket details will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Response Modal -->
    <div class="modal fade" id="responseModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Response</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_response">
                    <input type="hidden" name="ticket_id" id="responseTicketId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Response Message *</label>
                            <textarea class="form-control" name="response" rows="5" 
                                      placeholder="Enter your response to the ticket..." required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Attachments</label>
                            <input type="file" class="form-control" name="attachments[]" multiple 
                                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.txt,.xlsx,.xls">
                            <div class="form-text">You can attach multiple files (PDF, DOC, images, etc.). Max 5MB per file.</div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_internal" id="isInternal">
                            <label class="form-check-label" for="isInternal">
                                Internal note (not visible to employee)
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Response</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Assignment Modal -->
    <div class="modal fade" id="assignmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="assign_ticket">
                    <input type="hidden" name="ticket_id" id="assignTicketId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Assign to Admin</label>
                            <select class="form-select" name="assigned_to">
                                <option value="">Unassigned</option>
                                <?php foreach ($adminUsers as $admin): ?>
                                    <option value="<?php echo $admin['id']; ?>">
                                        <?php echo safeOutput($admin['first_name'] . ' ' . $admin['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Assign Ticket</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
