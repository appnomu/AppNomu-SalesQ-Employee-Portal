<?php
require_once '../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/error-logger.php';

// Start secure session first
startSecureSession();
requireAdmin();

$logger = new ErrorLogger($db);

// Handle log cleanup
if (isset($_POST['clear_old_logs'])) {
    $logger->clearOldLogs();
    $success = 'Old logs cleared successfully';
}

// Get filter parameters
$filterType = $_GET['type'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$logsPerPage = 50;
$offset = ($page - 1) * $logsPerPage;

// Get error logs
$sql = "SELECT * FROM error_logs";
$params = [];

if ($filterType) {
    $sql .= " WHERE type = ?";
    $params[] = $filterType;
}

$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $logsPerPage;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count
$countSql = "SELECT COUNT(*) as total FROM error_logs";
if ($filterType) {
    $countSql .= " WHERE type = ?";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute([$filterType]);
} else {
    $countStmt = $db->prepare($countSql);
    $countStmt->execute();
}
$totalLogs = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalLogs / $logsPerPage);

// Get error type counts
$typeStmt = $db->prepare("
    SELECT type, COUNT(*) as count 
    FROM error_logs 
    GROUP BY type 
    ORDER BY count DESC
");
$typeStmt->execute();
$typeCounts = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Error Logs - AppNomu SalesQ</title>
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
        .log-card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 15px;
        }
        .log-ERROR { border-left: 4px solid #dc3545; }
        .log-WARNING { border-left: 4px solid #ffc107; }
        .log-NOTICE { border-left: 4px solid #17a2b8; }
        .log-upload_error { border-left: 4px solid #fd7e14; }
        .log-cron_error { border-left: 4px solid #e83e8c; }
        .log-database_error { border-left: 4px solid #6f42c1; }
        .log-REMINDER_INFO, .log-REMINDER_SUCCESS { border-left: 4px solid #28a745; }
        .log-REMINDER_FAILED, .log-REMINDER_ERROR { border-left: 4px solid #dc3545; }
        .context-json {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 12px;
            max-height: 200px;
            overflow-y: auto;
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
                        <a class="nav-link" href="dashboard.php">
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
                        <a class="nav-link active" href="error-logs.php">
                            <i class="fas fa-exclamation-triangle me-2"></i>Error Logs
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
            <div class="col-md-9 col-lg-10">
                <div class="container-fluid py-4">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2><i class="fas fa-exclamation-triangle me-2"></i>Error Logs</h2>
                            <p class="text-muted">System error tracking and debugging</p>
                        </div>
                        <div>
                            <form method="POST" class="d-inline">
                                <button type="submit" name="clear_old_logs" class="btn btn-warning" 
                                        onclick="return confirm('Clear logs older than 30 days?')">
                                    <i class="fas fa-trash me-2"></i>Clear Old Logs
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <?php if (isset($success)): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <?php echo $success; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Error Type Summary -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title">Error Summary</h5>
                            <div class="row">
                                <div class="col-md-2">
                                    <a href="error-logs.php" class="text-decoration-none">
                                        <div class="text-center p-3 border rounded">
                                            <h4><?php echo $totalLogs; ?></h4>
                                            <small class="text-muted">Total Logs</small>
                                        </div>
                                    </a>
                                </div>
                                <?php foreach (array_slice($typeCounts, 0, 5) as $typeCount): ?>
                                <div class="col-md-2">
                                    <a href="?type=<?php echo urlencode($typeCount['type']); ?>" class="text-decoration-none">
                                        <div class="text-center p-3 border rounded">
                                            <h4><?php echo $typeCount['count']; ?></h4>
                                            <small class="text-muted"><?php echo htmlspecialchars($typeCount['type']); ?></small>
                                        </div>
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filter -->
                    <?php if ($filterType): ?>
                    <div class="alert alert-info">
                        Filtering by: <strong><?php echo htmlspecialchars($filterType); ?></strong>
                        <a href="error-logs.php" class="btn btn-sm btn-outline-primary ms-2">Clear Filter</a>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Error Logs -->
                    <?php if (empty($logs)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No error logs found
                        </div>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <div class="card log-card log-<?php echo htmlspecialchars($log['type']); ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1">
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($log['type']); ?></span>
                                            <?php echo htmlspecialchars($log['message']); ?>
                                        </h6>
                                        <small class="text-muted">
                                            <i class="fas fa-clock me-1"></i><?php echo $log['created_at']; ?>
                                            <?php if ($log['file']): ?>
                                                | <i class="fas fa-file me-1"></i><?php echo basename($log['file']); ?>:<?php echo $log['line']; ?>
                                            <?php endif; ?>
                                            <?php if ($log['user_id']): ?>
                                                | <i class="fas fa-user me-1"></i>User ID: <?php echo $log['user_id']; ?>
                                            <?php endif; ?>
                                        </small>
                                        
                                        <?php if ($log['context']): ?>
                                        <div class="mt-2">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" 
                                                    data-bs-toggle="collapse" data-bs-target="#context-<?php echo $log['id']; ?>">
                                                <i class="fas fa-code me-1"></i>Show Context
                                            </button>
                                            <div class="collapse mt-2" id="context-<?php echo $log['id']; ?>">
                                                <div class="context-json">
                                                    <?php echo htmlspecialchars(json_encode(json_decode($log['context']), JSON_PRETTY_PRINT)); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <nav>
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo ($page - 1); ?><?php echo $filterType ? '&type=' . urlencode($filterType) : ''; ?>">Previous</a>
                                </li>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo $filterType ? '&type=' . urlencode($filterType) : ''; ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo ($page + 1); ?><?php echo $filterType ? '&type=' . urlencode($filterType) : ''; ?>">Next</a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
