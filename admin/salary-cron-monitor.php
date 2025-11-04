<?php
require_once '../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start secure session first
startSecureSession();
requireAdmin();

// Get current month data
$currentMonth = date('Y-m');
$currentDay = (int)date('d');

// Get monthly allocation summary
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_allocations,
        SUM(allocated_amount) as total_amount,
        MIN(allocation_date) as first_allocation,
        MAX(allocation_date) as last_allocation
    FROM salary_allocations 
    WHERE period = ? AND allocation_type = 'monthly'
");
$stmt->execute([$currentMonth]);
$monthlySummary = $stmt->fetch(PDO::FETCH_ASSOC);

// Get automated allocation status
$stmt = $db->prepare("
    SELECT COUNT(*) as auto_count, MAX(allocation_date) as auto_date
    FROM salary_allocations 
    WHERE period = ? AND allocation_type = 'monthly' AND notes LIKE '%Automated monthly salary allocation%'
");
$stmt->execute([$currentMonth]);
$autoStatus = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent cron logs - check table structure first
try {
    $stmt = $db->prepare("DESCRIBE cron_logs");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Build query based on available columns
    if (in_array('job_name', $columns) && in_array('created_at', $columns)) {
        $query = "SELECT * FROM cron_logs WHERE job_name = 'monthly_salary_allocation' OR (script_name = 'web-cron' AND message LIKE '%Salary allocation executed%') ORDER BY created_at DESC LIMIT 5";
    } else {
        $query = "SELECT * FROM cron_logs WHERE script_name = 'web-cron' OR message LIKE '%salary%' ORDER BY execution_time DESC LIMIT 5";
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $cronLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $cronLogs = [];
    error_log("Cron logs query error: " . $e->getMessage());
}

// Get employees with allocations this month
try {
    $stmt = $db->prepare("
        SELECT 
            u.employee_number, ep.first_name, ep.last_name, ep.monthly_salary,
            sa.allocated_amount, sa.allocation_date, sa.notes,
            CASE 
                WHEN sa.notes LIKE '%Automated%' THEN 'Automated'
                ELSE 'Manual'
            END as allocation_type
        FROM salary_allocations sa
        JOIN users u ON sa.employee_id = u.id
        JOIN employee_profiles ep ON u.id = ep.user_id
        WHERE sa.period = ? AND sa.allocation_type = 'monthly'
        ORDER BY sa.allocation_date DESC, ep.first_name
    ");
    $stmt->execute([$currentMonth]);
    $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allocations = [];
    error_log("Allocations query error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Salary Cron Monitor - AppNomu SalesQ</title>
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
        .info-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 600;
        }
        .status-success { background: #d4edda; color: #155724; }
        .status-warning { background: #fff3cd; color: #856404; }
        .status-danger { background: #f8d7da; color: #721c24; }
        .status-info { background: #d1ecf1; color: #0c5460; }
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
                        <a class="nav-link" href="tickets.php">
                            <i class="fas fa-ticket-alt me-2"></i>Tickets
                        </a>
                        <a class="nav-link" href="documents.php">
                            <i class="fas fa-file-pdf me-2"></i>Documents
                        </a>
                        <a class="nav-link" href="salary-management.php">
                            <i class="fas fa-dollar-sign me-2"></i>Salary Management
                        </a>
                        <a class="nav-link active" href="salary-cron-monitor.php">
                            <i class="fas fa-robot me-2"></i>Salary Automation
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
                            <h2><i class="fas fa-robot me-2"></i>Salary Automation Monitor</h2>
                            <p class="text-muted">Monitor automated monthly salary allocations</p>
                        </div>
                        <div class="text-end">
                            <small class="text-muted">
                                Current Month: <strong><?php echo date('F Y'); ?></strong><br>
                                Next Allocation: <strong>30th of <?php echo date('F'); ?></strong>
                            </small>
                        </div>
                    </div>
                    
                    <!-- Status Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="info-card text-center">
                                <div class="h3 text-primary mb-2"><?php echo $currentDay; ?></div>
                                <div class="text-muted">Current Day</div>
                                <?php if ($currentDay === 30): ?>
                                    <div class="status-badge status-success mt-2">Allocation Day!</div>
                                <?php else: ?>
                                    <div class="status-badge status-info mt-2"><?php echo (30 - $currentDay); ?> days to go</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-card text-center">
                                <div class="h3 text-success mb-2"><?php echo $monthlySummary['total_allocations'] ?? 0; ?></div>
                                <div class="text-muted">Allocations This Month</div>
                                <?php if ($autoStatus['auto_count'] > 0): ?>
                                    <div class="status-badge status-success mt-2">Automated ✓</div>
                                <?php else: ?>
                                    <div class="status-badge status-warning mt-2">Manual Only</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-card text-center">
                                <div class="h3 text-info mb-2">UGX <?php echo number_format($monthlySummary['total_amount'] ?? 0); ?></div>
                                <div class="text-muted">Total Allocated</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-card text-center">
                                <div class="h3 text-warning mb-2"><?php echo count($cronLogs); ?></div>
                                <div class="text-muted">Cron Executions</div>
                                <?php if (!empty($cronLogs)): ?>
                                    <div class="status-badge status-success mt-2">Active</div>
                                <?php else: ?>
                                    <div class="status-badge status-danger mt-2">No Activity</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Automation Status -->
                    <div class="info-card mb-4">
                        <h5><i class="fas fa-cogs me-2"></i>Automation Status</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Current Month (<?php echo date('F Y'); ?>)</h6>
                                <?php if ($autoStatus['auto_count'] > 0): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle me-2"></i>
                                        <strong>Automated allocation completed</strong><br>
                                        <small>Date: <?php echo date('M j, Y g:i A', strtotime($autoStatus['auto_date'])); ?></small>
                                    </div>
                                <?php elseif ($monthlySummary['total_allocations'] > 0): ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <strong>Manual allocations only</strong><br>
                                        <small>No automated allocation detected for this month</small>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-clock me-2"></i>
                                        <strong>Waiting for allocation</strong><br>
                                        <small>No salary allocations for this month yet</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <h6>System Configuration</h6>
                                <ul class="list-unstyled">
                                    <li><i class="fas fa-calendar text-success me-2"></i>Schedule: 30th of each month</li>
                                    <li><i class="fas fa-clock text-success me-2"></i>Frequency: Daily check via web-cron</li>
                                    <li><i class="fas fa-bell text-success me-2"></i>Notifications: SMS + WhatsApp</li>
                                    <li><i class="fas fa-shield-alt text-success me-2"></i>Duplicate Prevention: Active</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Cron Logs -->
                    <div class="info-card mb-4">
                        <h5><i class="fas fa-list me-2"></i>Recent Cron Activity</h5>
                        <?php if (empty($cronLogs)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                No cron activity detected. Check if the web-cron is running properly.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Date/Time</th>
                                            <th>Job</th>
                                            <th>Status</th>
                                            <th>Message</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cronLogs as $log): ?>
                                            <tr>
                                                <td><?php echo date('M j, Y g:i A', strtotime($log['created_at'] ?? $log['execution_time'])); ?></td>
                                                <td>
                                                    <?php if (isset($log['job_name']) && $log['job_name'] === 'monthly_salary_allocation'): ?>
                                                        <span class="badge bg-primary">Salary Allocation</span>
                                                    <?php elseif (strpos($log['message'], 'Salary allocation executed') !== false): ?>
                                                        <span class="badge bg-primary">Salary Allocation</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Web Cron</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $statusClass = $log['status'] === 'success' ? 'success' : ($log['status'] === 'failed' ? 'danger' : 'warning');
                                                    echo "<span class='badge bg-{$statusClass}'>" . ucfirst($log['status']) . "</span>";
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($log['message']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- This Month's Allocations -->
                    <div class="info-card">
                        <h5><i class="fas fa-users me-2"></i>This Month's Allocations (<?php echo date('F Y'); ?>)</h5>
                        <?php if (empty($allocations)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                No salary allocations found for this month.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Employee</th>
                                            <th>Employee Number</th>
                                            <th>Monthly Salary</th>
                                            <th>Allocated Amount</th>
                                            <th>Type</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($allocations as $allocation): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($allocation['first_name'] . ' ' . $allocation['last_name']); ?></td>
                                                <td><?php echo htmlspecialchars($allocation['employee_number']); ?></td>
                                                <td>UGX <?php echo number_format($allocation['monthly_salary']); ?></td>
                                                <td>UGX <?php echo number_format($allocation['allocated_amount']); ?></td>
                                                <td>
                                                    <?php if ($allocation['allocation_type'] === 'Automated'): ?>
                                                        <span class="badge bg-success">Automated</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-primary">Manual</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('M j, Y g:i A', strtotime($allocation['allocation_date'])); ?></td>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 5 minutes
        setTimeout(function() {
            location.reload();
        }, 300000);
    </script>
</body>
</html>
