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
$success = '';
$error = '';

// Handle reminder creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_reminder'])) {
    $title = sanitizeInput($_POST['title']);
    $description = sanitizeInput($_POST['description']);
    $reminderDate = $_POST['reminder_date'];
    $reminderTime = $_POST['reminder_time'];
    $priority = $_POST['priority'];
    $deliveryMethod = $_POST['delivery_method'];
    
    $reminderDateTime = $reminderDate . ' ' . $reminderTime;
    
    if (empty($title) || empty($reminderDateTime)) {
        $error = 'Title and reminder date/time are required';
    } else {
        try {
            $stmt = $db->prepare("
                INSERT INTO reminders (user_id, title, description, reminder_datetime, priority, delivery_method, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$userId, $title, $description, $reminderDateTime, $priority, $deliveryMethod]);
            
            // Log activity
            logActivity($userId, 'reminder_create', 'reminders', $db->lastInsertId());
            
            $success = 'Reminder created successfully!';
        } catch (Exception $e) {
            $error = 'Failed to create reminder: ' . $e->getMessage();
        }
    }
}

// Handle reminder deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $reminderId = $_GET['delete'];
    
    try {
        $stmt = $db->prepare("DELETE FROM reminders WHERE id = ? AND user_id = ?");
        $stmt->execute([$reminderId, $userId]);
        
        logActivity($userId, 'reminder_delete', 'reminders', $reminderId);
        $success = 'Reminder deleted successfully!';
    } catch (Exception $e) {
        $error = 'Failed to delete reminder: ' . $e->getMessage();
    }
}

// Handle reminder status update
if (isset($_GET['complete']) && is_numeric($_GET['complete'])) {
    $reminderId = $_GET['complete'];
    
    try {
        $stmt = $db->prepare("UPDATE reminders SET status = 'completed' WHERE id = ? AND user_id = ?");
        $stmt->execute([$reminderId, $userId]);
        
        logActivity($userId, 'reminder_complete', 'reminders', $reminderId);
        $success = 'Reminder marked as completed!';
    } catch (Exception $e) {
        $error = 'Failed to update reminder: ' . $e->getMessage();
    }
}

// Pagination parameters
$page = max(1, intval($_GET['page'] ?? 1));
$remindersPerPage = 6;
$offset = ($page - 1) * $remindersPerPage;

// Get total count for pagination
$countStmt = $db->prepare("
    SELECT COUNT(*) as total
    FROM reminders 
    WHERE user_id = ?
");
$countStmt->execute([$userId]);
$totalReminders = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalReminders / $remindersPerPage);

// Get paginated user reminders
$stmt = $db->prepare("
    SELECT * FROM reminders 
    WHERE user_id = ? 
    ORDER BY reminder_datetime ASC
    LIMIT $remindersPerPage OFFSET $offset
");
$stmt->execute([$userId]);
$reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate upcoming and past reminders
$upcomingReminders = [];
$pastReminders = [];
$now = date('Y-m-d H:i:s');

foreach ($reminders as $reminder) {
    if ($reminder['reminder_datetime'] > $now && $reminder['status'] !== 'completed') {
        $upcomingReminders[] = $reminder;
    } else {
        $pastReminders[] = $reminder;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Reminders - AppNomu SalesQ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            min-height: 100vh;
            color: #e0e0e0;
        }
        .sidebar {
            background: linear-gradient(135deg, #1e1e1e 0%, #2a2a2a 100%);
            min-height: 100vh;
            box-shadow: 2px 0 5px rgba(0,0,0,0.3);
        }
        .nav-link {
            color: rgba(255, 255, 255, 0.8) !important;
            padding: 12px 15px;
            margin: 2px 0;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white !important;
        }
        .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            color: white !important;
            font-weight: 600;
        }
        .reminder-card {
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            transition: transform 0.3s;
            border: 1px solid #404040;
        }
        .reminder-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
        }
        .priority-high { border-left: 5px solid #dc3545; }
        .priority-medium { border-left: 5px solid #ffc107; }
        .priority-low { border-left: 5px solid #28a745; }
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
        .reminder-overdue {
            background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
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
                        <a class="nav-link" href="tickets.php">
                            <i class="fas fa-ticket-alt me-2"></i>Support Tickets
                        </a>
                        <a class="nav-link" href="withdrawal-salary.php">
                            <i class="fas fa-money-bill-wave me-2"></i>Salary Withdrawal
                        </a>
                        <a class="nav-link" href="documents.php">
                            <i class="fas fa-file-pdf me-2"></i>Documents
                        </a>
                        <a class="nav-link active" href="reminders.php">
                            <i class="fas fa-bell me-2"></i>Reminders
                        </a>
                    </nav>
                    
                    <div class="mt-auto pt-4">
                        <a href="../auth/logout.php" class="nav-link text-light">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-bell me-2"></i>My Reminders</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reminderModal">
                        <i class="fas fa-plus me-2"></i>Create Reminder
                    </button>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Upcoming Reminders -->
                <div class="mb-5">
                    <h4 class="mb-3"><i class="fas fa-clock me-2"></i>Upcoming Reminders</h4>
                    <?php if (empty($upcomingReminders)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-check fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No upcoming reminders</h5>
                            <p class="text-muted">Create a reminder to stay organized</p>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($upcomingReminders as $reminder): ?>
                                <?php
                                $isOverdue = $reminder['reminder_datetime'] < $now;
                                $priorityClass = 'priority-' . $reminder['priority'];
                                ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="reminder-card <?= $priorityClass ?> <?= $isOverdue ? 'reminder-overdue' : '' ?> p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="mb-0"><?= htmlspecialchars($reminder['title']) ?></h6>
                                            <span class="badge bg-<?= $reminder['priority'] === 'high' ? 'danger' : ($reminder['priority'] === 'medium' ? 'warning' : 'success') ?>">
                                                <?= ucfirst($reminder['priority']) ?>
                                            </span>
                                        </div>
                                        
                                        <?php if ($reminder['description']): ?>
                                            <p class="text-muted small mb-2"><?= htmlspecialchars($reminder['description']) ?></p>
                                        <?php endif; ?>
                                        
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <small class="text-muted">
                                                <i class="fas fa-calendar me-1"></i>
                                                <?= date('M j, Y g:i A', strtotime($reminder['reminder_datetime'])) ?>
                                            </small>
                                            <small class="text-muted">
                                                <i class="fas fa-<?= $reminder['delivery_method'] === 'sms' ? 'sms' : ($reminder['delivery_method'] === 'whatsapp' ? 'whatsapp' : 'bell') ?> me-1"></i>
                                                <?= ucfirst($reminder['delivery_method']) ?>
                                            </small>
                                        </div>
                                        
                                        <div class="btn-group w-100">
                                            <a href="?complete=<?= $reminder['id'] ?>" 
                                               class="btn btn-outline-success btn-sm">
                                                <i class="fas fa-check me-1"></i>Complete
                                            </a>
                                            <a href="?delete=<?= $reminder['id'] ?>" 
                                               class="btn btn-outline-danger btn-sm"
                                               onclick="return confirm('Are you sure you want to delete this reminder?')">
                                                <i class="fas fa-trash me-1"></i>Delete
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Past/Completed Reminders -->
                <?php if (!empty($pastReminders)): ?>
                    <div class="mb-5">
                        <h4 class="mb-3"><i class="fas fa-history me-2"></i>Past & Completed Reminders</h4>
                        <div class="row">
                            <?php foreach ($pastReminders as $reminder): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="reminder-card p-3 opacity-75">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="mb-0"><?= htmlspecialchars($reminder['title']) ?></h6>
                                            <span class="badge bg-<?= $reminder['status'] === 'completed' ? 'success' : 'secondary' ?>">
                                                <?= ucfirst($reminder['status']) ?>
                                            </span>
                                        </div>
                                        
                                        <?php if ($reminder['description']): ?>
                                            <p class="text-muted small mb-2"><?= htmlspecialchars($reminder['description']) ?></p>
                                        <?php endif; ?>
                                        
                                        <small class="text-muted">
                                            <i class="fas fa-calendar me-1"></i>
                                            <?= date('M j, Y g:i A', strtotime($reminder['reminder_datetime'])) ?>
                                        </small>
                                        
                                        <div class="mt-2">
                                            <a href="?delete=<?= $reminder['id'] ?>" 
                                               class="btn btn-outline-danger btn-sm"
                                               onclick="return confirm('Are you sure you want to delete this reminder?')">
                                                <i class="fas fa-trash me-1"></i>Delete
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Reminders pagination" class="mt-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="text-muted small">
                                Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $remindersPerPage, $totalReminders); ?> of <?php echo $totalReminders; ?> reminders
                            </div>
                            <ul class="pagination pagination-sm mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link bg-dark border-secondary text-light" href="?page=<?php echo ($page - 1); ?>">
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
                                        <a class="page-link <?php echo $i == $page ? 'bg-primary border-primary' : 'bg-dark border-secondary text-light'; ?>" 
                                           href="?page=<?php echo $i; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link bg-dark border-secondary text-light" href="?page=<?php echo ($page + 1); ?>">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </nav>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Reminder Modal -->
    <div class="modal fade" id="reminderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-bell me-2"></i>Create Reminder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Title *</label>
                            <input type="text" class="form-control" name="title" required
                                   placeholder="Enter reminder title">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"
                                      placeholder="Enter reminder description (optional)"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date *</label>
                                <input type="date" class="form-control" name="reminder_date" required
                                       min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Time *</label>
                                <input type="time" class="form-control" name="reminder_time" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select class="form-control" name="priority">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Delivery Method</label>
                                <select class="form-control" name="delivery_method">
                                    <option value="sms">SMS</option>
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="system">System Notification</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_reminder" class="btn btn-primary">
                            <i class="fas fa-bell me-2"></i>Create Reminder
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
