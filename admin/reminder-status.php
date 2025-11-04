<?php
require_once '../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start secure session first
startSecureSession();
requireAdmin();

// Handle manual cron trigger
if (isset($_POST['trigger_cron'])) {
    $cronUrl = "https://emp.appnomu.com/cron/web-cron-reminder.php?secret=reminder-cron-2025";
    $response = file_get_contents($cronUrl);
    $_SESSION['cron_result'] = $response;
    header('Location: reminder-status.php');
    exit();
}

// Get reminder statistics
$stats = [];

// Total reminders
$stmt = $db->prepare("SELECT COUNT(*) FROM reminders");
$stmt->execute();
$stats['total'] = $stmt->fetchColumn();

// Pending reminders
$stmt = $db->prepare("SELECT COUNT(*) FROM reminders WHERE status = 'pending'");
$stmt->execute();
$stats['pending'] = $stmt->fetchColumn();

// Overdue reminders
$stmt = $db->prepare("SELECT COUNT(*) FROM reminders WHERE status = 'pending' AND reminder_datetime <= NOW()");
$stmt->execute();
$stats['overdue'] = $stmt->fetchColumn();

// Sent reminders (last 24 hours)
$stmt = $db->prepare("SELECT COUNT(*) FROM reminders WHERE status = 'sent' AND processed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$stmt->execute();
$stats['sent_24h'] = $stmt->fetchColumn();

// Cron execution logs
$stmt = $db->prepare("SELECT COUNT(*) FROM cron_logs WHERE script_name IN ('reminder-cron', 'web-cron-reminder')");
$stmt->execute();
$stats['cron_executions'] = $stmt->fetchColumn();

// Last cron execution
$stmt = $db->prepare("
    SELECT execution_time, status, message 
    FROM cron_logs 
    WHERE script_name IN ('reminder-cron', 'web-cron-reminder') 
    ORDER BY execution_time DESC 
    LIMIT 1
");
$stmt->execute();
$lastCron = $stmt->fetch(PDO::FETCH_ASSOC);

// Recent reminders
$stmt = $db->prepare("
    SELECT r.*, u.email, ep.first_name, ep.last_name
    FROM reminders r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id
    ORDER BY r.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recentReminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent cron logs
$stmt = $db->prepare("
    SELECT execution_time, status, message 
    FROM cron_logs 
    WHERE script_name IN ('reminder-cron', 'web-cron-reminder') 
    ORDER BY execution_time DESC 
    LIMIT 10
");
$stmt->execute();
$cronLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reminder System Status - AppNomu SalesQ</title>
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 px-0">
                <div class="bg-primary text-white p-3" style="min-height: 100vh;">
                    <div class="text-center mb-4">
                        <img src="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png" 
                             alt="AppNomu SalesQ" style="max-height: 60px; margin-bottom: 15px;">
                        <h4>AppNomu SalesQ</h4>
                        <small>Admin Panel</small>
                    </div>
                    
                    <nav class="nav flex-column">
                        <a class="nav-link text-white-50" href="dashboard">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                        <a class="nav-link text-white-50" href="employees.php">
                            <i class="fas fa-users me-2"></i>Employees
                        </a>
                        <a class="nav-link text-white" href="reminder-status.php">
                            <i class="fas fa-bell me-2"></i>Reminder System
                        </a>
                        <a class="nav-link text-white-50" href="settings.php">
                            <i class="fas fa-cog me-2"></i>Settings
                        </a>
                    </nav>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="p-4">
                    <h2><i class="fas fa-bell me-2 text-primary"></i>Reminder System Status</h2>
                    
                    <?php if (isset($_SESSION['cron_result'])): ?>
                        <div class="alert alert-info">
                            <h6>Manual Cron Execution Result:</h6>
                            <pre style="white-space: pre-wrap; font-size: 0.9em;"><?php echo htmlspecialchars($_SESSION['cron_result']); ?></pre>
                        </div>
                        <?php unset($_SESSION['cron_result']); ?>
                    <?php endif; ?>
                    
                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card bg-primary text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h3><?php echo $stats['total']; ?></h3>
                                            <p class="mb-0">Total Reminders</p>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-bell fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="card bg-warning text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h3><?php echo $stats['pending']; ?></h3>
                                            <p class="mb-0">Pending</p>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-clock fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="card bg-danger text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h3><?php echo $stats['overdue']; ?></h3>
                                            <p class="mb-0">Overdue</p>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-exclamation-triangle fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="card bg-success text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h3><?php echo $stats['sent_24h']; ?></h3>
                                            <p class="mb-0">Sent (24h)</p>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-check-circle fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- System Status -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5><i class="fas fa-cogs me-2"></i>System Status</h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <strong>Cron Executions:</strong> 
                                        <span class="badge bg-<?php echo $stats['cron_executions'] > 0 ? 'success' : 'danger'; ?>">
                                            <?php echo $stats['cron_executions']; ?> total
                                        </span>
                                    </div>
                                    
                                    <?php if ($lastCron): ?>
                                        <div class="mb-3">
                                            <strong>Last Execution:</strong><br>
                                            <small class="text-muted"><?php echo $lastCron['execution_time']; ?></small><br>
                                            <span class="badge bg-<?php echo $lastCron['status'] === 'success' ? 'success' : 'danger'; ?>">
                                                <?php echo $lastCron['status']; ?>
                                            </span>
                                            <small class="text-muted d-block"><?php echo $lastCron['message']; ?></small>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            No cron executions found. The reminder system may not be running.
                                        </div>
                                    <?php endif; ?>
                                    
                                    <form method="POST" class="d-inline">
                                        <button type="submit" name="trigger_cron" class="btn btn-primary">
                                            <i class="fas fa-play me-2"></i>Trigger Manual Processing
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5><i class="fas fa-tools me-2"></i>Setup Instructions</h5>
                                </div>
                                <div class="card-body">
                                    <p><strong>Server Cron Job:</strong></p>
                                    <code style="font-size: 0.8em;">
                                        * * * * * php /home/appnomuc/domains/emp.appnomu.com/public_html/cron/reminder-cron.php
                                    </code>
                                    
                                    <p class="mt-3"><strong>Web Cron Alternative:</strong></p>
                                    <a href="https://emp.appnomu.com/cron/web-cron-reminder.php?secret=reminder-cron-2025" 
                                       target="_blank" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-external-link-alt me-1"></i>Test Web Cron
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Reminders -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5><i class="fas fa-list me-2"></i>Recent Reminders</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recentReminders)): ?>
                                        <p class="text-muted">No reminders found</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Title</th>
                                                        <th>User</th>
                                                        <th>Due</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($recentReminders as $reminder): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($reminder['title']); ?></td>
                                                            <td><?php echo htmlspecialchars($reminder['first_name'] . ' ' . $reminder['last_name']); ?></td>
                                                            <td>
                                                                <small><?php echo date('M j, g:i A', strtotime($reminder['reminder_datetime'])); ?></small>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-<?php 
                                                                    echo match($reminder['status']) {
                                                                        'pending' => 'warning',
                                                                        'sent' => 'success',
                                                                        'failed' => 'danger',
                                                                        'completed' => 'info',
                                                                        default => 'secondary'
                                                                    };
                                                                ?>">
                                                                    <?php echo $reminder['status']; ?>
                                                                </span>
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
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5><i class="fas fa-history me-2"></i>Cron Execution Log</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($cronLogs)): ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            No cron executions logged. Please set up the cron job or use manual trigger.
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Time</th>
                                                        <th>Status</th>
                                                        <th>Message</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($cronLogs as $log): ?>
                                                        <tr>
                                                            <td>
                                                                <small><?php echo date('M j, g:i A', strtotime($log['execution_time'])); ?></small>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-<?php echo $log['status'] === 'success' ? 'success' : 'danger'; ?>">
                                                                    <?php echo $log['status']; ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <small><?php echo htmlspecialchars($log['message']); ?></small>
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
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
