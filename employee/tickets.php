<?php
require_once __DIR__ . '/../config/session-security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Start secure session first
startSecureSession();
requireAuth();

// Ensure user is employee
if ($_SESSION['role'] !== 'employee') {
    header('Location: ../admin/dashboard');
    exit();
}

$userId = $_SESSION['user_id'];
$message = getFlashMessage();

// Handle ticket creation
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'create') {
    $subject = sanitizeInput($_POST['subject'], 'raw');
    $description = sanitizeInput($_POST['description'], 'raw');
    $category = sanitizeInput($_POST['category']);
    $priority = sanitizeInput($_POST['priority']);
    
    $errors = [];
    
    if (empty($subject) || empty($description)) {
        $errors[] = 'Please fill in all required fields';
    }
    
    // Handle file uploads
    $uploadedFiles = [];
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $allowedTypes = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'xlsx', 'xls'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB
        
        for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $fileName = $_FILES['attachments']['name'][$i];
                $fileSize = $_FILES['attachments']['size'][$i];
                $fileTmp = $_FILES['attachments']['tmp_name'][$i];
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                if (!in_array($fileExt, $allowedTypes)) {
                    $errors[] = "File type not allowed: {$fileName}";
                    continue;
                }
                
                if ($fileSize > $maxFileSize) {
                    $errors[] = "File too large: {$fileName} (max 5MB)";
                    continue;
                }
                
                $uploadedFiles[] = [
                    'name' => $fileName,
                    'tmp_name' => $fileTmp,
                    'size' => $fileSize,
                    'type' => mime_content_type($fileTmp)
                ];
            }
        }
    }
    
    if (empty($errors)) {
        try {
            // Generate ticket number
            $ticketNumber = 'TKT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $stmt = $db->prepare("
                INSERT INTO tickets (ticket_number, employee_id, subject, description, category, priority) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ticketNumber, $userId, $subject, $description, $category, $priority]);
            $ticketId = $db->lastInsertId();
            
            // Handle file uploads
            foreach ($uploadedFiles as $file) {
                $uniqueFileName = uniqid() . '_' . $file['name'];
                $uploadPath = '../uploads/' . $uniqueFileName;
                
                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    $stmt = $db->prepare("
                        INSERT INTO ticket_attachments (ticket_id, filename, original_filename, file_size, mime_type, uploaded_by) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$ticketId, $uniqueFileName, $file['name'], $file['size'], $file['type'], $userId]);
                }
            }
            
            logActivity($userId, 'ticket_created', 'tickets', $ticketId);
            
            // Send notification to admin and confirmation to employee
            try {
                require_once '../includes/infobip.php';
                require_once '../includes/email-templates.php';
                
                // Get employee details
                $stmt = $db->prepare("
                    SELECT u.email, ep.first_name, ep.last_name 
                    FROM users u 
                    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
                    WHERE u.id = ?
                ");
                $stmt->execute([$userId]);
                $employee = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $employeeName = $employee ? 
                    trim($employee['first_name'] . ' ' . $employee['last_name']) : 
                    $_SESSION['user_name'];
                
                // Send notification to admin
                $stmt = $db->prepare("SELECT email FROM users WHERE role = 'admin' LIMIT 1");
                $stmt->execute();
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($admin) {
                    $emailSubject = 'New Support Ticket #' . $ticketNumber . ' - ' . $subject;
                    $emailMessage = generateTicketCreationEmailTemplate(
                        $ticketNumber,
                        $employeeName,
                        $_SESSION['employee_number'],
                        $subject,
                        $category,
                        $priority,
                        $description
                    );
                    
                    sendEmail($admin['email'], $emailSubject, $emailMessage);
                }
                
                // Send confirmation to employee
                if ($employee && $employee['email']) {
                    $confirmationSubject = 'Ticket Created Successfully #' . $ticketNumber;
                    $confirmationMessage = "
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset='UTF-8'>
                        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                        <title>Ticket Confirmation</title>
                    </head>
                    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
                        
                        <div style='background: #d4edda; padding: 20px; border-radius: 8px; margin-bottom: 20px;'>
                            <h2 style='color: #155724; margin: 0 0 10px 0;'>Support Ticket Created Successfully</h2>
                            <p style='margin: 0; color: #155724;'>Your support request has been submitted and assigned ticket number <strong>" . htmlspecialchars($ticketNumber) . "</strong></p>
                        </div>
                        
                        <div style='background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin-bottom: 20px;'>
                            <p style='margin: 0 0 15px 0; color: #212529;'>Dear " . htmlspecialchars($employeeName) . ",</p>
                            
                            <p style='margin: 0 0 15px 0; color: #495057;'>
                                Thank you for contacting our support team. We have received your request and it has been assigned the following details:
                            </p>
                            
                            <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>
                                <tr>
                                    <td style='padding: 8px 0; font-weight: bold; color: #495057; width: 30%;'>Ticket Number:</td>
                                    <td style='padding: 8px 0; color: #212529;'>" . htmlspecialchars($ticketNumber) . "</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; font-weight: bold; color: #495057;'>Subject:</td>
                                    <td style='padding: 8px 0; color: #212529;'>" . htmlspecialchars($subject) . "</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; font-weight: bold; color: #495057;'>Priority:</td>
                                    <td style='padding: 8px 0; color: #212529;'>" . htmlspecialchars(ucfirst($priority)) . "</td>
                                </tr>
                            </table>
                            
                            <p style='margin: 0; color: #495057;'>
                                Our support team will review your request and respond as soon as possible. You will receive email notifications when there are updates to your ticket.
                            </p>
                        </div>
                        
                        <div style='background: #cce5ff; border: 1px solid #99d6ff; border-radius: 8px; padding: 15px; text-align: center;'>
                            <p style='margin: 0; color: #004085; font-size: 14px;'>
                                You can track the status of your ticket by logging into the Employee Portal.
                            </p>
                        </div>
                        
                        <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; text-align: center;'>
                            <p style='margin: 0; color: #6c757d; font-size: 12px;'>
                                AppNomu SalesQ Employee Portal<br>
                                This is an automated confirmation. Please do not reply to this email.
                            </p>
                        </div>
                        
                    </body>
                    </html>";
                    
                    sendEmail($employee['email'], $confirmationSubject, $confirmationMessage);
                }
                
            } catch (Exception $e) {
                // Email sending failed, but ticket was created
                error_log('Ticket email notification failed: ' . $e->getMessage());
            }
            
            redirectWithMessage('tickets.php', 'Support ticket created successfully. Ticket #: ' . $ticketNumber, 'success');
        } catch (Exception $e) {
            $errors[] = 'Failed to create ticket: ' . $e->getMessage();
        }
    }
}

// Handle ticket response
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'respond') {
    $ticketId = intval($_POST['ticket_id']);
    $message = sanitizeInput($_POST['message']);
    
    // Verify ticket belongs to current user
    $stmt = $db->prepare("SELECT * FROM tickets WHERE id = ? AND employee_id = ?");
    $stmt->execute([$ticketId, $userId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ticket && !empty($message)) {
        $stmt = $db->prepare("
            INSERT INTO ticket_responses (ticket_id, user_id, message, is_internal) 
            VALUES (?, ?, ?, FALSE)
        ");
        $stmt->execute([$ticketId, $userId, $message]);
        
        logActivity($userId, 'ticket_response_added', 'ticket_responses', $db->lastInsertId());
        
        redirectWithMessage('tickets.php', 'Response added successfully', 'success');
    }
}

// Get filter parameters
$status = $_GET['status'] ?? '';
$category = $_GET['category'] ?? '';
$priority = $_GET['priority'] ?? '';

// Build query
$whereClause = "WHERE t.employee_id = ?";
$params = [$userId];

if ($status) {
    $whereClause .= " AND t.status = ?";
    $params[] = $status;
}

if ($category) {
    $whereClause .= " AND t.category = ?";
    $params[] = $category;
}

if ($priority) {
    $whereClause .= " AND t.priority = ?";
    $params[] = $priority;
}

// Get tickets
$stmt = $db->prepare("
    SELECT t.*, 
           assigned_user.employee_number as assigned_to_number,
           assigned_profile.first_name as assigned_to_first_name,
           assigned_profile.last_name as assigned_to_last_name
    FROM tickets t 
    LEFT JOIN users assigned_user ON t.assigned_to = assigned_user.id
    LEFT JOIN employee_profiles assigned_profile ON assigned_user.id = assigned_profile.user_id
    $whereClause
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
");
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get ticket categories for filter
$categories = ['Technical', 'HR', 'Finance', 'General', 'Equipment', 'Access'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Support Tickets - AppNomu SalesQ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #e0e0e0;
        }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #1e1e1e 0%, #2a2a2a 100%);
            box-shadow: 2px 0 5px rgba(0,0,0,0.3);
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
        .table-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            border: 1px solid #404040;
        }
        .btn-primary {
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
            border: none;
        }
        .modal-content {
            background-color: #2a2a2a;
            border: 1px solid #404040;
        }
        .modal-header {
            border-bottom: 1px solid #404040;
        }
        .modal-footer {
            border-top: 1px solid #404040;
        }
        .form-control, .form-select {
            background-color: #3a3a3a;
            border: 1px solid #555;
            color: #e0e0e0;
        }
        .form-control:focus, .form-select:focus {
            background-color: #3a3a3a;
            border-color: #4a90e2;
            color: #e0e0e0;
            box-shadow: 0 0 0 0.2rem rgba(74, 144, 226, 0.25);
        }
        .form-label {
            color: #e0e0e0;
        }
        .alert-success {
            background-color: #1e4d2b;
            border-color: #2d5a3d;
            color: #a3d9a5;
        }
        .alert-danger {
            background-color: #4d1e1e;
            border-color: #5a2d2d;
            color: #d9a3a3;
        }
        .ticket-card {
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            transition: transform 0.3s;
            border: 1px solid #404040;
        }
        .ticket-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
        }
        .priority-urgent { border-left: 5px solid #dc3545; }
        .priority-high { border-left: 5px solid #fd7e14; }
        .priority-medium { border-left: 5px solid #ffc107; }
        .priority-low { border-left: 5px solid #28a745; }
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
                        <small>Employee Panel</small>
                    </div>
                    
                    <nav class="nav flex-column">
                        <a class="nav-link" href="dashboard">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                        <a class="nav-link" href="profile.php">
                            <i class="fas fa-user me-2"></i>My Profile
                        </a>
                        <a class="nav-link" href="leave-requests.php">
                            <i class="fas fa-calendar-alt me-2"></i>Leave Requests
                        </a>
                        <a class="nav-link" href="tasks.php">
                            <i class="fas fa-tasks me-2"></i>My Tasks
                        </a>
                        <a class="nav-link active" href="tickets.php">
                            <i class="fas fa-ticket-alt me-2"></i>Support Tickets
                        </a>
                        <a class="nav-link" href="withdrawal-salary.php">
                            <i class="fas fa-money-bill-wave me-2"></i>Salary Withdrawal
                        </a>
                        <a class="nav-link" href="documents.php">
                            <i class="fas fa-file-pdf me-2"></i>Documents
                        </a>
                        <a class="nav-link" href="reminders.php">
                            <i class="fas fa-bell me-2"></i>Reminders
                        </a>
                    </nav>
                    
                    <div class="mt-auto pt-4">
                        <div class="text-white-50 small">
                            <i class="fas fa-user me-2"></i><?php echo $_SESSION['user_name']; ?>
                        </div>
                        <div class="text-white-50 small">
                            <i class="fas fa-id-badge me-2"></i><?php echo $_SESSION['employee_number']; ?>
                        </div>
                        <a href="../auth/logout.php" class="nav-link text-white-50">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="p-4">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2>Support Tickets</h2>
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createTicketModal">
                            <i class="fas fa-plus me-2"></i>Create Ticket
                        </button>
                    </div>
                    
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message['message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($errors) && !empty($errors)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php foreach ($errors as $error): ?>
                                <div><?php echo $error; ?></div>
                            <?php endforeach; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Filters -->
                    <div class="card table-card mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <select class="form-select" name="status">
                                        <option value="">All Status</option>
                                        <option value="open" <?php echo $status === 'open' ? 'selected' : ''; ?>>Open</option>
                                        <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="resolved" <?php echo $status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                        <option value="closed" <?php echo $status === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" name="category">
                                        <option value="">All Categories</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo $cat; ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                                <?php echo $cat; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" name="priority">
                                        <option value="">All Priorities</option>
                                        <option value="urgent" <?php echo $priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                        <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>High</option>
                                        <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>Low</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-outline-primary w-100">
                                        <i class="fas fa-filter me-2"></i>Apply Filters
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Tickets Table -->
                    <div class="card table-card">
                        <div class="card-body p-0">
                            <?php if (empty($tickets)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-ticket-alt fa-4x mb-3" style="color: #666;"></i>
                                    <h4 style="color: #e0e0e0;">No Support Tickets</h4>
                                    <p style="color: #b0b0b0;">You haven't created any support tickets yet or none match the current filters.</p>
                                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createTicketModal">
                                        <i class="fas fa-plus me-2"></i>Create Your First Ticket
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-dark table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Ticket</th>
                                                <th>Priority</th>
                                                <th>Status</th>
                                                <th>Category</th>
                                                <th>Assigned To</th>
                                                <th>Created</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tickets as $ticket): ?>
                                                <?php 
                                                    $priorityClass = 'secondary';
                                                    if ($ticket['priority'] === 'urgent') $priorityClass = 'danger';
                                                    elseif ($ticket['priority'] === 'high') $priorityClass = 'warning';
                                                    elseif ($ticket['priority'] === 'medium') $priorityClass = 'info';
                                                    elseif ($ticket['priority'] === 'low') $priorityClass = 'success';
                                                    
                                                    $statusClass = 'secondary';
                                                    if ($ticket['status'] === 'open') $statusClass = 'danger';
                                                    elseif ($ticket['status'] === 'in_progress') $statusClass = 'warning';
                                                    elseif ($ticket['status'] === 'resolved') $statusClass = 'info';
                                                    elseif ($ticket['status'] === 'closed') $statusClass = 'success';
                                                ?>
                                                <tr class="ticket-row priority-<?php echo $ticket['priority']; ?>">
                                                    <td>
                                                        <div>
                                                            <strong><?php echo safeOutput($ticket['subject']); ?></strong>
                                                            <br><small class="text-muted"><?php echo $ticket['ticket_number']; ?></small>
                                                            <?php if ($ticket['description']): ?>
                                                                <br><small class="text-muted"><?php echo strlen($ticket['description']) > 60 ? substr($ticket['description'], 0, 60) . '...' : $ticket['description']; ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $priorityClass; ?>">
                                                            <?php echo ucfirst($ticket['priority']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $statusClass; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="text-muted"><?php echo $ticket['category'] ?? 'General'; ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($ticket['assigned_to']): ?>
                                                            <div>
                                                                <i class="fas fa-user-tie me-1"></i>
                                                                <?php echo $ticket['assigned_to_first_name'] . ' ' . $ticket['assigned_to_last_name']; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted">Unassigned</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <small class="text-muted">
                                                            <?php echo date('M j, Y', strtotime($ticket['created_at'])); ?>
                                                            <br><?php echo date('g:i A', strtotime($ticket['created_at'])); ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <button class="btn btn-outline-primary" onclick="viewTicket(<?php echo $ticket['id']; ?>)" title="View Details">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            <?php if ($ticket['status'] !== 'closed'): ?>
                                                                <button class="btn btn-outline-warning" onclick="respondToTicket(<?php echo $ticket['id']; ?>)" title="Add Response">
                                                                    <i class="fas fa-reply"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Ticket Modal -->
    <div class="modal fade" id="createTicketModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Support Ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Category *</label>
                                    <select class="form-select" name="category" required>
                                        <option value="">Select category</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Priority *</label>
                                    <select class="form-select" name="priority" required>
                                        <option value="">Select priority</option>
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Subject *</label>
                            <input type="text" class="form-control" name="subject" 
                                   placeholder="Brief description of your issue" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description *</label>
                            <textarea class="form-control" name="description" rows="6" 
                                      placeholder="Please provide detailed information about your issue, including steps to reproduce if applicable..." required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Attachments</label>
                            <input type="file" class="form-control" name="attachments[]" multiple 
                                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.txt,.xlsx,.xls">
                            <div class="form-text">You can attach multiple files (PDF, DOC, images, etc.). Max 5MB per file.</div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> Your ticket will be reviewed by our support team. 
                            You will receive updates via email and can track progress here.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Create Ticket</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
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
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Response</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="respond">
                    <input type="hidden" name="ticket_id" id="responseTicketId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Your Response</label>
                            <textarea class="form-control" name="message" rows="5" 
                                      placeholder="Add your response or additional information..." required></textarea>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewTicket(ticketId) {
            // Load ticket details via AJAX
            fetch(`ticket-details?id=${ticketId}`)
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
        
        function respondToTicket(ticketId) {
            document.getElementById('responseTicketId').value = ticketId;
            new bootstrap.Modal(document.getElementById('responseModal')).show();
        }
    </script>
</body>
</html>
