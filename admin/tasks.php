<?php
require_once '../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/system-settings.php';
require_once '../config/config.php';

// Start secure session first
startSecureSession();
requireAuth();

// Ensure user is admin
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../employee/dashboard');
    exit();
}

$message = getFlashMessage();

// Handle task creation
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'create') {
    $title = sanitizeInput($_POST['title']);
    $description = sanitizeInput($_POST['description']);
    $assignedTo = intval($_POST['assigned_to']);
    $priority = sanitizeInput($_POST['priority']);
    $dueDate = sanitizeInput($_POST['due_date']);
    $estimatedHours = floatval($_POST['estimated_hours']);
    
    if ($title && $assignedTo && $dueDate) {
        try {
            $stmt = $db->prepare("
                INSERT INTO tasks (title, description, assigned_to, assigned_by, priority, due_date, estimated_hours)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $description, $assignedTo, $_SESSION['user_id'], $priority, $dueDate, $estimatedHours]);
            
            // Log activity
            logActivity($_SESSION['user_id'], 'task_created', 'tasks', $db->lastInsertId(), "Created task: {$title} for employee ID: {$assignedTo}");
            
            // Send SMS notification to assigned employee
            try {
                require_once '../includes/infobip.php';
                $stmt = $db->prepare("SELECT u.phone, ep.first_name FROM users u LEFT JOIN employee_profiles ep ON u.id = ep.user_id WHERE u.id = ?");
                $stmt->execute([$assignedTo]);
                $employee = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($employee && $employee['phone']) {
                    $message = "Hello {$employee['first_name']}, you have been assigned a new task: {$title}. Due date: " . date('M j, Y', strtotime($dueDate)) . ". Please check EP Portal for details.";
                    $infobip = new InfobipAPI();
                    $infobip->sendSMS($employee['phone'], $message, 'AppNomu');
                }
            } catch (Exception $e) {
                error_log("Task assignment SMS error: " . $e->getMessage());
            }
            
            redirectWithMessage('tasks.php', 'Task created successfully', 'success');
        } catch (Exception $e) {
            redirectWithMessage('tasks.php', 'Failed to create task: ' . $e->getMessage(), 'error');
        }
    } else {
        error_log("Task update validation failed - TaskID: $taskId, Status: $status");
        redirectWithMessage('tasks.php', 'Invalid task data provided', 'error');
    }
}

// Handle task status update
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $taskId = intval($_POST['task_id']);
    $status = sanitizeInput($_POST['status']);
    $progress = intval($_POST['progress_percentage']);
    $actualHours = floatval($_POST['actual_hours']);
    $notes = sanitizeInput($_POST['notes']);
    
    if ($taskId && $status) {
        try {
            if ($status === 'completed') {
                $stmt = $db->prepare("
                    UPDATE tasks 
                    SET status = ?, progress_percentage = ?, actual_hours = ?, notes = ?, 
                        completion_date = NOW(), updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$status, $progress, $actualHours, $notes, $taskId]);
            } else {
                $stmt = $db->prepare("
                    UPDATE tasks 
                    SET status = ?, progress_percentage = ?, actual_hours = ?, notes = ?, 
                        completion_date = NULL, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$status, $progress, $actualHours, $notes, $taskId]);
            }
            
            // Log activity
            logActivity($_SESSION['user_id'], 'task_updated', 'tasks', $taskId, "Updated task ID: {$taskId} to status: {$status}");
            
            redirectWithMessage('tasks.php', 'Task updated successfully', 'success');
        } catch (Exception $e) {
            error_log("Task update error: " . $e->getMessage());
            error_log("Task ID: $taskId, Status: $status, Progress: $progress, Hours: $actualHours");
            redirectWithMessage('tasks.php', 'Failed to update task: ' . $e->getMessage(), 'error');
        }
    }
}

// Get filter parameters
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$assignedTo = $_GET['assigned_to'] ?? '';

// Pagination parameters
$page = max(1, intval($_GET['page'] ?? 1));
$tasksPerPage = 5;
$offset = ($page - 1) * $tasksPerPage;

// Build query with filters
$whereClause = "WHERE 1=1";
$params = [];

if ($status) {
    $whereClause .= " AND t.status = ?";
    $params[] = $status;
}

if ($priority) {
    $whereClause .= " AND t.priority = ?";
    $params[] = $priority;
}

if ($assignedTo) {
    $whereClause .= " AND t.assigned_to = ?";
    $params[] = $assignedTo;
}

// Update overdue tasks
$db->exec("UPDATE tasks SET status = 'overdue' WHERE status IN ('pending', 'in_progress') AND due_date < CURDATE()");

// Get total count for pagination
$countStmt = $db->prepare("
    SELECT COUNT(*) as total
    FROM tasks t 
    JOIN users assignee ON t.assigned_to = assignee.id 
    LEFT JOIN employee_profiles assignee_profile ON assignee.id = assignee_profile.user_id 
    JOIN users assigner ON t.assigned_by = assigner.id 
    LEFT JOIN employee_profiles assigner_profile ON assigner.id = assigner_profile.user_id 
    {$whereClause}
");
$countStmt->execute($params);
$totalTasks = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalTasks / $tasksPerPage);

// Get paginated tasks with employee details
$stmt = $db->prepare("
    SELECT t.*, 
           assignee.employee_number as assignee_number,
           assignee_profile.first_name as assignee_first_name,
           assignee_profile.last_name as assignee_last_name,
           assignee_profile.department as assignee_department,
           assigner.employee_number as assigner_number,
           assigner_profile.first_name as assigner_first_name,
           assigner_profile.last_name as assigner_last_name
    FROM tasks t 
    JOIN users assignee ON t.assigned_to = assignee.id 
    LEFT JOIN employee_profiles assignee_profile ON assignee.id = assignee_profile.user_id 
    JOIN users assigner ON t.assigned_by = assigner.id 
    LEFT JOIN employee_profiles assigner_profile ON assigner.id = assigner_profile.user_id 
    {$whereClause}
    ORDER BY 
        CASE t.status 
            WHEN 'pending' THEN 1 
            WHEN 'overdue' THEN 2 
            WHEN 'in_progress' THEN 3 
            WHEN 'completed' THEN 4 
        END,
        t.created_at DESC,
        t.due_date ASC
    LIMIT {$tasksPerPage} OFFSET {$offset}
");
$stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get employees for assignment dropdown
$stmt = $db->prepare("
    SELECT u.id, u.employee_number, ep.first_name, ep.last_name, ep.department 
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    WHERE u.role = 'employee' AND u.status = 'active'
    ORDER BY ep.first_name, ep.last_name
");
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Tasks - AppNomu SalesQ</title>
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32)); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
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
        .table-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        .task-priority {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
        .progress-bar-container {
            height: 8px;
            background-color: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #28a745 0%, #20c997 100%);
            transition: width 0.3s ease;
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
                        <a class="nav-link active" href="tasks.php">
                            <i class="fas fa-tasks me-2"></i>Tasks
                        </a>
                        <a class="nav-link" href="tickets.php">
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
                            <i class="fas fa-user me-2"></i><?php echo $_SESSION['user_name']; ?>
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
                        <h2>Task Management</h2>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTaskModal">
                            <i class="fas fa-plus me-2"></i>Create Task
                        </button>
                    </div>
                    
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message['message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Filters -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <select class="form-select" name="status">
                                        <option value="">All Status</option>
                                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="overdue" <?php echo $status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" name="priority">
                                        <option value="">All Priority</option>
                                        <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>Low</option>
                                        <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>High</option>
                                        <option value="urgent" <?php echo $priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select" name="assigned_to">
                                        <option value="">All Employees</option>
                                        <?php foreach ($employees as $employee): ?>
                                            <option value="<?php echo $employee['id']; ?>" <?php echo $assignedTo == $employee['id'] ? 'selected' : ''; ?>>
                                                <?php echo $employee['first_name'] . ' ' . $employee['last_name']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-outline-primary w-100">
                                        <i class="fas fa-filter"></i> Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Tasks Table -->
                    <div class="card table-card">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Task</th>
                                            <th>Assigned To</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Progress</th>
                                            <th>Due Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($tasks)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4">
                                                <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                                                <p class="text-muted">No tasks found</p>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($tasks as $task): ?>
                                            <tr>
                                                <td>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                                        <?php if ($task['description']): ?>
                                                            <br><small class="text-muted"><?php echo strlen($task['description']) > 50 ? substr(htmlspecialchars($task['description']), 0, 50) . '...' : htmlspecialchars($task['description']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong><?php echo $task['assignee_first_name'] . ' ' . $task['assignee_last_name']; ?></strong>
                                                        <br><small class="text-muted"><?php echo $task['assignee_number']; ?></small>
                                                        <?php if ($task['assignee_department']): ?>
                                                            <br><small class="text-muted"><?php echo $task['assignee_department']; ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php 
                                                        $priorityClass = 'secondary';
                                                        if ($task['priority'] === 'urgent') $priorityClass = 'danger';
                                                        elseif ($task['priority'] === 'high') $priorityClass = 'warning';
                                                        elseif ($task['priority'] === 'medium') $priorityClass = 'info';
                                                    ?>
                                                    <span class="badge task-priority bg-<?php echo $priorityClass; ?>">
                                                        <?php echo ucfirst($task['priority']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php 
                                                        $statusClass = 'warning';
                                                        if ($task['status'] === 'completed') $statusClass = 'success';
                                                        elseif ($task['status'] === 'in_progress') $statusClass = 'primary';
                                                        elseif ($task['status'] === 'overdue') $statusClass = 'danger';
                                                    ?>
                                                    <span class="badge bg-<?php echo $statusClass; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="progress-bar-container mb-1">
                                                        <div class="progress-bar" style="width: <?php echo $task['progress_percentage'] ?? 0; ?>%"></div>
                                                    </div>
                                                    <small class="text-muted"><?php echo $task['progress_percentage'] ?? 0; ?>%</small>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $dueDate = new DateTime($task['due_date']);
                                                    $today = new DateTime();
                                                    $isOverdue = $dueDate < $today && $task['status'] !== 'completed';
                                                    ?>
                                                    <span class="<?php echo $isOverdue ? 'text-danger fw-bold' : ''; ?>">
                                                        <?php echo $dueDate->format('M j, Y'); ?>
                                                    </span>
                                                    <?php if ($isOverdue): ?>
                                                        <br><small class="text-danger">
                                                            <i class="fas fa-exclamation-triangle"></i> Overdue
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" onclick="viewTask(<?php echo $task['id']; ?>)">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <button class="btn btn-outline-warning" onclick="updateTask(<?php echo $task['id']; ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <div class="text-muted">
                            Showing <?php echo ($offset + 1); ?> to <?php echo min($offset + $tasksPerPage, $totalTasks); ?> of <?php echo $totalTasks; ?> tasks
                        </div>
                        <nav aria-label="Tasks pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <!-- Previous Button -->
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?><?php echo $assignedTo ? '&assigned_to=' . urlencode($assignedTo) : ''; ?>">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled">
                                        <span class="page-link"><i class="fas fa-chevron-left"></i></span>
                                    </li>
                                <?php endif; ?>
                                
                                <!-- Page Numbers -->
                                <?php 
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                
                                if ($startPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=1<?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?><?php echo $assignedTo ? '&assigned_to=' . urlencode($assignedTo) : ''; ?>">1</a>
                                    </li>
                                    <?php if ($startPage > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?><?php echo $assignedTo ? '&assigned_to=' . urlencode($assignedTo) : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $totalPages; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?><?php echo $assignedTo ? '&assigned_to=' . urlencode($assignedTo) : ''; ?>"><?php echo $totalPages; ?></a>
                                    </li>
                                <?php endif; ?>
                                
                                <!-- Next Button -->
                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?><?php echo $assignedTo ? '&assigned_to=' . urlencode($assignedTo) : ''; ?>">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled">
                                        <span class="page-link"><i class="fas fa-chevron-right"></i></span>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Task Modal -->
    <div class="modal fade" id="createTaskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Create New Task
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label">Task Title *</label>
                                    <input type="text" class="form-control" name="title" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Priority *</label>
                                    <select class="form-select" name="priority" required>
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="Task description and requirements..."></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Assign To *</label>
                                    <select class="form-select" name="assigned_to" required>
                                        <option value="">Select Employee</option>
                                        <?php foreach ($employees as $employee): ?>
                                            <option value="<?php echo $employee['id']; ?>">
                                                <?php echo $employee['first_name'] . ' ' . $employee['last_name'] . ' (' . $employee['employee_number'] . ')'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Due Date *</label>
                                    <input type="date" class="form-control" name="due_date" required min="<?php echo date('Y-m-d'); ?>" onchange="calculateEstimatedHours()">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Estimated Hours</label>
                                    <input type="number" class="form-control" name="estimated_hours" step="0.5" min="0.5" placeholder="Auto-calculated" readonly>
                                    <small class="text-muted">Auto-calculated based on due date</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Create Task
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function calculateEstimatedHours() {
            const dueDateInput = document.querySelector('input[name="due_date"]');
            const estimatedHoursInput = document.querySelector('input[name="estimated_hours"]');
            
            if (dueDateInput.value) {
                const today = new Date();
                const dueDate = new Date(dueDateInput.value);
                
                // Calculate working days between today and due date
                const timeDiff = dueDate.getTime() - today.getTime();
                const daysDiff = Math.ceil(timeDiff / (1000 * 3600 * 24));
                
                // Calculate working days (exclude weekends)
                let workingDays = 0;
                const currentDate = new Date(today);
                
                while (currentDate <= dueDate) {
                    const dayOfWeek = currentDate.getDay();
                    if (dayOfWeek !== 0 && dayOfWeek !== 6) { // Not Sunday (0) or Saturday (6)
                        workingDays++;
                    }
                    currentDate.setDate(currentDate.getDate() + 1);
                }
                
                // Estimate 8 hours per working day (standard work day)
                const estimatedHours = Math.max(workingDays * 8, 8); // Minimum 8 hours
                estimatedHoursInput.value = estimatedHours;
                estimatedHoursInput.removeAttribute('readonly');
            }
        }
        function viewTask(id) {
            // Fetch and display task details
            fetch(`../api/get-task?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showTaskModal(data.task);
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while fetching task details.');
                });
        }
        
        function updateTask(id) {
            // Fetch task details first
            fetch(`../api/get-task?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showUpdateTaskModal(data.task);
                    } else {
                        alert('Error loading task details: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while fetching task details.');
                });
        }
        
        function showUpdateTaskModal(task) {
            const modalHtml = `
                <div class="modal fade" id="updateTaskModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-edit me-2"></i>Update Task
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form id="updateTaskForm">
                                <div class="modal-body">
                                    <input type="hidden" id="update_task_id" value="${task.id}">
                                    
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="mb-3">
                                                <label for="update_title" class="form-label">Task Title</label>
                                                <input type="text" class="form-control" id="update_title" value="${task.title}" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="update_priority" class="form-label">Priority</label>
                                                <select class="form-select" id="update_priority" required>
                                                    <option value="low" ${task.priority === 'low' ? 'selected' : ''}>Low</option>
                                                    <option value="medium" ${task.priority === 'medium' ? 'selected' : ''}>Medium</option>
                                                    <option value="high" ${task.priority === 'high' ? 'selected' : ''}>High</option>
                                                    <option value="urgent" ${task.priority === 'urgent' ? 'selected' : ''}>Urgent</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="update_description" class="form-label">Description</label>
                                        <textarea class="form-control" id="update_description" rows="3">${task.description || ''}</textarea>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="update_status" class="form-label">Status</label>
                                                <select class="form-select" id="update_status" required>
                                                    <option value="pending" ${task.status === 'pending' ? 'selected' : ''}>Pending</option>
                                                    <option value="in_progress" ${task.status === 'in_progress' ? 'selected' : ''}>In Progress</option>
                                                    <option value="completed" ${task.status === 'completed' ? 'selected' : ''}>Completed</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="update_progress" class="form-label">Progress (%)</label>
                                                <input type="number" class="form-control" id="update_progress" min="0" max="100" value="${task.progress_percentage || 0}">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="update_due_date" class="form-label">Due Date</label>
                                                <input type="date" class="form-control" id="update_due_date" value="${task.due_date ? task.due_date.split(' ')[0] : ''}" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="update_estimated_hours" class="form-label">Estimated Hours</label>
                                                <input type="number" class="form-control" id="update_estimated_hours" step="0.5" value="${task.estimated_hours || ''}">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="update_actual_hours" class="form-label">Actual Hours</label>
                                                <input type="number" class="form-control" id="update_actual_hours" step="0.5" value="${task.actual_hours || ''}">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="update_notes" class="form-label">Notes</label>
                                        <textarea class="form-control" id="update_notes" rows="2">${task.notes || ''}</textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="update_comment" class="form-label">Add Comment (Optional)</label>
                                        <textarea class="form-control" id="update_comment" rows="2" placeholder="Add a comment about this update..."></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Update Task
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal
            const existingModal = document.getElementById('updateTaskModal');
            if (existingModal) existingModal.remove();
            
            // Add modal to body
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('updateTaskModal'));
            modal.show();
            
            // Handle form submission
            document.getElementById('updateTaskForm').addEventListener('submit', function(e) {
                e.preventDefault();
                submitTaskUpdate();
            });
            
            // Auto-remove modal after it's hidden
            modal._element.addEventListener('hidden.bs.modal', () => {
                modal._element.remove();
            });
        }
        
        function submitTaskUpdate() {
            const formData = {
                task_id: document.getElementById('update_task_id').value,
                title: document.getElementById('update_title').value,
                description: document.getElementById('update_description').value,
                priority: document.getElementById('update_priority').value,
                status: document.getElementById('update_status').value,
                progress_percentage: document.getElementById('update_progress').value,
                due_date: document.getElementById('update_due_date').value,
                estimated_hours: document.getElementById('update_estimated_hours').value,
                actual_hours: document.getElementById('update_actual_hours').value,
                notes: document.getElementById('update_notes').value,
                comment: document.getElementById('update_comment').value
            };
            
            // Add CSRF token to form data
            formData.csrf_token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            
            fetch('../api/update-task', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Hide modal
                    bootstrap.Modal.getInstance(document.getElementById('updateTaskModal')).hide();
                    
                    // Show success message
                    alert('Task updated successfully!');
                    
                    // Reload page to show updated data
                    location.reload();
                } else {
                    alert('Error updating task: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the task.');
            });
        }
        
        function showTaskModal(task) {
            const modalHtml = `
                <div class="modal fade" id="viewTaskModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-tasks me-2"></i>Task Details
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <h4>${task.title}</h4>
                                        <p class="text-muted mb-3">${task.description || 'No description provided'}</p>
                                    </div>
                                    <div class="col-md-4">
                                        <span class="badge bg-${task.priority === 'urgent' ? 'danger' : (task.priority === 'high' ? 'warning' : (task.priority === 'medium' ? 'info' : 'secondary'))} fs-6">
                                            ${task.priority.charAt(0).toUpperCase() + task.priority.slice(1)} Priority
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <h6 class="text-muted mb-3">Assignment Details</h6>
                                        <p><strong>Assigned To:</strong> ${task.assignee_first_name} ${task.assignee_last_name}</p>
                                        <p><strong>Employee Number:</strong> ${task.assignee_number}</p>
                                        <p><strong>Department:</strong> ${task.assignee_department || 'N/A'}</p>
                                        <p><strong>Position:</strong> ${task.assignee_position || 'N/A'}</p>
                                        <p><strong>Assigned By:</strong> ${task.assigner_first_name} ${task.assigner_last_name}</p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-muted mb-3">Task Progress</h6>
                                        <p><strong>Status:</strong> 
                                            <span class="badge bg-${task.status === 'completed' ? 'success' : (task.status === 'in_progress' ? 'primary' : (task.status === 'overdue' ? 'danger' : 'warning'))}">
                                                ${task.status.charAt(0).toUpperCase() + task.status.slice(1).replace('_', ' ')}
                                            </span>
                                        </p>
                                        <p><strong>Progress:</strong> ${task.progress_percentage || 0}%</p>
                                        <div class="progress mb-3" style="height: 10px;">
                                            <div class="progress-bar bg-success" style="width: ${task.progress_percentage || 0}%"></div>
                                        </div>
                                        <p><strong>Due Date:</strong> ${new Date(task.due_date).toLocaleDateString()}</p>
                                        <p><strong>Created:</strong> ${new Date(task.created_at).toLocaleDateString()}</p>
                                        ${task.completion_date ? `<p><strong>Completed:</strong> ${new Date(task.completion_date).toLocaleDateString()}</p>` : ''}
                                    </div>
                                </div>
                                
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <p><strong>Estimated Hours:</strong> ${task.estimated_hours || 'Not specified'}</p>
                                        <p><strong>Actual Hours:</strong> ${task.actual_hours || 'Not recorded'}</p>
                                    </div>
                                    <div class="col-md-6">
                                        ${task.start_date ? `<p><strong>Started:</strong> ${new Date(task.start_date).toLocaleDateString()}</p>` : ''}
                                    </div>
                                </div>
                                
                                ${task.notes ? `
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <h6 class="text-muted mb-2">Notes</h6>
                                            <div class="alert alert-light">
                                                ${task.notes}
                                            </div>
                                        </div>
                                    </div>
                                ` : ''}
                                
                                ${task.comments && task.comments.length > 0 ? `
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <h6 class="text-muted mb-3">Comments & Updates</h6>
                                            ${task.comments.map(comment => `
                                                <div class="border-start border-3 border-primary ps-3 mb-3">
                                                    <div class="d-flex justify-content-between">
                                                        <strong>${comment.first_name} ${comment.last_name}</strong>
                                                        <small class="text-muted">${new Date(comment.created_at).toLocaleString()}</small>
                                                    </div>
                                                    <p class="mb-0 mt-1">${comment.comment}</p>
                                                </div>
                                            `).join('')}
                                        </div>
                                    </div>
                                ` : ''}
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-warning" onclick="updateTask(${task.id})" data-bs-dismiss="modal">
                                    <i class="fas fa-edit me-2"></i>Update Task
                                </button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal
            const existingModal = document.getElementById('viewTaskModal');
            if (existingModal) existingModal.remove();
            
            // Add modal to body
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('viewTaskModal'));
            modal.show();
            
            // Auto-remove modal after it's hidden
            modal._element.addEventListener('hidden.bs.modal', () => {
                modal._element.remove();
            });
        }
    </script>
</body>
</html>
