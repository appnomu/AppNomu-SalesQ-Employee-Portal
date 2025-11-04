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

// Handle task status update
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $taskId = intval($_POST['task_id']);
    $newStatus = sanitizeInput($_POST['status']);
    $progress = intval($_POST['progress_percentage'] ?? 0);
    $actualHours = floatval($_POST['actual_hours'] ?? 0);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    // Verify task belongs to current user
    $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ? AND assigned_to = ?");
    $stmt->execute([$taskId, $userId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($task) {
        $completionDate = ($newStatus === 'completed') ? 'NOW()' : 'NULL';
        
        $stmt = $db->prepare("
            UPDATE tasks 
            SET status = ?, progress_percentage = ?, actual_hours = ?, notes = ?,
                completion_date = {$completionDate}, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $progress, $actualHours, $notes, $taskId]);
        
        logActivity($userId, 'task_status_updated', 'tasks', $taskId);
        
        // Send notification to task creator
        try {
            require_once '../includes/infobip.php';
            $stmt = $db->prepare("
                SELECT u.email, ep.first_name, ep.last_name 
                FROM users u 
                LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
                WHERE u.id = ?
            ");
            $stmt->execute([$task['assigned_by']]);
            $assignedBy = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($assignedBy) {
                $infobip = new InfobipAPI();
                $subject = 'Task Status Updated - EP Portal';
                $statusMessage = "
                    <h2>Task Status Updated</h2>
                    <p><strong>Task:</strong> {$task['title']}</p>
                    <p><strong>Employee:</strong> {$_SESSION['user_name']}</p>
                    <p><strong>New Status:</strong> " . ucfirst(str_replace('_', ' ', $newStatus)) . "</p>
                    <p><strong>Updated:</strong> " . date('F j, Y H:i') . "</p>
                ";
                $infobip->sendEmail($assignedBy['email'], $subject, $statusMessage);
            }
        } catch (Exception $e) {
            // Email sending failed, but status was updated
        }
        
        redirectWithMessage('tasks', 'Task status updated successfully', 'success');
    } else {
        redirectWithMessage('tasks', 'Task not found or access denied', 'error');
    }
}

// Get filter parameters
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';

// Pagination parameters
$page = max(1, intval($_GET['page'] ?? 1));
$tasksPerPage = 4;
$offset = ($page - 1) * $tasksPerPage;

// Build query
$whereClause = "WHERE t.assigned_to = ?";
$params = [$userId];

if ($status) {
    $whereClause .= " AND t.status = ?";
    $params[] = $status;
}

if ($priority) {
    $whereClause .= " AND t.priority = ?";
    $params[] = $priority;
}

// Get total count for pagination
$countStmt = $db->prepare("
    SELECT COUNT(*) as total
    FROM tasks t 
    LEFT JOIN users u ON t.assigned_by = u.id
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id
    $whereClause
");
$countStmt->execute($params);
$totalTasks = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalTasks / $tasksPerPage);

// Get paginated tasks
$stmt = $db->prepare("
    SELECT t.*, 
           u.employee_number as assigned_by_number,
           ep.first_name as assigned_by_first_name,
           ep.last_name as assigned_by_last_name
    FROM tasks t 
    LEFT JOIN users u ON t.assigned_by = u.id
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id
    $whereClause
    ORDER BY 
        CASE t.priority 
            WHEN 'urgent' THEN 1 
            WHEN 'high' THEN 2 
            WHEN 'medium' THEN 3 
            WHEN 'low' THEN 4 
        END,
        t.due_date ASC,
        t.created_at DESC
    LIMIT $tasksPerPage OFFSET $offset
");
$stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get task statistics
$stmt = $db->prepare("
    SELECT 
        status,
        COUNT(*) as count
    FROM tasks 
    WHERE assigned_to = ?
    GROUP BY status
");
$stmt->execute([$userId]);
$taskStats = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $taskStats[$row['status']] = $row['count'];
}
?>
<!DOCTYPE html>
<html lang="en" style="background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); min-height: 100vh;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>My Tasks - AppNomu SalesQ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #e0e0e0;
            min-height: 100vh;
        }
        .container-fluid {
            background: transparent;
        }
        .col-md-9, .col-lg-10 {
            background: transparent;
        }
        .p-4 {
            background: transparent;
        }
        .row {
            background: transparent;
        }
        .col-md-6, .col-lg-4, .col-md-3, .col-md-4 {
            background: transparent;
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
            background: #2a2a2a;
            border: 1px solid #404040;
        }
        .priority-urgent { border-left: 5px solid #dc3545; }
        .priority-high { border-left: 5px solid #fd7e14; }
        .priority-medium { border-left: 5px solid #ffc107; }
        .priority-low { border-left: 5px solid #28a745; }
        .task-card {
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            transition: transform 0.3s;
            border: 1px solid #404040;
        }
        .task-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
        }
        .stat-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            transition: transform 0.3s;
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            border: 1px solid #404040;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
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
                        <a class="nav-link" href="profile">
                            <i class="fas fa-user me-2"></i>My Profile
                        </a>
                        <a class="nav-link" href="leave-requests">
                            <i class="fas fa-calendar-alt me-2"></i>Leave Requests
                        </a>
                        <a class="nav-link active" href="tasks">
                            <i class="fas fa-tasks me-2"></i>My Tasks
                        </a>
                        <a class="nav-link" href="tickets">
                            <i class="fas fa-ticket-alt me-2"></i>Support Tickets
                        </a>
                        <a class="nav-link" href="withdrawal-salary">
                            <i class="fas fa-money-bill-wave me-2"></i>Salary Withdrawal
                        </a>
                        <a class="nav-link" href="documents">
                            <i class="fas fa-file-pdf me-2"></i>Documents
                        </a>
                        <a class="nav-link" href="reminders">
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
                        <a href="../auth/logout" class="nav-link text-white-50">
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
                        <h2>My Tasks</h2>
                        <div style="color: #b0b0b0;">
                            <i class="fas fa-tasks me-2"></i><?php echo count($tasks); ?> Total Tasks
                        </div>
                    </div>
                    
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message['message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Task Statistics -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                                    <h3 class="mb-0"><?php echo $taskStats['pending'] ?? 0; ?></h3>
                                    <small style="color: #b0b0b0;">Pending</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <i class="fas fa-play fa-2x text-primary mb-2"></i>
                                    <h3 class="mb-0"><?php echo $taskStats['in_progress'] ?? 0; ?></h3>
                                    <small style="color: #b0b0b0;">In Progress</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <i class="fas fa-check fa-2x text-success mb-2"></i>
                                    <h3 class="mb-0"><?php echo $taskStats['completed'] ?? 0; ?></h3>
                                    <small style="color: #b0b0b0;">Completed</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body text-center">
                                    <i class="fas fa-times fa-2x text-danger mb-2"></i>
                                    <h3 class="mb-0"><?php echo $taskStats['cancelled'] ?? 0; ?></h3>
                                    <small style="color: #b0b0b0;">Cancelled</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="card table-card mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-4">
                                    <select class="form-select" name="status">
                                        <option value="">All Status</option>
                                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select" name="priority">
                                        <option value="">All Priorities</option>
                                        <option value="urgent" <?php echo $priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                        <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>High</option>
                                        <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>Low</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-outline-primary w-100">
                                        <i class="fas fa-filter me-2"></i>Apply Filters
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Tasks List -->
                    <?php if (empty($tasks)): ?>
                        <div class="card table-card">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-tasks fa-4x mb-3" style="color: #666;"></i>
                                <h4 style="color: #e0e0e0;">No Tasks Found</h4>
                                <p style="color: #b0b0b0;">You don't have any tasks assigned yet or matching the current filters.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Task</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Progress</th>
                                        <th>Assigned By</th>
                                        <th>Due Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tasks as $task): ?>
                                        <?php 
                                            $priorityClass = 'secondary';
                                            if ($task['priority'] === 'urgent') $priorityClass = 'danger';
                                            elseif ($task['priority'] === 'high') $priorityClass = 'warning';
                                            elseif ($task['priority'] === 'medium') $priorityClass = 'info';
                                            elseif ($task['priority'] === 'low') $priorityClass = 'success';
                                            
                                            $statusClass = 'secondary';
                                            if ($task['status'] === 'pending') $statusClass = 'secondary';
                                            elseif ($task['status'] === 'in_progress') $statusClass = 'primary';
                                            elseif ($task['status'] === 'completed') $statusClass = 'success';
                                            elseif ($task['status'] === 'cancelled') $statusClass = 'danger';
                                        ?>
                                        <tr class="task-row priority-<?php echo $task['priority']; ?>">
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                                    <?php if ($task['description']): ?>
                                                        <br><small class="text-muted"><?php echo strlen($task['description']) > 60 ? substr($task['description'], 0, 60) . '...' : $task['description']; ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $priorityClass; ?>">
                                                    <?php echo ucfirst($task['priority']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $statusClass; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="progress" style="height: 8px; width: 100px; background-color: #404040;">
                                                    <div class="progress-bar bg-success" role="progressbar" 
                                                         style="width: <?php echo $task['progress_percentage'] ?? 0; ?>%"></div>
                                                </div>
                                                <small class="text-muted"><?php echo $task['progress_percentage'] ?? 0; ?>%</small>
                                            </td>
                                            <td>
                                                <div>
                                                    <i class="fas fa-user me-1"></i>
                                                    <?php echo $task['assigned_by_first_name'] . ' ' . $task['assigned_by_last_name']; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($task['due_date']): ?>
                                                    <small class="<?php echo strtotime($task['due_date']) < time() ? 'text-danger fw-bold' : 'text-muted'; ?>">
                                                        <?php echo date('M j, Y', strtotime($task['due_date'])); ?>
                                                        <br><?php echo date('g:i A', strtotime($task['due_date'])); ?>
                                                        <?php if (strtotime($task['due_date']) < time()): ?>
                                                            <br><i class="fas fa-exclamation-triangle"></i> Overdue
                                                        <?php endif; ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">No due date</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-primary" onclick="viewTask(<?php echo $task['id']; ?>)" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($task['status'] !== 'completed' && $task['status'] !== 'cancelled'): ?>
                                                        <button class="btn btn-outline-warning" onclick="updateTaskProgress(<?php echo $task['id']; ?>)" title="Update Progress">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <?php if ($task['status'] === 'pending'): ?>
                                                            <button class="btn btn-outline-success" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'in_progress')" title="Start Task">
                                                                <i class="fas fa-play"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if ($task['status'] === 'in_progress'): ?>
                                                            <button class="btn btn-outline-info" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'completed')" title="Mark Complete">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <nav aria-label="Tasks pagination" class="mt-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-muted small">
                                        Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $tasksPerPage, $totalTasks); ?> of <?php echo $totalTasks; ?> tasks
                                    </div>
                                    <ul class="pagination pagination-sm mb-0">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link bg-dark border-secondary text-light" href="?page=<?php echo ($page - 1); ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?>">
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
                                                   href="?page=<?php echo $i; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $totalPages): ?>
                                            <li class="page-item">
                                                <a class="page-link bg-dark border-secondary text-light" href="?page=<?php echo ($page + 1); ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?>">
                                                    <i class="fas fa-chevron-right"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Task Details Modal -->
    <div class="modal fade" id="taskDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-light">
                        <i class="fas fa-tasks me-2"></i>Task Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-dark text-light" id="taskDetailsContent">
                    <!-- Task details will be loaded here -->
                </div>
                <div class="modal-footer border-secondary bg-dark">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Update Progress Modal -->
    <div class="modal fade" id="updateProgressModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">
                        <i class="fas fa-chart-line me-2"></i>Update Task Progress
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="progressForm">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="task_id" id="progress_task_id">
                    <input type="hidden" name="status" id="progress_status">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Task Status</label>
                            <select class="form-select bg-dark text-light border-secondary" name="status" id="status_select" required>
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Progress Percentage</label>
                            <div class="input-group">
                                <input type="range" class="form-range" name="progress_percentage" id="progress_range" 
                                       min="0" max="100" value="0" oninput="updateProgressDisplay()">
                                <span class="input-group-text bg-dark text-light border-secondary" id="progress_display">0%</span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Actual Hours Worked</label>
                            <input type="number" class="form-control bg-dark text-light border-secondary" 
                                   name="actual_hours" step="0.5" min="0" placeholder="0.0">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Progress Notes</label>
                            <textarea class="form-control bg-dark text-light border-secondary" 
                                      name="notes" rows="3" placeholder="Add notes about your progress..."></textarea>
                        </div>
                        
                        <div class="alert alert-info bg-dark border-info text-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Your task creator will be notified of this progress update via email.
                        </div>
                    </div>
                    
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Progress
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewTask(taskId) {
            // Show loading state
            const modal = new bootstrap.Modal(document.getElementById('taskDetailsModal'));
            const content = document.getElementById('taskDetailsContent');
            
            content.innerHTML = `
                <div class="d-flex justify-content-center align-items-center" style="height: 200px;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            
            modal.show();
            
            // Load task details via API
            fetch(`../api/get-task?id=${taskId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const task = data.task;
                        
                        // Format dates
                        const createdDate = new Date(task.created_at).toLocaleDateString('en-US', {
                            year: 'numeric', month: 'short', day: 'numeric', 
                            hour: '2-digit', minute: '2-digit'
                        });
                        const dueDate = task.due_date ? new Date(task.due_date).toLocaleDateString('en-US', {
                            year: 'numeric', month: 'short', day: 'numeric', 
                            hour: '2-digit', minute: '2-digit'
                        }) : 'No due date';
                        
                        // Priority and status badges
                        const priorityClass = {
                            'urgent': 'danger', 'high': 'warning', 
                            'medium': 'info', 'low': 'success'
                        }[task.priority] || 'secondary';
                        
                        const statusClass = {
                            'pending': 'secondary', 'in_progress': 'primary',
                            'completed': 'success', 'cancelled': 'danger'
                        }[task.status] || 'secondary';
                        
                        // Build comments HTML
                        let commentsHtml = '';
                        if (task.comments && task.comments.length > 0) {
                            commentsHtml = task.comments.map(comment => {
                                const commentDate = new Date(comment.created_at).toLocaleDateString('en-US', {
                                    year: 'numeric', month: 'short', day: 'numeric', 
                                    hour: '2-digit', minute: '2-digit'
                                });
                                const formattedComment = comment.comment.replace(/\n/g, '<br>').replace(/\r\n/g, '<br>');
                                return `
                                    <div class="border-bottom border-secondary pb-2 mb-2">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <strong class="text-light">${comment.first_name} ${comment.last_name}</strong>
                                            <small class="text-muted">${commentDate}</small>
                                        </div>
                                        <p class="mb-0 mt-1 text-light" style="line-height: 1.6;">${formattedComment}</p>
                                    </div>
                                `;
                            }).join('');
                        } else {
                            commentsHtml = '<p class="text-muted">No comments yet.</p>';
                        }
                        
                        // Format description with proper line breaks
                        const formattedDescription = task.description ? 
                            task.description.replace(/\n/g, '<br>').replace(/\r\n/g, '<br>') : 
                            'No description provided.';
                        
                        content.innerHTML = `
                            <div class="row">
                                <div class="col-md-8">
                                    <h4 class="text-light mb-3">${task.title}</h4>
                                    <div class="mb-4">
                                        <p class="text-light" style="line-height: 1.6; max-height: 120px; overflow-y: auto;">${formattedDescription}</p>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-sm-6">
                                            <strong class="text-light">Priority:</strong><br>
                                            <span class="badge bg-${priorityClass} mt-1">${task.priority.charAt(0).toUpperCase() + task.priority.slice(1)}</span>
                                        </div>
                                        <div class="col-sm-6">
                                            <strong class="text-light">Status:</strong><br>
                                            <span class="badge bg-${statusClass} mt-1">${task.status.replace('_', ' ').charAt(0).toUpperCase() + task.status.replace('_', ' ').slice(1)}</span>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <strong class="text-light">Progress</strong>
                                            <span class="text-light">${task.progress_percentage || 0}%</span>
                                        </div>
                                        <div class="progress" style="height: 10px; background-color: #404040;">
                                            <div class="progress-bar bg-success" style="width: ${task.progress_percentage || 0}%"></div>
                                        </div>
                                    </div>
                                    
                                    ${task.estimated_hours ? `
                                        <div class="mb-3">
                                            <strong class="text-light">Hours:</strong> 
                                            <span class="text-muted">Estimated: ${task.estimated_hours}</span>
                                            ${task.actual_hours ? ` | <span class="text-muted">Actual: ${task.actual_hours}</span>` : ''}
                                        </div>
                                    ` : ''}
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="card bg-secondary border-secondary">
                                        <div class="card-body">
                                            <h6 class="card-title text-light mb-3">Task Information</h6>
                                            <div class="mb-3">
                                                <strong class="text-light">Assigned By:</strong><br>
                                                <span class="text-muted">${task.assigner_first_name} ${task.assigner_last_name}</span>
                                            </div>
                                            <div class="mb-3">
                                                <strong class="text-light">Created:</strong><br>
                                                <span class="text-muted">${createdDate}</span>
                                            </div>
                                            <div class="mb-0">
                                                <strong class="text-light">Due Date:</strong><br>
                                                <span class="text-muted">${dueDate}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            ${task.comments && task.comments.length > 0 ? `
                                <hr class="border-secondary my-4">
                                <h5 class="text-light mb-3">Comments & Updates</h5>
                                <div style="max-height: 200px; overflow-y: auto;">
                                    ${task.comments.slice(0, 3).map(comment => {
                                        const commentDate = new Date(comment.created_at).toLocaleDateString('en-US', {
                                            year: 'numeric', month: 'short', day: 'numeric', 
                                            hour: '2-digit', minute: '2-digit'
                                        });
                                        const formattedComment = comment.comment.replace(/\n/g, '<br>').replace(/\r\n/g, '<br>');
                                        return `
                                            <div class="mb-3 pb-3 border-bottom border-secondary">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <strong class="text-light">${comment.first_name} ${comment.last_name}</strong>
                                                    <small class="text-muted">${commentDate}</small>
                                                </div>
                                                <p class="mb-0 text-light" style="line-height: 1.5;">${formattedComment}</p>
                                            </div>
                                        `;
                                    }).join('')}
                                    ${task.comments.length > 3 ? `<small class="text-muted">+ ${task.comments.length - 3} more comments</small>` : ''}
                                </div>
                            ` : ''}
                        `;
                    } else {
                        content.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Error loading task details: ${data.message}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading task details:', error);
                    content.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Failed to load task details. Please try again.
                        </div>
                    `;
                });
        }
        
        function updateTaskProgress(taskId) {
            // Fetch current task data to populate the modal
            fetch(`../api/get-task?id=${taskId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const task = data.task;
                        
                        // Populate modal with current task data
                        document.getElementById('progress_task_id').value = taskId;
                        document.getElementById('status_select').value = task.status || 'pending';
                        document.getElementById('progress_range').value = task.progress_percentage || 0;
                        document.querySelector('input[name="actual_hours"]').value = task.actual_hours || '';
                        document.querySelector('textarea[name="notes"]').value = task.notes || '';
                        
                        updateProgressDisplay();
                        
                        // Show modal
                        new bootstrap.Modal(document.getElementById('updateProgressModal')).show();
                    } else {
                        alert('Error loading task details: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load task details');
                });
        }
        
        function updateProgressDisplay() {
            const range = document.getElementById('progress_range');
            const display = document.getElementById('progress_display');
            display.textContent = range.value + '%';
        }
        
        function updateTaskStatus(taskId, status) {
            if (confirm(`Are you sure you want to mark this task as ${status.replace('_', ' ')}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="task_id" value="${taskId}">
                    <input type="hidden" name="status" value="${status}">
                    <input type="hidden" name="progress_percentage" value="${status === 'completed' ? '100' : '0'}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
