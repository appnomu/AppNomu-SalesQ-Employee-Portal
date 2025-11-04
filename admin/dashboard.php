<?php
require_once '../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/system-settings.php';
require_once '../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Start secure session first
startSecureSession();

requireAdmin();

// Get dashboard statistics
$stats = [];

// Total employees
$stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'employee' AND status = 'active'");
$stmt->execute();
$stats['total_employees'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Pending leave requests
$stmt = $db->prepare("SELECT COUNT(*) as count FROM leave_requests WHERE status = 'pending'");
$stmt->execute();
$stats['pending_leaves'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Active tasks
$stmt = $db->prepare("SELECT COUNT(*) as count FROM tasks WHERE status IN ('pending', 'in_progress')");
$stmt->execute();
$stats['active_tasks'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Open tickets
$stmt = $db->prepare("SELECT COUNT(*) as count FROM tickets WHERE status IN ('open', 'in_progress')");
$stmt->execute();
$stats['open_tickets'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Recent tickets
$stmt = $db->prepare("
    SELECT t.*, u.employee_number, ep.first_name, ep.last_name,
           (SELECT COUNT(*) FROM ticket_responses tr WHERE tr.ticket_id = t.id) as response_count
    FROM tickets t 
    JOIN users u ON t.employee_id = u.id 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    ORDER BY t.created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recentTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent leave requests
$stmt = $db->prepare("
    SELECT lr.*, u.employee_number, ep.first_name, ep.last_name, lt.name as leave_type_name
    FROM leave_requests lr 
    JOIN users u ON lr.employee_id = u.id 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    JOIN leave_types lt ON lr.leave_type_id = lt.id
    ORDER BY lr.created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recentLeaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - AppNomu SalesQ</title>
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.css" rel="stylesheet">
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
        .stat-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        .table-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
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
                        <a class="nav-link active" href="dashboard">
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
                        <h2>Dashboard</h2>
                        <div class="text-muted">
                            <i class="fas fa-calendar me-2"></i><?php echo date('F j, Y'); ?>
                        </div>
                    </div>
                    
                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center">
                                    <div class="stat-icon bg-primary me-3">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div>
                                        <h3 class="mb-0"><?php echo $stats['total_employees']; ?></h3>
                                        <small class="text-muted">Total Employees</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center">
                                    <div class="stat-icon bg-warning me-3">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <div>
                                        <h3 class="mb-0"><?php echo $stats['pending_leaves']; ?></h3>
                                        <small class="text-muted">Pending Leaves</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center">
                                    <div class="stat-icon bg-info me-3">
                                        <i class="fas fa-tasks"></i>
                                    </div>
                                    <div>
                                        <h3 class="mb-0"><?php echo $stats['active_tasks']; ?></h3>
                                        <small class="text-muted">Active Tasks</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center">
                                    <div class="stat-icon bg-danger me-3">
                                        <i class="fas fa-ticket-alt"></i>
                                    </div>
                                    <div>
                                        <h3 class="mb-0"><?php echo $stats['open_tickets']; ?></h3>
                                        <small class="text-muted">Open Tickets</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-bolt me-2"></i>Quick Actions
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-3 mb-2">
                                            <a href="salary-management.php" class="btn btn-primary w-100">
                                                <i class="fas fa-dollar-sign me-2"></i>Salary Management
                                            </a>
                                        </div>
                                        <div class="col-md-3 mb-2">
                                            <a href="salary-cron-monitor.php" class="btn btn-info w-100">
                                                <i class="fas fa-robot me-2"></i>Salary Automation
                                            </a>
                                        </div>
                                        <div class="col-md-3 mb-2">
                                            <a href="employees.php" class="btn btn-success w-100">
                                                <i class="fas fa-users me-2"></i>Manage Employees
                                            </a>
                                        </div>
                                        <div class="col-md-3 mb-2">
                                            <a href="withdrawals.php" class="btn btn-warning w-100">
                                                <i class="fas fa-money-bill-wave me-2"></i>Withdrawals
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Tickets and Leave Requests -->
                    <div class="row">
                        <div class="col-md-8">
                            <div class="card table-card">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-ticket-alt me-2"></i>Recent Tickets
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Employee</th>
                                                    <th>Subject</th>
                                                    <th>Status</th>
                                                    <th>Responses</th>
                                                    <th>Created</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($recentTickets)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center py-4">
                                                        <i class="fas fa-ticket-alt fa-2x text-muted mb-2"></i>
                                                        <p class="text-muted mb-0">No recent tickets found</p>
                                                    </td>
                                                </tr>
                                                <?php else: ?>
                                                    <?php foreach ($recentTickets as $ticket): ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo ($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? ''); ?>
                                                            <small class="text-muted d-block"><?php echo $ticket['employee_number'] ?? 'N/A'; ?></small>
                                                        </td>
                                                        <td>
                                                            <span class="fw-bold"><?php echo htmlspecialchars($ticket['subject']); ?></span>
                                                            <small class="text-muted d-block"><?php echo ucfirst($ticket['priority']); ?> Priority</small>
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
                                                            <span class="badge bg-info"><?php echo $ticket['response_count']; ?></span>
                                                        </td>
                                                        <td>
                                                            <small><?php echo date('M j, Y H:i', strtotime($ticket['created_at'])); ?></small>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="card table-card">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-calendar-check me-2"></i>Recent Leave Requests
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <div class="list-group list-group-flush">
                                        <?php if (empty($recentLeaves)): ?>
                                        <div class="list-group-item text-center py-4">
                                            <i class="fas fa-calendar-check fa-2x text-muted mb-2"></i>
                                            <p class="text-muted mb-0">No recent leave requests</p>
                                        </div>
                                        <?php else: ?>
                                            <?php foreach ($recentLeaves as $leave): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1"><?php echo ($leave['first_name'] ?? '') . ' ' . ($leave['last_name'] ?? ''); ?></h6>
                                                        <p class="mb-1 small"><?php echo $leave['leave_type_name'] ?? 'N/A'; ?></p>
                                                        <small class="text-muted"><?php echo date('M j', strtotime($leave['start_date'])); ?> - <?php echo date('M j', strtotime($leave['end_date'])); ?></small>
                                                    </div>
                                                    <span class="badge bg-<?php echo $leave['status'] === 'pending' ? 'warning' : ($leave['status'] === 'approved' ? 'success' : 'danger'); ?>">
                                                        <?php echo ucfirst($leave['status']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</body>
</html>
