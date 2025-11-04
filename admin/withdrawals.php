<?php
require_once '../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/system-settings.php';

// Start secure session first
startSecureSession();
requireAdmin();

// Admin can only view withdrawal statements - no status updates allowed
// Withdrawals are now processed automatically after OTP verification

// Get filter parameters
$statusFilter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';
$paymentMethodFilter = isset($_GET['payment_method']) ? sanitizeInput($_GET['payment_method']) : '';
$withdrawalTypeFilter = isset($_GET['withdrawal_type']) ? sanitizeInput($_GET['withdrawal_type']) : '';
$dateFrom = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : '';

// Pagination parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 5;
$offset = ($page - 1) * $perPage;

// Build query with filters
$whereConditions = [];
$params = [];

if (!empty($statusFilter)) {
    $whereConditions[] = "sw.status = ?";
    $params[] = $statusFilter;
}

if (!empty($paymentMethodFilter)) {
    $whereConditions[] = "sw.payment_method = ?";
    $params[] = $paymentMethodFilter;
}

if (!empty($withdrawalTypeFilter)) {
    $whereConditions[] = "sw.withdrawal_type = ?";
    $params[] = $withdrawalTypeFilter;
}

if (!empty($dateFrom)) {
    $whereConditions[] = "DATE(sw.created_at) >= ?";
    $params[] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereConditions[] = "DATE(sw.created_at) <= ?";
    $params[] = $dateTo;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get total count for pagination
$countStmt = $db->prepare("
    SELECT COUNT(*) as total
    FROM salary_withdrawals sw 
    JOIN users u ON sw.employee_id = u.id 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    {$whereClause}
");
$countStmt->execute($params);
$totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRecords / $perPage);

// Get withdrawals with employee info (with pagination)
$stmt = $db->prepare("
    SELECT sw.*, 
           u.employee_number, 
           ep.first_name, 
           ep.last_name, 
           ep.department,
           ep.position,
           ep.salary
    FROM salary_withdrawals sw 
    JOIN users u ON sw.employee_id = u.id 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    {$whereClause}
    ORDER BY 
        CASE sw.status 
            WHEN 'pending' THEN 1 
            WHEN 'processing' THEN 2 
            WHEN 'completed' THEN 3 
            WHEN 'failed' THEN 4 
            WHEN 'cancelled' THEN 5 
        END,
        sw.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get withdrawal statistics with charges
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
        SUM(CASE WHEN status IN ('completed', 'processing') THEN amount ELSE 0 END) as total_completed_amount,
        SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as total_pending_amount,
        SUM(CASE WHEN status IN ('completed', 'processing') THEN COALESCE(charges, 0) ELSE 0 END) as total_charges_collected,
        SUM(CASE WHEN status IN ('completed', 'processing') THEN COALESCE(net_amount, amount) ELSE 0 END) as total_net_transferred
    FROM salary_withdrawals
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
    <title>Salary Withdrawals - AppNomu SalesQ</title>
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
        
        .withdrawal-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        
        .withdrawal-card:hover {
            transform: translateY(-2px);
        }
        
        .status-pending { border-left: 4px solid #ffc107; }
        .status-processing { border-left: 4px solid #0dcaf0; }
        .status-completed { border-left: 4px solid #198754; }
        .status-failed { border-left: 4px solid #dc3545; }
        .status-cancelled { border-left: 4px solid #6c757d; }
        
        .amount-large {
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        .payment-method-badge {
            font-size: 0.8rem;
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
                        <a class="nav-link" href="tickets.php">
                            <i class="fas fa-ticket-alt me-2"></i>Tickets
                        </a>
                        <a class="nav-link" href="documents.php">
                            <i class="fas fa-file-pdf me-2"></i>Documents
                        </a>
                        <a class="nav-link" href="salary-management.php">
                            <i class="fas fa-dollar-sign me-2"></i>Salary Management
                        </a>
                        <a class="nav-link active" href="withdrawals.php">
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
                            <h2><i class="fas fa-money-bill-wave me-2"></i>Salary Withdrawals</h2>
                            <p class="text-muted">Manage employee salary withdrawal requests and payments</p>
                        </div>
                    </div>
                    
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Key Statistics -->
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="stats-card text-center">
                                <h3 class="text-primary"><?php echo $stats['total']; ?></h3>
                                <small class="text-muted">Total Requests</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stats-card text-center">
                                <h3 class="text-success">UGX <?php echo number_format($stats['total_completed_amount'] ?? 0); ?></h3>
                                <small class="text-muted">Total Completed</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stats-card text-center">
                                <h3 class="text-info">UGX <?php echo number_format($stats['total_charges_collected'] ?? 0); ?></h3>
                                <small class="text-muted">Charges Collected</small>
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
                                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="processing" <?php echo $statusFilter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                        <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="failed" <?php echo $statusFilter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                        <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Payment Method</label>
                                    <select name="payment_method" class="form-select">
                                        <option value="">All Methods</option>
                                        <option value="bank_transfer" <?php echo $paymentMethodFilter === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                        <option value="mobile_money" <?php echo $paymentMethodFilter === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Type</label>
                                    <select name="withdrawal_type" class="form-select">
                                        <option value="">All Types</option>
                                        <option value="full_salary" <?php echo $withdrawalTypeFilter === 'full_salary' ? 'selected' : ''; ?>>Full Salary</option>
                                        <option value="partial" <?php echo $withdrawalTypeFilter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                        <option value="advance" <?php echo $withdrawalTypeFilter === 'advance' ? 'selected' : ''; ?>>Advance</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Date From</label>
                                    <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Date To</label>
                                    <input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">&nbsp;</label>
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter me-2"></i>Filter
                                        </button>
                                        <a href="withdrawals.php" class="btn btn-outline-secondary">Clear</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Withdrawals Table -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Withdrawal Requests</h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($withdrawals)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-money-bill-wave fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No withdrawal requests found</h5>
                                    <p class="text-muted">No withdrawal requests match your current filters.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                                    <table class="table table-hover mb-0" style="font-size: 0.9rem;">
                                        <thead class="table-dark sticky-top">
                                            <tr>
                                                <th style="width: 8%;">ID</th>
                                                <th style="width: 20%;">Employee</th>
                                                <th style="width: 12%;">Amount</th>
                                                <th style="width: 10%;">Charges</th>
                                                <th style="width: 12%;">Net</th>
                                                <th style="width: 10%;">Method</th>
                                                <th style="width: 10%;">Status</th>
                                                <th style="width: 13%;">Date</th>
                                                <th style="width: 5%;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($withdrawals as $withdrawal): ?>
                                                <tr class="align-middle">
                                                    <td>
                                                        <strong>#<?php echo $withdrawal['id']; ?></strong>
                                                    </td>
                                                    <td>
                                                        <div>
                                                            <strong><?php echo htmlspecialchars($withdrawal['first_name'] . ' ' . $withdrawal['last_name']); ?></strong>
                                                            <br><small class="text-muted"><?php echo htmlspecialchars($withdrawal['employee_number']); ?></small>
                                                            <br><small class="text-muted"><?php echo htmlspecialchars($withdrawal['department'] ?? 'N/A'); ?></small>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <strong class="text-primary">UGX <?php echo number_format($withdrawal['amount'] ?? 0); ?></strong>
                                                        <br><small class="text-muted"><?php echo ucfirst(str_replace('_', ' ', $withdrawal['withdrawal_type'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if (isset($withdrawal['charges']) && $withdrawal['charges'] > 0): ?>
                                                            <span class="text-warning fw-bold">UGX <?php echo number_format($withdrawal['charges']); ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">UGX 0</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="text-success fw-bold">
                                                            UGX <?php echo number_format($withdrawal['net_amount'] ?? ($withdrawal['amount'] - ($withdrawal['charges'] ?? 0))); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $withdrawal['payment_method'] === 'mobile_money' ? 'info' : 'primary'; ?>">
                                                            <i class="fas fa-<?php echo $withdrawal['payment_method'] === 'mobile_money' ? 'mobile-alt' : 'university'; ?> me-1"></i>
                                                            <?php echo $withdrawal['payment_method'] === 'mobile_money' ? 'Mobile' : 'Bank'; ?>
                                                        </span>
                                                        <?php if ($withdrawal['payment_method'] === 'mobile_money' && $withdrawal['mobile_money_provider']): ?>
                                                            <br><small class="badge bg-secondary mt-1"><?php echo strtoupper($withdrawal['mobile_money_provider']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $withdrawal['status'] === 'completed' ? 'success' : 
                                                                ($withdrawal['status'] === 'processing' ? 'info' : 
                                                                ($withdrawal['status'] === 'failed' ? 'danger' : 
                                                                ($withdrawal['status'] === 'cancelled' ? 'secondary' : 'warning'))); 
                                                        ?>">
                                                            <?php echo ucfirst($withdrawal['status']); ?>
                                                        </span>
                                                        <?php if ($withdrawal['failure_reason']): ?>
                                                            <br><small class="text-danger" title="<?php echo htmlspecialchars($withdrawal['failure_reason']); ?>">
                                                                <i class="fas fa-exclamation-triangle"></i> Failed
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <small>
                                                            <strong>Requested:</strong><br>
                                                            <?php echo date('M j, Y', strtotime($withdrawal['created_at'])); ?><br>
                                                            <?php echo date('g:i A', strtotime($withdrawal['created_at'])); ?>
                                                        </small>
                                                        <?php if ($withdrawal['processed_at']): ?>
                                                            <br><small class="text-success">
                                                                <strong>Processed:</strong><br>
                                                                <?php echo date('M j, g:i A', strtotime($withdrawal['processed_at'])); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="viewWithdrawal(<?php echo $withdrawal['id']; ?>)">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <?php if ($withdrawal['flutterwave_reference']): ?>
                                                            <br><small class="text-muted mt-1" title="<?php echo htmlspecialchars($withdrawal['flutterwave_reference']); ?>">
                                                                <i class="fas fa-receipt"></i> Ref
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <?php if ($totalPages > 1): ?>
                                    <div class="d-flex justify-content-between align-items-center p-3 bg-light">
                                        <div>
                                            <small class="text-muted">
                                                Showing <?php echo (($page - 1) * $perPage) + 1; ?> to <?php echo min($page * $perPage, $totalRecords); ?> 
                                                of <?php echo $totalRecords; ?> records
                                            </small>
                                        </div>
                                        <nav aria-label="Withdrawal pagination">
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
        function viewWithdrawal(id) {
            // Fetch withdrawal details and show in modal
            fetch(`../api/get-withdrawal?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showWithdrawalModal(data.withdrawal);
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while fetching withdrawal details.');
                });
        }
        
        function showWithdrawalModal(withdrawal) {
            // Create modal HTML
            const modalHtml = `
                <div class="modal fade" id="withdrawalModal" tabindex="-1" aria-labelledby="withdrawalModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="withdrawalModalLabel">
                                    <i class="fas fa-money-bill-wave me-2"></i>Withdrawal Details #${withdrawal.id}
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="text-primary mb-3"><i class="fas fa-user me-2"></i>Employee Information</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr><td><strong>Name:</strong></td><td>${withdrawal.employee_name}</td></tr>
                                            <tr><td><strong>Employee #:</strong></td><td>${withdrawal.employee_number}</td></tr>
                                            <tr><td><strong>Department:</strong></td><td>${withdrawal.department || 'N/A'}</td></tr>
                                            <tr><td><strong>Position:</strong></td><td>${withdrawal.position || 'N/A'}</td></tr>
                                            <tr><td><strong>Email:</strong></td><td>${withdrawal.email || 'N/A'}</td></tr>
                                            <tr><td><strong>Phone:</strong></td><td>${withdrawal.phone || 'N/A'}</td></tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-success mb-3"><i class="fas fa-dollar-sign me-2"></i>Financial Details</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr><td><strong>Monthly Salary:</strong></td><td>UGX ${new Intl.NumberFormat().format(withdrawal.salary || 0)}</td></tr>
                                            <tr><td><strong>Requested Amount:</strong></td><td class="text-primary fw-bold">UGX ${new Intl.NumberFormat().format(withdrawal.amount)}</td></tr>
                                            <tr><td><strong>Processing Charges:</strong></td><td class="text-warning fw-bold">UGX ${new Intl.NumberFormat().format(withdrawal.charges)}</td></tr>
                                            <tr><td><strong>Net Amount:</strong></td><td class="text-success fw-bold">UGX ${new Intl.NumberFormat().format(withdrawal.net_amount)}</td></tr>
                                            <tr><td><strong>Type:</strong></td><td>${withdrawal.withdrawal_type.replace('_', ' ').toUpperCase()}</td></tr>
                                        </table>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="text-info mb-3"><i class="fas fa-credit-card me-2"></i>Payment Details</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr><td><strong>Method:</strong></td><td>
                                                <span class="badge bg-${withdrawal.payment_method === 'mobile_money' ? 'info' : 'primary'}">
                                                    <i class="fas fa-${withdrawal.payment_method === 'mobile_money' ? 'mobile-alt' : 'university'} me-1"></i>
                                                    ${withdrawal.payment_method === 'mobile_money' ? 'Mobile Money' : 'Bank Transfer'}
                                                </span>
                                            </td></tr>
                                            ${withdrawal.payment_method === 'bank_transfer' ? 
                                                `<tr><td><strong>Bank:</strong></td><td>${withdrawal.bank_name || 'N/A'}</td></tr>
                                                 <tr><td><strong>Account:</strong></td><td>${withdrawal.bank_account || 'N/A'}</td></tr>` :
                                                `<tr><td><strong>Provider:</strong></td><td>${withdrawal.mobile_money_provider ? withdrawal.mobile_money_provider.toUpperCase() : 'N/A'}</td></tr>
                                                 <tr><td><strong>Mobile:</strong></td><td>${withdrawal.mobile_number || 'N/A'}</td></tr>`
                                            }
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-secondary mb-3"><i class="fas fa-info-circle me-2"></i>Transaction Status</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr><td><strong>Status:</strong></td><td>
                                                <span class="badge bg-${withdrawal.status === 'completed' ? 'success' : 
                                                    (withdrawal.status === 'processing' ? 'info' : 
                                                    (withdrawal.status === 'failed' ? 'danger' : 
                                                    (withdrawal.status === 'cancelled' ? 'secondary' : 'warning')))}">
                                                    ${withdrawal.status.toUpperCase()}
                                                </span>
                                            </td></tr>
                                            <tr><td><strong>Reference:</strong></td><td>${withdrawal.flutterwave_reference || 'N/A'}</td></tr>
                                            <tr><td><strong>Requested:</strong></td><td>${withdrawal.created_at_formatted}</td></tr>
                                            ${withdrawal.processed_at_formatted ? 
                                                `<tr><td><strong>Processed:</strong></td><td>${withdrawal.processed_at_formatted}</td></tr>` : ''
                                            }
                                        </table>
                                        ${withdrawal.failure_reason ? 
                                            `<div class="alert alert-danger mt-2">
                                                <strong>Failure Reason:</strong><br>
                                                ${withdrawal.failure_reason}
                                            </div>` : ''
                                        }
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if any
            const existingModal = document.getElementById('withdrawalModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add modal to body
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('withdrawalModal'));
            modal.show();
            
            // Clean up modal after it's hidden
            document.getElementById('withdrawalModal').addEventListener('hidden.bs.modal', function() {
                this.remove();
            });
        }
    </script>
</body>
</html>
